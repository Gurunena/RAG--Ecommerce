<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/_admin.php';

try {
    require_method('POST');
    csrf_check();

    $me      = require_admin();
    $adminId = (int) $me['id'];

    $raw        = body_str('sub_order_id');
    $subOrderId = ctype_digit($raw) ? (int) $raw : (int) preg_replace('/\D/', '', $raw);
    $action     = body_str('action');
    $status     = body_str('status');
    $reason     = body_str('reason');
    $note       = body_str('note');

    if ($subOrderId <= 0) {
        json_fail('No order was specified.', 'VALIDATION', 400);
    }
    if (!in_array($action, ['override', 'dispute', 'refund', 'resolve'], true)) {
        json_fail('Unknown action.', 'VALIDATION', 400);
    }

    if (in_array($action, ['dispute', 'refund'], true) && $reason === '') {
        fail_validation(
            ['reason' => $action === 'refund'
                ? 'Give a reason — it goes on the order record'
                : 'Give the dispute reason — the seller is shown it'],
            'This needs a reason.'
        );
    }
    foreach (['reason' => $reason, 'note' => $note] as $field => $value) {
        if (mb_strlen($value) > 500) {
            fail_validation([$field => 'That text is too long (500 characters max)']);
        }
    }

    $result = db_transaction(function (PDO $pdo) use (
        $subOrderId, $action, $status, $reason, $note, $adminId
    ): array {
        $sub = admin_lock_sub_order($pdo, $subOrderId);
        if ($sub === null) {
            return ['missing' => true];
        }

        $from    = $sub['status'];
        $orderId = (int) $sub['order_id'];
        $ref     = sub_order_ref($subOrderId);

        if ($action === 'override') {
            order_assert_transition('admin', $from, $status);

            order_apply_status(
                $pdo,
                $subOrderId,
                $orderId,
                $status,
                'admin',
                $adminId,
                $note !== '' ? $note : 'admin override'
            );

            admin_log(
                $pdo,
                $adminId,
                'override_status',
                'sub_order',
                $subOrderId,
                sprintf('%s: %s → %s%s', $ref, $from, $status, $note !== '' ? ' — ' . $note : '')
            );

            return ['missing' => false, 'from' => $from, 'to' => $status, 'ref' => $ref];
        }

        if ($action === 'dispute') {
            order_assert_transition('admin', $from, 'disputed');

            $pdo->prepare(
                "UPDATE sub_orders
                    SET dispute_reason = ?,
                        dispute_opened_by = 'admin',
                        dispute_opened_at = NOW(),
                        dispute_resolution = NULL,
                        dispute_resolved_at = NULL
                  WHERE id = ?"
            )->execute([$reason, $subOrderId]);

            order_apply_status($pdo, $subOrderId, $orderId, 'disputed', 'admin', $adminId, $reason);

            admin_log($pdo, $adminId, 'open_dispute', 'sub_order', $subOrderId, $ref . ': ' . $reason);

            return ['missing' => false, 'from' => $from, 'to' => 'disputed', 'ref' => $ref];
        }

        if ($action === 'resolve') {
            if ($from !== 'disputed') {
                throw new AdminOrderProblem(sprintf('%s is not disputed, so there is nothing to resolve.', $ref));
            }

            order_assert_transition('admin', $from, 'delivered');

            $resolution = $note !== '' ? $note : 'Resolved in the seller\'s favour';

            $pdo->prepare(
                'UPDATE sub_orders
                    SET dispute_resolution = ?, dispute_resolved_at = NOW()
                  WHERE id = ?'
            )->execute([$resolution, $subOrderId]);

            // Same code path as the customer's own confirmation, so the
            // escrow release and its audit entry are identical.
            order_apply_status(
                $pdo,
                $subOrderId,
                $orderId,
                'delivered',
                'admin',
                $adminId,
                'Dispute resolved for the seller · escrow released'
            );

            admin_log($pdo, $adminId, 'resolve_dispute', 'sub_order', $subOrderId, $ref . ': ' . $resolution);

            return ['missing' => false, 'from' => $from, 'to' => 'delivered', 'ref' => $ref];
        }

        order_assert_transition('admin', $from, 'refunded');

        $amount = round((float) $sub['subtotal'] + (float) $sub['delivery_fee'], 2);

        /* ---- 1. the sub-order ---- */
        $pdo->prepare(
            'UPDATE sub_orders
                SET dispute_resolution = ?, dispute_resolved_at = NOW()
              WHERE id = ? AND dispute_opened_at IS NOT NULL'
        )->execute(['Refunded to the customer: ' . $reason, $subOrderId]);

        // order_apply_status stamps refunded_at and leaves
        // payout_released_at alone — a refunded order never pays out.
        order_apply_status(
            $pdo,
            $subOrderId,
            $orderId,
            'refunded',
            'admin',
            $adminId,
            'Refunded: ' . $reason
        );

        /* ---- 2 & 3. the payment, locked while we work out the new total ---- */
        $payStmt = $pdo->prepare(
            'SELECT id, amount, refunded_amount, status
               FROM payments
              WHERE order_id = ?
              LIMIT 1
                FOR UPDATE'
        );
        $payStmt->execute([$orderId]);
        $payment = $payStmt->fetch();

        if ($payment) {
            $captured    = (float) $payment['amount'];
            $refundedNow = round((float) $payment['refunded_amount'] + $amount, 2);

            // Never claim to have refunded more than was taken.
            if ($refundedNow > $captured) {
                $refundedNow = $captured;
            }

            // A hair under, to survive DECIMAL rounding on the last refund
            // of a multi-seller order.
            $fully = $refundedNow >= $captured - 0.01;

            $pdo->prepare(
                'UPDATE payments SET refunded_amount = ?, status = ? WHERE id = ?'
            )->execute([
                $refundedNow,
                $fully ? 'refunded' : 'partially_refunded',
                (int) $payment['id'],
            ]);

            $pdo->prepare('UPDATE orders SET payment_status = ? WHERE id = ?')
                ->execute([$fully ? 'refunded' : 'partially_refunded', $orderId]);
        }

        /* ---- 4. the stock goes back ---- */
        $pdo->prepare(
            'UPDATE products p
               JOIN order_items oi ON oi.product_id = p.id
                SET p.stock = LEAST(1000000, p.stock + oi.quantity)
              WHERE oi.sub_order_id = ?'
        )->execute([$subOrderId]);

        admin_log(
            $pdo,
            $adminId,
            'refund',
            'sub_order',
            $subOrderId,
            sprintf('%s refunded %s: %s', $ref, number_format($amount, 2), $reason)
        );

        return ['missing' => false, 'from' => $from, 'to' => 'refunded', 'ref' => $ref, 'amount' => $amount];
    });

    if ($result['missing']) {
        json_fail('That order could not be found.', 'NOT_FOUND', 404);
    }

    app_log('Admin order action', [
        'admin'     => $adminId,
        'sub_order' => $subOrderId,
        'action'    => $action,
        'from'      => $result['from'],
        'to'        => $result['to'],
    ]);

    $messages = [
        'override' => sprintf('%s set to %s.', $result['ref'], $result['to']),
        'dispute'  => sprintf('%s flagged as disputed — the payout is held.', $result['ref']),
        'resolve'  => sprintf('%s resolved for the seller — the payout has been released.', $result['ref']),
        'refund'   => sprintf('%s refunded. The stock has been put back.', $result['ref']),
    ];

    json_ok([
        'orders'  => admin_orders(),
        'status'  => $result['to'],
        'message' => $messages[$action],
    ]);
} catch (AdminOrderProblem $e) {
    // Rolled back already; this is a state the admin can see and act on.
    app_log('Admin order action refused: ' . $e->getMessage());
    json_fail($e->getMessage(), 'VALIDATION', 409);
} catch (Throwable $e) {
    app_log('admin/order_action.php failed: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    json_fail('Could not update that order right now. Please try again.', 'SERVER', 500);
}
