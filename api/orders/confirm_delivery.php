<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/_orders.php';

try {
    require_method('POST');
    csrf_check();
    $me = require_login();

    $userId     = (int) $me['id'];
    $subOrderId = body_int('sub_order_id', 0, 1);

    if (!$subOrderId) {
        json_fail('No shipment was specified.', 'VALIDATION', 400);
    }

    $result = db_transaction(function (PDO $pdo) use ($subOrderId, $userId): array {
        $stmt = $pdo->prepare(
            'SELECT so.id, so.order_id, so.status, so.payout_released_at
               FROM sub_orders so
               JOIN orders o ON o.id = so.order_id
              WHERE so.id = ? AND o.user_id = ?
              LIMIT 1
                FOR UPDATE'
        );
        $stmt->execute([$subOrderId, $userId]);
        $sub = $stmt->fetch();

        if (!$sub) {
            return ['missing' => true];
        }

        // 'shipped' → 'delivered' is the only move this grants, and
        // order_assert_transition is what decides that — not this file.
        order_assert_transition('customer', $sub['status'], 'delivered');

        order_apply_status(
            $pdo,
            (int) $sub['id'],
            (int) $sub['order_id'],
            'delivered',
            'customer',
            $userId,
            'Delivery confirmed by the customer · escrow released to the seller'
        );

        return ['missing' => false, 'order_id' => (int) $sub['order_id']];
    });

    if ($result['missing']) {
        json_fail('That shipment could not be found.', 'NOT_FOUND', 404);
    }

    app_log('Escrow released by customer', [
        'sub_order' => $subOrderId,
        'order'     => $result['order_id'],
        'user'      => $userId,
    ]);

    // The whole order back, so the page can repaint the timeline, the status
    // pill and the payout line from one response.
    json_ok([
        'order'   => order_find_for_user($result['order_id'], $userId),
        'message' => 'Delivery confirmed — the payment has been released to the seller.',
    ]);
} catch (Throwable $e) {
    app_log('orders/confirm_delivery.php failed: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    json_fail('Could not confirm that delivery right now. Please try again.', 'SERVER', 500);
}
