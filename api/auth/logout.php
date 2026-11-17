<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

try {
    require_method('POST');
    csrf_check();

    $uid = $_SESSION['uid'] ?? null;

    session_kill();

    if ($uid) {
        app_log('Logout', ['user' => $uid]);
    }

    json_ok(['signed_out' => true]);
} catch (Throwable $e) {
    app_log('logout.php failed: ' . $e->getMessage());
    json_fail('Could not sign you out cleanly. Please close the browser.', 'SERVER', 500);
}
