<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

try {
    require_method('POST');
    csrf_check();
    $me = require_login();

    $current = body_raw('current_password');
    $next    = body_raw('new_password');

    $errors = collect_errors([
        'current_password' => $current === '' ? 'Enter your current password' : null,
        'new_password'     => invalid_password($next, 'New password'),
    ]);
    if ($errors) {
        fail_validation($errors);
    }

    $stmt = db()->prepare('SELECT password_hash FROM users WHERE id = ?');
    $stmt->execute([(int) $me['id']]);
    $hash = (string) $stmt->fetchColumn();

    if (!password_verify($current, $hash)) {
        app_log('Wrong current password on change', ['user' => $me['id']]);
        fail_validation(['current_password' => 'That is not your current password']);
    }

    if (password_verify($next, $hash)) {
        fail_validation(['new_password' => 'Choose a password you have not used here before']);
    }

    $save = db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
    $save->execute([password_hash($next, PASSWORD_DEFAULT), (int) $me['id']]);

    // Fresh session id and fresh CSRF token for the still-signed-in user.
    session_promote((int) $me['id'], $me['role']);

    app_log('Password changed', ['user' => $me['id']]);

    json_ok([
        'changed'    => true,
        'csrf_token' => csrf_token(),
    ]);
} catch (Throwable $e) {
    app_log('change_password.php failed: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    json_fail('Could not change your password right now. Please try again.', 'SERVER', 500);
}
