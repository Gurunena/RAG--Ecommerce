<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/_orders.php';

try {
    require_method('GET');
    $me = require_login();

    $raw = query_str('id');
    if ($raw === '') {
        json_fail('No order was specified.', 'VALIDATION', 400);
    }

    // 'ORD-000012' and '12' are the same order.
    $id = ctype_digit($raw)
        ? (int) $raw
        : (int) preg_replace('/\D/', '', $raw);

    if ($id <= 0) {
        json_fail('That order could not be found.', 'NOT_FOUND', 404);
    }

    $order = order_find_for_user($id, (int) $me['id']);
    if ($order === null) {
        json_fail('That order could not be found.', 'NOT_FOUND', 404);
    }

    json_ok(['order' => $order]);
} catch (Throwable $e) {
    app_log('orders/detail.php failed: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    json_fail('Could not load that order right now. Please try again.', 'SERVER', 500);
}
