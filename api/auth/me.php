<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

try {
    require_method('GET');

    $user = current_user();

    // A suspended account is signed out on the spot — this is what makes an
    // admin's suspend decision land on the user's next page view.
    if ($user && $user['status'] === 'suspended') {
        app_log('Suspended account session ended', ['user' => $user['id']]);
        session_kill();
        json_ok([
            'user'       => null,
            'csrf_token' => null,
            'reason'     => 'This account has been suspended. Contact support.',
        ]);
    }

    json_ok([
        'user'       => $user ? public_user($user) : null,
        'csrf_token' => csrf_token(),
    ]);
} catch (Throwable $e) {
    app_log('me.php failed: ' . $e->getMessage());
    json_fail('Could not load your session. Please reload the page.', 'SERVER', 500);
}
