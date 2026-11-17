<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

try {
    require_method('POST');
    csrf_check();

    /* ---------- the admin can close sign-ups from the settings table ---------- */
    if (setting('customer_signups_open', true) !== true) {
        json_fail('New sign-ups are paused right now. Please try again later.', 'FORBIDDEN', 403);
    }

    $name     = body_str('name');
    $email    = mb_strtolower(body_str('email'));
    $phone    = body_str('phone');
    $password = body_raw('password');

    $errors = collect_errors([
        'name'     => invalid_name($name),
        'email'    => invalid_email($email),
        'phone'    => invalid_phone($phone),
        'password' => invalid_password($password),
        'accept_terms' => body_bool('accept_terms')
            ? null
            : 'You must accept the terms to continue',
    ]);
    if ($errors) {
        fail_validation($errors);
    }

    $phone = normalize_phone_lk($phone);

    $exists = db()->prepare('SELECT 1 FROM users WHERE email = ?');
    $exists->execute([$email]);
    if ($exists->fetchColumn()) {
        fail_validation(
            ['email' => 'An account with this email already exists — sign in instead'],
            'That email is already registered.'
        );
    }

    try {
        $insert = db()->prepare(
            'INSERT INTO users (name, email, phone, password_hash, role, status)
             VALUES (?, ?, ?, ?, \'customer\', \'active\')'
        );
        // PASSWORD_DEFAULT is bcrypt on PHP 8.2. password_hash() generates and
        // embeds its own random salt, so two people with the same password get
        // different hashes and one leaked hash cannot be reused.
        $insert->execute([
            $name,
            $email,
            $phone,
            password_hash($password, PASSWORD_DEFAULT),
        ]);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {      // integrity constraint — the unique email
            fail_validation(
                ['email' => 'An account with this email already exists — sign in instead'],
                'That email is already registered.'
            );
        }
        throw $e;
    }

    $userId = (int) db()->lastInsertId();

    $stmt = db()->prepare(
        'SELECT id, name, email, phone, role, status, avatar_url, created_at
           FROM users WHERE id = ?'
    );
    $stmt->execute([$userId]);
    $row = $stmt->fetch();

    session_promote($userId, 'customer');
    app_log('Registered customer', ['user' => $userId]);

    json_ok([
        'user'       => public_user($row),
        'csrf_token' => csrf_token(),
    ], 201);
} catch (Throwable $e) {
    app_log('register.php failed: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    json_fail('Could not create your account right now. Please try again.', 'SERVER', 500);
}
