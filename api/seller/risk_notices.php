<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/_seller.php';

try {
    require_method('GET');

    $me       = require_seller();
    $sellerId = (int) $me['seller']['id'];

    $stmt = db()->prepare(
        'SELECT so.id, so.order_id, so.subtotal, so.delivery_fee,
                so.payout_hold_at, so.status,
                o.currency, o.created_at AS order_placed_at
           FROM sub_orders so
           JOIN orders o ON o.id = so.order_id
          WHERE so.seller_id = ?
            AND so.payout_hold = 1
            AND so.payout_released_at IS NULL
       ORDER BY so.payout_hold_at DESC, so.id DESC'
    );
    $stmt->execute([$sellerId]);
    $rows = $stmt->fetchAll();

    $notices = [];
    $totalHeld = 0.0;

    foreach ($rows as $row) {
        $amount = round((float) $row['subtotal'] + (float) $row['delivery_fee'], 2);
        $totalHeld += $amount;

        $notices[] = [
            'sub_order_id' => (int) $row['id'],
            'order_id'     => (int) $row['order_id'],
            'amount'       => $amount,
            'currency'     => $row['currency'] ?? 'LKR',
            'since'        => $row['payout_hold_at'],
            'order_status' => $row['status'],
            'message'      => sprintf(
                'Order #%d is under routine review before payout.',
                (int) $row['order_id']
            ),
        ];
    }

    json_ok([
        'notices'    => $notices,
        'count'      => count($notices),
        'total_held' => round($totalHeld, 2),
        'explainer'  => count($notices) > 0
            ? 'Some payouts are reviewed by our team before release. Your order is '
              . 'unaffected — ship as normal, and the payout follows once the review '
              . 'finishes. This is not a penalty and nothing is required from you.'
            : null,
    ]);
} catch (Throwable $e) {
    app_log('seller/risk_notices.php failed: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    json_fail('Could not load your payout notices right now.', 'SERVER', 500);
}
