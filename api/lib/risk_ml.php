<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli' && basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit("This is a library, not an endpoint.\n");
}

require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/risk_engine.php';

const RISK_FEATURE_COLUMNS = [
    'order_value',
    'item_count',
    'max_qty',
    'account_age_hours',
    'orders_24h',
    'past_cancel_rate',
    'address_accounts',
    'hour_of_day',
    'value_vs_buyer_median',
];

function risk_feature_sql(bool $single): string
{
    $sql = <<<'SQL'
SELECT
    o.id AS order_id,

    /* 1. what the order was worth */
    o.total_amount AS order_value,

    /* 2. how many units in it */
    COALESCE((
        SELECT SUM(oi.quantity) FROM order_items oi
          JOIN sub_orders so ON so.id = oi.sub_order_id
         WHERE so.order_id = o.id
    ), 0) AS item_count,

    /* 3. the largest single-line quantity — bulk buying shows up here
          where item_count would hide it across many lines */
    COALESCE((
        SELECT MAX(oi.quantity) FROM order_items oi
          JOIN sub_orders so ON so.id = oi.sub_order_id
         WHERE so.order_id = o.id
    ), 0) AS max_qty,

    /* 4. how old the account was AT THE TIME, in hours */
    COALESCE(TIMESTAMPDIFF(HOUR, u.created_at, o.created_at), 0) AS account_age_hours,

    /* 5. the buyer's orders in the preceding 24 hours (this one excluded) */
    COALESCE((
        SELECT COUNT(*) FROM orders o2
         WHERE o2.user_id = o.user_id
           AND o2.id <> o.id
           AND o2.created_at BETWEEN o.created_at - INTERVAL 24 HOUR AND o.created_at
    ), 0) AS orders_24h,

    /* 6. the share of the buyer's PREVIOUS orders that were cancelled or
          refunded, 0 when they had none */
    COALESCE((
        SELECT COUNT(DISTINCT CASE WHEN so3.status IN ('cancelled','refunded')
                                    OR so3.refunded_at IS NOT NULL
                                   THEN o3.id END)
               / NULLIF(COUNT(DISTINCT o3.id), 0)
          FROM orders o3
          JOIN sub_orders so3 ON so3.order_id = o3.id
         WHERE o3.user_id = o.user_id
           AND o3.id <> o.id
           AND o3.created_at < o.created_at
    ), 0) AS past_cancel_rate,

    /* 7. how many DISTINCT accounts had used this shipping address by then.
          Exact string match here, deliberately: the fuzzy normalisation in
          the rule engine lives in PHP (risk_normalise_address) and cannot
          be expressed in one SQL expression. The model gets a weaker
          version of the same idea, which is honest — it is a feature, not
          the rule, and the evaluation in train_anomaly.py shows it as the
          weak one it is. */
    COALESCE((
        SELECT COUNT(DISTINCT o4.user_id) FROM orders o4
         WHERE o4.shipping_address = o.shipping_address
           AND o4.created_at <= o.created_at
    ), 1) AS address_accounts,

    /* 8. hour of day, 0-23 */
    HOUR(o.created_at) AS hour_of_day,

    /* 9. the order against the buyer's own median, as a ratio. 1.0 when
          they have no history — "unremarkable for them" is the right
          default for a first order, rather than 0 which would read as
          anomalously small. */
    COALESCE((
        SELECT o.total_amount / NULLIF(MEDIAN(o5.total_amount) OVER (), 0)
          FROM orders o5
         WHERE o5.user_id = o.user_id
           AND o5.id <> o.id
           AND o5.created_at < o.created_at
         LIMIT 1
    ), 1.0) AS value_vs_buyer_median,

    /* NOT a feature — ground truth for the evaluation only. */
    COALESCE(rd.anomaly, '') AS label

  FROM orders o
  LEFT JOIN users u ON u.id = o.user_id
  LEFT JOIN risk_demo_data rd
         ON rd.object_type = 'order' AND rd.object_id = o.id
SQL;

    return $single
        ? $sql . "\n WHERE o.id = ?\n LIMIT 1"
        : $sql . "\n ORDER BY o.created_at DESC";
}

function risk_ml_features(PDO $db, int $orderId): ?array
{
    $stmt = $db->prepare(risk_feature_sql(true));
    $stmt->execute([$orderId]);
    $row = $stmt->fetch();

    if (!$row) {
        return null;
    }

    $features = [];
    foreach (RISK_FEATURE_COLUMNS as $column) {
        $features[$column] = (float) ($row[$column] ?? 0);
    }

    return $features;
}

function risk_ml_score(PDO $db, int $orderId, ?string &$why = null): ?array
{
    $why = null;

    $features = risk_ml_features($db, $orderId);
    if ($features === null) {
        $why = 'order not found';
        return null;
    }

    $response = risk_ai_post('risk_ml_url', 'http://localhost:8001/risk/score', [
        'features' => $features,
        'order_id' => $orderId,
    ], $why);

    if ($response === null) {
        return null;
    }

    $score = $response['ml_score'] ?? null;
    if (!is_int($score) && !(is_float($score) && $score == (int) $score)) {
        $why = 'ml_score was not an integer';
        app_log('Risk ML: bad ml_score', ['order' => $orderId, 'got' => $score]);
        return null;
    }

    $score = (int) $score;
    if ($score < 0 || $score > 100) {
        $why = "ml_score out of range ($score)";
        app_log('Risk ML: ml_score out of range', ['order' => $orderId, 'score' => $score]);
        return null;
    }

    return [
        'ml_score'      => $score,
        'model_version' => $response['model_version'] ?? null,
        'trained_at'    => $response['trained_at'] ?? null,
    ];
}

