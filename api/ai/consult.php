<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

/** Longer than any real question, short enough to not be an attack. */
const AI_MAX_MESSAGE = 1000;

const AI_CONTEXT_KEYS = [
    'category'        => 'string',
    'budget'          => 'number',
    'intent'          => 'string',
    'giftBudget'      => 'bool',
    'gift_clarified'  => 'bool',
];

try {
    require_method('POST');
    csrf_check();

    $message = body_str('message');

    if ($message === '') {
        fail_validation(['message' => 'Type a question first'], 'There was nothing to ask.');
    }
    if (mb_strlen($message) > AI_MAX_MESSAGE) {
        fail_validation(
            ['message' => sprintf('Please keep it under %d characters', AI_MAX_MESSAGE)],
            'That question is too long.'
        );
    }

    /* ---------- the context, key by key ---------- */
    $incoming = json_body()['context'] ?? [];
    $context  = [];

    if (is_array($incoming)) {
        foreach (AI_CONTEXT_KEYS as $key => $type) {
            if (!array_key_exists($key, $incoming)) {
                continue;
            }
            $value = $incoming[$key];

            if ($type === 'string' && is_string($value) && $value !== '') {
                $context[$key] = mb_substr($value, 0, 60);
            } elseif ($type === 'number' && is_numeric($value)) {
                // Clamped: a budget of 1e18 is not a budget.
                $context[$key] = max(0, min((float) $value, 100000000));
            } elseif ($type === 'bool') {
                $context[$key] = (bool) $value;
            }
        }
    }

    $url     = (string) config('ai_service_url', 'http://localhost:8001/consult');
    $timeout = (int) config('ai_service_timeout', 25);

    $payload = json_encode(
        ['message' => $message, 'context' => (object) $context],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    if (!function_exists('curl_init')) {
        app_log('cURL extension missing — cannot reach the AI service');
        json_fail(
            'The shopping consultant is not reachable from this server (cURL is not enabled in PHP).',
            'SERVER',
            503
        );
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Accept: application/json',
        ],
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT        => max(5, $timeout),
        // It is our own service on localhost; a redirect would mean
        // something is wrong, not something to follow.
        CURLOPT_FOLLOWLOCATION => false,
    ]);

    $raw      = curl_exec($ch);
    $status   = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    $curlNo   = curl_errno($ch);
    curl_close($ch);

    /* ---------- the service could not be reached at all ---------- */
    if ($raw === false) {
        app_log('AI service unreachable', [
            'url'   => $url,
            'errno' => $curlNo,
            'error' => $curlErr,
        ]);

        // 503, and a `fallback` flag the chat uses to decide whether to fall
        // back to its own keyword matching rather than show an error.
        json_fail(
            $curlNo === CURLE_OPERATION_TIMEDOUT
                ? 'The shopping consultant is taking too long to answer. Please try again in a moment.'
                : 'The shopping consultant is offline at the moment. Start the Python service to get grounded answers.',
            'SERVER',
            503,
            ['fallback' => true]
        );
    }

    /* ---------- it answered, but not with a success ---------- */
    if ($status < 200 || $status >= 300) {
        app_log('AI service returned an error status', [
            'status' => $status,
            'body'   => mb_substr((string) $raw, 0, 500),
        ]);
        json_fail(
            'The shopping consultant could not answer that just now. Please try again.',
            'SERVER',
            502,
            ['fallback' => true]
        );
    }

    /* ---------- it answered with something that is not our shape ---------- */
    $decoded = json_decode((string) $raw, true);

    if (!is_array($decoded) || !isset($decoded['type'])) {
        app_log('AI service sent an unexpected body', ['body' => mb_substr((string) $raw, 0, 500)]);
        json_fail(
            'The shopping consultant sent back something unexpected. Please try again.',
            'SERVER',
            502,
            ['fallback' => true]
        );
    }

    $productIds = [];
    if (isset($decoded['products']) && is_array($decoded['products'])) {
        foreach ($decoded['products'] as $product) {
            $id = is_array($product) ? ($product['id'] ?? null) : null;
            if (is_string($id) && $id !== '') {
                $productIds[] = $id;
            }
        }
    }

    json_ok([
        'type'    => (string) $decoded['type'],
        'message' => isset($decoded['message']) && is_string($decoded['message'])
            ? $decoded['message']
            : '',
        'chips'   => isset($decoded['chips']) && is_array($decoded['chips'])
            ? array_values(array_filter($decoded['chips'], 'is_string'))
            : [],
        'context' => isset($decoded['context']) && is_array($decoded['context'])
            ? $decoded['context']
            : $context,

        // What to re-hydrate, in the AI's ranking order.
        'product_ids' => $productIds,

        // Says where the answer came from, so the chat's status dot is
        // telling the truth rather than guessing.
        'source' => 'ai',
    ]);
} catch (Throwable $e) {
    app_log('ai/consult.php failed: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    json_fail(
        'Could not reach the shopping consultant right now. Please try again.',
        'SERVER',
        500,
        ['fallback' => true]
    );
}
