<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../_uploads.php';

try {
    require_method('POST');
    csrf_check();
    $me = require_login();

    $userId   = (int) $me['id'];
    $previous = $me['avatar_url'] ?? null;

    $removing = !isset($_FILES['file']) && body_bool('remove');

    if ($removing) {
        db()->prepare('UPDATE users SET avatar_url = NULL WHERE id = ?')->execute([$userId]);
        delete_stored_upload($previous);

        json_ok([
            'url'     => null,
            'user'    => public_user(current_user(true)),
            'message' => 'Profile photo removed.',
        ]);
    }

    $url = store_uploaded_image('file', 'avatar');

    db()->prepare('UPDATE users SET avatar_url = ? WHERE id = ?')->execute([$url, $userId]);

    // Only ever removes a file this app stored under uploads/; a seeded
    // images/... path is left alone (see delete_stored_upload).
    delete_stored_upload($previous);

    app_log('Avatar updated', ['user' => $userId, 'role' => $me['role']]);

    json_ok([
        'url'     => $url,
        'user'    => public_user(current_user(true)),
        'message' => 'Profile photo updated.',
    ], 201);
} catch (Throwable $e) {
    app_log('auth/avatar.php failed: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    json_fail('Could not update your photo right now. Please try again.', 'SERVER', 500);
}
