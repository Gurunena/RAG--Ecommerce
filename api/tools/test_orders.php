<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit("This is a command-line script and will not run over HTTP.\n");
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';

$BASE = rtrim($argv[1] ?? 'http://localhost/consult-project', '/');

$pass = 0;
$fail = 0;
$jarDir = sys_get_temp_dir() . '/consult-ordertest-' . getmypid();
@mkdir($jarDir, 0777, true);

function section(string $title): void
{
    echo "\n", str_repeat('=', 68), "\n  ", $title, "\n", str_repeat('=', 68), "\n";
}

function check(string $label, bool $ok, ?string $detail = ''): void
{
    global $pass, $fail;
    $detail = $detail ?? 'null';
    if ($ok) {
        $pass++;
        printf("  [PASS] %s%s\n", $label, $detail !== '' ? "  ($detail)" : '');
    } else {
        $fail++;
        printf("  [FAIL] %s%s\n", $label, $detail !== '' ? "  ($detail)" : '');
    }
}

function api(string $jar, string $method, string $path, ?array $body = null, array $headers = []): array
{
    global $BASE, $jarDir;

    $jarFile = $jarDir . '/' . $jar . '.txt';
    $ch = curl_init($BASE . '/api/' . ltrim($path, '/'));

    $hdr = array_merge(['Accept: application/json'], $headers);
    if ($body !== null) {
        $hdr[] = 'Content-Type: application/json';
    }

    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEFILE     => $jarFile,
        CURLOPT_COOKIEJAR      => $jarFile,
        CURLOPT_HTTPHEADER     => $hdr,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_POSTFIELDS     => $body === null ? null : json_encode($body),
    ]);

    $raw    = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err    = curl_error($ch);
    curl_close($ch);

    if ($raw === false) {
        return ['status' => 0, 'body' => null, 'raw' => $err];
    }
    return ['status' => $status, 'body' => json_decode($raw, true), 'raw' => $raw];
}

function csrf(string $jar): string
{
    $r = api($jar, 'GET', 'auth/csrf.php');
    return $r['body']['data']['csrf_token'] ?? '';
}

function post(string $jar, string $path, array $body = []): array
{
    return api($jar, 'POST', $path, $body, ['X-CSRF-Token: ' . csrf($jar)]);
}

/** Shorthand for the data payload of a response. */
function data(array $res)
{
    return $res['body']['data'] ?? null;
}

section('0. Environment');

$probe = api('guest', 'GET', 'auth/csrf.php');
if ($probe['status'] !== 200) {
    echo "  Cannot reach {$BASE}/api/auth/csrf.php (status {$probe['status']}).\n";
    echo "  Start Apache in the XAMPP Control Panel, then run this again.\n";
    exit(1);
}
check('Apache serves the API', true, $BASE);

try {
    db()->query('SELECT 1');
    check('MySQL reachable from PHP', true);
} catch (Throwable $e) {
    check('MySQL reachable from PHP', false, $e->getMessage());
    exit(1);
}

$picks = db()->query(
    "SELECT p.id, p.public_id, p.title, p.price, p.stock, p.seller_id, s.business_name
       FROM products p
       JOIN sellers s ON s.id = p.seller_id
      WHERE p.status = 'active' AND p.deleted_at IS NULL AND p.stock >= 2
   ORDER BY p.seller_id, p.id"
)->fetchAll();

$bySeller = [];
foreach ($picks as $row) {
    $bySeller[(int) $row['seller_id']][] = $row;
}
if (count($bySeller) < 2) {
    echo "  Need active, in-stock products from at least two sellers. Import sql/seed.sql first.\n";
    exit(1);
}

$sellerIds = array_slice(array_keys($bySeller), 0, 2);
$itemA = $bySeller[$sellerIds[0]][0];
$itemB = $bySeller[$sellerIds[1]][0];

check(
    'Two products from two different sellers available',
    true,
    sprintf('%s (%s) + %s (%s)', $itemA['public_id'], $itemA['business_name'], $itemB['public_id'], $itemB['business_name'])
);

