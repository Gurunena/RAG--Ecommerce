<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/_cart.php';

try {
    require_method('POST');
    csrf_check();
    $me = require_login();

    $publicId = body_str('product_id');
    if ($publicId === '') {
        json_fail('No product was specified.', 'VALIDATION', 400);
    }

    // Resolved without the public-listing filter: a product that has since
    // been taken down must still be removable from the cart.
    $row = find_product($publicId, false);
    if ($row !== null) {
        db()->prepare('DELETE FROM cart_items WHERE user_id = ? AND product_id = ?')
            ->execute([(int) $me['id'], (int) $row['id']]);
    }

    json_ok(cart_payload((int) $me['id']));
} catch (Throwable $e) {
    app_log('cart/remove.php failed: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    json_fail('Could not remove that item right now. Please try again.', 'SERVER', 500);
}
