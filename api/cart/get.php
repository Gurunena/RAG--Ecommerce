<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/_cart.php';

try {
    require_method('GET');
    $me = require_login();

    json_ok(cart_payload((int) $me['id']));
} catch (Throwable $e) {
    app_log('cart/get.php failed: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    json_fail('Could not load your cart right now. Please try again.', 'SERVER', 500);
}