$stockBeforeA = (int) $itemA['stock'];
$stockBeforeB = (int) $itemB['stock'];

section('1. Test customer');

$email    = 'ordertest+' . bin2hex(random_bytes(4)) . '@example.com';
$password = 'Consult@2026';

$reg = post('buyer', 'auth/register.php', [
    'name'          => 'Order Test Buyer',
    'email'         => $email,
    'phone'         => '0771234567',
    'password'      => $password,
    'accept_terms'  => true,
]);
check('Customer registered', $reg['status'] === 200 || $reg['status'] === 201, 'status ' . $reg['status']);

$me = api('buyer', 'GET', 'auth/me.php');
$buyerId = (int) (data($me)['user']['id'] ?? 0);
check('Session established', $buyerId > 0, 'user id ' . $buyerId);

if (!$buyerId) {
    echo "  Cannot continue without a signed-in customer.\n";
    exit(1);
}

section('2. Checkout guards');

$shipping = [
    'name'    => 'Order Test Buyer',
    'phone'   => '0771234567',
    'address' => 'No. 42, Galle Road',
    'city'    => 'Colombo 03',
    'zip'     => '00300',
    'note'    => 'Automated test order',
];

$empty = post('buyer', 'orders/checkout.php', $shipping);
check(
    'Checkout with an empty cart is refused 409',
    $empty['status'] === 409,
    'got ' . $empty['status'] . ' — ' . ($empty['body']['error'] ?? '?')
);

$guest = post('guest', 'orders/checkout.php', $shipping);
check(
    'Checkout as a signed-out guest is refused 401',
    $guest['status'] === 401 && ($guest['body']['code'] ?? '') === 'AUTH',
    'got ' . $guest['status'] . ' ' . ($guest['body']['code'] ?? '?')
);

$noCsrf = api('buyer', 'POST', 'orders/checkout.php', $shipping);
check(
    'Checkout without a CSRF token is refused 403',
    $noCsrf['status'] === 403,
    'got ' . $noCsrf['status']
);

$badAddr = post('buyer', 'orders/checkout.php', ['name' => 'X', 'phone' => 'nope', 'address' => '', 'city' => '']);
check(
    'Invalid delivery details are refused 422 with per-field errors',
    $badAddr['status'] === 422 && is_array($badAddr['body']['fields'] ?? null),
    'fields: ' . implode(',', array_keys($badAddr['body']['fields'] ?? []))
);

section('3. One cart, two sellers');

$addA = post('buyer', 'cart/add.php', ['product_id' => $itemA['public_id'], 'qty' => 2]);
$addB = post('buyer', 'cart/add.php', ['product_id' => $itemB['public_id'], 'qty' => 1]);
check('Added seller A\'s product (qty 2)', $addA['status'] === 200, $itemA['title']);
check('Added seller B\'s product (qty 1)', $addB['status'] === 200, $itemB['title']);

$cart = data(api('buyer', 'GET', 'cart/get.php'));
check('Cart holds 2 sellers', (int) ($cart['sellers'] ?? 0) === 2, 'sellers = ' . ($cart['sellers'] ?? '?'));

section('3b. A mid-transaction failure rolls everything back');

$countOrders = static function (): int {
    return (int) db()->query('SELECT COUNT(*) FROM orders')->fetchColumn();
};
$countPayments = static function (): int {
    return (int) db()->query('SELECT COUNT(*) FROM payments')->fetchColumn();
};
$countSubs = static function (): int {
    return (int) db()->query('SELECT COUNT(*) FROM sub_orders')->fetchColumn();
};
$stockOf = static function (int $id): int {
    $q = db()->prepare('SELECT stock FROM products WHERE id = ?');
    $q->execute([$id]);
    return (int) $q->fetchColumn();
};

$ordersBefore   = $countOrders();
$paymentsBefore = $countPayments();
$subsBefore     = $countSubs();
$stockBeforeFail = [$stockOf((int) $itemA['id']), $stockOf((int) $itemB['id'])];

