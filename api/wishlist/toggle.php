<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../catalog.php';

try {
    require_method('POST');
    csrf_check();
    $me = require_login();

    $publicId = body_str('product_id');
    if ($publicId === '') {
        json_fail('No product was specified.', 'VALIDATION', 400);
    }

    $product = find_product($publicId, true);
    if ($product === null) {
        json_fail('That product is no longer available.', 'NOT_FOUND', 404);
    }
    $productId = (int) $product['id'];
    $userId    = (int) $me['id'];

    // DELETE first and look at the row count: if something was removed it
    // was saved, and it now is not. One round trip, no race between a
    // SELECT and the write that follows it.
    $remove = db()->prepare('DELETE FROM wishlist WHERE user_id = ? AND product_id = ?');
    $remove->execute([$userId, $productId]);

    $saved = false;
    if ($remove->rowCount() === 0) {
        // INSERT IGNORE: two tabs clicking at once must not 500 on the
        // primary key.
        db()->prepare('INSERT IGNORE INTO wishlist (user_id, product_id) VALUES (?, ?)')
            ->execute([$userId, $productId]);
        $saved = true;
    }

    $ids = db()->prepare(
        'SELECT p.public_id
           FROM wishlist w
           JOIN products p ON p.id = w.product_id
          WHERE w.user_id = ?
            AND ' . product_public_where()
    );
    $ids->execute([$userId]);
    $list = $ids->fetchAll(PDO::FETCH_COLUMN, 0);

    json_ok([
        'saved'   => $saved,
        'product' => $product['public_id'],
        'title'   => $product['title'],
        'ids'     => $list,
        'count'   => count($list),
    ]);
} catch (Throwable $e) {
    app_log('wishlist/toggle.php failed: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    json_fail('Could not update your wishlist right now. Please try again.', 'SERVER', 500);
}
