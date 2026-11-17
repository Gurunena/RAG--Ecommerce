<?php
declare(strict_types=1);

require_once __DIR__ . '/../catalog.php';

function cart_state(int $userId): array
{
    $notices = [];

    /* ---------- drop lines whose product is no longer buyable ---------- */
    $stale = db()->prepare(
        'SELECT ci.product_id, p.title
           FROM cart_items ci
           JOIN products p ON p.id = ci.product_id
          WHERE ci.user_id = ?
            AND NOT (' . product_public_where() . ')'
    );
    $stale->execute([$userId]);
    $staleRows = $stale->fetchAll();

    if ($staleRows) {
        $ids = array_map(static fn(array $r): int => (int) $r['product_id'], $staleRows);
        $in  = implode(',', array_fill(0, count($ids), '?'));
        db()->prepare("DELETE FROM cart_items WHERE user_id = ? AND product_id IN ($in)")
            ->execute(array_merge([$userId], $ids));

        foreach ($staleRows as $r) {
            $notices[] = sprintf('%s is no longer available and was removed from your cart.', $r['title']);
        }
    }

    /* ---------- read what is left ---------- */
    $stmt = db()->prepare(
        'SELECT ' . product_select_list() . ', ci.quantity AS cart_qty
           FROM cart_items ci
           JOIN products p ON p.id = ci.product_id
           JOIN sellers  s ON s.id = p.seller_id
          WHERE ci.user_id = ?
            AND ' . product_public_where() . '
       ORDER BY s.business_name, ci.added_at, ci.id'
    );
    $stmt->execute([$userId]);
    $rows = $stmt->fetchAll();

    /* ---------- clamp quantities to what is actually in stock ---------- */
    $lines = [];
    $ids    = array_map(static fn(array $r): int => (int) $r['id'], $rows);
    $images = product_images_for($ids);
    $tags   = product_tags_for($ids);

    foreach ($rows as $row) {
        $productId = (int) $row['id'];
        $stock     = (int) $row['stock'];
        $qty       = (int) $row['cart_qty'];

        if ($stock <= 0) {
            db()->prepare('DELETE FROM cart_items WHERE user_id = ? AND product_id = ?')
                ->execute([$userId, $productId]);
            $notices[] = sprintf('%s has sold out and was removed from your cart.', $row['title']);
            continue;
        }

        if ($qty > $stock) {
            db()->prepare('UPDATE cart_items SET quantity = ? WHERE user_id = ? AND product_id = ?')
                ->execute([$stock, $userId, $productId]);
            $notices[] = sprintf('Only %d of %s left — your quantity was reduced.', $stock, $row['title']);
            $qty = $stock;
        }

        $lines[] = [
            'product' => shape_product($row, $images[$productId] ?? [], $tags[$productId] ?? []),
            'qty'     => $qty,
        ];
    }

    return ['lines' => $lines, 'notices' => $notices];
}

function cart_payload(int $userId): array
{
    $state = cart_state($userId);

    $subtotal = 0.0;
    $count    = 0;
    $sellers  = [];

    foreach ($state['lines'] as $line) {
        $subtotal += $line['product']['price'] * $line['qty'];
        $count    += $line['qty'];
        $sellers[$line['product']['sellerId']] = true;
    }

    $sellerCount = count($sellers);
    $delivery    = $sellerCount * 450;

    return [
        'lines'    => $state['lines'],
        'notices'  => $state['notices'],
        'count'    => $count,
        'sellers'  => $sellerCount,
        'subtotal' => round($subtotal, 2),
        'delivery' => $delivery,
        'total'    => round($subtotal + $delivery, 2),
    ];
}

function cart_require_product(string $publicId): array
{
    if ($publicId === '') {
        json_fail('No product was specified.', 'VALIDATION', 400);
    }
    $row = find_product($publicId, true);
    if ($row === null) {
        json_fail('That product is no longer available.', 'NOT_FOUND', 404);
    }
    return $row;
}
