<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/_seller.php';

try {
    require_method('POST');
    csrf_check();

    $me       = require_seller();          // deleting is allowed while unverified
    $sellerId = (int) $me['seller']['id'];

    $id = body_str('id');
    if ($id === '') {
        json_fail('No listing was specified.', 'VALIDATION', 400);
    }

    $product = seller_find_product($sellerId, $id);
    if ($product === null) {
        json_fail('That listing could not be found.', 'NOT_FOUND', 404);
    }

    db()->prepare(
        "UPDATE products
            SET deleted_at = NOW(), status = 'inactive'
          WHERE id = ? AND seller_id = ? AND deleted_at IS NULL"
    )->execute([(int) $product['id'], $sellerId]);

    db()->prepare('DELETE FROM product_sales WHERE product_id = ? AND end_date >= CURDATE()')
        ->execute([(int) $product['id']]);

    app_log('Listing deleted', ['seller' => $sellerId, 'product' => $product['public_id']]);

    json_ok([
        'id'      => $product['public_id'],
        'message' => $product['title'] . ' has been removed from your shop.',
    ]);
} catch (Throwable $e) {
    app_log('seller/product_delete.php failed: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    json_fail('Could not remove that listing right now. Please try again.', 'SERVER', 500);
}
