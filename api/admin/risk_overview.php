<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/_admin.php';
require_once __DIR__ . '/../lib/risk_engine.php';
require_once __DIR__ . '/../lib/risk_persist.php';

try {
    require_method('GET');
    require_admin();

    $db = db();

    $openAlerts = (int) $db->query(
        'SELECT COUNT(*) FROM risk_alerts WHERE status IN (\'open\', \'under_review\')'
    )->fetchColumn();

    $highRisk = (int) $db->query(
        'SELECT COUNT(*) FROM risk_alerts
          WHERE level = \'high\' AND status IN (\'open\', \'under_review\')'
    )->fetchColumn();

    $held = $db->query(
        'SELECT COALESCE(SUM(so.subtotal), 0) AS amount, COUNT(*) AS n
           FROM sub_orders so
          WHERE so.payout_hold = 1'
    )->fetch();

    $decided = $db->query(
        'SELECT
            SUM(status = \'dismissed\')       AS dismissed,
            SUM(status = \'confirmed_fraud\') AS confirmed,
            COUNT(*)                          AS total
           FROM risk_alerts
          WHERE status IN (\'dismissed\', \'confirmed_fraud\')'
    )->fetch();

    $decidedTotal = (int) ($decided['total'] ?? 0);

    $unscored = (int) $db->query(
        'SELECT COUNT(DISTINCT order_id) FROM order_risk_scores WHERE level = \'unscored\''
    )->fetchColumn();

    $rows = $db->query(
        'SELECT DATE(created_at) AS d,
                COUNT(*)                AS alerts,
                SUM(level = \'high\')   AS high
           FROM risk_alerts
          WHERE created_at >= CURDATE() - INTERVAL 29 DAY
       GROUP BY DATE(created_at)
       ORDER BY d'
    )->fetchAll();

    $byDate = [];
    foreach ($rows as $row) {
        $byDate[(string) $row['d']] = [
            'alerts' => (int) $row['alerts'],
            'high'   => (int) $row['high'],
        ];
    }

    $series = [];
    $today = new DateTimeImmutable(substr(risk_now($db), 0, 10));
    for ($i = 29; $i >= 0; $i--) {
        $day = $today->sub(new DateInterval("P{$i}D"))->format('Y-m-d');
        $series[] = [
            'date'   => $day,
            'alerts' => $byDate[$day]['alerts'] ?? 0,
            'high'   => $byDate[$day]['high'] ?? 0,
        ];
    }

    $scoreRows = $db->query(
        'SELECT signals FROM order_risk_scores
          WHERE created_at >= CURDATE() - INTERVAL 29 DAY
            AND level IN (\'medium\', \'high\')'
    )->fetchAll(PDO::FETCH_COLUMN);

    $counts = [];
    foreach ($scoreRows as $json) {
        $decodedSignals = json_decode((string) $json, true);
        if (!is_array($decodedSignals)) {
            continue;
        }
        foreach ($decodedSignals as $signal) {
            $code = (string) ($signal['code'] ?? '');
            if ($code !== '') {
                $counts[$code] = ($counts[$code] ?? 0) + 1;
            }
        }
    }
    arsort($counts);

    $signals = [];
    foreach ($counts as $code => $count) {
        $meta = RISK_SIGNALS[$code] ?? null;
        $signals[] = [
            'code'     => $code,
            'label'    => $meta['label'] ?? $code,
            'category' => $meta['category'] ?? 'other',
            'weight'   => $meta['weight'] ?? null,
            'count'    => $count,
        ];
    }

    $model = null;
    $modelUrl = (string) config('risk_ml_url', 'http://localhost:8001/risk/score');
    $modelUrl = str_replace('/risk/score', '/risk/model', $modelUrl);

    if (function_exists('curl_init')) {
        $ch = curl_init($modelUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_TIMEOUT        => 4,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw !== false && $status >= 200 && $status < 300) {
            $decodedModel = json_decode((string) $raw, true);
            if (is_array($decodedModel)) {
                $model = [
                    'loaded'      => (bool) ($decodedModel['loaded'] ?? false),
                    'version'     => $decodedModel['model_version'] ?? null,
                    'trained_at'  => $decodedModel['trained_at'] ?? null,
                    'rows'        => $decodedModel['rows_trained'] ?? null,
                    'reason'      => $decodedModel['reason'] ?? null,
                    // Said on the page itself, not only in a README.
                    'data_note'   => $decodedModel['data_note'] ?? null,
                ];
            }
        }
    }

    $config = risk_config();

    json_ok([
        'kpis' => [
            'open_alerts'     => $openAlerts,
            'high_risk'       => $highRisk,
            'held_amount'     => round((float) ($held['amount'] ?? 0), 2),
            'held_sub_orders' => (int) ($held['n'] ?? 0),
            'decided'         => $decidedTotal,
            'confirmed'       => (int) ($decided['confirmed'] ?? 0),
            'dismissed'       => (int) ($decided['dismissed'] ?? 0),
            // null, not 0, when nothing has been decided — "0% dismissed"
            // would read as a perfect detector rather than as no data.
            'dismissed_rate'  => $decidedTotal > 0
                ? round((int) $decided['dismissed'] / $decidedTotal, 3)
                : null,
            'unscored'        => $unscored,
        ],
        'series'  => $series,
        'signals' => $signals,
        'model'   => $model,
        'config'  => [
            'medium_at'     => $config['medium_at'],
            'high_at'       => $config['high_at'],
            'rule_weight'   => $config['rule_weight'],
            'ml_weight'     => $config['ml_weight'],
            'hold_at_level' => $config['hold_at_level'],
            'holds_enabled' => $config['holds_enabled'],
            'llm_explain'   => $config['llm_explain'],
        ],
        'demo_data' => true,
    ]);
} catch (Throwable $e) {
    app_log('admin/risk_overview.php failed: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    json_fail('Could not load the risk overview right now.', 'SERVER', 500);
}
