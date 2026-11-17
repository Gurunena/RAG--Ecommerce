<?php
declare(strict_types=1);

require_once __DIR__ . '/../helpers.php';

const RISK_SIGNALS = [
    /* ---- buyer behaviour and velocity ---- */
    'VELOCITY_USER' => [
        'label'    => 'Many orders in a short window',
        'weight'   => 35,   // Reaches medium alone: a burst is the clearest single thing in the set.
        'category' => 'velocity',
    ],
    'NEW_ACCOUNT_HIGH_VALUE' => [
        'label'    => 'Brand-new account, unusually large order',
        'weight'   => 25,
        'category' => 'account',
    ],
    'FAILED_PAYMENTS_BEFORE_SUCCESS' => [
        'label'    => 'Several failed payments just before this one',
        'weight'   => 40,   // The heaviest. A run of declines then a success has few innocent
        'category' => 'payment',
    ],
    'HIGH_CANCEL_REFUND_RATE' => [
        'label'    => 'Buyer cancels or refunds most of their orders',
        'weight'   => 22,
        'category' => 'account',
    ],

    /* ---- what is in the order ---- */
    'QTY_OUTLIER' => [
        'label'    => 'Quantity far above normal for this product',
        'weight'   => 18,
        'category' => 'content',
    ],
    'VALUE_OUTLIER' => [
        'label'    => 'Order value far above this buyer\'s own history',
        'weight'   => 18,
        'category' => 'content',
    ],
    'HIGH_VALUE_ELECTRONICS_COMBO' => [
        'label'    => 'Several high-value items of one kind, new buyer',
        'weight'   => 20,
        'category' => 'content',
    ],

    /* ---- address and identity ---- */
    'SHARED_ADDRESS_MANY_ACCOUNTS' => [
        'label'    => 'Address or phone shared across several accounts',
        'weight'   => 30,
        'category' => 'identity',
    ],
    'BUYER_MATCHES_SELLER' => [
        'label'    => 'Buyer details match the seller\'s own',
        'weight'   => 38,   // Reaches medium alone: self-dealing is how fake sales and
        'category' => 'identity',
    ],

    /* ---- the seller side ---- */
    'SELLER_ORDER_SPIKE' => [
        'label'    => 'Seller\'s order rate suddenly much higher',
        'weight'   => 16,
        'category' => 'seller',
    ],
    'SELLER_FEW_BUYERS' => [
        'label'    => 'Nearly all of a seller\'s orders from one or two buyers',
        'weight'   => 18,
        'category' => 'seller',
    ],
    'SELLER_DISPUTE_RATE' => [
        'label'    => 'Seller\'s dispute and refund rate is high',
        'weight'   => 22,
        'category' => 'seller',
    ],
    'REVIEW_BURST_NEW_ACCOUNTS' => [
        'label'    => 'Burst of 5-star reviews from brand-new accounts',
        'weight'   => 25,
        'category' => 'seller',
    ],
];

/** The level boundaries and blend weights, read once. */
function risk_config(bool $forget = false): array
{
    static $config = null;

    if ($forget) {
        $config = null;
    }

    if ($config !== null) {
        return $config;
    }

    $num = static function (string $key, float $fallback): float {
        $v = setting($key, $fallback);
        return is_numeric($v) ? (float) $v : $fallback;
    };

    return $config = [
        'medium_at'      => $num('risk_medium_at', 35),
        'high_at'        => $num('risk_high_at', 65),
        'rule_weight'    => $num('risk_rule_weight', 0.7),
        'ml_weight'      => $num('risk_ml_weight', 0.3),
        'hold_at_level'  => is_string(setting('risk_hold_at_level', 'high'))
            ? (string) setting('risk_hold_at_level', 'high')
            : 'high',
        'holds_enabled'  => setting('risk_holds_enabled', true) !== false,
        'llm_explain'    => setting('risk_llm_explain', true) !== false,
    ];
}

function risk_config_reset(): void
{
    risk_config(true);
}

/** A score to a level, using the configured boundaries. */
function risk_level_for(int $score): string
{
    $c = risk_config();
    if ($score >= $c['high_at']) {
        return 'high';
    }
    if ($score >= $c['medium_at']) {
        return 'medium';
    }
    return 'low';
}

function risk_signal(string $code, array $evidence): array
{
    $meta = RISK_SIGNALS[$code] ?? null;
    if ($meta === null) {
        throw new InvalidArgumentException('Unknown risk signal: ' . $code);
    }

    return [
        'code'     => $code,
        'label'    => $meta['label'],
        'weight'   => $meta['weight'],
        'category' => $meta['category'],
        'evidence' => $evidence,
    ];
}

