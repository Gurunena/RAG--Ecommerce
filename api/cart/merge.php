<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/_cart.php';

try {
    require_method('POST');
    csrf_check();
    $me = require_login();

    $items = json_body()['items'] ?? [];
    if (!is_array($items)) {
        json_fail('items must be an array.', 'VALIDATION', 400);
    }

    // A cap, so a tampered-with localStorage cannot make this loop huge.
    $items = array_slice($items, 0, 100);

    $merged  = 0;
    $skipped = [];

    db_transaction(static function (PDO $pdo) use ($items, $me, &$merged, &$skipped) {
        $find   = $pdo->prepare('SELECT quantity FROM cart_items WHERE user_id = ? AND product_id = ?');
        $upsert = $pdo->prepare(
            'INSERT INTO cart_items (user_id, product_id, quantity) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE quantity = VALUES(quantity)'
        );

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $publicId = isset($item['product_id']) && is_string($item['product_id'])
                ? trim($item['product_id'])
                : '';
            $qty = isset($item['qty']) && is_numeric($item['qty']) ? (int) $item['qty'] : 1;

            if ($publicId === '' || $qty < 1) {
                continue;
            }

            // Silently skipped, not an error: a guest cart can easily hold
            // something that has since been taken down or sold out, and the
            // sign-in it is attached to must still succeed.
            $product = find_product($publicId, true);
            if ($product === null || (int) $product['stock'] <= 0) {
                $skipped[] = $publicId;
                continue;
            }

            $productId = (int) $product['id'];

            $find->execute([(int) $me['id'], $productId]);
            $have = (int) ($find->fetchColumn() ?: 0);

            $target = min(max($have, $qty), (int) $product['stock'], 99);
            if ($target === $have) {
                continue;                 // the account's cart already wins
            }

            $upsert->execute([(int) $me['id'], $productId, $target]);
            $merged++;
        }
    });

    if ($merged > 0 || $skipped) {
        app_log('Guest cart merged', [
            'user'    => $me['id'],
            'merged'  => $merged,
            'skipped' => count($skipped),
        ]);
    }

    $payload = cart_payload((int) $me['id']);
    $payload['merged']  = $merged;
    $payload['skipped'] = count($skipped);

    json_ok($payload);
} catch (Throwable $e) {
    app_log('cart/merge.php failed: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    json_fail('Could not move your saved cart over. Please try adding the items again.', 'SERVER', 500);
}
