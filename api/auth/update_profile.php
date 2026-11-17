<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

try {
    require_method('POST');
    csrf_check();
    $me = require_login();

    $name  = body_str('name');
    $email = mb_strtolower(body_str('email'));
    $phone = body_str('phone');

    $errors = collect_errors([
        'name'  => invalid_name($name),
        'email' => invalid_email($email),
        // Optional here: the seeded reviewer accounts have no number, and
        // making them fill one in to change their name would be silly.
        'phone' => invalid_phone($phone, false),
    ]);
    if ($errors) {
        fail_validation($errors);
    }

    $phone = $phone === '' ? null : normalize_phone_lk($phone);

    // Email is the login identity, so a change has to stay unique.
    if ($email !== mb_strtolower((string) $me['email'])) {
        $taken = db()->prepare('SELECT 1 FROM users WHERE email = ? AND id <> ?');
        $taken->execute([$email, (int) $me['id']]);
        if ($taken->fetchColumn()) {
            fail_validation(['email' => 'That email is already used by another account']);
        }
    }

    try {
        $stmt = db()->prepare('UPDATE users SET name = ?, email = ?, phone = ? WHERE id = ?');
        $stmt->execute([$name, $email, $phone, (int) $me['id']]);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            fail_validation(['email' => 'That email is already used by another account']);
        }
        throw $e;
    }

    app_log('Profile updated', ['user' => $me['id']]);

    json_ok(['user' => public_user(current_user(true))]);
} catch (Throwable $e) {
    app_log('update_profile.php failed: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    json_fail('Could not save your profile right now. Please try again.', 'SERVER', 500);
}
