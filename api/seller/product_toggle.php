<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/_seller.php';

try {
    require_method('POST');
    csrf_check();

    $me       = require_verified_seller();
    $sellerId = (int) $me['seller']['id'];

    $id = body_str('id');
    if ($id === '') {
        json_fail('No listing was specified.', 'VALIDATION', 400);
    }

    $product = seller_find_product($sellerId, $id);
    if ($product === null) {
        json_fail('That listing could not be found.', 'NOT_FOUND', 404);
    }

    $status = $product['status'];

    if ($status === 'pending') {
        json_fail(
            'That listing is still waiting for an admin to approve it, so there is nothing to pause yet.',
            'VALIDATION',
            409
        );
    }
    if ($status === 'rejected') {
        json_fail(
            'That listing was not approved. Edit it to send it back for review.',
            'VALIDATION',
            409
        );
    }

    $next = $status === 'active' ? 'inactive' : 'active';

    db()->prepare('UPDATE products SET status = ? WHERE id = ? AND seller_id = ?')
        ->execute([$next, (int) $product['id'], $sellerId]);

    json_ok([
        'product' => seller_find_product_shaped($sellerId, $product['public_id']),
        'status'  => $next,
        'message' => $next === 'active'
            ? $product['title'] . ' is back on sale.'
            : $product['title'] . ' is paused and hidden from shoppers.',
    ]);
} catch (Throwable $e) {
    app_log('seller/product_toggle.php failed: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    json_fail('Could not change that listing right now. Please try again.', 'SERVER', 500);
}
