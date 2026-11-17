<?php
declare(strict_types=1);

require_once __DIR__ . '/../catalog.php';
require_once __DIR__ . '/../orders/_orders.php';

function require_seller(): array
{
    $user = require_role('seller');

    $stmt = db()->prepare(
        'SELECT id, public_id, business_name, business_type, registration_no, city, district,
                primary_category, bank_name, account_number, branch,
                kyc_status, kyc_rejection_reason, kyc_reviewed_at,
                commission_rate, rating, created_at
           FROM sellers
          WHERE id = ?'
    );
    $stmt->execute([(int) $user['id']]);
    $seller = $stmt->fetch();

    if (!$seller) {
        app_log('Seller account with no sellers row', ['user' => $user['id']]);
        json_fail('Your seller profile is incomplete. Please contact support.', 'FORBIDDEN', 403);
    }

    return ['user' => $user, 'seller' => $seller];
}

function require_verified_seller(): array
{
    $me = require_seller();
    $status = $me['seller']['kyc_status'];

    if ($status === 'verified') {
        return $me;
    }

    json_fail(
        $status === 'rejected'
            ? 'Your seller application was not approved, so listings cannot be published. '
              . 'The reason is on your dashboard — you can correct it and ask for another review.'
            : 'Your seller account is still being verified. You can set your shop up now, '
              . 'but listings go live once an admin approves your application.',
        'FORBIDDEN',
        403
    );
}

function seller_product_select(): string
{
    return 'p.id, p.public_id, p.seller_id, p.title, p.subtitle, p.category, p.icon,
            p.description, p.price, p.old_price, p.currency, p.stock, p.specs,
            p.badge, p.status, p.rejection_reason, p.reviewed_at,
            p.rating, p.review_count, p.created_at, p.updated_at';
}

function seller_sales_for(array $productIds): array
{
    if (!$productIds) {
        return [];
    }
    $in = implode(',', array_fill(0, count($productIds), '?'));

    $stmt = db()->prepare(
        "SELECT id, product_id, sale_price, start_date, end_date,
                (CURDATE() BETWEEN start_date AND end_date) AS is_active
           FROM product_sales
          WHERE product_id IN ($in)
            AND end_date >= CURDATE()
       ORDER BY product_id, is_active DESC, start_date"
    );
    $stmt->execute(array_map('intval', $productIds));

    $out = [];
    foreach ($stmt as $row) {
        $pid = (int) $row['product_id'];
        if (isset($out[$pid])) {
            continue;                       // the first row per product is the one we want
        }
        $out[$pid] = [
            'id'         => (int) $row['id'],
            'sale_price' => (float) $row['sale_price'],
            'start_date' => $row['start_date'],
            'end_date'   => $row['end_date'],
            'active'     => (bool) $row['is_active'],
        ];
    }
    return $out;
}

function seller_product_shape(array $row, array $images = [], array $tags = [], ?array $sale = null): array
{
    return [
        'id'       => $row['public_id'],
        'db_id'    => (int) $row['id'],
        'seller_id' => (int) $row['seller_id'],

        'title'       => $row['title'],
        'subtitle'    => $row['subtitle'] ?? '',
        'category'    => $row['category'],
        'icon'        => $row['icon'] ?? 'laptop',
        'description' => $row['description'] ?? '',

        'price'     => (float) $row['price'],
        'old_price' => $row['old_price'] === null ? null : (float) $row['old_price'],
        'currency'  => $row['currency'],
        'stock'     => (int) $row['stock'],

        'specs'  => decode_json_object($row['specs']),
        'tags'   => $tags,
        'images' => $images,
        'badge'  => $row['badge'],

        // What the seller is allowed to know about their own listing.
        'status'           => $row['status'],
        'rejection_reason' => $row['rejection_reason'],
        'reviewed_at'      => $row['reviewed_at'],

        'rating'       => (float) $row['rating'],
        'review_count' => (int) $row['review_count'],

        'sale' => $sale,

        'created_at' => $row['created_at'],
        'updated_at' => $row['updated_at'],
    ];
}

function seller_products(int $sellerId): array
{
    $stmt = db()->prepare(
        'SELECT ' . seller_product_select() . '
           FROM products p
          WHERE p.seller_id = ? AND p.deleted_at IS NULL
       ORDER BY p.created_at DESC, p.id DESC'
    );
    $stmt->execute([$sellerId]);
    $rows = $stmt->fetchAll();

    if (!$rows) {
        return [];
    }

    $ids    = array_map(static fn(array $r): int => (int) $r['id'], $rows);
    $images = product_images_for($ids);
    $tags   = product_tags_for($ids);
    $sales  = seller_sales_for($ids);

    return array_map(
        static fn(array $r): array => seller_product_shape(
            $r,
            $images[(int) $r['id']] ?? [],
            $tags[(int) $r['id']] ?? [],
            $sales[(int) $r['id']] ?? null
        ),
        $rows
    );
}

function seller_find_product(int $sellerId, string $id): ?array
{
    $stmt = db()->prepare(
        'SELECT ' . seller_product_select() . '
           FROM products p
          WHERE (p.public_id = ? OR p.id = ?)
            AND p.seller_id = ?
            AND p.deleted_at IS NULL
          LIMIT 1'
    );
    $stmt->execute([$id, ctype_digit($id) ? (int) $id : 0, $sellerId]);
    $row = $stmt->fetch();

    return $row === false ? null : $row;
}

