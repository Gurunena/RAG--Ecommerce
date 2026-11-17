<?php
declare(strict_types=1);

require_once __DIR__ . '/../catalog.php';

const ORDER_FLOW     = ['pending', 'packed', 'shipped', 'delivered'];
const ORDER_TERMINAL = ['cancelled', 'disputed', 'refunded'];

function order_transition_map(): array
{
    return [
        'pending'   => ['packed', 'cancelled', 'disputed', 'refunded'],
        'packed'    => ['shipped', 'cancelled', 'disputed', 'refunded'],
        'shipped'   => ['delivered', 'disputed', 'cancelled', 'refunded'],
        'delivered' => ['disputed', 'refunded'],
        'cancelled' => ['refunded'],
        // An admin resolving a dispute can send it either way.
        'disputed'  => ['delivered', 'refunded', 'cancelled'],
        'refunded'  => [],
    ];
}

function order_role_map(): array
{
    return [
        'seller' => [
            'pending' => ['packed', 'cancelled'],
            'packed'  => ['shipped', 'cancelled'],
        ],
        'customer' => [
            // Cancel is only honest while nothing has been handed to a courier.
            'pending'   => ['cancelled'],
            'shipped'   => ['delivered', 'disputed'],
            'delivered' => ['disputed'],
        ],
        'admin' => order_transition_map(),
    ];
}

/** Is this move legal for this role? Pure predicate — nothing is written. */
function order_can_transition(string $role, string $from, string $to): bool
{
    $allowedAtAll = order_transition_map()[$from] ?? [];
    if (!in_array($to, $allowedAtAll, true)) {
        return false;
    }
    $forRole = order_role_map()[$role][$from] ?? [];
    return in_array($to, $forRole, true);
}

function order_assert_transition(string $role, string $from, string $to): void
{
    if ($from === $to) {
        json_fail('That order is already ' . $to . '.', 'VALIDATION', 409);
    }
    if (!in_array($to, array_merge(ORDER_FLOW, ORDER_TERMINAL), true)) {
        json_fail('That is not a valid order status.', 'VALIDATION', 422);
    }
    if (!order_can_transition($role, $from, $to)) {
        app_log('Illegal order transition refused', [
            'role' => $role,
            'from' => $from,
            'to'   => $to,
        ]);
        json_fail(
            sprintf('An order that is %s cannot be marked %s.', $from, $to),
            'VALIDATION',
            409
        );
    }
}

function order_customer_actions(string $status): array
{
    return [
        'confirm_delivery' => order_can_transition('customer', $status, 'delivered'),
        'cancel'           => order_can_transition('customer', $status, 'cancelled'),
        'dispute'          => order_can_transition('customer', $status, 'disputed'),
    ];
}

function order_log_status(
    PDO $pdo,
    int $subOrderId,
    string $status,
    string $actor,
    ?int $userId = null,
    ?string $note = null
): void {
    $pdo->prepare(
        'INSERT INTO order_status_log (sub_order_id, status, changed_by, changed_by_user_id, note)
         VALUES (?, ?, ?, ?, ?)'
    )->execute([$subOrderId, $status, $actor, $userId, $note]);
}

function order_apply_status(
    PDO $pdo,
    int $subOrderId,
    int $orderId,
    string $to,
    string $actor,
    ?int $userId = null,
    ?string $note = null
): void {
    $sets = ['status = ?', 'status_updated_at = NOW()'];
    $args = [$to];

    if ($to === 'delivered') {
        // Escrow release. COALESCE keeps the ORIGINAL timestamps if an admin
        // resolves a dispute back to delivered — the money was released the
        // first time, and rewriting the date would falsify the payout record.
        $sets[] = 'delivered_at = COALESCE(delivered_at, NOW())';
        $sets[] = 'payout_released_at = COALESCE(payout_released_at, NOW())';
    } elseif ($to === 'refunded') {
        $sets[] = 'refunded_at = NOW()';
    }

    $args[] = $subOrderId;
    $pdo->prepare('UPDATE sub_orders SET ' . implode(', ', $sets) . ' WHERE id = ?')
        ->execute($args);

    order_log_status($pdo, $subOrderId, $to, $actor, $userId, $note);
    order_sync_payment_escrow($pdo, $orderId);
}

