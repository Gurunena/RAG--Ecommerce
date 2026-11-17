<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

const SALE_PRICE_SQL = "(SELECT ps.sale_price
                           FROM product_sales ps
                          WHERE ps.product_id = p.id
                            AND CURDATE() BETWEEN ps.start_date AND ps.end_date
                       ORDER BY ps.sale_price ASC
                          LIMIT 1)";

const SALE_END_SQL = "(SELECT ps.end_date
                         FROM product_sales ps
                        WHERE ps.product_id = p.id
                          AND CURDATE() BETWEEN ps.start_date AND ps.end_date
                     ORDER BY ps.sale_price ASC
                        LIMIT 1)";

function product_select_list(): string
{
    return 'p.id, p.public_id, p.seller_id, p.title, p.subtitle, p.category,
            p.icon, p.description, p.price, p.old_price, p.currency, p.stock,
            p.specs, p.rating_breakdown, p.badge, p.status, p.rating,
            p.review_count, p.created_at,
            s.public_id     AS seller_public_id,
            s.business_name AS seller_name,
            s.city          AS seller_city,
            s.rating        AS seller_rating,
            (s.kyc_status = \'verified\') AS seller_verified,
            ' . SALE_PRICE_SQL . ' AS sale_price,
            ' . SALE_END_SQL . '   AS sale_ends,
            COALESCE(' . SALE_PRICE_SQL . ', p.price) AS effective_price';
}

/** The complete SELECT ... FROM every catalog query starts from. */
function product_base_sql(): string
{
    return 'SELECT ' . product_select_list() . '
              FROM products p
              JOIN sellers s ON s.id = p.seller_id';
}

/** What the public storefront is allowed to see. */
function product_public_where(): string
{
    return "p.status = 'active' AND p.deleted_at IS NULL";
}

/** [productId => ['images/a.webp', ...]] in sort order. */
function product_images_for(array $productIds): array
{
    if (!$productIds) {
        return [];
    }
    $in = implode(',', array_fill(0, count($productIds), '?'));
    $stmt = db()->prepare(
        "SELECT product_id, image_url
           FROM product_images
          WHERE product_id IN ($in)
       ORDER BY product_id, sort_order, id"
    );
    $stmt->execute(array_map('intval', $productIds));

    $out = [];
    foreach ($stmt as $row) {
        $out[(int) $row['product_id']][] = $row['image_url'];
    }
    return $out;
}

/** [productId => ['programming', 'student', ...]] */
function product_tags_for(array $productIds): array
{
    if (!$productIds) {
        return [];
    }
    $in = implode(',', array_fill(0, count($productIds), '?'));
    $stmt = db()->prepare(
        "SELECT product_id, tag FROM product_tags WHERE product_id IN ($in) ORDER BY product_id, id"
    );
    $stmt->execute(array_map('intval', $productIds));

    $out = [];
    foreach ($stmt as $row) {
        $out[(int) $row['product_id']][] = $row['tag'];
    }
    return $out;
}

function decode_json_object(?string $raw): object
{
    if ($raw === null || $raw === '') {
        return new stdClass();
    }
    $decoded = json_decode($raw);
    return is_object($decoded) ? $decoded : new stdClass();
}

/** Same, but null is allowed (the frontend derives a curve when absent). */
function decode_json_object_or_null(?string $raw): ?object
{
    if ($raw === null || $raw === '') {
        return null;
    }
    $decoded = json_decode($raw);
    return is_object($decoded) ? $decoded : null;
}

function shape_product(array $row, array $images = [], array $tags = []): array
{
    $base    = (float) $row['price'];
    $sale    = $row['sale_price'] === null ? null : (float) $row['sale_price'];
    $onSale  = $sale !== null;

    // `price` is what you pay; `oldPrice` is what the strikethrough shows.
    // During a sale that is the normal price, otherwise the product's own
    // was-price if it has one.
    $price    = $onSale ? $sale : $base;
    $oldPrice = $onSale ? $base : ($row['old_price'] === null ? null : (float) $row['old_price']);

    $sellerPublic = $row['seller_public_id'];
    $sellerId     = (int) $row['seller_id'];

    return [
        // 'p1' is the id the whole frontend (and the AI service) uses.
        'id'    => $row['public_id'],
        'dbId'  => (int) $row['id'],

        // Only a grouping key for the cart's per-seller blocks. Falls back to
        // a 'sid-' form for sellers with no public_id, which cannot collide
        // with a real one like 's1'.
        'sellerId' => $sellerPublic !== null ? $sellerPublic : 'sid-' . $sellerId,

        'category' => $row['category'],
        'icon'     => $row['icon'],
        'title'    => $row['title'],
        'subtitle' => $row['subtitle'] ?? '',

        'image'  => $images[0] ?? null,
        'images' => $images,

        'price'      => $price,
        'oldPrice'   => $oldPrice,
        'basePrice'  => $base,
        'onSale'     => $onSale,
        'saleEndsAt' => $onSale ? $row['sale_ends'] : null,
        'currency'   => $row['currency'],

        'stock' => (int) $row['stock'],
        'tags'  => $tags,
        'specs' => decode_json_object($row['specs']),

        // products.json called this `why`; it is products.description.
        'why' => $row['description'] ?? '',

        'rating'  => (float) $row['rating'],
        'reviews' => (int) $row['review_count'],
        'badge'   => $row['badge'],

        // ALWAYS present — see the file header.
        'seller' => [
            'id'       => $sellerPublic !== null ? $sellerPublic : 'sid-' . $sellerId,
            'name'     => $row['seller_name'],
            'city'     => $row['seller_city'] ?? '',
            'rating'   => (float) $row['seller_rating'],
            'verified' => (bool) $row['seller_verified'],
        ],
    ];
}

/** Shape a whole result set, fetching images and tags in two extra queries. */
function shape_products(array $rows): array
{
    if (!$rows) {
        return [];
    }
    $ids    = array_map(static fn(array $r): int => (int) $r['id'], $rows);
    $images = product_images_for($ids);
    $tags   = product_tags_for($ids);

    return array_map(
        static fn(array $r): array => shape_product($r, $images[(int) $r['id']] ?? [], $tags[(int) $r['id']] ?? []),
        $rows
    );
}

function find_product(string $id, bool $publicOnly = true): ?array
{
    $where = 'WHERE (p.public_id = ? OR p.id = ?)';
    if ($publicOnly) {
        $where .= ' AND ' . product_public_where();
    }

    // The numeric comparison gets 0 for a non-numeric id, which matches no
    // row, so 'p1' and '4' both work through one prepared statement.
    $stmt = db()->prepare(product_base_sql() . ' ' . $where . ' LIMIT 1');
    $stmt->execute([$id, ctype_digit($id) ? (int) $id : 0]);

    $row = $stmt->fetch();
    return $row === false ? null : $row;
}

function refresh_product_rating(int $productId): void
{
    $stmt = db()->prepare(
        'UPDATE products p
            SET p.rating = COALESCE((SELECT ROUND(AVG(r.rating), 1) FROM reviews r WHERE r.product_id = p.id), 0),
                p.review_count = (SELECT COUNT(*) FROM reviews r WHERE r.product_id = p.id)
          WHERE p.id = ?'
    );
    $stmt->execute([$productId]);
}
