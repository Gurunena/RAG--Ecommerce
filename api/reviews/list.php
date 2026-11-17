<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../catalog.php';

try {
    require_method('GET');

    $id = query_str('product');
    if ($id === '') {
        json_fail('No product was specified.', 'VALIDATION', 400);
    }

    $product = find_product($id, true);
    if ($product === null) {
        json_fail('That product is no longer available.', 'NOT_FOUND', 404);
    }
    $productId = (int) $product['id'];

    $perPage = query_int('per_page', 10, 1, 50);
    $page    = query_int('page', 1, 1, 10000);

    $countStmt = db()->prepare('SELECT COUNT(*) FROM reviews WHERE product_id = ?');
    $countStmt->execute([$productId]);
    $total = (int) $countStmt->fetchColumn();

    $me = current_user();

    // LIMIT/OFFSET are PHP-cast integers, not bound strings — see the note
    // in products/list.php.
    $stmt = db()->prepare(
        'SELECT r.id, r.rating, r.review_text, r.is_verified_buyer, r.helpful_count,
                r.created_at, r.user_id, u.name AS author
           FROM reviews r
           JOIN users u ON u.id = r.user_id
          WHERE r.product_id = ?
       ORDER BY r.created_at DESC, r.id DESC
          LIMIT ' . (int) $perPage . ' OFFSET ' . (int) (($page - 1) * $perPage)
    );
    $stmt->execute([$productId]);

    $items = [];
    foreach ($stmt as $r) {
        $items[] = [
            'id'       => (int) $r['id'],
            'name'     => $r['author'],
            'rating'   => (int) $r['rating'],
            // ISO 8601, not MySQL's "Y-m-d H:i:s": new Date() on that
            // string is implementation-defined and Safari returns Invalid Date.
            'date'     => str_replace(' ', 'T', (string) $r['created_at']),
            'verified' => (bool) $r['is_verified_buyer'],
            'helpful'  => (int) $r['helpful_count'],
            'text'     => $r['review_text'] ?? '',
            'mine'     => $me !== null && (int) $r['user_id'] === (int) $me['id'],
        ];
    }

    json_ok([
        'items'    => $items,
        'total'    => $total,
        'page'     => $page,
        'per_page' => $perPage,
        'pages'    => (int) ceil($total / $perPage),
        'summary'  => [
            // The denormalised aggregate on products, which is what the grid
            // and the big number on the detail page both show.
            'rating'  => (float) $product['rating'],
            'reviews' => (int) $product['review_count'],
        ],
    ]);
} catch (Throwable $e) {
    app_log('reviews/list.php failed: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    json_fail('Could not load the reviews right now. Please try again.', 'SERVER', 500);
}