function risk_normalise_address(?string $address): string
{
    $s = mb_strtolower(trim((string) $address));
    if ($s === '') {
        return '';
    }

    // Drop a leading "no." / "no" house-number prefix.
    $s = preg_replace('/^no\.?\s*/u', '', $s) ?? $s;

    $abbreviations = [
        '/\broad\b/u'   => 'rd',
        '/\bstreet\b/u' => 'st',
        '/\bavenue\b/u' => 'ave',
        '/\blane\b/u'   => 'ln',
        '/\bplace\b/u'  => 'pl',
        '/\bmawatha\b/u' => 'mw',
    ];
    $s = preg_replace(array_keys($abbreviations), array_values($abbreviations), $s) ?? $s;

    // Everything that is not a letter, digit or space goes.
    $s = preg_replace('/[^\p{L}\p{N} ]+/u', ' ', $s) ?? $s;
    $s = preg_replace('/\s+/u', ' ', $s) ?? $s;

    return trim($s);
}

/** Digits only, so '077 123 4567' and '0771234567' compare equal. */
function risk_normalise_phone(?string $phone): string
{
    $digits = preg_replace('/\D+/', '', (string) $phone) ?? '';
    // Fold the +94 international form onto the local 0 form.
    if (str_starts_with($digits, '94') && strlen($digits) === 11) {
        $digits = '0' . substr($digits, 2);
    }
    return $digits;
}

function risk_median(array $values): ?float
{
    $values = array_values(array_filter($values, 'is_numeric'));
    if (!$values) {
        return null;
    }
    sort($values);
    $n   = count($values);
    $mid = intdiv($n, 2);

    return $n % 2
        ? (float) $values[$mid]
        : ((float) $values[$mid - 1] + (float) $values[$mid]) / 2;
}

function risk_mad(array $values, ?float $median = null): ?float
{
    $values = array_values(array_filter($values, 'is_numeric'));
    if (!$values) {
        return null;
    }
    $median ??= risk_median($values);
    if ($median === null) {
        return null;
    }

    $deviations = array_map(static fn($v): float => abs((float) $v - $median), $values);

    $mad = risk_median($deviations);
    return $mad === null ? null : $mad * 1.4826;
}

function risk_now(PDO $db): string
{
    return (string) $db->query('SELECT NOW()')->fetchColumn();
}