function order_sync_payment_escrow(PDO $pdo, int $orderId): void
{
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) AS total,
                SUM(payout_released_at IS NOT NULL
                    OR status IN ('cancelled','refunded')) AS settled
           FROM sub_orders
          WHERE order_id = ?"
    );
    $stmt->execute([$orderId]);
    $row = $stmt->fetch();

    if (!$row || (int) $row['total'] === 0 || (int) $row['settled'] < (int) $row['total']) {
        return;
    }

    $pdo->prepare(
        'UPDATE payments
            SET escrow_released_at = COALESCE(escrow_released_at, NOW())
          WHERE order_id = ?'
    )->execute([$orderId]);
}

/** 'ORD-000012' / 'SO-000007' — what the customer quotes to support. */
function order_ref(int $id): string
{
    return 'ORD-' . str_pad((string) $id, 6, '0', STR_PAD_LEFT);
}

function sub_order_ref(int $id): string
{
    return 'SO-' . str_pad((string) $id, 6, '0', STR_PAD_LEFT);
}

function platform_commission_rate(): float
{
    $rate = setting('commission_rate', 8);

    // A non-numeric value in the table would otherwise price every future
    // order at 0; the default is safer than whatever is in there.
    return is_numeric($rate) ? (float) $rate : 8.0;
}

function order_payout(array $sub): array
{
    $gross      = (float) $sub['subtotal'];
    $rate       = (float) $sub['commission_rate'];
    $commission = round($gross * $rate / 100, 2);
    $net        = round($gross - $commission, 2);

    if ($sub['refunded_at'] !== null || $sub['status'] === 'refunded') {
        $state = 'refunded';
    } elseif ($sub['payout_released_at'] !== null) {
        $state = 'released';
    } elseif ($sub['status'] === 'cancelled') {
        $state = 'cancelled';
    } elseif ($sub['status'] === 'disputed') {
        $state = 'held';
    } else {
        $state = 'escrow';
    }

    return [
        'gross'       => $gross,
        'rate'        => $rate,
        'commission'  => $commission,
        'net'         => $net,
        'state'       => $state,
        'released_at' => $sub['payout_released_at'],
    ];
}