function risk_blend(int $ruleScore, ?int $mlScore): array
{
    $ruleScore = max(0, min(100, $ruleScore));

    if ($mlScore === null) {
        return [
            'score'      => $ruleScore,
            'rule_score' => $ruleScore,
            'ml_score'   => null,
            'blended'    => false,
        ];
    }

    $config = risk_config();
    $ruleWeight = (float) $config['rule_weight'];
    $mlWeight   = (float) $config['ml_weight'];

    $total = $ruleWeight + $mlWeight;
    if ($total <= 0) {
        return [
            'score'      => $ruleScore,
            'rule_score' => $ruleScore,
            'ml_score'   => $mlScore,
            'blended'    => false,
        ];
    }
    $ruleWeight /= $total;
    $mlWeight   /= $total;

    $mlScore = max(0, min(100, $mlScore));
    $final   = (int) round($ruleWeight * $ruleScore + $mlWeight * $mlScore);

    return [
        'score'      => max(0, min(100, $final)),
        'rule_score' => $ruleScore,
        'ml_score'   => $mlScore,
        'blended'    => true,
    ];
}

function risk_explain(array $result): array
{
    $template = risk_template_explanation($result);
    $config   = risk_config();

    if (!$config['llm_explain']) {
        return ['text' => $template, 'source' => 'template', 'reason' => 'llm_explain is off'];
    }

    $signals = $result['signals'] ?? [];
    if (!$signals) {
        return ['text' => $template, 'source' => 'template', 'reason' => 'no signals'];
    }

    $payload = ['signals' => []];
    foreach ($signals as $signal) {
        $payload['signals'][] = [
            'code'   => (string) ($signal['code'] ?? ''),
            'label'  => (string) ($signal['label'] ?? ''),
            'detail' => (string) ($signal['detail'] ?? ''),
        ];
    }

    $payload['score'] = (int) ($result['score'] ?? 0);
    $payload['level'] = (string) ($result['level'] ?? '');

    $payload['subject'] = match (true) {
        isset($result['seller']) => 'seller',
        isset($result['user'])   => 'buyer account',
        default                  => 'order',
    };

    $payload['allowed_numbers'] = array_values(array_map(
        'floatval',
        risk_evidence_numbers($result)
    ));

    $why = null;
    $response = risk_ai_post('risk_explain_url', 'http://localhost:8001/risk/explain', $payload, $why);

    if ($response === null) {
        return ['text' => $template, 'source' => 'template', 'reason' => $why];
    }

    $text = $response['explanation'] ?? null;
    if (!is_string($text) || trim($text) === '') {
        return [
            'text'   => $template,
            'source' => 'template',
            'reason' => is_string($response['reason'] ?? null)
                ? (string) $response['reason']
                : 'no explanation returned',
        ];
    }

    /* Verified again, by the side that publishes it. */
    $problem = risk_explanation_problem($text, $result);
    if ($problem !== null) {
        app_log('Risk explanation rejected by PHP', [
            'problem' => $problem,
            'text'    => mb_substr($text, 0, 200),
        ]);
        return ['text' => $template, 'source' => 'template', 'reason' => $problem];
    }

    if (
        isset($response['score']) && (int) $response['score'] !== $payload['score']
        || isset($response['level']) && (string) $response['level'] !== $payload['level']
    ) {
        app_log('Risk explanation: score/level came back changed', [
            'sent' => [$payload['score'], $payload['level']],
            'got'  => [$response['score'] ?? null, $response['level'] ?? null],
        ]);
        return ['text' => $template, 'source' => 'template', 'reason' => 'score/level mismatch'];
    }

    return ['text' => trim($text), 'source' => 'llm', 'reason' => null];
}

function risk_ai_post(string $configKey, string $fallback, array $payload, ?string &$why = null): ?array
{
    $why = null;

    if (!function_exists('curl_init')) {
        $why = 'cURL is not enabled in PHP';
        return null;
    }

    $url     = (string) config($configKey, $fallback);
    $timeout = (int) config('risk_ai_timeout', 8);

    $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($body === false) {
        $why = 'payload could not be encoded';
        return null;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Accept: application/json',
        ],
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_TIMEOUT        => max(2, $timeout),
        CURLOPT_FOLLOWLOCATION => false,
    ]);

    $raw     = curl_exec($ch);
    $status  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlNo  = curl_errno($ch);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($raw === false) {
        $why = $curlNo === CURLE_OPERATION_TIMEDOUT
            ? 'the AI service timed out'
            : 'the AI service is not reachable';
        return null;
    }

    if ($status === 503) {
        $decoded = json_decode((string) $raw, true);
        $why = 'no model available: ' . (
            is_array($decoded) && is_string($decoded['detail'] ?? null)
                ? $decoded['detail']
                : 'service returned 503'
        );
        return null;
    }

    if ($status < 200 || $status >= 300) {
        $why = "the AI service answered $status";
        app_log('Risk AI service error', [
            'url'    => $url,
            'status' => $status,
            'body'   => mb_substr((string) $raw, 0, 300),
            'errno'  => $curlNo,
            'error'  => $curlErr,
        ]);
        return null;
    }

    $decoded = json_decode((string) $raw, true);
    if (!is_array($decoded)) {
        $why = 'the AI service returned a body that is not JSON';
        app_log('Risk AI service: unparseable body', [
            'url'  => $url,
            'body' => mb_substr((string) $raw, 0, 300),
        ]);
        return null;
    }

    return $decoded;
}