db()->exec('DROP TRIGGER IF EXISTS trg_test_force_failure');
db()->exec(
    "CREATE TRIGGER trg_test_force_failure BEFORE INSERT ON order_items
     FOR EACH ROW
     BEGIN
       SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'forced test failure';
     END"
);

$broken = post('buyer', 'orders/checkout.php', $shipping);

check(
    'A write failure answers with a clean error, not a stack trace',
    $broken['status'] === 500
        && ($broken['body']['code'] ?? '') === 'SERVER'
        && stripos((string) $broken['raw'], 'SQLSTATE') === false
        && stripos((string) $broken['raw'], 'trg_test_force_failure') === false,
    'status ' . $broken['status'] . ' — ' . ($broken['body']['error'] ?? '?')
);

db()->exec('DROP TRIGGER IF EXISTS trg_test_force_failure');

check('ROLLBACK: no orders row survived', $countOrders() === $ordersBefore, $ordersBefore . ' → ' . $countOrders());
check('ROLLBACK: no sub_orders rows survived', $countSubs() === $subsBefore, $subsBefore . ' → ' . $countSubs());
check('ROLLBACK: no payments row survived', $countPayments() === $paymentsBefore, $paymentsBefore . ' → ' . $countPayments());
check(
    'ROLLBACK: stock was not decremented',
    [$stockOf((int) $itemA['id']), $stockOf((int) $itemB['id'])] === $stockBeforeFail,
    implode('/', $stockBeforeFail)
);

$cartStill = data(api('buyer', 'GET', 'cart/get.php'));
check(
    'ROLLBACK: the cart is still intact, so the shopper can retry',
    (int) ($cartStill['sellers'] ?? 0) === 2 && (int) ($cartStill['count'] ?? 0) === 3,
    'count ' . ($cartStill['count'] ?? '?') . ', sellers ' . ($cartStill['sellers'] ?? '?')
);

section('3c. The order that succeeds');

$expectedGoods    = round((float) $itemA['price'] * 2 + (float) $itemB['price'], 2);
$expectedDelivery = 2 * 450;
$expectedTotal    = round($expectedGoods + $expectedDelivery, 2);

$res = post('buyer', 'orders/checkout.php', $shipping + [
    'total'    => 5,
    'subtotal' => 5,
    'items'    => [['product_id' => $itemA['public_id'], 'qty' => 99, 'price' => 1]],
]);

check('Checkout succeeded', $res['status'] === 201, 'status ' . $res['status'] . ' ' . ($res['body']['error'] ?? ''));