function order_sub_orders_for(array $orderIds, bool $includeTimeline = true): array
{
    if (!$orderIds) {
        return [];
    }
    $in   = implode(',', array_fill(0, count($orderIds), '?'));
    $args = array_map('intval', $orderIds);

    $stmt = db()->prepare(
        "SELECT so.id, so.order_id, so.seller_id, so.status, so.subtotal, so.delivery_fee,
                so.commission_rate, so.tracking_number, so.courier_name,
                so.status_updated_at, so.delivered_at, so.payout_released_at, so.refunded_at,
                so.dispute_reason, so.dispute_opened_by, so.dispute_opened_at,
                so.dispute_resolution, so.dispute_resolved_at,
                s.public_id     AS seller_public_id,
                s.business_name AS seller_name,
                s.city          AS seller_city,
                s.rating        AS seller_rating,
                (s.kyc_status = 'verified') AS seller_verified,
                o.created_at    AS placed_at
           FROM sub_orders so
           JOIN sellers s ON s.id = so.seller_id
           JOIN orders  o ON o.id = so.order_id
          WHERE so.order_id IN ($in)
       ORDER BY so.order_id DESC, so.id"
    );
    $stmt->execute($args);
    $subs = $stmt->fetchAll();

    if (!$subs) {
        return [];
    }

    $subIds = array_map(static fn(array $r): int => (int) $r['id'], $subs);
    $items  = order_items_for($subIds);
    $logs   = $includeTimeline ? order_timelines_for($subIds) : [];

    $out = [];
    foreach ($subs as $row) {
        $id       = (int) $row['id'];
        $subtotal = (float) $row['subtotal'];
        $delivery = (float) $row['delivery_fee'];
        $public   = $row['seller_public_id'];

        $shaped = [
            'id'        => $id,
            'ref'       => sub_order_ref($id),
            'order_id'  => (int) $row['order_id'],
            'order_ref' => order_ref((int) $row['order_id']),

            'status'            => $row['status'],
            'status_updated_at' => $row['status_updated_at'],
            'placed_at'         => $row['placed_at'],
            'delivered_at'      => $row['delivered_at'],

            'subtotal'     => $subtotal,
            'delivery_fee' => $delivery,
            'total'        => round($subtotal + $delivery, 2),

            'tracking_number' => $row['tracking_number'],
            'courier_name'    => $row['courier_name'],

            'payout' => order_payout($row),

            'seller' => [
                'id'       => $public !== null ? $public : 'sid-' . (int) $row['seller_id'],
                'name'     => $row['seller_name'],
                'city'     => $row['seller_city'] ?? '',
                'rating'   => (float) $row['seller_rating'],
                'verified' => (bool) $row['seller_verified'],
            ],

            'items' => $items[$id] ?? [],
            'can'   => order_customer_actions($row['status']),

            // Only present once something has actually been disputed, so the
            // UI can test one field instead of five.
            'dispute' => $row['dispute_opened_at'] === null ? null : [
                'reason'      => $row['dispute_reason'],
                'opened_by'   => $row['dispute_opened_by'],
                'opened_at'   => $row['dispute_opened_at'],
                'resolution'  => $row['dispute_resolution'],
                'resolved_at' => $row['dispute_resolved_at'],
            ],
        ];

        if ($includeTimeline) {
            $shaped['timeline'] = $logs[$id] ?? [];
        }

        $out[(int) $row['order_id']][] = $shaped;
    }

    return $out;
}

function order_items_for(array $subOrderIds): array
{
    if (!$subOrderIds) {
        return [];
    }
    $in = implode(',', array_fill(0, count($subOrderIds), '?'));

    $stmt = db()->prepare(
        "SELECT oi.id, oi.sub_order_id, oi.product_id, oi.product_title, oi.quantity, oi.unit_price,
                p.public_id, p.icon, p.category, p.status AS product_status, p.deleted_at
           FROM order_items oi
           JOIN products p ON p.id = oi.product_id
          WHERE oi.sub_order_id IN ($in)
       ORDER BY oi.sub_order_id, oi.id"
    );
    $stmt->execute(array_map('intval', $subOrderIds));
    $rows = $stmt->fetchAll();

    if (!$rows) {
        return [];
    }

    $images = product_images_for(array_map(static fn(array $r): int => (int) $r['product_id'], $rows));

    $out = [];
    foreach ($rows as $row) {
        $productId = (int) $row['product_id'];
        $qty       = (int) $row['quantity'];
        $unit      = (float) $row['unit_price'];
        $gallery   = $images[$productId] ?? [];

        $out[(int) $row['sub_order_id']][] = [
            'id'  => (int) $row['id'],
            'qty' => $qty,

            // Snapshot values — what was bought, at the price that was paid.
            'title'      => $row['product_title'],
            'unit_price' => $unit,
            'line_total' => round($unit * $qty, 2),

            // Enough of the live product for a thumbnail and a link. The
            // nested object is named `product` and uses `id`/`title`/`image`
            // so the existing line markup renders it unchanged.
            'product' => [
                'id'       => $row['public_id'],
                'title'    => $row['product_title'],
                'icon'     => $row['icon'],
                'category' => $row['category'],
                'image'    => $gallery[0] ?? null,
                'images'   => $gallery,
                'price'    => $unit,
                // False once a listing is taken down or soft-deleted, so the
                // UI can drop the link rather than send someone to a 404.
                'available' => $row['product_status'] === 'active' && $row['deleted_at'] === null,
            ],
        ];
    }

    return $out;
}

