<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/_seller.php';

try {
    require_method('POST');
    csrf_check();

    $me       = require_verified_seller();
    $sellerId = (int) $me['seller']['id'];

    $body = json_body();

    /* ---------- which listings? ---------- */
    $ids = [];
    if (is_array($body['ids'] ?? null)) {
        foreach ($body['ids'] as $raw) {
            if (is_string($raw) || is_int($raw)) {
                $ids[] = (string) $raw;
            }
            if (count($ids) >= 200) {
                break;
            }
        }
    } elseif (body_str('id') !== '') {
        $ids[] = body_str('id');
    }

    if (!$ids) {
        json_fail('No listing was specified.', 'VALIDATION', 400);
    }

    $resolved = [];
    $unknown  = [];
    foreach (array_unique($ids) as $raw) {
        $row = seller_find_product($sellerId, $raw);
        if ($row === null) {
            $unknown[] = $raw;
        } else {
            $resolved[] = $row;
        }
    }

    if (!$resolved) {
        json_fail('That listing could not be found.', 'NOT_FOUND', 404);
    }

    /* ---------- absolute, or relative? ---------- */
    $hasStock = isset($body['stock']) && is_numeric($body['stock']);
    $hasDelta = isset($body['delta']) && is_numeric($body['delta']);

    if (!$hasStock && !$hasDelta) {
        fail_validation(['stock' => 'Enter a stock quantity']);
    }
    if ($hasDelta && count($resolved) > 1) {
        json_fail('A stock adjustment applies to one listing at a time.', 'VALIDATION', 400);
    }

    if ($hasStock) {
        $target = (int) $body['stock'];
        if ($target < 0) {
            fail_validation(['stock' => 'Stock cannot be negative']);
        }
        if ($target > 1000000) {
            fail_validation(['stock' => 'That stock quantity is too large']);
        }

        db_transaction(function (PDO $pdo) use ($resolved, $target, $sellerId): void {
            $stmt = $pdo->prepare('UPDATE products SET stock = ? WHERE id = ? AND seller_id = ?');
            foreach ($resolved as $row) {
                // seller_id in the WHERE as well as the id, so even here the
                // statement cannot reach another seller's row.
                $stmt->execute([$target, (int) $row['id'], $sellerId]);
            }
        });
    } else {
        $delta = (int) $body['delta'];
        if ($delta === 0) {
            json_fail('Nothing to change.', 'VALIDATION', 400);
        }
        if (abs($delta) > 100000) {
            json_fail('That adjustment is too large.', 'VALIDATION', 400);
        }

        // Computed by the database, so concurrent clicks both land.
        db()->prepare(
            'UPDATE products
                SET stock = GREATEST(0, LEAST(1000000, stock + ?))
              WHERE id = ? AND seller_id = ?'
        )->execute([$delta, (int) $resolved[0]['id'], $sellerId]);
    }

    $updated = array_values(array_filter(array_map(
        static fn(array $row): ?array => seller_find_product_shaped($sellerId, $row['public_id']),
        $resolved
    )));

    $message = count($updated) === 1
        ? sprintf('%s: stock set to %d', $updated[0]['title'], $updated[0]['stock'])
        : sprintf('Stock updated on %d listings', count($updated));

    $payload = [
        'products' => $updated,
        'message'  => $message,
    ];
    if ($unknown) {
        // Said out loud rather than dropped: a bulk edit that half-worked
        // must not look like a full success.
        $payload['skipped'] = $unknown;
        $payload['message'] .= sprintf(' · %d could not be found', count($unknown));
    }

    json_ok($payload);
} catch (Throwable $e) {
    app_log('seller/stock.php failed: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    json_fail('Could not update stock right now. Please try again.', 'SERVER', 500);
}
