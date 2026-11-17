<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/_cart.php';

try {
    require_method('POST');
    csrf_check();
    $me = require_login();

    $product   = cart_require_product(body_str('product_id'));
    $productId = (int) $product['id'];
    $stock     = (int) $product['stock'];
    $qty       = body_int('qty', 1, -99, 99);

    if ($qty !== null && $qty <= 0) {
        db()->prepare('DELETE FROM cart_items WHERE user_id = ? AND product_id = ?')
            ->execute([(int) $me['id'], $productId]);
        json_ok(cart_payload((int) $me['id']));
    }

    $target  = min((int) $qty, $stock, 99);
    $clamped = $target < (int) $qty;

    // UPDATE only: this endpoint changes an existing line, it does not
    // create one. Nothing changed means the line was not in the cart.
    $stmt = db()->prepare('UPDATE cart_items SET quantity = ? WHERE user_id = ? AND product_id = ?');
    $stmt->execute([$target, (int) $me['id'], $productId]);

    if ($stmt->rowCount() === 0) {
        $check = db()->prepare('SELECT 1 FROM cart_items WHERE user_id = ? AND product_id = ?');
        $check->execute([(int) $me['id'], $productId]);
        if (!$check->fetchColumn()) {
            json_fail('That item is not in your cart.', 'NOT_FOUND', 404);
        }
    }

    $payload = cart_payload((int) $me['id']);
    if ($clamped) {
        $payload['notices'][] = sprintf('Only %d of %s left in stock.', $target, $product['title']);
    }

    json_ok($payload);
} catch (Throwable $e) {
    app_log('cart/update.php failed: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    json_fail('Could not update your cart right now. Please try again.', 'SERVER', 500);
}
