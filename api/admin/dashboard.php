<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/_admin.php';

/** A sub-order that counts toward revenue: not cancelled, not refunded. */
const ADMIN_LIVE_SQL = "so.status NOT IN ('cancelled','refunded') AND so.refunded_at IS NULL";

try {
    require_method('GET');

    $me      = require_admin();
    $adminId = (int) $me['id'];

    $o = db()->query(
        "SELECT
            COUNT(*)                                        AS sub_orders_total,
            COUNT(DISTINCT so.order_id)                     AS orders_total,
            COALESCE(SUM(CASE WHEN " . ADMIN_LIVE_SQL . " THEN so.subtotal END), 0) AS gmv_total,
            COALESCE(SUM(CASE WHEN " . ADMIN_LIVE_SQL . "
                              THEN so.subtotal * so.commission_rate / 100 END), 0)  AS commission_total,
            COALESCE(SUM(CASE WHEN so.payout_released_at IS NULL
                               AND so.status NOT IN ('cancelled','refunded')
                              THEN so.subtotal * (1 - so.commission_rate / 100) END), 0) AS escrow_held,
            COALESCE(SUM(CASE WHEN so.payout_released_at IS NOT NULL
                              THEN so.subtotal * (1 - so.commission_rate / 100) END), 0) AS payouts_released,
            COALESCE(SUM(CASE WHEN so.refunded_at IS NOT NULL THEN so.subtotal END), 0)  AS refunded_total,
            SUM(so.status = 'disputed')                     AS disputes_open,
            SUM(so.status IN ('pending','packed','shipped')) AS orders_open,
            SUM(so.status = 'delivered')                    AS orders_delivered,
            SUM(so.status = 'cancelled')                    AS orders_cancelled,
            SUM(so.status = 'refunded')                     AS orders_refunded,
            COALESCE(SUM(CASE WHEN " . ADMIN_LIVE_SQL . "
                              AND ord.created_at >= CURDATE() - INTERVAL 29 DAY
                              THEN so.subtotal END), 0)     AS gmv_30d,
            COALESCE(SUM(CASE WHEN " . ADMIN_LIVE_SQL . "
                              AND ord.created_at >= CURDATE() - INTERVAL 6 DAY
                              THEN so.subtotal END), 0)     AS gmv_7d
         FROM sub_orders so
         JOIN orders ord ON ord.id = so.order_id"
    )->fetch() ?: [];

    $u = db()->query(
        "SELECT COUNT(*)                            AS users_total,
                SUM(role = 'customer')              AS customers,
                SUM(role = 'seller')                AS sellers,
                SUM(role = 'admin')                 AS admins,
                SUM(status = 'suspended')           AS suspended,
                SUM(created_at >= CURDATE() - INTERVAL 29 DAY) AS new_30d
           FROM users"
    )->fetch() ?: [];

    $sv = db()->query(
        "SELECT SUM(kyc_status = 'pending')  AS sellers_pending,
                SUM(kyc_status = 'verified') AS sellers_verified,
                SUM(kyc_status = 'rejected') AS sellers_rejected
           FROM sellers"
    )->fetch() ?: [];

    $pr = db()->query(
        "SELECT COUNT(*)                   AS products_total,
                SUM(status = 'pending')    AS products_pending,
                SUM(status = 'active')     AS products_active,
                SUM(status = 'rejected')   AS products_rejected,
                SUM(status = 'inactive')   AS products_inactive
           FROM products
          WHERE deleted_at IS NULL"
    )->fetch() ?: [];

    $misc = db()->query(
        "SELECT (SELECT COUNT(*) FROM contact_messages WHERE status = 'new') AS messages_new,
                (SELECT COUNT(*) FROM reviews)                              AS reviews_total,
                (SELECT COUNT(*) FROM faqs)                                 AS faqs_total"
    )->fetch() ?: [];

    $gmv     = round((float) ($o['gmv_total'] ?? 0), 2);
    $orders  = (int) ($o['orders_total'] ?? 0);
    $subs    = (int) ($o['sub_orders_total'] ?? 0);

    $stats = [
        /* money */
        'gmv_total'        => $gmv,
        'gmv_30d'          => round((float) ($o['gmv_30d'] ?? 0), 2),
        'gmv_7d'           => round((float) ($o['gmv_7d'] ?? 0), 2),
        'commission_total' => round((float) ($o['commission_total'] ?? 0), 2),
        'escrow_held'      => round((float) ($o['escrow_held'] ?? 0), 2),
        'payouts_released' => round((float) ($o['payouts_released'] ?? 0), 2),
        'refunded_total'   => round((float) ($o['refunded_total'] ?? 0), 2),
        // Per ORDER, not per sub-order: what one checkout is worth.
        'avg_order_value'  => $orders > 0 ? round($gmv / $orders, 2) : 0.0,

        /* orders */
        'orders_total'     => $orders,
        'sub_orders_total' => $subs,
        'orders_open'      => (int) ($o['orders_open'] ?? 0),
        'orders_delivered' => (int) ($o['orders_delivered'] ?? 0),
        'orders_cancelled' => (int) ($o['orders_cancelled'] ?? 0),
        'orders_refunded'  => (int) ($o['orders_refunded'] ?? 0),
        'disputes_open'    => (int) ($o['disputes_open'] ?? 0),

        /* people */
        'users_total'       => (int) ($u['users_total'] ?? 0),
        'customers'         => (int) ($u['customers'] ?? 0),
        'sellers'           => (int) ($u['sellers'] ?? 0),
        'admins'            => (int) ($u['admins'] ?? 0),
        'users_suspended'   => (int) ($u['suspended'] ?? 0),
        'users_new_30d'     => (int) ($u['new_30d'] ?? 0),
        'sellers_pending'   => (int) ($sv['sellers_pending'] ?? 0),
        'sellers_verified'  => (int) ($sv['sellers_verified'] ?? 0),
        'sellers_rejected'  => (int) ($sv['sellers_rejected'] ?? 0),

        /* catalogue */
        'products_total'    => (int) ($pr['products_total'] ?? 0),
        'products_pending'  => (int) ($pr['products_pending'] ?? 0),
        'products_active'   => (int) ($pr['products_active'] ?? 0),
        'products_rejected' => (int) ($pr['products_rejected'] ?? 0),
        'products_inactive' => (int) ($pr['products_inactive'] ?? 0),

        /* the rest */
        'messages_new'  => (int) ($misc['messages_new'] ?? 0),
        'reviews_total' => (int) ($misc['reviews_total'] ?? 0),
        'faqs_total'    => (int) ($misc['faqs_total'] ?? 0),
    ];

    $days = query_int('days', 30, 7, 90);
    $from = $days - 1;

    $sstmt = db()->prepare(
        "SELECT DATE(ord.created_at) AS d,
                COALESCE(SUM(CASE WHEN " . ADMIN_LIVE_SQL . " THEN so.subtotal END), 0) AS gmv,
                COALESCE(SUM(CASE WHEN " . ADMIN_LIVE_SQL . "
                                  THEN so.subtotal * so.commission_rate / 100 END), 0)  AS commission,
                COUNT(DISTINCT so.order_id) AS orders,
                COUNT(*)                    AS sub_orders,
                SUM(so.status = 'disputed') AS disputes
           FROM sub_orders so
           JOIN orders ord ON ord.id = so.order_id
          WHERE ord.created_at >= CURDATE() - INTERVAL " . $from . " DAY
       GROUP BY DATE(ord.created_at)
       ORDER BY d"
    );
    $sstmt->execute();

    $byDay = [];
    foreach ($sstmt as $row) {
        $byDay[(string) $row['d']] = $row;
    }

    /* Sign-ups per day, so the Overview can show growth alongside revenue. */
    $ustmt = db()->prepare(
        "SELECT DATE(created_at) AS d, COUNT(*) AS n
           FROM users
          WHERE created_at >= CURDATE() - INTERVAL " . $from . " DAY
       GROUP BY DATE(created_at)"
    );
    $ustmt->execute();

    $signupsByDay = [];
    foreach ($ustmt as $row) {
        $signupsByDay[(string) $row['d']] = (int) $row['n'];
    }

    // Quiet days are filled with zeroes: a chart with gaps reads as a dip in
    // the wrong place.
    $series = [];
    for ($i = $from; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime("-$i days"));
        $row  = $byDay[$date] ?? null;

        $series[] = [
            'date'       => $date,
            'label'      => date('j M', strtotime($date)),
            'gmv'        => $row ? round((float) $row['gmv'], 2) : 0.0,
            'commission' => $row ? round((float) $row['commission'], 2) : 0.0,
            'orders'     => $row ? (int) $row['orders'] : 0,
            'sub_orders' => $row ? (int) $row['sub_orders'] : 0,
            'disputes'   => $row ? (int) $row['disputes'] : 0,
            'signups'    => $signupsByDay[$date] ?? 0,
        ];
    }

    /* ---- every seller, verification state and all ---- */
    $sellersStmt = db()->prepare(
        admin_user_sql() . " WHERE u.role = 'seller'
         ORDER BY FIELD(s.kyc_status, 'pending', 'rejected', 'verified'), u.created_at DESC"
    );
    $sellersStmt->execute();
    $sellers = array_map('admin_user_shape', $sellersStmt->fetchAll());

    /* ---- every user ---- */
    $usersStmt = db()->prepare(admin_user_sql() . ' ORDER BY u.created_at DESC LIMIT 500');
    $usersStmt->execute();
    $users = array_map('admin_user_shape', $usersStmt->fetchAll());

    /* ---- the catalogue, every status ---- */
    $products = admin_products();

    /* ---- every sub-order ---- */
    $allOrders = admin_orders();

    /* ---- FAQs ---- */
    $faqs = array_map(
        static fn(array $r): array => [
            'id'         => (int) $r['id'],
            'question'   => $r['question'],
            'answer'     => $r['answer'],
            'category'   => $r['category'],
            'sort_order' => (int) $r['sort_order'],
            'created_at' => $r['created_at'],
            'updated_at' => $r['updated_at'],
        ],
        db()->query(
            'SELECT id, question, answer, category, sort_order, created_at, updated_at
               FROM faqs
           ORDER BY category, sort_order, id'
        )->fetchAll()
    );

    /* ---- contact messages, newest first ---- */
    $messages = array_map(
        static fn(array $r): array => [
            'id'         => (int) $r['id'],
            'user_id'    => $r['user_id'] === null ? null : (int) $r['user_id'],
            'name'       => $r['name'],
            'email'      => $r['email'],
            'subject'    => $r['subject'],
            'message'    => $r['message'],
            'status'     => $r['status'],
            'created_at' => $r['created_at'],
        ],
        db()->query(
            'SELECT id, user_id, name, email, subject, message, status, created_at
               FROM contact_messages
           ORDER BY created_at DESC
              LIMIT 100'
        )->fetchAll()
    );

    /* ---- commission by seller, for the Finance view ---- */
    $bySeller = array_map(
        static fn(array $r): array => [
            'seller_id'    => (int) $r['seller_id'],
            'seller'       => $r['business_name'],
            'public_id'    => $r['public_id'],
            'orders'       => (int) $r['orders'],
            'gmv'          => round((float) $r['gmv'], 2),
            'commission'   => round((float) $r['commission'], 2),
            'net'          => round((float) $r['net'], 2),
            'released'     => round((float) $r['released'], 2),
            'escrow'       => round((float) $r['escrow'], 2),
            'rate'         => $r['commission_rate'] === null ? null : (float) $r['commission_rate'],
        ],
        db()->query(
            "SELECT s.id AS seller_id, s.public_id, s.business_name, s.commission_rate,
                    COUNT(so.id) AS orders,
                    COALESCE(SUM(CASE WHEN " . ADMIN_LIVE_SQL . " THEN so.subtotal END), 0) AS gmv,
                    COALESCE(SUM(CASE WHEN " . ADMIN_LIVE_SQL . "
                                      THEN so.subtotal * so.commission_rate / 100 END), 0) AS commission,
                    COALESCE(SUM(CASE WHEN " . ADMIN_LIVE_SQL . "
                                      THEN so.subtotal * (1 - so.commission_rate / 100) END), 0) AS net,
                    COALESCE(SUM(CASE WHEN so.payout_released_at IS NOT NULL
                                      THEN so.subtotal * (1 - so.commission_rate / 100) END), 0) AS released,
                    COALESCE(SUM(CASE WHEN so.payout_released_at IS NULL
                                       AND so.status NOT IN ('cancelled','refunded')
                                      THEN so.subtotal * (1 - so.commission_rate / 100) END), 0) AS escrow
               FROM sellers s
               LEFT JOIN sub_orders so ON so.seller_id = s.id
               LEFT JOIN orders ord    ON ord.id = so.order_id
           GROUP BY s.id, s.public_id, s.business_name, s.commission_rate
           ORDER BY gmv DESC"
        )->fetchAll()
    );

    json_ok([
        'admin' => [
            'id'         => $adminId,
            'name'       => $me['name'],
            'email'      => $me['email'],
            'phone'      => $me['phone'],
            'avatar_url' => $me['avatar_url'],
        ],

        'stats'  => $stats,
        'series' => $series,

        'sellers'     => $sellers,
        'users'       => $users,
        'products'    => $products,
        'orders'      => $allOrders,
        'faqs'        => $faqs,
        'messages'    => $messages,
        'by_seller'   => $bySeller,
        'settings'    => admin_settings(),

        // The audit trail the Overview page shows.
        'actions' => admin_recent_actions(40),
    ]);
} catch (Throwable $e) {
    app_log('admin/dashboard.php failed: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    json_fail('Could not load the admin console right now. Please try again.', 'SERVER', 500);
}
