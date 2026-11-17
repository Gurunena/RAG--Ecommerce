<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

try {
    require_method('GET');

    $consultUrl = (string) config('ai_service_url', 'http://localhost:8001/consult');
    // /health sits next to /consult on the same service.
    $healthUrl  = preg_replace('#/consult/?$#', '/health', $consultUrl);

    $online  = false;
    $detail  = null;
    $service = null;

    if (function_exists('curl_init')) {
        $ch = curl_init($healthUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            // Short: this runs on page load, and the whole question is
            // "is anything listening". A slow answer is a no.
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_TIMEOUT        => 4,
            CURLOPT_FOLLOWLOCATION => false,
        ]);

        $raw    = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $online = $raw !== false && $status >= 200 && $status < 300;

        if ($online) {
            $decoded = json_decode((string) $raw, true);
            if (is_array($decoded)) {
                $service = $decoded;
            }
        } else {
            $detail = 'The AI service did not answer on ' . $healthUrl;
        }
    } else {
        $detail = 'cURL is not enabled in PHP, so this server cannot reach the AI service.';
    }

    /* ---------- has the snapshot drifted from the database? ---------- */
    $snapshot = null;
    $file     = APP_ROOT . '/data/products.json';

    if (is_file($file)) {
        $json = json_decode((string) @file_get_contents($file), true);

        $inFile = is_array($json['products'] ?? null) ? count($json['products']) : 0;
        $inDb   = (int) db()->query(
            "SELECT COUNT(*) FROM products WHERE status = 'active' AND deleted_at IS NULL"
        )->fetchColumn();

        $loaded = is_array($service) && isset($service['products_loaded'])
            ? (int) $service['products_loaded']
            : null;

        $fileMatchesDb      = $inFile === $inDb;
        $serviceMatchesFile = $loaded === null || $loaded === $inFile;

        $snapshot = [
            'products_in_file'    => $inFile,
            'products_in_db'      => $inDb,
            'products_in_service' => $loaded,
            'generated_at'        => isset($json['meta']['generated_at']) && is_string($json['meta']['generated_at'])
                ? $json['meta']['generated_at']
                : null,

            'in_sync' => $fileMatchesDb && $serviceMatchesFile,

            'needs_export'  => !$fileMatchesDb,
            'needs_restart' => $fileMatchesDb && !$serviceMatchesFile,

            // The fix, named in the answer so nobody has to go looking.
            'regenerate_with' => $fileMatchesDb
                ? ($serviceMatchesFile
                    ? null
                    : 'restart the AI service — data/products.json has been regenerated since it started')
                : 'php api/tools/export_products_json.php (then restart the AI service)',
        ];
    }

    json_ok([
        'online'   => $online,
        'url'      => $healthUrl,
        'detail'   => $detail,
        'service'  => $service,
        'snapshot' => $snapshot,
    ]);
} catch (Throwable $e) {
    app_log('ai/health.php failed: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    // A failed health check is not an error worth a 500 — it is a "no".
    json_ok(['online' => false, 'detail' => 'Health check failed on this server.']);
}
