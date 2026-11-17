<?php
declare(strict_types=1);

require_once __DIR__ . '/../catalog.php';
require_once __DIR__ . '/../orders/_orders.php';

function require_admin(): array
{
    return require_role('admin');
}

class AdminOrderProblem extends RuntimeException
{
}

const ADMIN_TARGETS = [
    'user', 'seller', 'product', 'order', 'sub_order', 'faq', 'settings', 'risk_alert',
];

function admin_log(
    PDO $pdo,
    int $adminId,
    string $actionType,
    string $targetType,
    ?int $targetId = null,
    ?string $notes = null
): void {
    if (!in_array($targetType, ADMIN_TARGETS, true)) {
        // A typo here would be an un-inserted audit row, which is the one
        // failure this system must not shrug off.
        throw new InvalidArgumentException('Unknown admin_actions target type: ' . $targetType);
    }

    $pdo->prepare(
        'INSERT INTO admin_actions (admin_id, action_type, target_type, target_id, notes)
         VALUES (?, ?, ?, ?, ?)'
    )->execute([
        $adminId,
        mb_substr($actionType, 0, 60),
        $targetType,
        $targetId,
        $notes === null || $notes === '' ? null : mb_substr($notes, 0, 500),
    ]);
}

function admin_recent_actions(int $limit = 40): array
{
    $limit = max(1, min($limit, 200));

    $stmt = db()->prepare(
        "SELECT a.id, a.action_type, a.target_type, a.target_id, a.notes, a.created_at,
                admin.name AS admin_name,
                COALESCE(
                    tu.name,
                    ts.business_name,
                    tp.title,
                    tf.question
                ) AS target_name
           FROM admin_actions a
           JOIN users admin ON admin.id = a.admin_id
           LEFT JOIN users    tu ON a.target_type IN ('user','seller')  AND tu.id = a.target_id
           LEFT JOIN sellers  ts ON a.target_type = 'seller'            AND ts.id = a.target_id
           LEFT JOIN products tp ON a.target_type = 'product'           AND tp.id = a.target_id
           LEFT JOIN faqs     tf ON a.target_type = 'faq'               AND tf.id = a.target_id
          WHERE 1
       ORDER BY a.created_at DESC, a.id DESC
          LIMIT " . $limit
    );
    $stmt->execute();

    return array_map(static fn(array $r): array => [
        'id'          => (int) $r['id'],
        'action'      => $r['action_type'],
        'target_type' => $r['target_type'],
        'target_id'   => $r['target_id'] === null ? null : (int) $r['target_id'],
        'target_name' => $r['target_name'],
        'notes'       => $r['notes'],
        'admin_name'  => $r['admin_name'],
        'created_at'  => $r['created_at'],
    ], $stmt->fetchAll());
}

function admin_user_shape(array $row): array
{
    $user = [
        'id'         => (int) $row['id'],
        'name'       => $row['name'],
        'email'      => $row['email'],
        'phone'      => $row['phone'],
        'role'       => $row['role'],
        'status'     => $row['status'],
        'avatar_url' => $row['avatar_url'],
        'created_at' => $row['created_at'],
        'last_login_at' => $row['last_login_at'] ?? null,
        'seller'     => null,
    ];

    if ($row['role'] !== 'seller' || !isset($row['business_name'])) {
        return $user;
    }

    $user['seller'] = [
        'public_id'        => $row['public_id'],
        'business_name'    => $row['business_name'],
        'business_type'    => $row['business_type'],
        'registration_no'  => $row['registration_no'],
        'city'             => $row['city'],
        'district'         => $row['district'],
        'primary_category' => $row['primary_category'],
        // Identity evidence, for the verification queue. Admin-only.
        'nic_number'       => $row['nic_number'],
        'bank_name'        => $row['bank_name'],
        'account_number'   => $row['account_number'],
        'branch'           => $row['branch'],
        'kyc_status'       => $row['kyc_status'],
        'kyc_document_url' => $row['kyc_document_url'],
        'kyc_rejection_reason' => $row['kyc_rejection_reason'],
        'kyc_reviewed_at'  => $row['kyc_reviewed_at'],
        'commission_rate'  => $row['commission_rate'] === null ? null : (float) $row['commission_rate'],
        'rating'           => (float) $row['rating'],
        'verified'         => $row['kyc_status'] === 'verified',
    ];

    return $user;
}

