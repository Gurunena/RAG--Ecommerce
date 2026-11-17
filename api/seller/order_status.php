<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/_seller.php';

try {
    require_method('POST');
    csrf_check();

    $me       = require_verified_seller();
    $sellerId = (int) $me['seller']['id'];
    $userId   = (int) $me['user']['id'];

    /* Accepts the numeric id or the printed 'SO-000012' reference. */
    $raw = body_str('sub_order_id');
    $subOrderId = ctype_digit($raw) ? (int) $raw : (int) preg_replace('/\D/', '', $raw);

    if ($subOrderId <= 0) {
        json_fail('No shipment was specified.', 'VALIDATION', 400);
    }

    $status   = body_str('status');
    $tracking = body_str('tracking_number');
    $courier  = body_str('courier_name');
    $note     = body_str('note');

    if ($status === '') {
        json_fail('No status was given.', 'VALIDATION', 400);
    }

    /* Cancelling is the one move that should say why. */
    if ($status === 'cancelled' && $note === '') {
        fail_validation(
            ['note' => 'Give the customer a reason for the cancellation'],
            'Please say why this order is being cancelled.'
        );
    }

    if (mb_strlen($note) > 255) {
        fail_validation(['note' => 'That note is too long (255 characters max)']);
    }
    if (mb_strlen($tracking) > 60) {
        fail_validation(['tracking_number' => 'That tracking number is too long (60 characters max)']);
    }
    if ($tracking !== '' && !preg_match('/^[A-Za-z0-9 \-\/]+$/', $tracking)) {
        fail_validation(['tracking_number' => 'Tracking numbers are letters, digits and dashes only']);
    }
    if (mb_strlen($courier) > 60) {
        fail_validation(['courier_name' => 'That courier name is too long (60 characters max)']);
    }

    $result = db_transaction(function (PDO $pdo) use (
        $sellerId, $userId, $subOrderId, $status, $tracking, $courier, $note
    ): array {
        $sub = seller_lock_sub_order($pdo, $sellerId, $subOrderId);
        if ($sub === null) {
            return ['missing' => true];
        }

        order_assert_transition('seller', $sub['status'], $status);

        if ($tracking !== '' || $courier !== '') {
            $pdo->prepare(
                'UPDATE sub_orders
                    SET tracking_number = COALESCE(NULLIF(?, \'\'), tracking_number),
                        courier_name    = COALESCE(NULLIF(?, \'\'), courier_name)
                  WHERE id = ? AND seller_id = ?'
            )->execute([$tracking, $courier, $subOrderId, $sellerId]);
        }

        order_apply_status(
            $pdo,
            $subOrderId,
            (int) $sub['order_id'],
            $status,
            'seller',
            $userId,
            $note !== '' ? $note : null
        );

        if ($status === 'cancelled') {
            $pdo->prepare(
                'UPDATE products p
                   JOIN order_items oi ON oi.product_id = p.id
                    SET p.stock = LEAST(1000000, p.stock + oi.quantity)
                  WHERE oi.sub_order_id = ? AND p.seller_id = ?'
            )->execute([$subOrderId, $sellerId]);
        }

        return ['missing' => false, 'from' => $sub['status']];
    });

    if ($result['missing']) {
        json_fail('That shipment could not be found.', 'NOT_FOUND', 404);
    }

    app_log('Seller advanced a shipment', [
        'seller'    => $sellerId,
        'sub_order' => $subOrderId,
        'from'      => $result['from'],
        'to'        => $status,
    ]);

    json_ok([
        'orders'  => seller_orders($sellerId),
        'status'  => $status,
        'message' => [
            'packed'    => 'Marked as packed. Hand it to the courier next.',
            'shipped'   => 'Marked as shipped. The customer confirms delivery, which releases your payment.',
            'cancelled' => 'Order cancelled and the stock put back.',
        ][$status] ?? ('Order marked as ' . $status . '.'),
    ]);
} catch (Throwable $e) {
    app_log('seller/order_status.php failed: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    json_fail('Could not update that order right now. Please try again.', 'SERVER', 500);
}
