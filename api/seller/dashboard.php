<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/_seller.php';

/** Revenue only counts orders that were not cancelled or refunded. */
const SELLER_LIVE_SQL = "so.status NOT IN ('cancelled','refunded') AND so.refunded_at IS NULL";

try {
    require_method('GET');

    $me       = require_seller();
    $sellerId = (int) $me['seller']['id'];

    $stmt = db()->prepare(
        "SELECT
            COUNT(*)                                          AS orders_total,
            COALESCE(SUM(CASE WHEN " . SELLER_LIVE_SQL . " THEN so.subtotal END), 0) AS revenue_total,
            COALESCE(SUM(CASE WHEN so.payout_released_at IS NOT NULL
                              THEN so.subtotal * (1 - so.commission_rate / 100) END), 0) AS payout_released,
            COALESCE(SUM(CASE WHEN so.payout_released_at IS NULL
                               AND so.status NOT IN ('cancelled','refunded','disputed')
                              THEN so.subtotal * (1 - so.commission_rate / 100) END), 0) AS payout_escrow,
            COALESCE(SUM(CASE WHEN so.status = 'disputed'
                              THEN so.subtotal * (1 - so.commission_rate / 100) END), 0) AS payout_held,
            COALESCE(SUM(CASE WHEN " . SELLER_LIVE_SQL . "
                              THEN so.subtotal * so.commission_rate / 100 END), 0) AS commission_total,
            SUM(so.status IN ('pending','packed','shipped'))  AS orders_open,
            SUM(so.status = 'pending')                        AS orders_new,
            SUM(so.status = 'delivered')                      AS orders_delivered,
            SUM(so.status = 'disputed')                       AS orders_disputed,
            COALESCE(SUM(CASE WHEN " . SELLER_LIVE_SQL . "
                              AND o.created_at >= CURDATE() - INTERVAL 29 DAY
                              THEN so.subtotal END), 0)       AS revenue_30d,
            SUM(o.created_at >= CURDATE() - INTERVAL 29 DAY)  AS orders_30d,
            COALESCE(SUM(CASE WHEN " . SELLER_LIVE_SQL . "
                              AND o.created_at >= CURDATE() - INTERVAL 6 DAY
                              THEN so.subtotal END), 0)       AS revenue_7d,
            SUM(o.created_at >= CURDATE() - INTERVAL 6 DAY)   AS orders_7d
         FROM sub_orders so
         JOIN orders o ON o.id = so.order_id
        WHERE so.seller_id = ?"
    );
    $stmt->execute([$sellerId]);
    $k = $stmt->fetch() ?: [];

    /* ---------- listings, counted by moderation state ---------- */
    $pstmt = db()->prepare(
        "SELECT COUNT(*)                        AS total,
                SUM(status = 'active')          AS active,
                SUM(status = 'pending')         AS pending,
                SUM(status = 'rejected')        AS rejected,
                SUM(status = 'inactive')        AS inactive,
                SUM(stock <= 0)                 AS out_of_stock,
                SUM(stock > 0 AND stock <= 5)   AS low_stock,
                COALESCE(SUM(stock), 0)         AS units_in_stock
           FROM products
          WHERE seller_id = ? AND deleted_at IS NULL"
    );
    $pstmt->execute([$sellerId]);
    $p = $pstmt->fetch() ?: [];

    /* ---------- how many units have actually sold ---------- */
    $ustmt = db()->prepare(
        "SELECT COALESCE(SUM(oi.quantity), 0)
           FROM order_items oi
           JOIN sub_orders so ON so.id = oi.sub_order_id
          WHERE so.seller_id = ? AND " . SELLER_LIVE_SQL
    );
    $ustmt->execute([$sellerId]);
    $unitsSold = (int) $ustmt->fetchColumn();

    $stats = [
        'revenue_total'    => round((float) ($k['revenue_total'] ?? 0), 2),
        'revenue_30d'      => round((float) ($k['revenue_30d'] ?? 0), 2),
        'revenue_7d'       => round((float) ($k['revenue_7d'] ?? 0), 2),
        'commission_total' => round((float) ($k['commission_total'] ?? 0), 2),

        'orders_total'     => (int) ($k['orders_total'] ?? 0),
        'orders_30d'       => (int) ($k['orders_30d'] ?? 0),
        'orders_7d'        => (int) ($k['orders_7d'] ?? 0),
        'orders_open'      => (int) ($k['orders_open'] ?? 0),
        'orders_new'       => (int) ($k['orders_new'] ?? 0),
        'orders_delivered' => (int) ($k['orders_delivered'] ?? 0),
        'orders_disputed'  => (int) ($k['orders_disputed'] ?? 0),

        'products_total'    => (int) ($p['total'] ?? 0),
        'products_active'   => (int) ($p['active'] ?? 0),
        'products_pending'  => (int) ($p['pending'] ?? 0),
        'products_rejected' => (int) ($p['rejected'] ?? 0),
        'products_inactive' => (int) ($p['inactive'] ?? 0),
        'out_of_stock'      => (int) ($p['out_of_stock'] ?? 0),
        'low_stock'         => (int) ($p['low_stock'] ?? 0),
        'units_in_stock'    => (int) ($p['units_in_stock'] ?? 0),
        'units_sold'        => $unitsSold,

        'rating' => (float) $me['seller']['rating'],
    ];

    $payouts = [
        'released' => round((float) ($k['payout_released'] ?? 0), 2),
        'escrow'   => round((float) ($k['payout_escrow'] ?? 0), 2),
        'held'     => round((float) ($k['payout_held'] ?? 0), 2),
    ];

    $days = query_int('days', 30, 7, 90);

    $sstmt = db()->prepare(
        "SELECT DATE(o.created_at) AS d,
                COALESCE(SUM(CASE WHEN " . SELLER_LIVE_SQL . " THEN so.subtotal END), 0) AS revenue,
                COUNT(*) AS orders,
                COALESCE(SUM(CASE WHEN so.payout_released_at IS NULL
                                   AND so.status NOT IN ('cancelled','refunded')
                                  THEN so.subtotal * (1 - so.commission_rate / 100) END), 0) AS escrow
           FROM sub_orders so
           JOIN orders o ON o.id = so.order_id
          WHERE so.seller_id = ?
            AND o.created_at >= CURDATE() - INTERVAL " . ($days - 1) . " DAY
       GROUP BY DATE(o.created_at)
       ORDER BY d"
    );
    $sstmt->execute([$sellerId]);

    $byDay = [];
    foreach ($sstmt as $row) {
        $byDay[(string) $row['d']] = $row;
    }

    /* Units sold per day needs the items, so it is its own grouped query. */
    $ustmt2 = db()->prepare(
        "SELECT DATE(o.created_at) AS d, COALESCE(SUM(oi.quantity), 0) AS units
           FROM order_items oi
           JOIN sub_orders so ON so.id = oi.sub_order_id
           JOIN orders o      ON o.id  = so.order_id
          WHERE so.seller_id = ?
            AND " . SELLER_LIVE_SQL . "
            AND o.created_at >= CURDATE() - INTERVAL " . ($days - 1) . " DAY
       GROUP BY DATE(o.created_at)"
    );
    $ustmt2->execute([$sellerId]);

    $unitsByDay = [];
    foreach ($ustmt2 as $row) {
        $unitsByDay[(string) $row['d']] = (int) $row['units'];
    }

    $series = [];
    for ($i = $days - 1; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime("-$i days"));
        $row  = $byDay[$date] ?? null;

        $series[] = [
            'date'    => $date,
            'label'   => date('j M', strtotime($date)),
            'revenue' => $row ? round((float) $row['revenue'], 2) : 0.0,
            'orders'  => $row ? (int) $row['orders'] : 0,
            'escrow'  => $row ? round((float) $row['escrow'], 2) : 0.0,
            'units'   => $unitsByDay[$date] ?? 0,
        ];
    }

    $products = seller_products($sellerId);
    $orders   = seller_orders($sellerId);

    $ownRate = $me['seller']['commission_rate'];

    json_ok([
        // The seller's own profile. public_user() already nests this on
        // auth/me.php; repeated here so the dashboard has it from one call.
        'seller' => [
            'id'            => (int) $me['seller']['id'],
            'public_id'     => $me['seller']['public_id'],
            'name'          => $me['user']['name'],
            'email'         => $me['user']['email'],
            'phone'         => $me['user']['phone'],
            'avatar_url'    => $me['user']['avatar_url'],
            'business_name' => $me['seller']['business_name'],
            'business_type' => $me['seller']['business_type'],
            'registration_no' => $me['seller']['registration_no'],
            'city'          => $me['seller']['city'],
            'district'      => $me['seller']['district'],
            'primary_category' => $me['seller']['primary_category'],
            'bank_name'     => $me['seller']['bank_name'],
            'account_number' => $me['seller']['account_number'],
            'branch'        => $me['seller']['branch'],
            'rating'        => (float) $me['seller']['rating'],
            'created_at'    => $me['seller']['created_at'],

            // The verification state, and the reason if it was refused —
            // the dashboard's gate reads these. NIC is never returned.
            'kyc_status'           => $me['seller']['kyc_status'],
            'kyc_rejection_reason' => $me['seller']['kyc_rejection_reason'],
            'kyc_reviewed_at'      => $me['seller']['kyc_reviewed_at'],
            'verified'             => $me['seller']['kyc_status'] === 'verified',
        ],

        'stats'    => $stats,
        'series'   => $series,
        'payouts'  => $payouts,
        'products' => $products,
        'orders'   => $orders,

        'settings' => [
            'categories' => platform_categories(),
            // The rate that actually applies to this seller, plus whether it
            // is their own negotiated one — the payouts page says which.
            'commission_rate'   => $ownRate === null
                ? platform_commission_rate()
                : (float) $ownRate,
            'commission_is_own' => $ownRate !== null,
            'platform_rate'     => platform_commission_rate(),
            'low_stock_at'      => 5,
        ],
    ]);
} catch (Throwable $e) {
    app_log('seller/dashboard.php failed: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    json_fail('Could not load your dashboard right now. Please try again.', 'SERVER', 500);
}
