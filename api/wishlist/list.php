<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../catalog.php';

try {
    require_method('GET');
    $me = require_login();

    if (query_str('ids_only') === '1') {
        $stmt = db()->prepare(
            'SELECT p.public_id
               FROM wishlist w
               JOIN products p ON p.id = w.product_id
              WHERE w.user_id = ?
                AND ' . product_public_where() . '
           ORDER BY w.added_at DESC, w.product_id DESC'
        );
        $stmt->execute([(int) $me['id']]);

        json_ok(['ids' => $stmt->fetchAll(PDO::FETCH_COLUMN, 0)]);
    }

    $stmt = db()->prepare(
        'SELECT ' . product_select_list() . ', w.added_at
           FROM wishlist w
           JOIN products p ON p.id = w.product_id
           JOIN sellers  s ON s.id = p.seller_id
          WHERE w.user_id = ?
            AND ' . product_public_where() . '
       ORDER BY w.added_at DESC, w.product_id DESC'
    );
    $stmt->execute([(int) $me['id']]);
    $rows = $stmt->fetchAll();

    $items = shape_products($rows);
    json_ok([
        'items' => $items,
        'ids'   => array_map(static fn(array $p): string => $p['id'], $items),
        'count' => count($items),
    ]);
} catch (Throwable $e) {
    app_log('wishlist/list.php failed: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    json_fail('Could not load your wishlist right now. Please try again.', 'SERVER', 500);
}