function score_order(PDO $db, int $orderId): array
{
    /* ---------- the order, the buyer, and the totals ---------- */
    $stmt = $db->prepare(
        'SELECT o.id, o.user_id, o.total_amount, o.currency, o.created_at,
                o.shipping_name, o.shipping_phone, o.shipping_address, o.shipping_city,
                u.created_at AS user_created_at, u.email AS user_email, u.name AS user_name,
                TIMESTAMPDIFF(HOUR, u.created_at, o.created_at) AS account_age_hours,
                (SELECT COUNT(*) FROM sub_orders so WHERE so.order_id = o.id) AS seller_count,
                (SELECT COALESCE(SUM(oi.quantity), 0)
                   FROM order_items oi
                   JOIN sub_orders so2 ON so2.id = oi.sub_order_id
                  WHERE so2.order_id = o.id) AS item_count
           FROM orders o
           LEFT JOIN users u ON u.id = o.user_id
          WHERE o.id = ?'
    );
    $stmt->execute([$orderId]);
    $order = $stmt->fetch();

    if (!$order) {
        return [
            'score'      => 0,
            'rule_score' => 0,
            'level'      => 'unscored',
            'signals'    => [],
            'order'      => null,
            'reason'     => 'Order ' . $orderId . ' does not exist',
        ];
    }

    $userId    = $order['user_id'] === null ? null : (int) $order['user_id'];
    $total     = (float) $order['total_amount'];
    $placedAt  = (string) $order['created_at'];
    $ageHours  = $order['account_age_hours'] === null ? null : (int) $order['account_age_hours'];

    $signals = [];

    if ($userId !== null) {

        $vel = $db->prepare(
            'SELECT
                SUM(created_at > ? - INTERVAL 1 HOUR)  AS last_hour,
                SUM(created_at > ? - INTERVAL 24 HOUR) AS last_day,
                TIMESTAMPDIFF(MINUTE,
                    MIN(CASE WHEN created_at > ? - INTERVAL 1 HOUR THEN created_at END), ?) AS span_minutes
               FROM orders
              WHERE user_id = ? AND created_at <= ?'
        );
        $vel->execute([$placedAt, $placedAt, $placedAt, $placedAt, $userId, $placedAt]);
        $v = $vel->fetch() ?: [];

        $lastHour = (int) ($v['last_hour'] ?? 0);
        $lastDay  = (int) ($v['last_day'] ?? 0);

        if ($lastHour >= 3 || $lastDay >= 6) {
            $signals[] = risk_signal('VELOCITY_USER', [
                'orders_last_hour' => $lastHour,
                'orders_last_24h'  => $lastDay,
                'window_minutes'   => (int) ($v['span_minutes'] ?? 0),
                'threshold'        => $lastHour >= 3 ? '3 or more within 1 hour' : '6 or more within 24 hours',
            ]);
        }

        if ($ageHours !== null && $ageHours < 24) {
            $med = $db->prepare(
                'SELECT MEDIAN(total_amount) OVER () AS platform_median, COUNT(*) OVER () AS n
                   FROM orders WHERE created_at <= ? LIMIT 1'
            );
            $med->execute([$placedAt]);
            $m = $med->fetch() ?: [];

            $platformMedian = isset($m['platform_median']) ? (float) $m['platform_median'] : 0.0;
            $population     = (int) ($m['n'] ?? 0);

            if ($population >= 20 && $platformMedian > 0) {
                $ratio = $total / $platformMedian;
                // 2.5x the platform median from an account under a day old.
                if ($ratio >= 2.5) {
                    $signals[] = risk_signal('NEW_ACCOUNT_HIGH_VALUE', [
                        'account_age_hours' => $ageHours,
                        'order_total'       => round($total, 2),
                        'platform_median'   => round($platformMedian, 2),
                        'times_median'      => round($ratio, 1),
                        'population'        => $population,
                    ]);
                }
            }
        }

        $failed = $db->prepare(
            'SELECT COUNT(*) FROM payments p
               JOIN orders o ON o.id = p.order_id
              WHERE o.user_id = ?
                AND p.status = \'failed\'
                AND p.id <> COALESCE((SELECT id FROM payments WHERE order_id = ? LIMIT 1), 0)
                AND o.created_at BETWEEN ? - INTERVAL 1 HOUR AND ?'
        );
        $failed->execute([$userId, $orderId, $placedAt, $placedAt]);
        $failCount = (int) $failed->fetchColumn();

        if ($failCount >= 2) {
            $signals[] = risk_signal('FAILED_PAYMENTS_BEFORE_SUCCESS', [
                'failed_payments' => $failCount,
                'window'          => 'the hour before this order',
            ]);
        }

        $hist = $db->prepare(
            'SELECT COUNT(DISTINCT o.id) AS orders,
                    COUNT(DISTINCT CASE WHEN so.status IN (\'cancelled\',\'refunded\')
                                         OR so.refunded_at IS NOT NULL
                                        THEN o.id END) AS bad
               FROM orders o
               JOIN sub_orders so ON so.order_id = o.id
              WHERE o.user_id = ? AND o.id <> ? AND o.created_at <= ?'
        );
        $hist->execute([$userId, $orderId, $placedAt]);
        $h = $hist->fetch() ?: [];

        $priorOrders = (int) ($h['orders'] ?? 0);
        $badOrders   = (int) ($h['bad'] ?? 0);

        if ($priorOrders >= 5) {
            $rate = $badOrders / $priorOrders;
            if ($rate > 0.40) {
                $signals[] = risk_signal('HIGH_CANCEL_REFUND_RATE', [
                    'prior_orders'          => $priorOrders,
                    'cancelled_or_refunded' => $badOrders,
                    'rate_percent'          => round($rate * 100, 1),
                    'threshold_percent'     => 40,
                ]);
            }
        }

        if ($priorOrders >= 5) {
            $vals = $db->prepare(
                'SELECT total_amount FROM orders
                  WHERE user_id = ? AND id <> ? AND created_at <= ?'
            );
            $vals->execute([$userId, $orderId, $placedAt]);
            $history = array_map('floatval', $vals->fetchAll(PDO::FETCH_COLUMN));

            $median = risk_median($history);
            $mad    = risk_mad($history, $median);

            if ($median !== null && $median > 0) {
                $limit = ($mad !== null && $mad > 0.01)
                    ? $median + 3 * $mad
                    : $median * 3;

                if ($total > $limit) {
                    $signals[] = risk_signal('VALUE_OUTLIER', [
                        'order_total'    => round($total, 2),
                        'buyer_median'   => round($median, 2),
                        'mad'            => $mad === null ? null : round($mad, 2),
                        'threshold'      => round($limit, 2),
                        'prior_orders'   => $priorOrders,
                        'method'         => ($mad !== null && $mad > 0.01)
                            ? 'median + 3 x MAD'
                            : 'median x 3 (MAD was zero)',
                    ]);
                }
            }
        }
    }

    $qty = $db->prepare(
        'SELECT oi.product_id, p.title, oi.quantity,
                (SELECT COUNT(*) FROM order_items oi2
                   JOIN sub_orders so2 ON so2.id = oi2.sub_order_id
                   JOIN orders o2 ON o2.id = so2.order_id
                  WHERE oi2.product_id = oi.product_id AND o2.created_at < ?) AS history_count,
                (SELECT MAX(oi3.quantity) FROM order_items oi3
                   JOIN sub_orders so3 ON so3.id = oi3.sub_order_id
                   JOIN orders o3 ON o3.id = so3.order_id
                  WHERE oi3.product_id = oi.product_id AND o3.created_at < ?) AS history_max
           FROM order_items oi
           JOIN sub_orders so ON so.id = oi.sub_order_id
           JOIN products p ON p.id = oi.product_id
          WHERE so.order_id = ?
       ORDER BY oi.quantity DESC'
    );
    $qty->execute([$placedAt, $placedAt, $orderId]);
    $lines = $qty->fetchAll();

    foreach ($lines as $line) {
        $historyCount = (int) $line['history_count'];
        $quantity     = (int) $line['quantity'];

        if ($historyCount < 10) {
            continue;                       // GUARD: not enough history to call it unusual
        }

        $past = $db->prepare(
            'SELECT oi.quantity FROM order_items oi
               JOIN sub_orders so ON so.id = oi.sub_order_id
               JOIN orders o ON o.id = so.order_id
              WHERE oi.product_id = ? AND o.created_at < ?'
        );
        $past->execute([(int) $line['product_id'], $placedAt]);
        $quantities = array_map('intval', $past->fetchAll(PDO::FETCH_COLUMN));
        sort($quantities);

        $p95 = $quantities[(int) floor(0.95 * (count($quantities) - 1))] ?? 1;
        $typicalMax = (int) ($line['history_max'] ?? 1);

        if ($quantity > $p95 && $quantity > 2 || ($typicalMax <= 2 && $quantity > 5)) {
            $signals[] = risk_signal('QTY_OUTLIER', [
                'product'         => $line['title'],
                'quantity'        => $quantity,
                'usual_95th'      => $p95,
                'highest_ever'    => $typicalMax,
                'past_sales_seen' => $historyCount,
            ]);
            break;                          // one is enough; the worst line fired
        }
    }

    if ($userId !== null && $ageHours !== null && $ageHours < 24 * 7) {
        $combo = $db->prepare(
            'SELECT p.category, COUNT(*) AS line_count, SUM(oi.quantity) AS units,
                    SUM(oi.quantity * oi.unit_price) AS category_value
               FROM order_items oi
               JOIN sub_orders so ON so.id = oi.sub_order_id
               JOIN products p ON p.id = oi.product_id
              WHERE so.order_id = ?
           GROUP BY p.category
           ORDER BY category_value DESC
              LIMIT 1'
        );
        $combo->execute([$orderId]);
        $top = $combo->fetch();

        $priorCount = $db->prepare('SELECT COUNT(*) FROM orders WHERE user_id = ? AND id <> ? AND created_at <= ?');
        $priorCount->execute([$userId, $orderId, $placedAt]);
        $priorOrdersForCombo = (int) $priorCount->fetchColumn();

        if ($top && $priorOrdersForCombo < 3 && (int) $top['units'] >= 3) {
            $medStmt = $db->prepare(
                'SELECT MEDIAN(total_amount) OVER () AS m, COUNT(*) OVER () AS n
                   FROM orders WHERE created_at <= ? LIMIT 1'
            );
            $medStmt->execute([$placedAt]);
            $mm = $medStmt->fetch() ?: [];

            $platformMedian = (float) ($mm['m'] ?? 0);
            $population     = (int) ($mm['n'] ?? 0);

            if ($population >= 20 && $platformMedian > 0
                && (float) $top['category_value'] >= 2 * $platformMedian) {
                $signals[] = risk_signal('HIGH_VALUE_ELECTRONICS_COMBO', [
                    'category'          => $top['category'],
                    'units'             => (int) $top['units'],
                    'category_value'    => round((float) $top['category_value'], 2),
                    'platform_median'   => round($platformMedian, 2),
                    'account_age_hours' => $ageHours,
                    'buyer_prior_orders' => $priorOrdersForCombo,
                ]);
            }
        }
    }

    $addressKey = risk_normalise_address($order['shipping_address']);
    $phoneKey   = risk_normalise_phone($order['shipping_phone']);

    if ($addressKey !== '' || $phoneKey !== '') {
        $candidates = $db->prepare(
            'SELECT DISTINCT o.user_id, o.shipping_address, o.shipping_phone
               FROM orders o
              WHERE o.user_id IS NOT NULL
                AND o.created_at <= ?
                AND (o.shipping_city = ? OR o.shipping_phone = ?)'
        );
        $candidates->execute([$placedAt, $order['shipping_city'], $order['shipping_phone']]);

        $byAddress = [];
        $byPhone   = [];
        foreach ($candidates as $row) {
            $uid = (int) $row['user_id'];
            if (risk_normalise_address($row['shipping_address']) === $addressKey && $addressKey !== '') {
                $byAddress[$uid] = true;
            }
            if (risk_normalise_phone($row['shipping_phone']) === $phoneKey && $phoneKey !== '') {
                $byPhone[$uid] = true;
            }
        }

        $addressAccounts = count($byAddress);
        $phoneAccounts   = count($byPhone);

        if ($addressAccounts >= 3 || $phoneAccounts >= 3) {
            $signals[] = risk_signal('SHARED_ADDRESS_MANY_ACCOUNTS', [
                'accounts_at_address' => $addressAccounts,
                'accounts_on_phone'   => $phoneAccounts,
                'matched_on'          => $addressAccounts >= 3 ? 'shipping address' : 'phone number',
                'normalised_address'  => $addressKey,
            ]);
        }
    }

    $sellers = $db->prepare(
        'SELECT DISTINCT s.id, s.business_name, s.city,
                su.phone AS seller_phone, su.email AS seller_email
           FROM sub_orders so
           JOIN sellers s ON s.id = so.seller_id
           JOIN users su ON su.id = s.id
          WHERE so.order_id = ?'
    );
    $sellers->execute([$orderId]);

    foreach ($sellers as $seller) {
        $sellerPhone = risk_normalise_phone($seller['seller_phone']);
        $phoneMatch  = $sellerPhone !== '' && $sellerPhone === $phoneKey;

        if ($phoneMatch) {
            $signals[] = risk_signal('BUYER_MATCHES_SELLER', [
                'seller'     => $seller['business_name'],
                'matched_on' => 'phone number',
                'phone'      => $order['shipping_phone'],
            ]);
            break;
        }
    }

    $orderSellers = $db->prepare('SELECT DISTINCT seller_id FROM sub_orders WHERE order_id = ?');
    $orderSellers->execute([$orderId]);
    $sellerIds = array_map('intval', $orderSellers->fetchAll(PDO::FETCH_COLUMN));

    foreach ($sellerIds as $sellerId) {
        foreach (risk_seller_signals($db, $sellerId, $placedAt) as $signal) {
            $signals[] = $signal;
        }
    }

    // Heaviest first: the UI shows "top signal" in the table, and that
    // should be the one a reviewer most wants to see.
    usort($signals, static fn(array $a, array $b): int => $b['weight'] <=> $a['weight']);

    // De-duplicate by code — a two-seller order can raise the same seller
    // signal twice, which should not count twice.
    $seen = [];
    $unique = [];
    foreach ($signals as $signal) {
        if (isset($seen[$signal['code']])) {
            continue;
        }
        $seen[$signal['code']] = true;
        $unique[] = $signal;
    }
    $signals = $unique;

    $ruleScore = min(100, (int) array_sum(array_column($signals, 'weight')));

    return [
        'score'      => $ruleScore,
        'rule_score' => $ruleScore,
        'level'      => risk_level_for($ruleScore),
        'signals'    => $signals,
        'order'      => [
            'id'                => (int) $order['id'],
            'user_id'           => $userId,
            'buyer_name'        => $order['user_name'],
            'total'             => round($total, 2),
            'currency'          => $order['currency'],
            'placed_at'         => $placedAt,
            'account_age_hours' => $ageHours,
            'seller_count'      => (int) $order['seller_count'],
            'item_count'        => (int) $order['item_count'],
            'seller_ids'        => $sellerIds,
        ],
    ];
}

function risk_seller_signals(PDO $db, int $sellerId, string $asOf): array
{
    $signals = [];

    $spike = $db->prepare(
        'SELECT
            SUM(o.created_at >= DATE(?)) AS today,
            SUM(o.created_at >= DATE(?) - INTERVAL 14 DAY AND o.created_at < DATE(?)) AS baseline
           FROM sub_orders so
           JOIN orders o ON o.id = so.order_id
          WHERE so.seller_id = ? AND o.created_at <= ?'
    );
    $spike->execute([$asOf, $asOf, $asOf, $sellerId, $asOf]);
    $s = $spike->fetch() ?: [];

    $today    = (int) ($s['today'] ?? 0);
    $baseline = (int) ($s['baseline'] ?? 0);

    if ($baseline >= 14 && $today >= 4) {
        $dailyAverage = $baseline / 14;
        if ($dailyAverage > 0 && $today >= 4 * $dailyAverage) {
            $signals[] = risk_signal('SELLER_ORDER_SPIKE', [
                'orders_today'       => $today,
                'daily_average_14d'  => round($dailyAverage, 2),
                'times_average'      => round($today / $dailyAverage, 1),
                'baseline_orders'    => $baseline,
            ]);
        }
    }

    $buyers = $db->prepare(
        'SELECT o.user_id, COUNT(*) AS n
           FROM sub_orders so
           JOIN orders o ON o.id = so.order_id
          WHERE so.seller_id = ?
            AND o.created_at BETWEEN ? - INTERVAL 30 DAY AND ?
            AND o.user_id IS NOT NULL
       GROUP BY o.user_id
       ORDER BY n DESC'
    );
    $buyers->execute([$sellerId, $asOf, $asOf]);
    $buyerCounts = $buyers->fetchAll();

    $totalOrders = (int) array_sum(array_column($buyerCounts, 'n'));

    if ($totalOrders >= 8 && count($buyerCounts) > 0) {
        $topTwo = array_sum(array_column(array_slice($buyerCounts, 0, 2), 'n'));
        $share  = $topTwo / $totalOrders;

        // 80% of a month's orders from at most two accounts.
        if ($share >= 0.80 && count($buyerCounts) <= 3) {
            $signals[] = risk_signal('SELLER_FEW_BUYERS', [
                'orders_30d'        => $totalOrders,
                'distinct_buyers'   => count($buyerCounts),
                'top_two_share_pct' => round($share * 100, 1),
            ]);
        }
    }

    $disp = $db->prepare(
        'SELECT COUNT(*) AS orders,
                SUM(so.status = \'disputed\' OR so.refunded_at IS NOT NULL) AS bad
           FROM sub_orders so
           JOIN orders o ON o.id = so.order_id
          WHERE so.seller_id = ?
            AND o.created_at BETWEEN ? - INTERVAL 30 DAY AND ?'
    );
    $disp->execute([$sellerId, $asOf, $asOf]);
    $d = $disp->fetch() ?: [];

    $dispOrders = (int) ($d['orders'] ?? 0);
    $dispBad    = (int) ($d['bad'] ?? 0);

    if ($dispOrders >= 8) {
        $rate = $dispBad / $dispOrders;
        if ($rate > 0.25) {
            $signals[] = risk_signal('SELLER_DISPUTE_RATE', [
                'orders_30d'        => $dispOrders,
                'disputed_refunded' => $dispBad,
                'rate_percent'      => round($rate * 100, 1),
                'threshold_percent' => 25,
            ]);
        }
    }

    $burst = $db->prepare(
        'SELECT p.id, p.title,
                COUNT(*) AS new_account_five_stars,
                (SELECT COUNT(*) FROM reviews r2 WHERE r2.product_id = p.id) AS total_reviews
           FROM reviews r
           JOIN products p ON p.id = r.product_id
           JOIN users ru ON ru.id = r.user_id
          WHERE p.seller_id = ?
            AND r.rating = 5
            AND r.created_at BETWEEN ? - INTERVAL 7 DAY AND ?
            AND TIMESTAMPDIFF(DAY, ru.created_at, r.created_at) < 7
       GROUP BY p.id, p.title
       ORDER BY new_account_five_stars DESC
          LIMIT 1'
    );
    $burst->execute([$sellerId, $asOf, $asOf]);
    $b = $burst->fetch();

    if ($b && (int) $b['new_account_five_stars'] >= 4) {
        $signals[] = risk_signal('REVIEW_BURST_NEW_ACCOUNTS', [
            'product'            => $b['title'],
            'five_star_reviews'  => (int) $b['new_account_five_stars'],
            'from_accounts_under' => '7 days old',
            'window'             => '7 days',
            'product_total_reviews' => (int) $b['total_reviews'],
        ]);
    }

    return $signals;
}

function score_seller(PDO $db, int $sellerId, ?string $asOf = null): array
{
    $asOf ??= risk_now($db);          // the database's clock, not PHP's

    $exists = $db->prepare('SELECT s.id, s.business_name FROM sellers s WHERE s.id = ?');
    $exists->execute([$sellerId]);
    $seller = $exists->fetch();

    if (!$seller) {
        return ['score' => 0, 'rule_score' => 0, 'level' => 'unscored', 'signals' => [],
                'reason' => 'Seller ' . $sellerId . ' does not exist'];
    }

    $signals   = risk_seller_signals($db, $sellerId, $asOf);
    $ruleScore = min(100, (int) array_sum(array_column($signals, 'weight')));

    return [
        'score'      => $ruleScore,
        'rule_score' => $ruleScore,
        'level'      => risk_level_for($ruleScore),
        'signals'    => $signals,
        'seller'     => ['id' => $sellerId, 'name' => $seller['business_name']],
    ];
}

function score_user(PDO $db, int $userId, ?string $asOf = null): array
{
    $asOf ??= risk_now($db);          // the database's clock, not PHP's

    $exists = $db->prepare(
        'SELECT id, name, email, created_at,
                TIMESTAMPDIFF(HOUR, created_at, ?) AS account_age_hours
           FROM users WHERE id = ?'
    );
    $exists->execute([$asOf, $userId]);
    $user = $exists->fetch();

    if (!$user) {
        return ['score' => 0, 'rule_score' => 0, 'level' => 'unscored', 'signals' => [],
                'reason' => 'User ' . $userId . ' does not exist'];
    }

    $signals = [];

    $vel = $db->prepare(
        'SELECT COUNT(*) AS n,
                TIMESTAMPDIFF(MINUTE, MIN(created_at), MAX(created_at)) AS span
           FROM orders
          WHERE user_id = ? AND created_at BETWEEN ? - INTERVAL 24 HOUR AND ?'
    );
    $vel->execute([$userId, $asOf, $asOf]);
    $v = $vel->fetch() ?: [];

    if ((int) ($v['n'] ?? 0) >= 6) {
        $signals[] = risk_signal('VELOCITY_USER', [
            'orders_last_24h' => (int) $v['n'],
            'window_minutes'  => (int) ($v['span'] ?? 0),
            'threshold'       => '6 or more within 24 hours',
        ]);
    }

    $hist = $db->prepare(
        'SELECT COUNT(DISTINCT o.id) AS orders,
                COUNT(DISTINCT CASE WHEN so.status IN (\'cancelled\',\'refunded\')
                                     OR so.refunded_at IS NOT NULL
                                    THEN o.id END) AS bad
           FROM orders o
           JOIN sub_orders so ON so.order_id = o.id
          WHERE o.user_id = ? AND o.created_at <= ?'
    );
    $hist->execute([$userId, $asOf]);
    $h = $hist->fetch() ?: [];

    $orders = (int) ($h['orders'] ?? 0);
    $bad    = (int) ($h['bad'] ?? 0);

    if ($orders >= 5 && ($bad / $orders) > 0.40) {
        $signals[] = risk_signal('HIGH_CANCEL_REFUND_RATE', [
            'orders'                => $orders,
            'cancelled_or_refunded' => $bad,
            'rate_percent'          => round(($bad / $orders) * 100, 1),
            'threshold_percent'     => 40,
        ]);
    }

    $failed = $db->prepare(
        'SELECT COUNT(*) FROM payments p
           JOIN orders o ON o.id = p.order_id
          WHERE o.user_id = ? AND p.status = \'failed\'
            AND o.created_at BETWEEN ? - INTERVAL 24 HOUR AND ?'
    );
    $failed->execute([$userId, $asOf, $asOf]);
    $failCount = (int) $failed->fetchColumn();

    if ($failCount >= 2) {
        $signals[] = risk_signal('FAILED_PAYMENTS_BEFORE_SUCCESS', [
            'failed_payments' => $failCount,
            'window'          => 'the last 24 hours',
        ]);
    }

    $recent = $db->prepare(
        'SELECT shipping_address, shipping_phone, shipping_city
           FROM orders WHERE user_id = ? AND created_at <= ?
       ORDER BY created_at DESC LIMIT 1'
    );
    $recent->execute([$userId, $asOf]);
    $last = $recent->fetch();

    if ($last) {
        $addressKey = risk_normalise_address($last['shipping_address']);

        if ($addressKey !== '') {
            $others = $db->prepare(
                'SELECT DISTINCT user_id, shipping_address FROM orders
                  WHERE user_id IS NOT NULL AND created_at <= ? AND shipping_city = ?'
            );
            $others->execute([$asOf, $last['shipping_city']]);

            $accounts = [];
            foreach ($others as $row) {
                if (risk_normalise_address($row['shipping_address']) === $addressKey) {
                    $accounts[(int) $row['user_id']] = true;
                }
            }

            if (count($accounts) >= 3) {
                $signals[] = risk_signal('SHARED_ADDRESS_MANY_ACCOUNTS', [
                    'accounts_at_address' => count($accounts),
                    'matched_on'          => 'shipping address',
                    'normalised_address'  => $addressKey,
                ]);
            }
        }
    }

    usort($signals, static fn(array $a, array $b): int => $b['weight'] <=> $a['weight']);
    $ruleScore = min(100, (int) array_sum(array_column($signals, 'weight')));

    return [
        'score'      => $ruleScore,
        'rule_score' => $ruleScore,
        'level'      => risk_level_for($ruleScore),
        'signals'    => $signals,
        'user'       => [
            'id'                => $userId,
            'name'              => $user['name'],
            'account_age_hours' => (int) $user['account_age_hours'],
            'orders'            => $orders,
        ],
    ];
}

function risk_template_explanation(array $result): string
{
    $signals = $result['signals'] ?? [];

    if (!$signals) {
        return 'Nothing unusual was found in this order.';
    }

    /** One clause per signal, using the real numbers from its evidence. */
    $clause = static function (array $s): string {
        $e = $s['evidence'];

        switch ($s['code']) {
            case 'VELOCITY_USER':
                $n = $e['orders_last_hour'] ?? $e['orders_last_24h'] ?? 0;
                $w = !empty($e['window_minutes'])
                    ? sprintf('%d minutes', $e['window_minutes'])
                    : '24 hours';
                return sprintf('%d orders from this buyer within %s', $n, $w);

            case 'NEW_ACCOUNT_HIGH_VALUE':
                return sprintf(
                    'an account %d hours old spending %s times the typical order value',
                    $e['account_age_hours'] ?? 0,
                    $e['times_median'] ?? '?'
                );

            case 'FAILED_PAYMENTS_BEFORE_SUCCESS':
                return sprintf('%d failed payments shortly before this one', $e['failed_payments'] ?? 0);

            case 'HIGH_CANCEL_REFUND_RATE':
                return sprintf(
                    '%s%% of this buyer\'s %d orders were cancelled or refunded',
                    $e['rate_percent'] ?? '?',
                    $e['prior_orders'] ?? $e['orders'] ?? 0
                );

            case 'QTY_OUTLIER':
                return sprintf(
                    'a quantity of %d for %s, where %d is the usual maximum',
                    $e['quantity'] ?? 0,
                    $e['product'] ?? 'an item',
                    $e['usual_95th'] ?? 1
                );

            case 'VALUE_OUTLIER':
                return sprintf(
                    'a total of %s against this buyer\'s usual %s',
                    number_format((float) ($e['order_total'] ?? 0)),
                    number_format((float) ($e['buyer_median'] ?? 0))
                );

            case 'HIGH_VALUE_ELECTRONICS_COMBO':
                return sprintf(
                    '%d %s items in one order from a new account',
                    $e['units'] ?? 0,
                    $e['category'] ?? 'high-value'
                );

            case 'SHARED_ADDRESS_MANY_ACCOUNTS':
                return sprintf(
                    '%d different accounts using the same %s',
                    max((int) ($e['accounts_at_address'] ?? 0), (int) ($e['accounts_on_phone'] ?? 0)),
                    $e['matched_on'] ?? 'details'
                );

            case 'BUYER_MATCHES_SELLER':
                return sprintf(
                    'the buyer\'s %s matching the seller\'s own',
                    $e['matched_on'] ?? 'contact details'
                );

            case 'SELLER_ORDER_SPIKE':
                return sprintf(
                    'the seller receiving %d orders today against a %s daily average',
                    $e['orders_today'] ?? 0,
                    $e['daily_average_14d'] ?? '?'
                );

            case 'SELLER_FEW_BUYERS':
                return sprintf(
                    '%s%% of the seller\'s recent orders coming from %d buyers',
                    $e['top_two_share_pct'] ?? '?',
                    $e['distinct_buyers'] ?? 0
                );

            case 'SELLER_DISPUTE_RATE':
                return sprintf(
                    'a %s%% dispute rate across the seller\'s last %d orders',
                    $e['rate_percent'] ?? '?',
                    $e['orders_30d'] ?? 0
                );

            case 'REVIEW_BURST_NEW_ACCOUNTS':
                return sprintf(
                    '%d five-star reviews for %s from accounts under a week old',
                    $e['five_star_reviews'] ?? 0,
                    $e['product'] ?? 'one product'
                );

            default:
                return mb_strtolower($s['label']);
        }
    };

    // At most three clauses: a reviewer reads the top reasons, and a
    // sentence listing eight is a sentence nobody finishes.
    $top = array_slice($signals, 0, 3);
    $clauses = array_map($clause, $top);

    $joined = count($clauses) === 1
        ? $clauses[0]
        : implode(', ', array_slice($clauses, 0, -1)) . ' and ' . end($clauses);

    $sentence = 'This order shows unusual patterns: ' . $joined . '.';

    $remaining = count($signals) - count($top);
    if ($remaining > 0) {
        $sentence .= sprintf(' %d further signal%s also fired.', $remaining, $remaining === 1 ? '' : 's');
    }

    return $sentence;
}

function risk_evidence_numbers(array $result): array
{
    $numbers = [];

    $walk = static function ($value) use (&$walk, &$numbers): void {
        if (is_array($value)) {
            foreach ($value as $v) {
                $walk($v);
            }
            return;
        }
        if (is_int($value) || is_float($value)) {
            $numbers[] = (string) $value;
            // Also the rounded and thousands-separated forms, because that
            // is how a sentence would naturally write them.
            $numbers[] = (string) (int) round((float) $value);
            $numbers[] = number_format((float) $value);
            $numbers[] = number_format((float) $value, 0, '.', '');
            return;
        }
        if (is_string($value)) {
            // Numbers embedded in an evidence string, e.g. "7 days old".
            if (preg_match_all('/\d+(?:\.\d+)?/', $value, $m)) {
                foreach ($m[0] as $n) {
                    $numbers[] = $n;
                }
            }
        }
    };

    foreach ($result['signals'] ?? [] as $signal) {
        $walk($signal['evidence'] ?? []);
        $numbers[] = (string) $signal['weight'];
    }

    // The score and the level boundaries are fair game too.
    $numbers[] = (string) ($result['score'] ?? 0);
    $numbers[] = (string) ($result['rule_score'] ?? 0);
    foreach (risk_config() as $value) {
        if (is_numeric($value)) {
            $numbers[] = (string) $value;
            $numbers[] = (string) (int) $value;
        }
    }

    return array_values(array_unique(array_filter($numbers, static fn(string $s): bool => $s !== '')));
}

function risk_explanation_problem(string $text, array $result): ?string
{
    $trimmed = trim($text);

    if ($trimmed === '') {
        return 'empty';
    }
    if (mb_strlen($trimmed) > 400) {
        return 'too long (' . mb_strlen($trimmed) . ' characters)';
    }

    $forbidden = [
        'fraud', 'fraudulent', 'criminal', 'crime', 'illegal', 'stolen',
        'thief', 'scam', 'scammer', 'launder', 'prosecute', 'lawsuit',
        'guilty', 'liable', 'sue ',
    ];
    foreach ($forbidden as $word) {
        if (str_contains(mb_strtolower($trimmed), $word)) {
            return 'uses the word "' . trim($word) . '"';
        }
    }

    $allowed = risk_evidence_numbers($result);

    if (preg_match_all('/\d+(?:[.,]\d+)?/', $trimmed, $m)) {
        foreach ($m[0] as $found) {
            $plain = str_replace(',', '', $found);

            if (in_array($found, $allowed, true) || in_array($plain, $allowed, true)) {
                continue;
            }
            // A bare 1 or 2 ("one of the items") is wording, not a claim.
            if (in_array($plain, ['0', '1', '2'], true)) {
                continue;
            }
            return 'mentions ' . $found . ', which is not in the evidence';
        }
    }

    return null;
}
