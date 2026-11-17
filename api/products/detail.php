<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../catalog.php';
require_once __DIR__ . '/../reviews/_review_rules.php';

try {
    require_method('GET');

    $id = query_str('id');
    if ($id === '') {
        json_fail('No product was specified.', 'VALIDATION', 400);
    }

    $row = find_product($id, true);
    if ($row === null) {
        json_fail('That product is no longer available.', 'NOT_FOUND', 404);
    }

    $productId = (int) $row['id'];

    $images = product_images_for([$productId])[$productId] ?? [];
    $tags   = product_tags_for([$productId])[$productId] ?? [];
    $product = shape_product($row, $images, $tags);

    /* ---------- the written reviews ---------- */
    $me = current_user();

    $stmt = db()->prepare(
        'SELECT r.id, r.rating, r.review_text, r.is_verified_buyer, r.helpful_count,
                r.created_at, r.user_id, u.name AS author
           FROM reviews r
           JOIN users u ON u.id = r.user_id
          WHERE r.product_id = ?
       ORDER BY r.created_at DESC, r.id DESC'
    );
    $stmt->execute([$productId]);

    $reviews = [];
    foreach ($stmt as $r) {
        $reviews[] = [
            'id'       => (int) $r['id'],
            // The reviewer's display name only. Never their email.
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

    $product['reviewsList'] = $reviews;

    $breakdown = decode_json_object_or_null($row['rating_breakdown']);
    if ($breakdown === null && $reviews) {
        $counts = [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0];
        foreach ($reviews as $r) {
            $counts[$r['rating']] = ($counts[$r['rating']] ?? 0) + 1;
        }
        $n = count($reviews);
        $breakdown = (object) array_map(
            static fn(int $c): int => (int) round($c / $n * 100),
            $counts
        );
    }
    $product['ratingBreakdown'] = $breakdown;

    /* ---------- may the viewer write a review? ---------- */
    $product['review_permission'] = review_permission($me, $productId);

    json_ok(['product' => $product]);
} catch (Throwable $e) {
    app_log('products/detail.php failed: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    json_fail('Could not load that product right now. Please try again.', 'SERVER', 500);
}