/** [subOrderId => [{status, changed_by, changed_at, note}, ...]] oldest first. */
function order_timelines_for(array $subOrderIds): array
{
    if (!$subOrderIds) {
        return [];
    }
    $in = implode(',', array_fill(0, count($subOrderIds), '?'));

    $stmt = db()->prepare(
        "SELECT sub_order_id, status, changed_by, note, changed_at
           FROM order_status_log
          WHERE sub_order_id IN ($in)
            AND internal = 0
            AND NOT (
                    changed_by = 'system'
                AND (note LIKE 'Flagged for review%'
                  OR note LIKE 'Reviewed by an admin%'
                  OR note LIKE 'Reviewed and cleared by an admin%')
            )
       ORDER BY sub_order_id, changed_at, id"
    );
    $stmt->execute(array_map('intval', $subOrderIds));

    $out = [];
    foreach ($stmt as $row) {
        $out[(int) $row['sub_order_id']][] = [
            'status' => $row['status'],
            // Deliberately the ROLE, not the person: one customer must never
            // learn another user's name from an order they can read.
            'changed_by' => $row['changed_by'],
            'note'       => $row['note'],
            'changed_at' => $row['changed_at'],
        ];
    }
    return $out;
}

function order_shape(array $order, array $subOrders): array
{
    $subtotal = 0.0;
    $delivery = 0.0;
    foreach ($subOrders as $sub) {
        $subtotal += $sub['subtotal'];
        $delivery += $sub['delivery_fee'];
    }

    return [
        'id'        => (int) $order['id'],
        'ref'       => order_ref((int) $order['id']),
        'placed_at' => $order['created_at'],

        'subtotal' => round($subtotal, 2),
        'delivery' => round($delivery, 2),
        'total'    => (float) $order['total_amount'],
        'currency' => $order['currency'],

        'payment_status' => $order['payment_status'],
        'payment'        => [
            'provider'           => $order['payment_provider'] ?? 'demo',
            'reference'          => $order['payment_ref'] ?? null,
            'status'             => $order['payment_state'] ?? null,
            'captured_at'        => $order['payment_captured_at'] ?? null,
            'escrow_released_at' => $order['payment_escrow_released_at'] ?? null,
            // The one flag the UI reads to print "demo payment".
            'demo'               => ($order['payment_provider'] ?? 'demo') === 'demo',
        ],

        'shipping' => [
            'name'    => $order['shipping_name'],
            'phone'   => $order['shipping_phone'],
            'address' => $order['shipping_address'],
            'city'    => $order['shipping_city'],
            'zip'     => $order['shipping_zip'],
            'note'    => $order['shipping_note'],
        ],

        'sellers'    => count($subOrders),
        'sub_orders' => $subOrders,
    ];
}

function order_base_sql(): string
{
    return "SELECT o.id, o.user_id, o.total_amount, o.currency, o.payment_status, o.created_at,
                   o.shipping_name, o.shipping_phone, o.shipping_address,
                   o.shipping_city, o.shipping_zip, o.shipping_note,
                   pay.provider           AS payment_provider,
                   pay.provider_ref       AS payment_ref,
                   pay.status             AS payment_state,
                   pay.captured_at        AS payment_captured_at,
                   pay.escrow_released_at AS payment_escrow_released_at
              FROM orders o
              LEFT JOIN payments pay ON pay.order_id = o.id";
}

function order_find_for_user(int $orderId, int $userId): ?array
{
    $stmt = db()->prepare(order_base_sql() . ' WHERE o.id = ? AND o.user_id = ? LIMIT 1');
    $stmt->execute([$orderId, $userId]);
    $row = $stmt->fetch();

    if ($row === false) {
        return null;
    }

    $subs = order_sub_orders_for([$orderId]);
    return order_shape($row, $subs[$orderId] ?? []);
}
