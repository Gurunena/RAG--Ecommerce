<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/_seller.php';

try {
    require_method('POST');
    csrf_check();

    $me       = require_verified_seller();
    $sellerId = (int) $me['seller']['id'];

    /* ---------- is this an edit, and is it theirs? ---------- */
    $id       = body_str('id');
    $existing = $id === '' ? null : seller_find_product($sellerId, $id);

    if ($id !== '' && $existing === null) {
        // Either no such listing, or somebody else's. Deliberately the same
        // answer for both: this endpoint does not confirm what exists.
        json_fail('That listing could not be found.', 'NOT_FOUND', 404);
    }

    $title    = body_str('title');
    $subtitle = body_str('subtitle');
    $category = body_str('category');
    $desc     = body_str('description');
    $icon     = body_str('icon');

    $body     = json_body();
    $price    = isset($body['price']) && is_numeric($body['price']) ? (float) $body['price'] : null;
    $oldPrice = isset($body['old_price']) && is_numeric($body['old_price']) ? (float) $body['old_price'] : null;
    $stock    = body_int('stock', null, 0, 1000000);

    $errors = [];

    if ($title === '') {
        $errors['title'] = 'Product title is required';
    } elseif (mb_strlen($title) < 3) {
        $errors['title'] = 'Give the product a fuller name (3 characters minimum)';
    } elseif (mb_strlen($title) > 180) {
        $errors['title'] = 'Title is too long (180 characters max)';
    }

    if (mb_strlen($subtitle) > 255) {
        $errors['subtitle'] = 'Subtitle is too long (255 characters max)';
    }
    if (mb_strlen($desc) > 5000) {
        $errors['description'] = 'Description is too long (5000 characters max)';
    }

    if ($category === '') {
        $errors['category'] = 'Choose a category';
    } elseif (!in_array($category, platform_categories(), true)) {
        $errors['category'] = 'Choose one of the categories we currently sell';
    }

    // DECIMAL(12,2) with CHECK (price > 0) in the schema; rejected here with
    // a readable message rather than letting the database refuse it.
    if ($price === null) {
        $errors['price'] = 'Price is required';
    } elseif ($price <= 0) {
        $errors['price'] = 'Price must be more than zero';
    } elseif ($price > 9999999999) {
        $errors['price'] = 'That price is too large';
    }

    if ($oldPrice !== null && $oldPrice > 0 && $price !== null && $oldPrice <= $price) {
        // A "was" price below the current one would render as a fake discount.
        $errors['old_price'] = 'The was-price has to be higher than the current price';
    }

    if ($stock === null) {
        $errors['stock'] = 'Stock quantity is required';
    }

    if ($errors) {
        fail_validation($errors);
    }

    /* ---------- specs: a flat object of short strings ---------- */
    $specs = [];
    if (is_array($body['specs'] ?? null)) {
        foreach ($body['specs'] as $key => $value) {
            if (!is_string($key) || $key === '') {
                continue;
            }
            if (is_int($value) || is_float($value)) {
                $value = (string) $value;
            }
            if (!is_string($value) || trim($value) === '') {
                continue;
            }
            // Capped so one listing cannot carry a megabyte of JSON.
            $specs[mb_substr(trim($key), 0, 40)] = mb_substr(trim($value), 0, 120);
            if (count($specs) >= 20) {
                break;
            }
        }
    }

    /* ---------- tags ---------- */
    $tags = [];
    if (is_array($body['tags'] ?? null)) {
        foreach ($body['tags'] as $tag) {
            if (!is_string($tag)) {
                continue;
            }
            $tag = mb_strtolower(trim($tag));
            if ($tag !== '' && mb_strlen($tag) <= 40 && !in_array($tag, $tags, true)) {
                $tags[] = $tag;
            }
            if (count($tags) >= 12) {
                break;
            }
        }
    }

    $images = [];
    if (is_array($body['images'] ?? null)) {
        foreach ($body['images'] as $img) {
            if (!is_string($img)) {
                continue;
            }
            $img = trim($img);
            if (preg_match('#^(uploads|images)/[A-Za-z0-9 ._()\-]+\.(jpe?g|png|webp|gif)$#i', $img)) {
                $images[] = $img;
            }
            if (count($images) >= 8) {
                break;
            }
        }
    }

    /* ---------- the scheduled sale (optional) ---------- */
    $sale = null;
    $saleIn = $body['sale'] ?? null;
    if (is_array($saleIn) && isset($saleIn['sale_price']) && is_numeric($saleIn['sale_price']) && (float) $saleIn['sale_price'] > 0) {
        $salePrice = (float) $saleIn['sale_price'];
        $start     = is_string($saleIn['start_date'] ?? null) ? trim($saleIn['start_date']) : '';
        $end       = is_string($saleIn['end_date'] ?? null) ? trim($saleIn['end_date']) : '';

        $saleErrors = [];
        if ($salePrice >= $price) {
            $saleErrors['sale_price'] = 'A sale price has to be below the normal price';
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)) {
            $saleErrors['start_date'] = 'Pick a start date';
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) {
            $saleErrors['end_date'] = 'Pick an end date';
        }
        if (!$saleErrors && $end < $start) {
            $saleErrors['end_date'] = 'The sale cannot end before it starts';
        }
        if ($saleErrors) {
            fail_validation($saleErrors, 'Please check the sale dates.');
        }

        $sale = ['price' => $salePrice, 'start' => $start, 'end' => $end];
    }

    if ($icon === '' || !preg_match('/^[a-z]{3,20}$/', $icon)) {
        $icon = icon_for_category($category);
    }

    $publicId = db_transaction(function (PDO $pdo) use (
        $existing, $sellerId, $title, $subtitle, $category, $icon, $desc,
        $price, $oldPrice, $stock, $specs, $tags, $images, $sale
    ): string {
        $specsJson = $specs ? json_encode($specs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;

        if ($existing === null) {
            /* ---------- a new listing: always 'pending' ---------- */
            $publicId = next_product_public_id($pdo);

            $pdo->prepare(
                "INSERT INTO products
                    (public_id, seller_id, title, subtitle, category, icon, description,
                     price, old_price, currency, stock, specs, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'LKR', ?, ?, 'pending')"
            )->execute([
                $publicId, $sellerId, $title, $subtitle === '' ? null : $subtitle,
                $category, $icon, $desc === '' ? null : $desc,
                $price, $oldPrice, $stock, $specsJson,
            ]);

            $productId = (int) $pdo->lastInsertId();
        } else {
            $productId = (int) $existing['id'];

            $status = $existing['status'] === 'rejected' ? 'pending' : $existing['status'];
            $reason = $existing['status'] === 'rejected' ? null : $existing['rejection_reason'];

            $pdo->prepare(
                'UPDATE products
                    SET title = ?, subtitle = ?, category = ?, icon = ?, description = ?,
                        price = ?, old_price = ?, stock = ?, specs = ?,
                        status = ?, rejection_reason = ?
                  WHERE id = ? AND seller_id = ?'
            )->execute([
                $title, $subtitle === '' ? null : $subtitle, $category, $icon,
                $desc === '' ? null : $desc, $price, $oldPrice, $stock, $specsJson,
                $status, $reason,
                // seller_id in the WHERE as well as the id: even here, the
                // statement cannot touch another seller's row.
                $productId, $sellerId,
            ]);

            $publicId = $existing['public_id'];
        }

        /* ---------- images: replace the set ---------- */
        if (is_array($images)) {
            $pdo->prepare('DELETE FROM product_images WHERE product_id = ?')->execute([$productId]);
            $insImg = $pdo->prepare(
                'INSERT INTO product_images (product_id, image_url, sort_order) VALUES (?, ?, ?)'
            );
            foreach ($images as $i => $url) {
                $insImg->execute([$productId, $url, $i]);
            }
        }

        /* ---------- tags: replace the set ---------- */
        $pdo->prepare('DELETE FROM product_tags WHERE product_id = ?')->execute([$productId]);
        if ($tags) {
            $insTag = $pdo->prepare('INSERT INTO product_tags (product_id, tag) VALUES (?, ?)');
            foreach ($tags as $tag) {
                $insTag->execute([$productId, $tag]);
            }
        }

        $pdo->prepare('DELETE FROM product_sales WHERE product_id = ? AND end_date >= CURDATE()')
            ->execute([$productId]);

        if ($sale !== null) {
            $pdo->prepare(
                'INSERT INTO product_sales (product_id, sale_price, start_date, end_date)
                 VALUES (?, ?, ?, ?)'
            )->execute([$productId, $sale['price'], $sale['start'], $sale['end']]);
        }

        return $publicId;
    });

    $saved = seller_find_product_shaped($sellerId, $publicId);

    app_log($existing === null ? 'Listing created' : 'Listing updated', [
        'seller'  => $sellerId,
        'product' => $publicId,
    ]);

    json_ok([
        'product' => $saved,
        'created' => $existing === null,
        'message' => $existing === null
            ? 'Listing created. It goes live once an admin approves it.'
            : (($existing['status'] ?? '') === 'rejected'
                ? 'Listing updated and sent back for review.'
                : 'Listing updated.'),
    ], $existing === null ? 201 : 200);
} catch (Throwable $e) {
    app_log('seller/product_save.php failed: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    json_fail('Could not save that listing right now. Please try again.', 'SERVER', 500);
}
