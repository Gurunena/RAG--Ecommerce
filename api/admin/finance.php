<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/_admin.php';

/** Counts toward revenue: not cancelled, not refunded. */
const FIN_LIVE = "so.status NOT IN ('cancelled','refunded') AND so.refunded_at IS NULL";

try {
    require_method('GET');

    $me      = require_admin();
    $adminId = (int) $me['id'];

    $range = query_str('range', '30d');
    $days  = ['7d' => 7, '30d' => 30, '90d' => 90, '365d' => 365, 'all' => null][$range] ?? 30;
    if (!array_key_exists($range, ['7d' => 1, '30d' => 1, '90d' => 1, '365d' => 1, 'all' => 1])) {
        $range = '30d';
    }

    $where = ['1 = 1'];
    $args  = [];

    if ($days !== null) {
        // (int) from the whitelist above — never interpolated user text.
        $where[] = 'ord.created_at >= CURDATE() - INTERVAL ' . (int) ($days - 1) . ' DAY';
    }

    /* ---------- one seller, or all ---------- */
    $sellerId = query_int('seller', null, 1);
    if ($sellerId !== null) {
        $exists = db()->prepare('SELECT business_name FROM sellers WHERE id = ?');
        $exists->execute([$sellerId]);
        $sellerName = $exists->fetchColumn();

        if ($sellerName === false) {
            json_fail('That seller could not be found.', 'NOT_FOUND', 404);
        }
        $where[] = 'so.seller_id = ?';
        $args[]  = $sellerId;
    } else {
        $sellerName = null;
    }

    $whereSql = implode(' AND ', $where);

    $stmt = db()->prepare(
        "SELECT COUNT(*)                    AS sub_orders,
                COUNT(DISTINCT so.order_id) AS orders,
                COUNT(DISTINCT so.seller_id) AS sellers,
                COALESCE(SUM(CASE WHEN " . FIN_LIVE . " THEN so.subtotal END), 0)        AS gmv,
                COALESCE(SUM(CASE WHEN " . FIN_LIVE . " THEN so.delivery_fee END), 0)    AS delivery,
                COALESCE(SUM(CASE WHEN " . FIN_LIVE . "
                                  THEN so.subtotal * so.commission_rate / 100 END), 0)   AS commission,
                COALESCE(SUM(CASE WHEN " . FIN_LIVE . "
                                  THEN so.subtotal * (1 - so.commission_rate / 100) END), 0) AS net,
                COALESCE(SUM(CASE WHEN so.payout_released_at IS NOT NULL
                                  THEN so.subtotal * (1 - so.commission_rate / 100) END), 0) AS released,
                COALESCE(SUM(CASE WHEN so.payout_released_at IS NULL
                                   AND so.status NOT IN ('cancelled','refunded')
                                  THEN so.subtotal * (1 - so.commission_rate / 100) END), 0) AS escrow,
                COALESCE(SUM(CASE WHEN so.status = 'disputed'
                                  THEN so.subtotal * (1 - so.commission_rate / 100) END), 0) AS disputed,
                COALESCE(SUM(CASE WHEN so.refunded_at IS NOT NULL THEN so.subtotal END), 0)  AS refunded,
                SUM(so.refunded_at IS NOT NULL) AS refund_count
           FROM sub_orders so
           JOIN orders ord ON ord.id = so.order_id
          WHERE $whereSql"
    );
    $stmt->execute($args);
    $t = $stmt->fetch() ?: [];

    $gmv    = round((float) ($t['gmv'] ?? 0), 2);
    $orders = (int) ($t['orders'] ?? 0);

    $totals = [
        'gmv'              => $gmv,
        'delivery'         => round((float) ($t['delivery'] ?? 0), 2),
        'commission'       => round((float) ($t['commission'] ?? 0), 2),
        'net_to_sellers'   => round((float) ($t['net'] ?? 0), 2),
        'released'         => round((float) ($t['released'] ?? 0), 2),
        'escrow'           => round((float) ($t['escrow'] ?? 0), 2),
        'disputed'         => round((float) ($t['disputed'] ?? 0), 2),
        'refunded'         => round((float) ($t['refunded'] ?? 0), 2),
        'refund_count'     => (int) ($t['refund_count'] ?? 0),
        'orders'           => $orders,
        'sub_orders'       => (int) ($t['sub_orders'] ?? 0),
        'sellers'          => (int) ($t['sellers'] ?? 0),
        'avg_order_value'  => $orders > 0 ? round($gmv / $orders, 2) : 0.0,
        // The rate actually earned across the range, which differs from the
        // platform default whenever a seller has a negotiated rate.
        'effective_rate'   => $gmv > 0 ? round((float) $t['commission'] / $gmv * 100, 2) : 0.0,
    ];

    $bsStmt = db()->prepare(
        "SELECT so.seller_id, s.public_id, s.business_name, s.commission_rate AS own_rate,
                COUNT(*)                                  AS sub_orders,
                COALESCE(SUM(CASE WHEN " . FIN_LIVE . " THEN so.subtotal END), 0) AS gmv,
                COALESCE(SUM(CASE WHEN " . FIN_LIVE . "
                                  THEN so.subtotal * so.commission_rate / 100 END), 0) AS commission,
                COALESCE(SUM(CASE WHEN " . FIN_LIVE . "
                                  THEN so.subtotal * (1 - so.commission_rate / 100) END), 0) AS net,
                COALESCE(SUM(CASE WHEN so.payout_released_at IS NOT NULL
                                  THEN so.subtotal * (1 - so.commission_rate / 100) END), 0) AS released,
                COALESCE(SUM(CASE WHEN so.payout_released_at IS NULL
                                   AND so.status NOT IN ('cancelled','refunded')
                                  THEN so.subtotal * (1 - so.commission_rate / 100) END), 0) AS escrow,
                COALESCE(SUM(CASE WHEN so.refunded_at IS NOT NULL THEN so.subtotal END), 0)  AS refunded
           FROM sub_orders so
           JOIN sellers s  ON s.id = so.seller_id
           JOIN orders ord ON ord.id = so.order_id
          WHERE $whereSql
       GROUP BY so.seller_id, s.public_id, s.business_name, s.commission_rate
       ORDER BY gmv DESC"
    );
    $bsStmt->execute($args);

    $bySeller = array_map(static function (array $r): array {
        $gmv = round((float) $r['gmv'], 2);
        $com = round((float) $r['commission'], 2);

        return [
            'seller_id'  => (int) $r['seller_id'],
            'public_id'  => $r['public_id'],
            'seller'     => $r['business_name'],
            'sub_orders' => (int) $r['sub_orders'],
            'gmv'        => $gmv,
            'commission' => $com,
            'net'        => round((float) $r['net'], 2),
            'released'   => round((float) $r['released'], 2),
            'escrow'     => round((float) $r['escrow'], 2),
            'refunded'   => round((float) $r['refunded'], 2),
            'own_rate'   => $r['own_rate'] === null ? null : (float) $r['own_rate'],
            // Averaged over the range's snapshotted rates, so a seller whose
            // rate changed mid-range reads correctly.
            'effective_rate' => $gmv > 0 ? round($com / $gmv * 100, 2) : 0.0,
        ];
    }, $bsStmt->fetchAll());

    $seriesDays = $days ?? 90;      // 'all' still charts the last 90 days

    $dsStmt = db()->prepare(
        "SELECT DATE(ord.created_at) AS d,
                COALESCE(SUM(CASE WHEN " . FIN_LIVE . " THEN so.subtotal END), 0) AS gmv,
                COALESCE(SUM(CASE WHEN " . FIN_LIVE . "
                                  THEN so.subtotal * so.commission_rate / 100 END), 0) AS commission,
                COUNT(DISTINCT so.order_id) AS orders
           FROM sub_orders so
           JOIN orders ord ON ord.id = so.order_id
          WHERE $whereSql
            AND ord.created_at >= CURDATE() - INTERVAL " . (int) ($seriesDays - 1) . " DAY
       GROUP BY DATE(ord.created_at)
       ORDER BY d"
    );
    $dsStmt->execute($args);

    $byDay = [];
    foreach ($dsStmt as $row) {
        $byDay[(string) $row['d']] = $row;
    }

    $series = [];
    for ($i = $seriesDays - 1; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime("-$i days"));
        $row  = $byDay[$date] ?? null;

        $series[] = [
            'date'       => $date,
            'label'      => date('j M', strtotime($date)),
            'gmv'        => $row ? round((float) $row['gmv'], 2) : 0.0,
            'commission' => $row ? round((float) $row['commission'], 2) : 0.0,
            'orders'     => $row ? (int) $row['orders'] : 0,
        ];
    }

    if (query_str('format') === 'csv') {
        $rowsStmt = db()->prepare(
            "SELECT so.id, so.order_id, s.business_name, ord.created_at, so.status,
                    so.subtotal, so.delivery_fee, so.commission_rate,
                    so.payout_released_at, so.refunded_at,
                    buyer.name AS buyer_name
               FROM sub_orders so
               JOIN sellers s  ON s.id = so.seller_id
               JOIN orders ord ON ord.id = so.order_id
               LEFT JOIN users buyer ON buyer.id = ord.user_id
              WHERE $whereSql
           ORDER BY ord.created_at DESC, so.id DESC"
        );
        $rowsStmt->execute($args);

        if (!headers_sent()) {
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="consult-finance-' . $range . '-' . date('Y-m-d') . '.csv"');
        }

        $out = fopen('php://output', 'w');

        // A BOM, so Excel opens the Sinhala/Tamil business names as UTF-8
        // instead of mojibake.
        fwrite($out, "\xEF\xBB\xBF");

        fputcsv($out, [
            'sub_order', 'order', 'seller', 'buyer', 'placed_at', 'status',
            'gmv_goods', 'delivery_fee', 'commission_rate_pct', 'commission',
            'net_to_seller', 'payout_state', 'payout_released_at', 'refunded_at',
        ]);

        foreach ($rowsStmt as $r) {
            $subtotal   = (float) $r['subtotal'];
            $rate       = (float) $r['commission_rate'];
            $commission = round($subtotal * $rate / 100, 2);

            $state = $r['refunded_at'] !== null
                ? 'refunded'
                : ($r['payout_released_at'] !== null
                    ? 'released'
                    : ($r['status'] === 'cancelled'
                        ? 'cancelled'
                        : ($r['status'] === 'disputed' ? 'held' : 'escrow')));

            fputcsv($out, [
                sub_order_ref((int) $r['id']),
                order_ref((int) $r['order_id']),
                $r['business_name'],
                $r['buyer_name'] ?? '',
                $r['created_at'],
                $r['status'],
                number_format($subtotal, 2, '.', ''),
                number_format((float) $r['delivery_fee'], 2, '.', ''),
                number_format($rate, 2, '.', ''),
                number_format($commission, 2, '.', ''),
                number_format(round($subtotal - $commission, 2), 2, '.', ''),
                $state,
                $r['payout_released_at'] ?? '',
                $r['refunded_at'] ?? '',
            ]);
        }

        fclose($out);

        // The export itself is an admin action worth recording.
        db_transaction(function (PDO $pdo) use ($adminId, $range, $sellerName): void {
            admin_log(
                $pdo,
                $adminId,
                'export_finance',
                'settings',
                null,
                'exported finance CSV (' . $range . ($sellerName !== null ? ', ' . $sellerName : ', all sellers') . ')'
            );
        });

        exit;
    }

    json_ok([
        'range'       => $range,
        'days'        => $days,
        'seller_id'   => $sellerId,
        'seller_name' => $sellerName,
        'totals'      => $totals,
        'by_seller'   => $bySeller,
        'series'      => $series,
    ]);
} catch (Throwable $e) {
    app_log('admin/finance.php failed: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    json_fail('Could not load the finance figures right now. Please try again.', 'SERVER', 500);
}
