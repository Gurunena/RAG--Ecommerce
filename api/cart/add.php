<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/_cart.php';

try {
    require_method('POST');
    csrf_check();
    $me = require_login();

    $product = cart_require_product(body_str('product_id'));
    $want    = body_int('qty', 1, 1, 99);

    $productId = (int) $product['id'];
    $stock     = (int) $product['stock'];

    if ($stock <= 0) {
        json_fail($product['title'] . ' is out of stock.', 'VALIDATION', 409);
    }

    // What is already in the cart decides the ceiling for this add.
    $existing = db()->prepare('SELECT quantity FROM cart_items WHERE user_id = ? AND product_id = ?');
    $existing->execute([(int) $me['id'], $productId]);
    $have = (int) ($existing->fetchColumn() ?: 0);

    $target  = min($have + $want, $stock, 99);
    $clamped = $target < $have + $want;

    db()->prepare(
        'INSERT INTO cart_items (user_id, product_id, quantity) VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE quantity = VALUES(quantity)'
    )->execute([(int) $me['id'], $productId, $target]);

    $payload = cart_payload((int) $me['id']);
    if ($clamped) {
        $payload['notices'][] = sprintf(
            'Only %d of %s available — your cart has the maximum.',
            $target,
            $product['title']
        );
    }
    $payload['added'] = $product['title'];

    json_ok($payload);
} catch (Throwable $e) {
    app_log('cart/add.php failed: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    json_fail('Could not add that to your cart right now. Please try again.', 'SERVER', 500);
}
