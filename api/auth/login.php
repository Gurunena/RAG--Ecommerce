<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

try {
    require_method('POST');
    csrf_check();

    $email    = mb_strtolower(body_str('email'));
    $password = body_raw('password');
    $ip       = client_ip();

    // Shape check only. Telling someone their password is "too short" at this
    // point would confirm nothing useful, but it does keep the inline UI happy
    // when a field was left blank.
    $errors = collect_errors([
        'email'    => $email === '' ? 'Email is required' : null,
        'password' => $password === '' ? 'Password is required' : null,
    ]);
    if ($errors) {
        fail_validation($errors);
    }

    /* ---------- rate limit: 5 failures per email OR IP / 10 minutes ---------- */
    if (login_is_rate_limited($email, $ip)) {
        app_log('Login rate limited', ['email' => $email, 'ip' => $ip]);
        json_fail(
            'Too many failed sign-in attempts. Please wait ' . (int) config('login_window_minutes')
                . ' minutes and try again.',
            'AUTH',
            429
        );
    }

    $stmt = db()->prepare(
        'SELECT id, name, email, phone, password_hash, role, status, avatar_url, created_at, last_login_at
           FROM users
          WHERE email = ?'
    );
    $stmt->execute([$email]);
    $row = $stmt->fetch();

    $dummyHash = '$2y$10$43/4zrecyQkMmPRGxUr2OebbDzQUtlAcwMGOyEobGRBN7mxVQytLe';

    $hash  = $row ? $row['password_hash'] : $dummyHash;
    $valid = password_verify($password, $hash) && $row !== false;

    if (!$valid) {
        login_record_failure($email, $ip);
        app_log('Failed login', ['email' => $email, 'ip' => $ip]);
        json_fail('Email or password is incorrect.', 'AUTH', 401);
    }

    if ($row['status'] === 'suspended') {
        // Correct password, but the account is closed. Not a failed attempt,
        // so it does not count toward the rate limit.
        app_log('Suspended account tried to sign in', ['user' => $row['id']]);
        json_fail('This account has been suspended. Contact support.', 'FORBIDDEN', 403);
    }

    /* ---------- the password was right: re-hash if the cost has moved on ---------- */
    if (password_needs_rehash($row['password_hash'], PASSWORD_DEFAULT)) {
        $rehash = db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
        $rehash->execute([password_hash($password, PASSWORD_DEFAULT), (int) $row['id']]);
    }

    session_promote((int) $row['id'], $row['role']);
    login_clear_failures($email);

    $touch = db()->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?');
    $touch->execute([(int) $row['id']]);

    app_log('Login OK', ['user' => $row['id'], 'role' => $row['role']]);

    json_ok([
        'user'       => public_user($row),
        'csrf_token' => csrf_token(),
    ]);
} catch (Throwable $e) {
    app_log('login.php failed: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    json_fail('Could not sign you in right now. Please try again.', 'SERVER', 500);
}