/** The same, shaped for the client (one extra pair of lookups). */
function seller_find_product_shaped(int $sellerId, string $id): ?array
{
    $row = seller_find_product($sellerId, $id);
    if ($row === null) {
        return null;
    }
    $pid = (int) $row['id'];

    return seller_product_shape(
        $row,
        product_images_for([$pid])[$pid] ?? [],
        product_tags_for([$pid])[$pid] ?? [],
        seller_sales_for([$pid])[$pid] ?? null
    );
}

function next_product_public_id(PDO $pdo): string
{
    $max = (int) $pdo->query(
        "SELECT COALESCE(MAX(CAST(SUBSTRING(public_id, 2) AS UNSIGNED)), 0)
           FROM products
          WHERE public_id REGEXP '^p[0-9]+$'"
    )->fetchColumn();

    return 'p' . ($max + 1);
}

function seller_orders(int $sellerId, int $limit = 500): array
{
    $stmt = db()->prepare(
        "SELECT so.id, so.order_id, so.seller_id, so.status, so.subtotal, so.delivery_fee,
                so.commission_rate, so.tracking_number, so.courier_name,
                so.status_updated_at, so.delivered_at, so.payout_released_at, so.refunded_at,
                so.dispute_reason, so.dispute_opened_by, so.dispute_opened_at,
                so.dispute_resolution, so.dispute_resolved_at,
                o.created_at  AS placed_at,
                o.user_id,
                o.shipping_name, o.shipping_phone, o.shipping_address,
                o.shipping_city, o.shipping_zip, o.shipping_note
           FROM sub_orders so
           JOIN orders o ON o.id = so.order_id
          WHERE so.seller_id = ?
       ORDER BY o.created_at DESC, so.id DESC
          LIMIT " . (int) $limit
    );
    $stmt->execute([$sellerId]);
    $rows = $stmt->fetchAll();

    if (!$rows) {
        return [];
    }

    $subIds = array_map(static fn(array $r): int => (int) $r['id'], $rows);
    $items  = order_items_for($subIds);
    $logs   = order_timelines_for($subIds);

    $out = [];
    foreach ($rows as $row) {
        $id       = (int) $row['id'];
        $subtotal = (float) $row['subtotal'];
        $delivery = (float) $row['delivery_fee'];

        $out[] = [
            'id'       => sub_order_ref($id),      // 'SO-000012' — what the UI prints
            'db_id'    => $id,
            // A STRING on purpose: the dashboard puts this in a data-key
            // attribute, and el.dataset.key always reads back as a string —
            // so an int here would make every `o.key === key` lookup fail.
            'key'      => (string) $id,
            'order_id' => order_ref((int) $row['order_id']),
            'user_id'  => (int) $row['user_id'],

            'status'            => $row['status'],
            'status_updated_at' => $row['status_updated_at'],
            'placed_at'         => $row['placed_at'],
            'delivered_at'      => $row['delivered_at'],
            'payout_released_at' => $row['payout_released_at'],
            'refunded_at'       => $row['refunded_at'],

            'subtotal'     => $subtotal,
            'delivery_fee' => $delivery,
            // `total` is the goods subtotal: it is what commission is taken
            // on and what the dashboard's revenue figures mean. Delivery is
            // the courier's, not the seller's revenue.
            'total'        => $subtotal,
            'grand_total'  => round($subtotal + $delivery, 2),

            'tracking_number' => $row['tracking_number'],
            'courier_name'    => $row['courier_name'],

            'payout' => order_payout($row),

            // Enough to pack and ship. No email, no payment details.
            'shipping' => [
                'name'    => $row['shipping_name'],
                'phone'   => $row['shipping_phone'],
                'address' => $row['shipping_address'],
                'city'    => $row['shipping_city'],
                'zip'     => $row['shipping_zip'],
                'note'    => $row['shipping_note'],
            ],

            'items'      => $items[$id] ?? [],
            'status_log' => $logs[$id] ?? [],

            'dispute' => $row['dispute_opened_at'] === null ? null : [
                'reason'      => $row['dispute_reason'],
                'opened_by'   => $row['dispute_opened_by'],
                'opened_at'   => $row['dispute_opened_at'],
                'resolution'  => $row['dispute_resolution'],
                'resolved_at' => $row['dispute_resolved_at'],
            ],

            // What the SELLER may do next, from the same role map the write
            // endpoint enforces — so a button exists only if the click works.
            'can' => [
                'pack'   => order_can_transition('seller', $row['status'], 'packed'),
                'ship'   => order_can_transition('seller', $row['status'], 'shipped'),
                'cancel' => order_can_transition('seller', $row['status'], 'cancelled'),
            ],
        ];
    }

    return $out;
}

function seller_lock_sub_order(PDO $pdo, int $sellerId, int $subOrderId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT id, order_id, seller_id, status
           FROM sub_orders
          WHERE id = ? AND seller_id = ?
          LIMIT 1
            FOR UPDATE'
    );
    $stmt->execute([$subOrderId, $sellerId]);
    $row = $stmt->fetch();

    return $row === false ? null : $row;
}

/** The categories the platform currently sells, from settings. */
function platform_categories(): array
{
    $categories = setting('categories', null);

    if (is_array($categories)) {
        $clean = array_values(array_filter($categories, 'is_string'));
        if ($clean) {
            return $clean;
        }
    }

    return ['laptop', 'phone', 'audio', 'appliance', 'camera'];
}

/** The icon the storefront draws when a listing has no image yet. */
function icon_for_category(string $category): string
{
    return [
        'laptop'    => 'laptop',
        'phone'     => 'phone',
        'audio'     => 'headphones',
        'appliance' => 'fridge',
        'camera'    => 'camera',
    ][$category] ?? 'laptop';
}
