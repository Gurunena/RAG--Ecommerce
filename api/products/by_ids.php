<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../catalog.php';

/** More than this in one request is not a product card list, it is a scrape. */
const BY_IDS_MAX = 24;

try {
    require_method('GET');

    $raw = query_str('ids');
    if ($raw === '') {
        json_fail('No product ids were given.', 'VALIDATION', 400);
    }

    /* Split, trim, drop blanks and duplicates, keep the caller's order. */
    $ids = [];
    foreach (explode(',', $raw) as $piece) {
        $piece = trim($piece);
        if ($piece === '' || in_array($piece, $ids, true)) {
            continue;
        }
        // Shaped like a public id ('p12') or a bare number. Anything else is
        // dropped rather than queried — these arrive from an AI response, so
        // they are not to be trusted as well-formed.
        if (!preg_match('/^p?\d{1,9}$/i', $piece)) {
            continue;
        }
        $ids[] = $piece;
        if (count($ids) >= BY_IDS_MAX) {
            break;
        }
    }

    if (!$ids) {
        json_ok(['items' => [], 'missing' => [], 'total' => 0]);
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $numeric      = array_map(static fn(string $v): int => ctype_digit($v) ? (int) $v : 0, $ids);

    $stmt = db()->prepare(
        product_base_sql() . "
          WHERE (p.public_id IN ($placeholders) OR p.id IN ($placeholders))
            AND " . product_public_where()
    );
    $stmt->execute(array_merge($ids, $numeric));
    $rows = $stmt->fetchAll();

    $shaped = shape_products($rows);

    $byId = [];
    foreach ($shaped as $product) {
        $byId[$product['id']] = $product;
        $byId[(string) $product['dbId']] = $product;
    }

    $items   = [];
    $missing = [];
    foreach ($ids as $id) {
        if (isset($byId[$id])) {
            $items[] = $byId[$id];
        } else {
            // Said out loud rather than silently dropped: the chat logs it,
            // which is how a drifted products.json gets noticed.
            $missing[] = $id;
        }
    }

    json_ok([
        'items' => $items,
        // Ids the caller asked for that are not buyable right now — taken
        // down, sold out of the catalogue, or never existed.
        'missing' => $missing,
        'total'   => count($items),
    ]);
} catch (Throwable $e) {
    app_log('products/by_ids.php failed: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    json_fail('Could not load those products right now. Please try again.', 'SERVER', 500);
}
