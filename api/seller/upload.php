<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/_seller.php';
require_once __DIR__ . '/../_uploads.php';

try {
    require_method('POST');
    csrf_check();                      // the token rides in the X-CSRF-Token header

    $me       = require_seller();
    $sellerId = (int) $me['seller']['id'];

    $kind = isset($_POST['kind']) && is_string($_POST['kind']) ? trim($_POST['kind']) : 'product';

    if ($kind === 'avatar') {
        json_fail('Profile photos go to api/auth/avatar.php.', 'VALIDATION', 400);
    }
    if ($kind !== 'product') {
        json_fail('Unknown upload type.', 'VALIDATION', 400);
    }

    // A listing going in front of shoppers needs a verified seller.
    require_verified_seller();

    $url = store_uploaded_image('file', 'product');

    app_log('Product image uploaded', ['seller' => $sellerId, 'url' => $url]);

    json_ok([
        'url'     => $url,
        'kind'    => 'product',
        'message' => 'Image uploaded.',
    ], 201);
} catch (Throwable $e) {
    app_log('seller/upload.php failed: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    json_fail('Could not upload that image right now. Please try again.', 'SERVER', 500);
}
