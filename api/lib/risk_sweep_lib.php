<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli' && basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit("This is a library, not an endpoint.\n");
}

require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/risk_engine.php';
require_once __DIR__ . '/risk_ml.php';
require_once __DIR__ . '/risk_persist.php';

const SWEEP_DEFAULT_HOURS = 48;

/** Stop before a runaway. A window this large means something is wrong. */
const SWEEP_MAX_ORDERS = 2000;

function risk_sweep_run(array $options = []): array
{
    $hours   = max(1, (int) ($options['hours'] ?? SWEEP_DEFAULT_HOURS));
    $doOrders  = $options['orders']  ?? true;
    $doSellers = $options['sellers'] ?? true;
    $doBuyers  = $options['buyers']  ?? true;
    $force   = (bool) ($options['force'] ?? false);
    $dryRun  = (bool) ($options['dry_run'] ?? false);
    $limit   = min(SWEEP_MAX_ORDERS, max(1, (int) ($options['limit'] ?? SWEEP_MAX_ORDERS)));

    $db = db();
    $started = microtime(true);

    $stats = [
        'window_hours'    => $hours,
        'orders_examined' => 0,
        'orders_scored'   => 0,
        'orders_skipped'  => 0,
        'orders_failed'   => 0,
        'by_level'        => ['low' => 0, 'medium' => 0, 'high' => 0, 'unscored' => 0],
        'alerts_raised'   => 0,
        'payouts_held'    => 0,
        'sellers_checked' => 0,
        'buyers_checked'  => 0,
        'ml_used'         => 0,
        'ml_missing'      => 0,
        'dry_run'         => $dryRun,
        'errors'          => [],
    ];

    $asOf = risk_now($db);

    if ($doOrders) {
        $stmt = $db->prepare(
            'SELECT DISTINCT o.id
               FROM orders o
               JOIN sub_orders so ON so.order_id = o.id
              WHERE o.created_at >= ? - INTERVAL ? HOUR
                AND so.payout_released_at IS NULL
           ORDER BY o.id DESC
              LIMIT ' . (int) $limit
        );
        $stmt->execute([$asOf, $hours]);
        $orderIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $stats['orders_examined'] = count($orderIds);

        foreach ($orderIds as $id) {
            $id = (int) $id;

            if ($dryRun) {
                continue;
            }

            $result = risk_score_order($id, 'sweep', $force);

            if (!$result['ok']) {
                $stats['orders_failed']++;
                if (count($stats['errors']) < 10) {
                    $stats['errors'][] = "order $id: " . (string) $result['reason'];
                }
                continue;
            }

            if ($result['score_id'] === null) {
                // Already scored and not forced — the normal case on a
                // 15-minute schedule over a 48-hour window.
                $stats['orders_skipped']++;
                continue;
            }

            $stats['orders_scored']++;
            $stats['by_level'][$result['level']] = ($stats['by_level'][$result['level']] ?? 0) + 1;

            if ($result['alert_id'] !== null) {
                $stats['alerts_raised']++;
            }
            $stats['payouts_held'] += $result['held'];

            if ($result['ml_score'] === null) {
                $stats['ml_missing']++;
            } else {
                $stats['ml_used']++;
            }
        }
    }

    if ($doSellers) {
        $stmt = $db->prepare(
            'SELECT DISTINCT so.seller_id
               FROM sub_orders so
               JOIN orders o ON o.id = so.order_id
              WHERE o.created_at >= ? - INTERVAL ? HOUR'
        );
        $stmt->execute([$asOf, $hours]);
        $sellerIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

        foreach ($sellerIds as $sellerId) {
            $stats['sellers_checked']++;

            if ($dryRun) {
                continue;
            }

            $result = risk_score_subject('seller', (int) $sellerId, $asOf);

            if (!$result['ok']) {
                if (count($stats['errors']) < 10) {
                    $stats['errors'][] = "seller $sellerId: " . (string) $result['reason'];
                }
                continue;
            }
            if ($result['alert_id'] !== null) {
                $stats['alerts_raised']++;
            }
        }
    }

    if ($doBuyers) {
        $stmt = $db->prepare(
            'SELECT DISTINCT o.user_id
               FROM orders o
              WHERE o.created_at >= ? - INTERVAL ? HOUR
                AND o.user_id IS NOT NULL'
        );
        $stmt->execute([$asOf, $hours]);
        $userIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

        foreach ($userIds as $userId) {
            $stats['buyers_checked']++;

            if ($dryRun) {
                continue;
            }

            $result = risk_score_subject('buyer', (int) $userId, $asOf);

            if (!$result['ok']) {
                if (count($stats['errors']) < 10) {
                    $stats['errors'][] = "buyer $userId: " . (string) $result['reason'];
                }
                continue;
            }
            if ($result['alert_id'] !== null) {
                $stats['alerts_raised']++;
            }
        }
    }

    $stats['seconds'] = round(microtime(true) - $started, 2);
    $stats['as_of']   = $asOf;

    return $stats;
}