/** The SELECT every admin user query starts from. */
function admin_user_sql(): string
{
    return 'SELECT u.id, u.name, u.email, u.phone, u.role, u.status, u.avatar_url,
                   u.created_at, u.last_login_at,
                   s.public_id, s.business_name, s.business_type, s.registration_no,
                   s.city, s.district, s.primary_category, s.nic_number,
                   s.bank_name, s.account_number, s.branch,
                   s.kyc_status, s.kyc_document_url, s.kyc_rejection_reason,
                   s.kyc_reviewed_at, s.commission_rate, s.rating
              FROM users u
              LEFT JOIN sellers s ON s.id = u.id';
}

/** One user by id, shaped — or null. */
function admin_find_user(int $id): ?array
{
    $stmt = db()->prepare(admin_user_sql() . ' WHERE u.id = ? LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch();

    return $row === false ? null : admin_user_shape($row);
}

function admin_products(?string $status = null, int $limit = 500): array
{
    $where = 'WHERE p.deleted_at IS NULL';
    $args  = [];

    if ($status !== null && $status !== '' && $status !== 'all') {
        $where .= ' AND p.status = ?';
        $args[] = $status;
    }

    $stmt = db()->prepare(
        'SELECT p.id, p.public_id, p.seller_id, p.title, p.subtitle, p.category, p.icon,
                p.description, p.price, p.old_price, p.currency, p.stock, p.specs,
                p.badge, p.status, p.rejection_reason, p.reviewed_at, p.reviewed_by,
                p.rating, p.review_count, p.created_at, p.updated_at,
                s.public_id     AS seller_public_id,
                s.business_name AS seller_name,
                s.city          AS seller_city,
                s.rating        AS seller_rating,
                (s.kyc_status = \'verified\') AS seller_verified,
                reviewer.name   AS reviewed_by_name,
                ' . SALE_PRICE_SQL . ' AS sale_price
           FROM products p
           JOIN sellers s ON s.id = p.seller_id
           LEFT JOIN users reviewer ON reviewer.id = p.reviewed_by
           ' . $where . '
       ORDER BY FIELD(p.status, \'pending\', \'rejected\', \'active\', \'inactive\'),
                p.created_at DESC
          LIMIT ' . (int) $limit
    );
    $stmt->execute($args);
    $rows = $stmt->fetchAll();

    if (!$rows) {
        return [];
    }

    $ids    = array_map(static fn(array $r): int => (int) $r['id'], $rows);
    $images = product_images_for($ids);
    $tags   = product_tags_for($ids);

    return array_map(static function (array $r) use ($images, $tags): array {
        $pid  = (int) $r['id'];
        $sale = $r['sale_price'] === null ? null : (float) $r['sale_price'];

        return [
            'id'        => $r['public_id'],
            'db_id'     => $pid,
            'seller_id' => (int) $r['seller_id'],

            'title'       => $r['title'],
            'subtitle'    => $r['subtitle'] ?? '',
            'category'    => $r['category'],
            'icon'        => $r['icon'] ?? 'laptop',
            'description' => $r['description'] ?? '',

            'price'     => (float) $r['price'],
            'old_price' => $r['old_price'] === null ? null : (float) $r['old_price'],
            'currency'  => $r['currency'],
            'stock'     => (int) $r['stock'],

            'specs'  => decode_json_object($r['specs']),
            'tags'   => $tags[$pid] ?? [],
            'images' => $images[$pid] ?? [],
            'badge'  => $r['badge'],

            'status'           => $r['status'],
            'rejection_reason' => $r['rejection_reason'],
            'reviewed_at'      => $r['reviewed_at'],
            'reviewed_by'      => $r['reviewed_by_name'],

            'rating'       => (float) $r['rating'],
            'review_count' => (int) $r['review_count'],

            // Whether a sale is running today, so a take-down decision is
            // made knowing the listing is currently discounted.
            'sale_price' => $sale,
            'on_sale'    => $sale !== null,

            'seller' => [
                'id'       => $r['seller_public_id'] ?? ('sid-' . (int) $r['seller_id']),
                'name'     => $r['seller_name'],
                'city'     => $r['seller_city'] ?? '',
                'rating'   => (float) $r['seller_rating'],
                'verified' => (bool) $r['seller_verified'],
            ],

            'created_at' => $r['created_at'],
            'updated_at' => $r['updated_at'],
        ];
    }, $rows);
}

function admin_products_one(string $publicId): ?array
{
    foreach (admin_products(null, 1000) as $p) {
        if ($p['id'] === $publicId) {
            return $p;
        }
    }
    return null;
}

/** One listing by public or numeric id, any status — or null. */
function admin_find_product(string $id): ?array
{
    $stmt = db()->prepare(
        'SELECT id, public_id, seller_id, title, status, rejection_reason
           FROM products
          WHERE (public_id = ? OR id = ?) AND deleted_at IS NULL
          LIMIT 1'
    );
    $stmt->execute([$id, ctype_digit($id) ? (int) $id : 0]);
    $row = $stmt->fetch();

    return $row === false ? null : $row;
}

function admin_orders(int $limit = 500): array
{
    $stmt = db()->prepare(
        "SELECT so.id, so.order_id, so.seller_id, so.status, so.subtotal, so.delivery_fee,
                so.commission_rate, so.tracking_number, so.courier_name,
                so.status_updated_at, so.delivered_at, so.payout_released_at, so.refunded_at,
                so.dispute_reason, so.dispute_opened_by, so.dispute_opened_at,
                so.dispute_resolution, so.dispute_resolved_at,
                o.created_at AS placed_at,
                o.user_id, o.payment_status,
                o.shipping_name, o.shipping_phone, o.shipping_address,
                o.shipping_city, o.shipping_zip, o.shipping_note,
                buyer.name  AS buyer_name,
                buyer.email AS buyer_email,
                s.public_id     AS seller_public_id,
                s.business_name AS seller_name
           FROM sub_orders so
           JOIN orders  o     ON o.id = so.order_id
           JOIN sellers s     ON s.id = so.seller_id
           LEFT JOIN users buyer ON buyer.id = o.user_id
       ORDER BY o.created_at DESC, so.id DESC
          LIMIT " . (int) $limit
    );
    $stmt->execute();
    $rows = $stmt->fetchAll();

    if (!$rows) {
        return [];
    }

    $subIds = array_map(static fn(array $r): int => (int) $r['id'], $rows);
    $items  = order_items_for($subIds);
    $logs   = order_timelines_for($subIds);

    return array_map(static function (array $r) use ($items, $logs): array {
        $id       = (int) $r['id'];
        $subtotal = (float) $r['subtotal'];

        return [
            'id'       => sub_order_ref($id),
            'db_id'    => $id,
            // A string: the console writes this into a data-key attribute,
            // which always reads back as a string.
            'key'      => (string) $id,
            'order_id' => order_ref((int) $r['order_id']),
            'order_db_id' => (int) $r['order_id'],

            'status'             => $r['status'],
            'status_updated_at'  => $r['status_updated_at'],
            'placed_at'          => $r['placed_at'],
            'delivered_at'       => $r['delivered_at'],
            'payout_released_at' => $r['payout_released_at'],
            'refunded_at'        => $r['refunded_at'],
            'payment_status'     => $r['payment_status'],

            'subtotal'     => $subtotal,
            'delivery_fee' => (float) $r['delivery_fee'],
            'total'        => $subtotal,
            'grand_total'  => round($subtotal + (float) $r['delivery_fee'], 2),

            'tracking_number' => $r['tracking_number'],
            'courier_name'    => $r['courier_name'],

            'payout' => order_payout($r),

            'seller_id'   => (int) $r['seller_id'],
            'seller_name' => $r['seller_name'],
            'seller'      => [
                'id'   => $r['seller_public_id'] ?? ('sid-' . (int) $r['seller_id']),
                'name' => $r['seller_name'],
            ],

            // An admin resolving a dispute needs to reach both parties.
            'user_id'    => $r['user_id'] === null ? null : (int) $r['user_id'],
            'buyer_name' => $r['buyer_name'],
            'buyer_email' => $r['buyer_email'],

            'shipping' => [
                'name'    => $r['shipping_name'],
                'phone'   => $r['shipping_phone'],
                'address' => $r['shipping_address'],
                'city'    => $r['shipping_city'],
                'zip'     => $r['shipping_zip'],
                'note'    => $r['shipping_note'],
            ],

            'items'      => $items[$id] ?? [],
            'status_log' => $logs[$id] ?? [],

            'dispute' => $r['dispute_opened_at'] === null ? null : [
                'reason'      => $r['dispute_reason'],
                'opened_by'   => $r['dispute_opened_by'],
                'opened_at'   => $r['dispute_opened_at'],
                'resolution'  => $r['dispute_resolution'],
                'resolved_at' => $r['dispute_resolved_at'],
            ],

            'can' => [
                'override' => (bool) (order_transition_map()[$r['status']] ?? []),
                'dispute'  => order_can_transition('admin', $r['status'], 'disputed'),
                'refund'   => order_can_transition('admin', $r['status'], 'refunded'),
                'release'  => order_can_transition('admin', $r['status'], 'delivered'),
                'next'     => array_values(order_transition_map()[$r['status']] ?? []),
            ],
        ];
    }, $rows);
}

function admin_lock_sub_order(PDO $pdo, int $subOrderId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT so.id, so.order_id, so.seller_id, so.status, so.subtotal, so.delivery_fee,
                so.refunded_at, so.payout_released_at
           FROM sub_orders so
          WHERE so.id = ?
          LIMIT 1
            FOR UPDATE'
    );
    $stmt->execute([$subOrderId]);
    $row = $stmt->fetch();

    return $row === false ? null : $row;
}

/** Every platform setting, decoded. */
function admin_settings(): array
{
    $rows = db()->query('SELECT setting_key, value, updated_at FROM settings')->fetchAll();

    $out = [];
    foreach ($rows as $row) {
        $out[$row['setting_key']] = json_decode((string) $row['value'], true);
    }

    // Defaults for anything a fresh database has not got yet, so the
    // settings form always has something to render.
    return array_merge([
        'commission_rate'       => 8,
        'categories'            => ['laptop', 'phone', 'audio', 'appliance', 'camera'],
        'risk_medium_at'        => 35,
        'risk_high_at'          => 65,
        'risk_rule_weight'      => 0.7,
        'risk_ml_weight'        => 0.3,
        'risk_hold_at_level'    => 'high',
        'risk_holds_enabled'    => true,
        'risk_llm_explain'      => true,
        'seller_signups_open'   => true,
        'customer_signups_open' => true,
    ], $out);
}

function admin_set_setting(PDO $pdo, string $key, $value, int $adminId): void
{
    $pdo->prepare(
        'INSERT INTO settings (setting_key, value, updated_by)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE value = VALUES(value), updated_by = VALUES(updated_by)'
    )->execute([
        $key,
        json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        $adminId,
    ]);

    setting_reset();

    if (function_exists('risk_config_reset')) {
        risk_config_reset();
    }
}
