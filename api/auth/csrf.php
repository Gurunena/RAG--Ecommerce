<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

try {
    require_method('GET');

    json_ok(['csrf_token' => csrf_token()]);
} catch (Throwable $e) {
    app_log('csrf.php failed: ' . $e->getMessage());
    json_fail('Could not start a secure session. Please reload the page.', 'SERVER', 500);
}
