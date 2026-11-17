<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../catalog.php';
require_once __DIR__ . '/_review_rules.php';

try {
    require_method('POST');
    csrf_check();
    $me = require_role('customer');

    $publicId = body_str('product_id');
    $rating   = body_int('rating');
    $text     = body_str('text');

    if ($publicId === '') {
        json_fail('No product was specified.', 'VALIDATION', 400);
    }

    $errors = collect_errors([
        'rating' => ($rating === null || $rating < 1 || $rating > 5)
            ? 'Choose a rating from 1 to 5 stars'
            : null,
        // The column is nullable, so a star-only rating is allowed; but if
        // they do write something it has to be worth reading.
        'text' => ($text !== '' && mb_strlen($text) < 10)
            ? 'Either leave the comment empty or write at least 10 characters'
            : (mb_strlen($text) > 2000 ? 'Please keep the review under 2000 characters' : null),
    ]);
    if ($errors) {
        fail_validation($errors);
    }

    $product = find_product($publicId, true);
    if ($product === null) {
        json_fail('That product is no longer available.', 'NOT_FOUND', 404);
    }
    $productId = (int) $product['id'];

    /* ---------- the gate ---------- */
    $permission = review_permission($me, $productId);
    if (!$permission['can_review']) {
        $status = $permission['reason'] === 'already_reviewed' ? 409 : 403;
        json_fail($permission['message'], 'FORBIDDEN', $status);
    }

    try {
        db_transaction(static function (PDO $pdo) use ($productId, $me, $rating, $text) {
            $insert = $pdo->prepare(
                'INSERT INTO reviews (product_id, user_id, rating, review_text, is_verified_buyer)
                 VALUES (?, ?, ?, ?, 1)'
            );
            $insert->execute([$productId, (int) $me['id'], $rating, $text === '' ? null : $text]);

            // The seeded percentages no longer describe the rows; let the
            // detail page derive the bars from real reviews instead.
            $pdo->prepare('UPDATE products SET rating_breakdown = NULL WHERE id = ?')
                ->execute([$productId]);

            refresh_product_rating($productId);
        });
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {      // the UNIQUE (product_id, user_id)
            json_fail('You have already reviewed this product.', 'FORBIDDEN', 409);
        }
        throw $e;
    }

    app_log('Review created', ['user' => $me['id'], 'product' => $productId, 'rating' => $rating]);

    // Hand back the refreshed product so the page can redraw the summary,
    // the stars and the breakdown without a second request.
    $fresh = find_product($publicId, true);
    $images = product_images_for([$productId])[$productId] ?? [];
    $tags   = product_tags_for([$productId])[$productId] ?? [];

    json_ok([
        'created' => true,
        'product' => shape_product($fresh, $images, $tags),
    ], 201);
} catch (Throwable $e) {
    app_log('reviews/create.php failed: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    json_fail('Could not save your review right now. Please try again.', 'SERVER', 500);
}
