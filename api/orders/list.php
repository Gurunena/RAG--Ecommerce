<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/_orders.php';

try {
    require_method('GET');
    $me = require_login();

    $userId  = (int) $me['id'];
    $page    = query_int('page', 1, 1, 10000);
    $perPage = query_int('per_page', 10, 1, 50);
    $offset  = ($page - 1) * $perPage;

    $status = query_str('status');
    if ($status !== '' && !in_array($status, array_merge(ORDER_FLOW, ORDER_TERMINAL), true)) {
        $status = '';
    }

    $where = 'WHERE o.user_id = ?';
    $args  = [$userId];

    if ($status !== '') {
        // EXISTS, not a JOIN: an order with two matching sub-orders must come
        // back once, not twice.
        $where .= ' AND EXISTS (SELECT 1 FROM sub_orders so
                                 WHERE so.order_id = o.id AND so.status = ?)';
        $args[] = $status;
    }

    /* ---------- how many, for the pager ---------- */
    $countStmt = db()->prepare("SELECT COUNT(*) FROM orders o $where");
    $countStmt->execute($args);
    $total = (int) $countStmt->fetchColumn();

    $stmt = db()->prepare(
        order_base_sql() . ' ' . $where . ' ORDER BY o.created_at DESC, o.id DESC ' .
        sprintf('LIMIT %d OFFSET %d', $perPage, $offset)
    );
    $stmt->execute($args);
    $rows = $stmt->fetchAll();

    /* One query for every sub-order on this page, not one per order. */
    $orderIds = array_map(static fn(array $r): int => (int) $r['id'], $rows);
    $subs     = order_sub_orders_for($orderIds);

    $orders = array_map(
        static fn(array $r): array => order_shape($r, $subs[(int) $r['id']] ?? []),
        $rows
    );

    json_ok([
        'items'    => $orders,
        'total'    => $total,
        'page'     => $page,
        'per_page' => $perPage,
        'pages'    => $perPage > 0 ? (int) ceil($total / $perPage) : 1,
    ]);
} catch (Throwable $e) {
    app_log('orders/list.php failed: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    json_fail('Could not load your orders right now. Please try again.', 'SERVER', 500);
}
