<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/_admin.php';
require_once __DIR__ . '/../lib/risk_engine.php';
require_once __DIR__ . '/../lib/risk_persist.php';

try {
    require_method('GET');
    require_admin();

    $alertId = query_int('id', 0, 1);
    if (!$alertId) {
        json_fail('No alert was specified.', 'VALIDATION', 400);
    }

    $db = db();

    $stmt = $db->prepare(
        'SELECT a.id, a.alert_type, a.order_id, a.sub_order_id, a.seller_id,
                a.user_id, a.level, a.score, a.risk_score_id, a.status,
                a.reviewed_by, a.review_note, a.created_at, a.reviewed_at,
                rev.name AS reviewed_by_name
           FROM risk_alerts a
           LEFT JOIN users rev ON rev.id = a.reviewed_by
          WHERE a.id = ?'
    );
    $stmt->execute([$alertId]);
    $alert = $stmt->fetch();

    if (!$alert) {
        json_fail('That alert could not be found.', 'NOT_FOUND', 404);
    }

    $orderId = $alert['order_id'] === null ? null : (int) $alert['order_id'];
    $userId  = $alert['user_id'] === null ? null : (int) $alert['user_id'];

    $order = null;
    $sellers = [];
    $holds = [];
    $timeline = [];

    if ($orderId !== null) {
        $stmt = $db->prepare(
            'SELECT o.id, o.user_id, o.total_amount, o.currency, o.created_at,
                    o.shipping_name, o.shipping_phone, o.shipping_address,
                    o.shipping_city, o.shipping_zip, o.shipping_note,
                    p.status AS payment_status, p.provider, p.provider_ref,
                    p.amount AS paid_amount, p.escrow_released_at
               FROM orders o
               LEFT JOIN payments p ON p.order_id = o.id
              WHERE o.id = ?'
        );
        $stmt->execute([$orderId]);
        $row = $stmt->fetch();

        if ($row) {
            $order = [
                'id'        => (int) $row['id'],
                'total'     => round((float) $row['total_amount'], 2),
                'currency'  => $row['currency'],
                'placed_at' => $row['created_at'],
                'shipping'  => [
                    'name'    => $row['shipping_name'],
                    'phone'   => $row['shipping_phone'],
                    'address' => $row['shipping_address'],
                    'city'    => $row['shipping_city'],
                    'zip'     => $row['shipping_zip'],
                ],
                'note'    => $row['shipping_note'],
                'payment' => [
                    'status'   => $row['payment_status'],
                    'provider' => $row['provider'],
                    'ref'      => $row['provider_ref'],
                    'amount'   => $row['paid_amount'] === null ? null : round((float) $row['paid_amount'], 2),
                    'escrow_released_at' => $row['escrow_released_at'],
                    // Said on the screen, not just in the schema: there is
                    // no gateway here and nobody should read provider_ref
                    // as a real transaction id.
                    'is_demo'  => $row['provider'] === 'demo',
                ],
            ];

            /* ---- per-seller breakdown, with the lines ---- */
            $stmt = $db->prepare(
                'SELECT so.id, so.seller_id, so.status, so.subtotal, so.delivery_fee,
                        so.commission_rate, so.payout_released_at,
                        so.payout_hold, so.payout_hold_at, so.payout_hold_reason,
                        s.business_name, s.city, s.kyc_status
                   FROM sub_orders so
                   LEFT JOIN sellers s ON s.id = so.seller_id
                  WHERE so.order_id = ?
               ORDER BY so.id'
            );
            $stmt->execute([$orderId]);
            $subOrders = $stmt->fetchAll();

            $itemStmt = $db->prepare(
                'SELECT oi.sub_order_id, oi.product_id, oi.product_title,
                        oi.unit_price, oi.quantity
                   FROM order_items oi
                  WHERE oi.sub_order_id = ?
               ORDER BY oi.id'
            );

            foreach ($subOrders as $sub) {
                $itemStmt->execute([(int) $sub['id']]);
                $items = [];
                foreach ($itemStmt->fetchAll() as $item) {
                    $items[] = [
                        'product_id' => $item['product_id'],
                        'title'      => $item['product_title'],
                        'unit_price' => round((float) $item['unit_price'], 2),
                        'quantity'   => (int) $item['quantity'],
                    ];
                }

                $sellers[] = [
                    'sub_order_id'    => (int) $sub['id'],
                    'seller_id'       => (int) $sub['seller_id'],
                    'name'            => $sub['business_name'],
                    'city'            => $sub['city'],
                    'kyc_status'      => $sub['kyc_status'],
                    'status'          => $sub['status'],
                    'subtotal'        => round((float) $sub['subtotal'], 2),
                    'delivery_fee'    => round((float) $sub['delivery_fee'], 2),
                    'commission_rate' => (float) $sub['commission_rate'],
                    'payout_released_at' => $sub['payout_released_at'],
                    'items'           => $items,
                ];

                if ((int) $sub['payout_hold'] === 1) {
                    $holds[] = [
                        'sub_order_id' => (int) $sub['id'],
                        'seller'       => $sub['business_name'],
                        'amount'       => round((float) $sub['subtotal'], 2),
                        'since'        => $sub['payout_hold_at'],
                        'reason'       => $sub['payout_hold_reason'],
                    ];
                }
            }

            /* ---- the status timeline, including the "flagged" notes ---- */
            $stmt = $db->prepare(
                'SELECT l.sub_order_id, l.status, l.changed_by, l.note, l.changed_at
                   FROM order_status_log l
                   JOIN sub_orders so ON so.id = l.sub_order_id
                  WHERE so.order_id = ?
               ORDER BY l.changed_at, l.id'
            );
            $stmt->execute([$orderId]);
            foreach ($stmt->fetchAll() as $row) {
                $timeline[] = [
                    'sub_order_id' => (int) $row['sub_order_id'],
                    'status'       => $row['status'],
                    'by'           => $row['changed_by'],
                    'note'         => $row['note'],
                    'at'           => $row['changed_at'],
                ];
            }
        }
    }

    $buyer = null;
    $buyerHistory = [];
    $relatedAccounts = [];

    if ($userId !== null) {
        $stmt = $db->prepare(
            'SELECT u.id, u.name, u.email, u.phone, u.role, u.status, u.created_at,
                    TIMESTAMPDIFF(HOUR, u.created_at, NOW()) AS age_hours,
                    (SELECT COUNT(*) FROM orders o WHERE o.user_id = u.id) AS order_count,
                    (SELECT COALESCE(SUM(o.total_amount), 0) FROM orders o WHERE o.user_id = u.id) AS lifetime_value
               FROM users u
              WHERE u.id = ?'
        );
        $stmt->execute([$userId]);
        $row = $stmt->fetch();

        if ($row) {
            $buyer = [
                'id'             => (int) $row['id'],
                'name'           => $row['name'],
                'email'          => $row['email'],
                'phone'          => $row['phone'],
                'status'         => $row['status'],
                'created_at'     => $row['created_at'],
                'age_hours'      => (int) $row['age_hours'],
                'order_count'    => (int) $row['order_count'],
                'lifetime_value' => round((float) $row['lifetime_value'], 2),
            ];
        }

        $stmt = $db->prepare(
            'SELECT o.id, o.total_amount, o.created_at,
                    GROUP_CONCAT(DISTINCT so.status ORDER BY so.status) AS statuses,
                    (SELECT rs.score FROM order_risk_scores rs
                      WHERE rs.order_id = o.id
                   ORDER BY rs.created_at DESC LIMIT 1) AS risk_score,
                    (SELECT rs.level FROM order_risk_scores rs
                      WHERE rs.order_id = o.id
                   ORDER BY rs.created_at DESC LIMIT 1) AS risk_level
               FROM orders o
               LEFT JOIN sub_orders so ON so.order_id = o.id
              WHERE o.user_id = ?
           GROUP BY o.id, o.total_amount, o.created_at
           ORDER BY o.created_at DESC
              LIMIT 20'
        );
        $stmt->execute([$userId]);
        foreach ($stmt->fetchAll() as $row) {
            $buyerHistory[] = [
                'order_id'   => (int) $row['id'],
                'total'      => round((float) $row['total_amount'], 2),
                'placed_at'  => $row['created_at'],
                'statuses'   => $row['statuses'] === null
                    ? []
                    : explode(',', (string) $row['statuses']),
                'risk_score' => $row['risk_score'] === null ? null : (int) $row['risk_score'],
                'risk_level' => $row['risk_level'],
                'is_this_one' => $orderId !== null && (int) $row['id'] === $orderId,
            ];
        }
    }

    if ($order !== null && ($order['shipping']['address'] ?? '') !== '') {
        $target = risk_normalise_address($order['shipping']['address']);

        $stmt = $db->prepare(
            'SELECT DISTINCT o.user_id, o.shipping_address, o.shipping_phone,
                    u.name, u.email, u.created_at, u.status
               FROM orders o
               JOIN users u ON u.id = o.user_id
              WHERE o.shipping_city = ?
                AND o.created_at >= NOW() - INTERVAL 180 DAY
                AND o.user_id IS NOT NULL'
        );
        $stmt->execute([$order['shipping']['city']]);

        $seen = [];
        foreach ($stmt->fetchAll() as $row) {
            if (risk_normalise_address($row['shipping_address']) !== $target) {
                continue;
            }
            $otherId = (int) $row['user_id'];
            if ($otherId === $userId || isset($seen[$otherId])) {
                continue;
            }
            $seen[$otherId] = true;

            $relatedAccounts[] = [
                'user_id'    => $otherId,
                'name'       => $row['name'],
                'email'      => $row['email'],
                'created_at' => $row['created_at'],
                'status'     => $row['status'],
                'same_phone' => risk_normalise_phone($row['shipping_phone'])
                    === risk_normalise_phone($order['shipping']['phone']),
            ];
        }
    }

    $score = null;
    $signals = [];
    $explanation = null;
    $explanationSource = null;

    $stored = null;
    if ($alert['risk_score_id'] !== null) {
        $stmt = $db->prepare(
            'SELECT id, order_id, score, level, rule_score, ml_score, signals,
                    explanation, explanation_source, model_version, scored_by, created_at
               FROM order_risk_scores
              WHERE id = ?'
        );
        $stmt->execute([(int) $alert['risk_score_id']]);
        $stored = $stmt->fetch() ?: null;
    }
    if ($stored === null && $orderId !== null) {
        $latest = risk_latest_score($db, $orderId);
        if ($latest !== null) {
            $stored = [
                'score' => $latest['score'], 'level' => $latest['level'],
                'rule_score' => $latest['rule_score'], 'ml_score' => $latest['ml_score'],
                'signals' => json_encode($latest['signals']),
                'explanation' => $latest['explanation'],
                'explanation_source' => $latest['explanation_source'],
                'model_version' => $latest['model_version'],
                'scored_by' => $latest['scored_by'],
                'created_at' => $latest['scored_at'],
            ];
        }
    }

    if ($stored !== null) {
        $score = [
            'score'         => (int) $stored['score'],
            'level'         => (string) $stored['level'],
            'rule_score'    => (int) $stored['rule_score'],
            'ml_score'      => $stored['ml_score'] === null ? null : (int) $stored['ml_score'],
            'model_version' => $stored['model_version'],
            'scored_by'     => $stored['scored_by'],
            'scored_at'     => $stored['created_at'],
            'blended'       => $stored['ml_score'] !== null,
        ];

        $explanation = $stored['explanation'];
        $explanationSource = $stored['explanation_source'];

        $decoded = json_decode((string) $stored['signals'], true);
        foreach (is_array($decoded) ? $decoded : [] as $signal) {
            $code = (string) ($signal['code'] ?? '');
            $meta = RISK_SIGNALS[$code] ?? null;
            $signals[] = [
                'code'     => $code,
                'label'    => $signal['label'] ?? ($meta['label'] ?? $code),
                'category' => $signal['category'] ?? ($meta['category'] ?? 'other'),
                'weight'   => $signal['weight'] ?? ($meta['weight'] ?? null),
                'detail'   => $signal['detail'] ?? '',
                'evidence' => $signal['evidence'] ?? [],
            ];
        }
    }

    json_ok([
        'alert' => [
            'id'          => (int) $alert['id'],
            'type'        => (string) $alert['alert_type'],
            'level'       => (string) $alert['level'],
            'score'       => (int) $alert['score'],
            'status'      => (string) $alert['status'],
            'created_at'  => (string) $alert['created_at'],
            'reviewed_at' => $alert['reviewed_at'],
            'reviewed_by' => $alert['reviewed_by_name'],
            'note'        => $alert['review_note'],
            'seller_id'   => $alert['seller_id'] === null ? null : (int) $alert['seller_id'],
        ],
        'order'              => $order,
        'buyer'              => $buyer,
        'sellers'            => $sellers,
        'score'              => $score,
        'signals'            => $signals,
        'explanation'        => $explanation,
        'explanation_source' => $explanationSource,
        'buyer_history'      => $buyerHistory,
        'related_accounts'   => $relatedAccounts,
        'holds'              => $holds,
        'timeline'           => $timeline,
        'actions' => [
            'under_review'    => in_array($alert['status'], ['open', 'under_review'], true),
            'confirmed_fraud' => in_array($alert['status'], ['open', 'under_review'], true),
            'dismissed'       => in_array($alert['status'], ['open', 'under_review'], true),
            'rescore'         => $orderId !== null,
        ],
    ]);
} catch (Throwable $e) {
    app_log('admin/risk_alert_detail.php failed: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    json_fail('Could not load that alert right now.', 'SERVER', 500);
}