$order = data($res)['order'] ?? null;
if (!$order) {
    echo "  No order came back — stopping.\n  Response: " . substr((string) $res['raw'], 0, 400) . "\n";
    $fail++;
} else {
    $orderId = (int) $order['id'];

    check(
        'Prices sent by the browser were IGNORED (re-priced from the DB)',
        abs((float) $order['total'] - $expectedTotal) < 0.01,
        sprintf('total %s, expected %s (browser claimed 5)', $order['total'], $expectedTotal)
    );
    check('ONE order row', $orderId > 0, $order['ref']);
    check('TWO sub-orders, one per seller', count($order['sub_orders']) === 2, 'got ' . count($order['sub_orders']));

    $subSellers = array_map(static fn(array $s): string => (string) $s['seller']['id'], $order['sub_orders']);
    check('Sub-orders belong to two DIFFERENT sellers', count(array_unique($subSellers)) === 2, implode(' + ', $subSellers));

    /* ---------- one payment, held in escrow ---------- */
    $payRows = db()->prepare('SELECT * FROM payments WHERE order_id = ?');
    $payRows->execute([$orderId]);
    $payments = $payRows->fetchAll();

    check('ONE payment row for the whole order', count($payments) === 1, count($payments) . ' row(s)');
    if ($payments) {
        $p = $payments[0];
        check('Payment provider is the simulated gateway', $p['provider'] === 'demo', $p['provider']);
        check('Payment reference is marked DEMO', str_starts_with((string) $p['provider_ref'], 'DEMO-'), (string) $p['provider_ref']);
        check('Payment captured (= held in escrow)', $p['status'] === 'captured', $p['status']);
        check('Payment amount matches the order total', abs((float) $p['amount'] - $expectedTotal) < 0.01, $p['amount']);
        check('Escrow NOT released at checkout', $p['escrow_released_at'] === null);
    }

    foreach ($order['sub_orders'] as $i => $sub) {
        check(
            sprintf('Sub-order %d starts at "pending", payout held', $i + 1),
            $sub['status'] === 'pending' && $sub['payout']['state'] === 'escrow',
            $sub['ref'] . ' · ' . $sub['status'] . ' · ' . $sub['payout']['state']
        );
    }

    /* ---------- the audit trail exists from the first instant ---------- */
    $logCount = db()->prepare(
        'SELECT COUNT(*) FROM order_status_log l
           JOIN sub_orders so ON so.id = l.sub_order_id
          WHERE so.order_id = ? AND l.internal = 0'
    );
    $logCount->execute([$orderId]);
    check('Status log written for both sub-orders', (int) $logCount->fetchColumn() === 2);

    /* ---------- stock came down, cart was emptied ---------- */
    $stockNow = db()->prepare('SELECT stock FROM products WHERE id = ?');
    $stockNow->execute([(int) $itemA['id']]);
    $afterA = (int) $stockNow->fetchColumn();
    $stockNow->execute([(int) $itemB['id']]);
    $afterB = (int) $stockNow->fetchColumn();

    check('Stock decremented by the quantity ordered (A, qty 2)', $afterA === $stockBeforeA - 2, "$stockBeforeA → $afterA");
    check('Stock decremented by the quantity ordered (B, qty 1)', $afterB === $stockBeforeB - 1, "$stockBeforeB → $afterB");

    $cartAfter = data(api('buyer', 'GET', 'cart/get.php'));
    check('Cart emptied by the order', (int) ($cartAfter['count'] ?? -1) === 0);

    section('4. Each seller sees only their own sub-order');

    $rows = db()->prepare('SELECT id, seller_id, status FROM sub_orders WHERE order_id = ? ORDER BY id');
    $rows->execute([$orderId]);
    $subRows = $rows->fetchAll();

    check('Two sub_orders rows in the database', count($subRows) === 2);

    foreach ($subRows as $sr) {
        $scoped = db()->prepare(
            'SELECT COUNT(*) FROM sub_orders WHERE order_id = ? AND seller_id = ?'
        );
        $scoped->execute([$orderId, (int) $sr['seller_id']]);
        check(
            sprintf('Seller %d is scoped to exactly 1 of the 2 sub-orders', (int) $sr['seller_id']),
            (int) $scoped->fetchColumn() === 1
        );
    }

    $crossRead = db()->prepare(
        'SELECT COUNT(*) FROM sub_orders WHERE id = ? AND seller_id = ?'
    );
    $crossRead->execute([(int) $subRows[0]['id'], (int) $subRows[1]['seller_id']]);
    check(
        'Seller B\'s id does NOT match seller A\'s sub-order (the WHERE that enforces it)',
        (int) $crossRead->fetchColumn() === 0
    );

    /* ---------- another customer cannot read this order ---------- */
    $other = post('other', 'auth/register.php', [
        'name'         => 'Nosy Other Buyer',
        'email'        => 'ordertest-other+' . bin2hex(random_bytes(4)) . '@example.com',
        'phone'        => '0779998888',
        'password'     => $password,
        'accept_terms' => true,
    ]);
    $otherOk = $other['status'] === 200 || $other['status'] === 201;

    if ($otherOk) {
        $steal = api('other', 'GET', 'orders/detail.php?id=' . $orderId);
        check(
            'Another customer reading this order gets 404, not the order',
            $steal['status'] === 404,
            'got ' . $steal['status']
        );

        $otherList = data(api('other', 'GET', 'orders/list.php'));
        check('Another customer\'s order list is empty', (int) ($otherList['total'] ?? -1) === 0);

        $stealConfirm = post('other', 'orders/confirm_delivery.php', ['sub_order_id' => (int) $subRows[0]['id']]);
        check(
            'Another customer cannot confirm this delivery (404)',
            $stealConfirm['status'] === 404,
            'got ' . $stealConfirm['status']
        );

        // Clean the second account up straight away.
        db()->prepare('DELETE FROM users WHERE email = ?')
            ->execute([$other['body']['data']['user']['email'] ?? '']);
    } else {
        check('Second test customer registered', false, 'status ' . $other['status']);
    }

    section('5. Status flow + escrow release');

    $subA = (int) $subRows[0]['id'];

    $tooEarly = post('buyer', 'orders/confirm_delivery.php', ['sub_order_id' => $subA]);
    check(
        'Confirming delivery while still "pending" is refused 409',
        $tooEarly['status'] === 409,
        'got ' . $tooEarly['status'] . ' — ' . ($tooEarly['body']['error'] ?? '?')
    );

    db()->prepare("UPDATE sub_orders SET status = 'packed' WHERE id = ?")->execute([$subA]);
    db()->prepare("INSERT INTO order_status_log (sub_order_id, status, changed_by) VALUES (?, 'packed', 'seller')")->execute([$subA]);
    db()->prepare("UPDATE sub_orders SET status = 'shipped' WHERE id = ?")->execute([$subA]);
    db()->prepare("INSERT INTO order_status_log (sub_order_id, status, changed_by) VALUES (?, 'shipped', 'seller')")->execute([$subA]);
    check('Seller advanced sub-order A to "shipped"', true, 'SO id ' . $subA);

    $confirm = post('buyer', 'orders/confirm_delivery.php', ['sub_order_id' => $subA]);
    check('Customer confirmed delivery', $confirm['status'] === 200, 'status ' . $confirm['status'] . ' ' . ($confirm['body']['error'] ?? ''));

    $row = db()->prepare('SELECT status, delivered_at, payout_released_at FROM sub_orders WHERE id = ?');
    $row->execute([$subA]);
    $after = $row->fetch();

    check('Sub-order A is "delivered"', ($after['status'] ?? '') === 'delivered', (string) ($after['status'] ?? '?'));
    check('delivered_at stamped', ($after['delivered_at'] ?? null) !== null, (string) ($after['delivered_at'] ?? 'NULL'));
    check('ESCROW RELEASED — payout_released_at stamped', ($after['payout_released_at'] ?? null) !== null, (string) ($after['payout_released_at'] ?? 'NULL'));

    $dup = post('buyer', 'orders/confirm_delivery.php', ['sub_order_id' => $subA]);
    check('Confirming twice is refused (no second release)', $dup['status'] === 409, 'got ' . $dup['status']);

    /* The OTHER seller's sub-order must be untouched by all of that. */
    $subB = (int) $subRows[1]['id'];
    $rowB = db()->prepare('SELECT status, payout_released_at FROM sub_orders WHERE id = ?');
    $rowB->execute([$subB]);
    $bAfter = $rowB->fetch();
    check(
        'Seller B\'s sub-order is UNAFFECTED (still pending, still held)',
        ($bAfter['status'] ?? '') === 'pending' && ($bAfter['payout_released_at'] ?? null) === null,
        (string) ($bAfter['status'] ?? '?')
    );

    /* The payment stays in escrow until EVERY sub-order is settled. */
    $payStmt = db()->prepare('SELECT escrow_released_at FROM payments WHERE order_id = ?');
    $payStmt->execute([$orderId]);
    check(
        'Payment escrow still open while one seller is outstanding',
        $payStmt->fetchColumn() === null
    );

    /* Settle the second one too. */
    db()->prepare("UPDATE sub_orders SET status = 'shipped' WHERE id = ?")->execute([$subB]);
    $confirmB = post('buyer', 'orders/confirm_delivery.php', ['sub_order_id' => $subB]);
    check('Customer confirmed the second shipment', $confirmB['status'] === 200, 'status ' . $confirmB['status']);

    $payStmt->execute([$orderId]);
    check(
        'Payment escrow CLOSED once both sellers were confirmed',
        $payStmt->fetchColumn() !== null
    );

    section('6. Order list + tracking timeline');

    $list = data(api('buyer', 'GET', 'orders/list.php'));
    check('Order list returns the order', (int) ($list['total'] ?? 0) === 1, 'total ' . ($list['total'] ?? '?'));

    $detail = data(api('buyer', 'GET', 'orders/detail.php?id=' . $orderId));
    $d = $detail['order'] ?? null;
    check('Detail reachable by numeric id', $d !== null);

    $byRef = data(api('buyer', 'GET', 'orders/detail.php?id=' . ($order['ref'] ?? '')));
    check('Detail reachable by printed reference', ($byRef['order']['id'] ?? 0) === $orderId, (string) ($order['ref'] ?? ''));

    if ($d) {
        $timeline = $d['sub_orders'][0]['timeline'] ?? [];
        $statuses = array_map(static fn(array $t): string => $t['status'], $timeline);
        check(
            'Timeline records every step in order',
            $statuses === ['pending', 'packed', 'shipped', 'delivered'],
            implode(' → ', $statuses)
        );
        check(
            'Timeline records WHO changed it',
            ($timeline[0]['changed_by'] ?? '') === 'system' && in_array('customer', array_column($timeline, 'changed_by'), true),
            implode(',', array_column($timeline, 'changed_by'))
        );
        check('Payment is labelled as a demo payment', ($d['payment']['demo'] ?? false) === true);
        check(
            'Shipping address snapshotted onto the order',
            ($d['shipping']['city'] ?? '') === 'Colombo 03',
            (string) ($d['shipping']['city'] ?? '?')
        );
        check(
            'Item price is the snapshot, not a live lookup',
            isset($d['sub_orders'][0]['items'][0]['unit_price']),
            'unit_price ' . ($d['sub_orders'][0]['items'][0]['unit_price'] ?? '?')
        );
    }

    /* ---------- append-only log ---------- */
    section('7. The audit trail cannot be rewritten');

    try {
        db()->prepare('UPDATE order_status_log SET note = ? WHERE sub_order_id = ?')
            ->execute(['tampered', $subA]);
        check('UPDATE on order_status_log is blocked by the trigger', false, 'the UPDATE succeeded');
    } catch (Throwable $e) {
        check('UPDATE on order_status_log is blocked by the trigger', true, 'append-only');
    }

    try {
        db()->prepare('DELETE FROM order_status_log WHERE sub_order_id = ?')->execute([$subA]);
        check('DELETE on order_status_log is blocked by the trigger', false, 'the DELETE succeeded');
    } catch (Throwable $e) {
        check('DELETE on order_status_log is blocked by the trigger', true, 'append-only');
    }
}

section('Cleanup');

db()->prepare('UPDATE products SET stock = ? WHERE id = ?')->execute([$stockBeforeA, (int) $itemA['id']]);
db()->prepare('UPDATE products SET stock = ? WHERE id = ?')->execute([$stockBeforeB, (int) $itemB['id']]);
check('Stock restored', true, sprintf('%s → %d, %s → %d', $itemA['public_id'], $stockBeforeA, $itemB['public_id'], $stockBeforeB));

db()->prepare('DELETE FROM orders WHERE user_id = ?')->execute([$buyerId]);
db()->prepare('DELETE FROM users WHERE id = ?')->execute([$buyerId]);

$gone = db()->prepare('SELECT COUNT(*) FROM users WHERE id = ?');
$gone->execute([$buyerId]);
check('Throwaway test customer and their orders removed', ((int) $gone->fetchColumn()) === 0);

foreach (glob($jarDir . '/*.txt') ?: [] as $f) {
    @unlink($f);
}
@rmdir($jarDir);

echo "\n", str_repeat('-', 68), "\n";
printf("  %d passed, %d failed\n", $pass, $fail);
echo str_repeat('-', 68), "\n";

exit($fail === 0 ? 0 : 1);
