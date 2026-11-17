<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../catalog.php';

try {
    require_method('GET');

    $where  = [product_public_where()];
    $params = [];
    $having = [];

    /* ---------- category ---------- */
    $categories = array_values(array_filter(
        array_map('trim', explode(',', query_str('category'))),
        static fn(string $c): bool => $c !== ''
    ));
    if ($categories) {
        // One placeholder per value: the IN list is built from the COUNT of
        // the values, never from the values themselves.
        $where[] = 'p.category IN (' . implode(',', array_fill(0, count($categories), '?')) . ')';
        $params  = array_merge($params, $categories);
    }

    /* ---------- free-text search ---------- */
    $search = query_str('search');
    if ($search !== '') {
        // LIKE with a bound parameter. The % wrap goes on the VALUE, so a
        // user typing %_ searches for those characters instead of becoming
        // a wildcard in the pattern.
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $search) . '%';
        $where[] = '(p.title LIKE ? OR p.subtitle LIKE ? OR p.category LIKE ?
                     OR EXISTS (SELECT 1 FROM product_tags pt WHERE pt.product_id = p.id AND pt.tag LIKE ?))';
        $params  = array_merge($params, [$like, $like, $like, $like]);
    }

    $minPrice = query_float('min_price');
    $maxPrice = query_float('max_price');
    if ($minPrice !== null && $minPrice > 0) {
        $having[] = 'effective_price >= ?';
    }
    if ($maxPrice !== null && $maxPrice > 0) {
        $having[] = 'effective_price <= ?';
    }

    /* ---------- rating / stock ---------- */
    $minRating = query_float('min_rating');
    if ($minRating !== null && $minRating > 0) {
        $where[]  = 'p.rating >= ?';
        $params[] = $minRating;
    }
    if (query_str('in_stock') === '1') {
        $where[] = 'p.stock > 0';
    }

    /* ---------- one seller's shop ---------- */
    $seller = query_str('seller');
    if ($seller !== '') {
        $where[]  = '(s.public_id = ? OR s.id = ?)';
        $params[] = $seller;
        $params[] = ctype_digit($seller) ? (int) $seller : 0;
    }

    $sorts = [
        'relevance'  => 'p.badge IS NULL, p.rating DESC, p.review_count DESC, p.id ASC',
        'price-asc'  => 'effective_price ASC, p.id ASC',
        'price-desc' => 'effective_price DESC, p.id ASC',
        'rating'     => 'p.rating DESC, p.review_count DESC, p.id ASC',
        'newest'     => 'p.created_at DESC, p.id DESC',
    ];
    $sortKey  = query_str('sort', 'relevance');
    $orderBy  = $sorts[$sortKey] ?? $sorts['relevance'];

    /* ---------- pagination ---------- */
    $perPage = query_int('per_page', 24, 1, 100);
    $page    = query_int('page', 1, 1, 10000);

    $whereSql  = 'WHERE ' . implode(' AND ', $where);
    $havingSql = $having ? ' HAVING ' . implode(' AND ', $having) : '';

    $countParams = $params;
    if ($minPrice !== null && $minPrice > 0) {
        $countParams[] = $minPrice;
    }
    if ($maxPrice !== null && $maxPrice > 0) {
        $countParams[] = $maxPrice;
    }

    $countSql = 'SELECT COUNT(*) FROM (
                     SELECT p.id, COALESCE(' . SALE_PRICE_SQL . ', p.price) AS effective_price
                       FROM products p
                       JOIN sellers s ON s.id = p.seller_id
                     ' . $whereSql . $havingSql . '
                 ) AS counted';
    $countStmt = db()->prepare($countSql);
    $countStmt->execute($countParams);
    $total = (int) $countStmt->fetchColumn();

    $sql = product_base_sql() . ' ' . $whereSql . $havingSql
         . ' ORDER BY ' . $orderBy
         . ' LIMIT ' . (int) $perPage . ' OFFSET ' . (int) (($page - 1) * $perPage);

    $stmt = db()->prepare($sql);
    $stmt->execute($countParams);

    json_ok([
        'items'    => shape_products($stmt->fetchAll()),
        'total'    => $total,
        'page'     => $page,
        'per_page' => $perPage,
        'pages'    => $perPage > 0 ? (int) ceil($total / $perPage) : 1,
        'sort'     => array_key_exists($sortKey, $sorts) ? $sortKey : 'relevance',
    ]);
} catch (Throwable $e) {
    app_log('products/list.php failed: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    json_fail('Could not load the catalogue right now. Please try again.', 'SERVER', 500);
}
