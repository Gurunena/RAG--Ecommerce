<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/_cart.php';

try {
    require_method('POST');
    csrf_check();
    $me = require_login();

    db()->prepare('DELETE FROM cart_items WHERE user_id = ?')->execute([(int) $me['id']]);

    json_ok(cart_payload((int) $me['id']));
} catch (Throwable $e) {
    app_log('cart/clear.php failed: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    json_fail('Could not clear your cart right now. Please try again.', 'SERVER', 500);
}
