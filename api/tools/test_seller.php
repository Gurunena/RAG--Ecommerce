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
$DEMO_PASSWORD = 'Consult@2026';

$pass = 0;
$fail = 0;
$jarDir = sys_get_temp_dir() . '/consult-sellertest-' . getmypid();
@mkdir($jarDir, 0777, true);

/* track what to clean up even if an assertion stops early */
$madeUsers    = [];
$madeProducts = [];

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
        CURLOPT_TIMEOUT        => 25,
        CURLOPT_POSTFIELDS     => $body === null ? null : json_encode($body),
    ]);

    $raw    = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false) {
        return ['status' => 0, 'body' => null, 'raw' => ''];
    }
    return ['status' => $status, 'body' => json_decode($raw, true), 'raw' => $raw];
}

/** multipart upload, for the image endpoints */
function upload(string $jar, string $path, string $file, string $kind): array
{
    global $BASE, $jarDir;

    $jarFile = $jarDir . '/' . $jar . '.txt';
    $ch = curl_init($BASE . '/api/' . ltrim($path, '/'));

    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEFILE     => $jarFile,
        CURLOPT_COOKIEJAR      => $jarFile,
        CURLOPT_HTTPHEADER     => ['Accept: application/json', 'X-CSRF-Token: ' . csrf($jar)],
        CURLOPT_TIMEOUT        => 25,
        CURLOPT_POSTFIELDS     => [
            'kind' => $kind,
            'file' => new CURLFile($file, mime_content_type($file) ?: 'image/png', basename($file)),
        ],
    ]);

    $raw    = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ['status' => $status, 'body' => json_decode((string) $raw, true), 'raw' => (string) $raw];
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

function data(array $res)
{
    return $res['body']['data'] ?? null;
}

function login(string $jar, string $email, string $password): array
{
    return post($jar, 'auth/login.php', ['email' => $email, 'password' => $password]);
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

section('1. Seller registration');

$suffix  = bin2hex(random_bytes(4));
$emailA  = "selltest-a+$suffix@example.com";

$application = [
    'first_name'       => 'Tharaka',
    'last_name'        => 'Bandara',
    'email'            => $emailA,
    'phone'            => '0771234567',
    'password'         => $DEMO_PASSWORD,
    'nic_number'       => '912345678V',
    'business_name'    => 'Test Electronics ' . $suffix,
    'business_type'    => 'sole_proprietorship',
    'registration_no'  => 'PV-00' . random_int(100, 999),
    'city'             => 'Kandy',
    'district'         => 'Kandy',
    'primary_category' => 'laptop',
    'bank_name'        => 'Commercial Bank',
    'account_number'   => '8001234567',
    'branch'           => 'Kandy City',
    'accept_terms'     => true,
];

$reg = post('sellerA', 'seller/register.php', $application);
check('Seller application accepted', $reg['status'] === 201, 'status ' . $reg['status'] . ' ' . ($reg['body']['error'] ?? ''));

$row = db()->prepare(
    'SELECT u.id, u.role, u.password_hash, s.kyc_status, s.public_id, s.nic_number
       FROM users u JOIN sellers s ON s.id = u.id WHERE u.email = ?'
);
$row->execute([$emailA]);
$sellerA = $row->fetch();

if (!$sellerA) {
    check('Both users and sellers rows created', false, 'no row found');
    echo "  Cannot continue.\n";
    exit(1);
}
$sellerAId = (int) $sellerA['id'];
$madeUsers[] = $sellerAId;

check('users row created with role seller', $sellerA['role'] === 'seller', $sellerA['role']);
check('sellers row created, kyc_status = pending', $sellerA['kyc_status'] === 'pending', $sellerA['kyc_status']);
check('public_id minted', (bool) preg_match('/^s\d+$/', (string) $sellerA['public_id']), (string) $sellerA['public_id']);
check(
    'Password is hashed, never plain text',
    $sellerA['password_hash'] !== $DEMO_PASSWORD && password_verify($DEMO_PASSWORD, $sellerA['password_hash']),
    substr((string) $sellerA['password_hash'], 0, 7) . '…'
);
check('Registration did NOT open a session', (data(api('sellerA', 'GET', 'auth/me.php'))['user'] ?? null) === null);

/* ---- an application cannot approve itself ---- */
$sneaky = $application;
$sneaky['email']      = "selltest-sneak+$suffix@example.com";
$sneaky['kyc_status'] = 'verified';
$sneaky['role']       = 'admin';
$sneaky['commission_rate'] = 0;

$sneak = post('guest', 'seller/register.php', $sneaky);
if ($sneak['status'] === 201) {
    $chk = db()->prepare('SELECT u.role, s.kyc_status, s.commission_rate FROM users u JOIN sellers s ON s.id=u.id WHERE u.email=?');
    $chk->execute([$sneaky['email']]);
    $sn = $chk->fetch();
    check(
        'An application CANNOT grant itself verified/admin/0% commission',
        $sn && $sn['role'] === 'seller' && $sn['kyc_status'] === 'pending' && $sn['commission_rate'] === null,
        'role ' . ($sn['role'] ?? '?') . ', kyc ' . ($sn['kyc_status'] ?? '?') . ', rate ' . var_export($sn['commission_rate'] ?? null, true)
    );
    $del = db()->prepare('SELECT id FROM users WHERE email = ?');
    $del->execute([$sneaky['email']]);
    $madeUsers[] = (int) $del->fetchColumn();
} else {
    check('An application CANNOT grant itself verified/admin/0% commission', false, 'registration failed: ' . $sneak['status']);
}

/* ---- duplicate email, and bad input ---- */
$dupe = post('guest', 'seller/register.php', $application);
check('Duplicate email refused with a field error', $dupe['status'] === 422 && isset($dupe['body']['fields']['email']));

$bad = post('guest', 'seller/register.php', [
    'first_name' => '', 'email' => 'not-an-email', 'phone' => '123',
    'password' => 'short', 'nic_number' => 'XYZ', 'business_name' => '',
    'city' => '', 'primary_category' => 'spaceships', 'bank_name' => '',
    'account_number' => 'abc', 'accept_terms' => false,
]);
$badFields = array_keys($bad['body']['fields'] ?? []);
check(
    'Every invalid field is reported at once',
    $bad['status'] === 422 && count($badFields) >= 9,
    count($badFields) . ' fields: ' . implode(',', array_slice($badFields, 0, 6)) . '…'
);

section('2. A pending seller cannot publish');

login('sellerA', $emailA, $DEMO_PASSWORD);
$meA = data(api('sellerA', 'GET', 'auth/me.php'));
check('Pending seller CAN sign in', ($meA['user']['id'] ?? 0) === $sellerAId);
check('Their own dashboard loads', api('sellerA', 'GET', 'seller/dashboard.php')['status'] === 200);

$dashA = data(api('sellerA', 'GET', 'seller/dashboard.php'));
check(
    'Dashboard reports kyc_status pending, verified = false',
    ($dashA['seller']['kyc_status'] ?? '') === 'pending' && ($dashA['seller']['verified'] ?? true) === false
);
check('NIC is never returned to the client', !str_contains(json_encode($dashA), '912345678V'));

$blocked = post('sellerA', 'seller/product_save.php', [
    'title' => 'Should Not Exist', 'category' => 'laptop', 'price' => 100000, 'stock' => 5,
]);
check(
    'Pending seller CANNOT create a listing (403)',
    $blocked['status'] === 403 && ($blocked['body']['code'] ?? '') === 'FORBIDDEN',
    'got ' . $blocked['status'] . ' — ' . ($blocked['body']['error'] ?? '')
);

$countA = db()->prepare('SELECT COUNT(*) FROM products WHERE seller_id = ?');
$countA->execute([$sellerAId]);
check('…and nothing was written', (int) $countA->fetchColumn() === 0);

section('3. Verified seller — product CRUD');

/* Approve seller A directly; the admin endpoint that does this is Phase 6. */
db()->prepare("UPDATE sellers SET kyc_status = 'verified', kyc_reviewed_at = NOW() WHERE id = ?")
    ->execute([$sellerAId]);
check('Seller A verified (standing in for the Phase 6 admin action)', true);

$create = post('sellerA', 'seller/product_save.php', [
    'title'       => 'Test Ultrabook 14',
    'subtitle'    => 'Core i5 · 16GB',
    'category'    => 'laptop',
    'description' => 'A listing created by the Phase 5 test runner.',
    'price'       => 240000,
    'old_price'   => 265000,
    'stock'       => 7,
    'specs'       => ['RAM' => '16GB', 'Storage' => '512GB SSD'],
    'tags'        => ['programming', 'student', 'programming'],
    // The request tries to publish itself and to pose as another seller.
    'status'      => 'active',
    'seller_id'   => 2,
]);
check('Listing created', $create['status'] === 201, 'status ' . $create['status'] . ' ' . ($create['body']['error'] ?? ''));

$productA = data($create)['product'] ?? null;
if (!$productA) {
    echo "  No product came back — stopping.\n";
    $fail++;
} else {
    $pubA = $productA['id'];
    $madeProducts[] = $pubA;

    check('New listing is "pending", not "active"', $productA['status'] === 'pending', $productA['status']);
    check('…even though the request asked for "active"', $productA['status'] !== 'active');

    $owner = db()->prepare('SELECT seller_id FROM products WHERE public_id = ?');
    $owner->execute([$pubA]);
    check('Listing belongs to the signed-in seller, not the id it sent', (int) $owner->fetchColumn() === $sellerAId);

    check('Duplicate tag collapsed', count($productA['tags']) === 2, implode(',', $productA['tags']));
    check('Specs stored as an object', isset($productA['specs']->RAM) || isset($productA['specs']['RAM']));

    /* ---- it is invisible to shoppers while pending ---- */
    $shopper = api('guest', 'GET', 'products/detail.php?id=' . urlencode($pubA));
    check('A pending listing is NOT visible on the storefront (404)', $shopper['status'] === 404, 'got ' . $shopper['status']);

    /* ---- validation ---- */
    $badSave = post('sellerA', 'seller/product_save.php', [
        'title' => 'x', 'category' => 'spaceships', 'price' => -5, 'stock' => 'abc',
        'old_price' => 1,
    ]);
    check(
        'Bad product input is refused per-field',
        $badSave['status'] === 422 && isset($badSave['body']['fields']['title'], $badSave['body']['fields']['category'], $badSave['body']['fields']['price']),
        implode(',', array_keys($badSave['body']['fields'] ?? []))
    );

    /* ---- approve it so the rest of the flow is realistic ---- */
    db()->prepare("UPDATE products SET status = 'active' WHERE public_id = ?")->execute([$pubA]);
    check('Listing approved (standing in for the Phase 6 admin action)', true);

    $live = api('guest', 'GET', 'products/detail.php?id=' . urlencode($pubA));
    check('Once active, shoppers can see it', $live['status'] === 200);

    /* ---- editing keeps an active listing live ---- */
    $edit = post('sellerA', 'seller/product_save.php', [
        'id' => $pubA, 'title' => 'Test Ultrabook 14 (2026)', 'category' => 'laptop',
        'price' => 235000, 'stock' => 7,
    ]);
    check('Editing an ACTIVE listing keeps it active', (data($edit)['product']['status'] ?? '') === 'active');
    check('Edited price saved', abs((float) (data($edit)['product']['price'] ?? 0) - 235000) < 0.01);

    /* ---- a rejected listing goes back in the queue when corrected ---- */
    db()->prepare("UPDATE products SET status='rejected', rejection_reason='Photos too blurry' WHERE public_id=?")
        ->execute([$pubA]);
    $dashRej = data(api('sellerA', 'GET', 'seller/dashboard.php'));
    $rejRow  = null;
    foreach ($dashRej['products'] as $p) {
        if ($p['id'] === $pubA) {
            $rejRow = $p;
        }
    }
    check('The seller is told WHY it was rejected', ($rejRow['rejection_reason'] ?? '') === 'Photos too blurry');

    $fixed = post('sellerA', 'seller/product_save.php', [
        'id' => $pubA, 'title' => 'Test Ultrabook 14 (2026)', 'category' => 'laptop',
        'price' => 235000, 'stock' => 7,
    ]);
    check('Correcting a REJECTED listing sends it back for review', (data($fixed)['product']['status'] ?? '') === 'pending');
    check('…and the old rejection reason is cleared', (data($fixed)['product']['rejection_reason'] ?? 'not-null') === 'not-null');

    db()->prepare("UPDATE products SET status = 'active' WHERE public_id = ?")->execute([$pubA]);

    /* ---- pause / resume ---- */
    $pause = post('sellerA', 'seller/product_toggle.php', ['id' => $pubA]);
    check('Pausing a live listing sets it inactive', (data($pause)['status'] ?? '') === 'inactive');
    check('A paused listing vanishes from the storefront', api('guest', 'GET', 'products/detail.php?id=' . urlencode($pubA))['status'] === 404);
    $resume = post('sellerA', 'seller/product_toggle.php', ['id' => $pubA]);
    check('Resuming puts it back on sale', (data($resume)['status'] ?? '') === 'active');

    section('4. Inventory');

    $set = post('sellerA', 'seller/stock.php', ['id' => $pubA, 'stock' => 12]);
    check('Stock set to an absolute value', (data($set)['products'][0]['stock'] ?? 0) === 12);

    $up = post('sellerA', 'seller/stock.php', ['id' => $pubA, 'delta' => 3]);
    check('Stock nudged up by a delta', (data($up)['products'][0]['stock'] ?? 0) === 15);

    $down = post('sellerA', 'seller/stock.php', ['id' => $pubA, 'delta' => -100]);
    check('Stock cannot be driven negative (clamped at 0)', (data($down)['products'][0]['stock'] ?? -1) === 0);

    $neg = post('sellerA', 'seller/stock.php', ['id' => $pubA, 'stock' => -4]);
    check('A negative absolute stock is refused', $neg['status'] === 422);

    post('sellerA', 'seller/stock.php', ['id' => $pubA, 'stock' => 9]);

    section('5. Scheduled sale pricing');

    $future = post('sellerA', 'seller/sale.php', [
        'id' => $pubA, 'sale_price' => 199000,
        'start_date' => date('Y-m-d', strtotime('+3 days')),
        'end_date'   => date('Y-m-d', strtotime('+10 days')),
    ]);
    check('A future sale is accepted', $future['status'] === 200, $future['body']['data']['message'] ?? '');
    check('…and is NOT active yet', (data($future)['product']['sale']['active'] ?? true) === false);

    $shopNow = data(api('guest', 'GET', 'products/detail.php?id=' . urlencode($pubA)));
    check(
        'Shoppers still pay the normal price before the sale starts',
        abs((float) ($shopNow['product']['price'] ?? 0) - 235000) < 0.01,
        'price ' . ($shopNow['product']['price'] ?? '?')
    );

    $live = post('sellerA', 'seller/sale.php', [
        'id' => $pubA, 'sale_price' => 199000,
        'start_date' => date('Y-m-d', strtotime('-1 day')),
        'end_date'   => date('Y-m-d', strtotime('+5 days')),
    ]);
    check('A sale covering today is active immediately', (data($live)['product']['sale']['active'] ?? false) === true);

    $shopSale = data(api('guest', 'GET', 'products/detail.php?id=' . urlencode($pubA)));
    check(
        'The STOREFRONT price is now the sale price — derived in SQL by date',
        abs((float) ($shopSale['product']['price'] ?? 0) - 199000) < 0.01,
        'price ' . ($shopSale['product']['price'] ?? '?')
    );
    check(
        'The normal price survives as the strikethrough',
        abs((float) ($shopSale['product']['oldPrice'] ?? 0) - 235000) < 0.01,
        'oldPrice ' . ($shopSale['product']['oldPrice'] ?? '?')
    );

    $stored = db()->prepare('SELECT price FROM products WHERE public_id = ?');
    $stored->execute([$pubA]);
    check(
        'products.price was NOT overwritten by the sale',
        abs((float) $stored->fetchColumn() - 235000) < 0.01
    );

    $expired = post('sellerA', 'seller/sale.php', [
        'id' => $pubA, 'sale_price' => 150000,
        'start_date' => date('Y-m-d', strtotime('-20 days')),
        'end_date'   => date('Y-m-d', strtotime('-10 days')),
    ]);
    check('A sale that is already over is refused', $expired['status'] === 422, 'got ' . $expired['status']);

    $tooHigh = post('sellerA', 'seller/sale.php', [
        'id' => $pubA, 'sale_price' => 999000,
        'start_date' => date('Y-m-d'), 'end_date' => date('Y-m-d', strtotime('+1 day')),
    ]);
    check('A "sale" price above the normal price is refused', $tooHigh['status'] === 422);

    $backwards = post('sellerA', 'seller/sale.php', [
        'id' => $pubA, 'sale_price' => 199000,
        'start_date' => date('Y-m-d', strtotime('+9 days')),
        'end_date'   => date('Y-m-d', strtotime('+2 days')),
    ]);
    check('End-before-start is refused', $backwards['status'] === 422);

    $cleared = post('sellerA', 'seller/sale.php', ['id' => $pubA, 'clear' => true]);
    check('A sale can be cancelled', (data($cleared)['product']['sale'] ?? 'no-sale') === 'no-sale', $cleared['body']['data']['message'] ?? '');

    section('6. Seller isolation');

    /* Seller B is one of the seeded sellers, who already owns products. */
    $emailB = 'techhub@example.com';
    $okB = login('sellerB', $emailB, $DEMO_PASSWORD);
    check('Seeded seller B signed in', $okB['status'] === 200, 'status ' . $okB['status']);

    $dashB = data(api('sellerB', 'GET', 'seller/dashboard.php'));
    $bIds  = array_column($dashB['products'] ?? [], 'id');
    check('Seller B has their own listings', count($bIds) > 0, count($bIds) . ' listings');

    check(
        'Seller B\'s dashboard does NOT contain seller A\'s listing',
        !in_array($pubA, $bIds, true)
    );
    $aIds = array_column($dashA['products'] ?? [], 'id');
    check(
        'Seller A\'s dashboard does NOT contain seller B\'s listings',
        count(array_intersect($aIds, $bIds)) === 0
    );

    $victim = $bIds[0];

    $steal = post('sellerA', 'seller/product_save.php', [
        'id' => $victim, 'title' => 'HIJACKED', 'category' => 'laptop', 'price' => 1, 'stock' => 1,
    ]);
    check('Seller A editing seller B\'s listing → 404', $steal['status'] === 404, 'got ' . $steal['status']);

    $titleCheck = db()->prepare('SELECT title, price FROM products WHERE public_id = ?');
    $titleCheck->execute([$victim]);
    $vRow = $titleCheck->fetch();
    check('…and seller B\'s row is untouched', $vRow['title'] !== 'HIJACKED', (string) $vRow['title']);

    check('Seller A deleting seller B\'s listing → 404', post('sellerA', 'seller/product_delete.php', ['id' => $victim])['status'] === 404);
    check('Seller A re-stocking seller B\'s listing → 404', post('sellerA', 'seller/stock.php', ['id' => $victim, 'stock' => 999])['status'] === 404);
    check('Seller A pausing seller B\'s listing → 404', post('sellerA', 'seller/product_toggle.php', ['id' => $victim])['status'] === 404);
    check('Seller A discounting seller B\'s listing → 404', post('sellerA', 'seller/sale.php', ['id' => $victim, 'sale_price' => 1, 'start_date' => date('Y-m-d'), 'end_date' => date('Y-m-d', strtotime('+1 day'))])['status'] === 404);

    $stockCheck = db()->prepare('SELECT stock FROM products WHERE public_id = ?');
    $stockCheck->execute([$victim]);
    check('…and seller B\'s stock is unchanged', (int) $stockCheck->fetchColumn() !== 999);

    section('7. Orders — a seller sees only their own half');

    /* Find a seeded multi-seller order to inspect. */
    $multi = db()->query(
        'SELECT order_id FROM sub_orders GROUP BY order_id HAVING COUNT(DISTINCT seller_id) > 1 LIMIT 1'
    )->fetchColumn();

    if ($multi) {
        $sides = db()->prepare('SELECT id, seller_id, status FROM sub_orders WHERE order_id = ? ORDER BY id');
        $sides->execute([(int) $multi]);
        $both = $sides->fetchAll();

        check('Found a seeded two-seller order', count($both) >= 2, 'order ' . $multi);

        $one = $both[0];
        $two = $both[1];

        $emailOne = db()->prepare('SELECT email FROM users WHERE id = ?');
        $emailOne->execute([(int) $one['seller_id']]);
        $oneEmail = (string) $emailOne->fetchColumn();

        if (login('sellerOne', $oneEmail, $DEMO_PASSWORD)['status'] === 200) {
            $dOne   = data(api('sellerOne', 'GET', 'seller/dashboard.php'));
            $myKeys = array_column($dOne['orders'] ?? [], 'db_id');

            check('Seller One sees their own half of the order', in_array((int) $one['id'], $myKeys, true));
            check('Seller One does NOT see the other seller\'s half', !in_array((int) $two['id'], $myKeys, true));

            $before = $two['status'];
            $cross  = post('sellerOne', 'seller/order_status.php', ['sub_order_id' => (int) $two['id'], 'status' => 'packed']);
            check('Seller One advancing the OTHER half → 404', $cross['status'] === 404, 'got ' . $cross['status']);

            $after = db()->prepare('SELECT status FROM sub_orders WHERE id = ?');
            $after->execute([(int) $two['id']]);
            check('…and the other half\'s status is unchanged', (string) $after->fetchColumn() === $before, $before);

            /* No customer email is ever exposed to a seller. */
            $custEmails = db()->query("SELECT email FROM users WHERE role = 'customer'")->fetchAll(PDO::FETCH_COLUMN);
            $leaked = array_filter($custEmails, static fn($e) => str_contains(json_encode($dOne['orders']), (string) $e));
            check('No customer email address appears in a seller\'s order data', !$leaked, $leaked ? implode(',', $leaked) : 'none');
            check(
                'The shipping address IS present (the seller has to post it)',
                isset($dOne['orders'][0]['shipping']['address'])
            );
        } else {
            check('Seller One signed in', false, $oneEmail);
        }
    } else {
        check('Found a seeded two-seller order', false, 'none in the database');
    }

    section('8. A seller cannot mark an order delivered');

    $cust = (int) db()->query("SELECT id FROM users WHERE role='customer' LIMIT 1")->fetchColumn();
    db()->prepare(
        "INSERT INTO orders (user_id, total_amount, currency, payment_status,
                             shipping_name, shipping_phone, shipping_address, shipping_city)
         VALUES (?, 235000, 'LKR', 'paid', 'Test Buyer', '0771234567', '1 Test Lane', 'Colombo')"
    )->execute([$cust]);
    $testOrderId = (int) db()->lastInsertId();

    db()->prepare(
        "INSERT INTO sub_orders (order_id, seller_id, status, subtotal, delivery_fee, commission_rate)
         VALUES (?, ?, 'pending', 235000, 450, 8.00)"
    )->execute([$testOrderId, $sellerAId]);
    $testSubId = (int) db()->lastInsertId();

    $packed = post('sellerA', 'seller/order_status.php', ['sub_order_id' => $testSubId, 'status' => 'packed']);
    check('Seller marks it packed', $packed['status'] === 200, 'status ' . $packed['status'] . ' ' . ($packed['body']['error'] ?? ''));

    $shipped = post('sellerA', 'seller/order_status.php', [
        'sub_order_id' => $testSubId, 'status' => 'shipped',
        'tracking_number' => 'TEST-12345', 'courier_name' => 'Pronto',
    ]);
    check('Seller marks it shipped, with tracking', $shipped['status'] === 200);

    $trk = db()->prepare('SELECT tracking_number, courier_name, payout_released_at FROM sub_orders WHERE id = ?');
    $trk->execute([$testSubId]);
    $trkRow = $trk->fetch();
    check('Tracking number stored', $trkRow['tracking_number'] === 'TEST-12345');
    check('Courier stored', $trkRow['courier_name'] === 'Pronto');
    check('ESCROW STILL HELD after the seller shipped it', $trkRow['payout_released_at'] === null);

    $selfPay = post('sellerA', 'seller/order_status.php', ['sub_order_id' => $testSubId, 'status' => 'delivered']);
    check(
        'Seller marking it DELIVERED is refused (409) — only the customer can',
        $selfPay['status'] === 409,
        'got ' . $selfPay['status'] . ' — ' . ($selfPay['body']['error'] ?? '')
    );

    $trk->execute([$testSubId]);
    check('…so the payout was NOT released', $trk->fetch()['payout_released_at'] === null);

    $backwards = post('sellerA', 'seller/order_status.php', ['sub_order_id' => $testSubId, 'status' => 'packed']);
    check('An order cannot move backwards (shipped → packed refused)', $backwards['status'] === 409);

    $nonsense = post('sellerA', 'seller/order_status.php', ['sub_order_id' => $testSubId, 'status' => 'banana']);
    check('An unknown status is refused', in_array($nonsense['status'], [409, 422], true), 'got ' . $nonsense['status']);

    /* ---- cancelling restocks ---- */
    db()->prepare("UPDATE sub_orders SET status='pending' WHERE id=?")->execute([$testSubId]);
    $pidStmt = db()->prepare('SELECT id, stock FROM products WHERE public_id = ?');
    $pidStmt->execute([$pubA]);
    $pRow = $pidStmt->fetch();
    db()->prepare(
        'INSERT INTO order_items (sub_order_id, product_id, product_title, quantity, unit_price)
         VALUES (?, ?, ?, 2, 235000)'
    )->execute([$testSubId, (int) $pRow['id'], 'Test Ultrabook 14 (2026)']);

    $stockBefore = (int) $pRow['stock'];
    $noReason = post('sellerA', 'seller/order_status.php', ['sub_order_id' => $testSubId, 'status' => 'cancelled']);
    check('Cancelling without a reason is refused', $noReason['status'] === 422, 'got ' . $noReason['status']);

    $cancel = post('sellerA', 'seller/order_status.php', [
        'sub_order_id' => $testSubId, 'status' => 'cancelled', 'note' => 'Out of stock at the warehouse',
    ]);
    check('Cancelling with a reason works', $cancel['status'] === 200, 'status ' . $cancel['status']);

    $pidStmt->execute([$pubA]);
    check(
        'Cancelling put the stock back',
        (int) $pidStmt->fetch()['stock'] === $stockBefore + 2,
        $stockBefore . ' → ' . ($stockBefore + 2)
    );

    $logged = db()->prepare('SELECT status, changed_by, note FROM order_status_log WHERE sub_order_id = ? ORDER BY id');
    $logged->execute([$testSubId]);
    $trail = $logged->fetchAll();
    check(
        'Every seller action is in the audit trail, attributed to "seller"',
        count($trail) >= 3 && $trail[0]['changed_by'] === 'seller',
        implode(' → ', array_column($trail, 'status'))
    );
    check(
        'The cancellation reason is on the record',
        in_array('Out of stock at the warehouse', array_column($trail, 'note'), true)
    );

    section('9. Image upload');

    $tmp = sys_get_temp_dir() . '/consult-test-upload.png';
    file_put_contents($tmp, base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8DwHwAFAAH/q842iQAAAABJRU5ErkJggg=='
    ));

    $up = upload('sellerA', 'seller/upload.php', $tmp, 'product');
    check('A real PNG uploads', $up['status'] === 201, 'status ' . $up['status'] . ' ' . ($up['body']['error'] ?? ''));

    $url = data($up)['url'] ?? '';
    check('Stored under uploads/ with a random name', (bool) preg_match('#^uploads/product-[0-9a-f]{24}\.png$#', $url), $url);
    check('The original filename is gone', !str_contains($url, 'consult-test-upload'));

    $diskPath = dirname(__DIR__, 2) . '/' . $url;
    check('The file is on disk', is_file($diskPath), $url);

    /* ---- a PHP script wearing a .png name ---- */
    $evil = sys_get_temp_dir() . '/evil.png';
    file_put_contents($evil, "<?php echo 'pwned'; ?>\n");
    $evilUp = upload('sellerA', 'seller/upload.php', $evil, 'product');
    check(
        'A PHP script renamed .png is REJECTED (content is checked, not the name)',
        $evilUp['status'] === 422,
        'got ' . $evilUp['status'] . ' — ' . ($evilUp['body']['error'] ?? '')
    );
    @unlink($evil);

    /* ---- the uploaded image can be attached to the listing ---- */
    $withImg = post('sellerA', 'seller/product_save.php', [
        'id' => $pubA, 'title' => 'Test Ultrabook 14 (2026)', 'category' => 'laptop',
        'price' => 235000, 'stock' => 9,
        'images' => [$url, 'http://evil.example.com/x.png', '../../../etc/passwd'],
    ]);
    $savedImgs = data($withImg)['product']['images'] ?? [];
    check('The uploaded image is attached to the listing', in_array($url, $savedImgs, true));
    check(
        'An off-site URL and a traversal path are both dropped',
        count($savedImgs) === 1,
        implode(' | ', $savedImgs)
    );

    @unlink($tmp);

    section('10. Store profile');

    $prof = post('sellerA', 'seller/profile.php', [
        'business_name' => 'Test Electronics Renamed',
        'city'          => 'Galle',
        'phone'         => '0712223334',
        // All four of these must be ignored.
        'kyc_status'      => 'rejected',
        'commission_rate' => 0,
        'nic_number'      => '999999999V',
        'role'            => 'admin',
    ]);
    check('Profile saved', $prof['status'] === 200, 'status ' . $prof['status'] . ' ' . ($prof['body']['error'] ?? ''));

    $after = db()->prepare(
        'SELECT s.business_name, s.city, s.kyc_status, s.commission_rate, s.nic_number, u.role, u.phone
           FROM sellers s JOIN users u ON u.id = s.id WHERE s.id = ?'
    );
    $after->execute([$sellerAId]);
    $a = $after->fetch();

    check('business_name updated', $a['business_name'] === 'Test Electronics Renamed');
    check('city updated', $a['city'] === 'Galle');
    check('phone updated', $a['phone'] === '0712223334');
    check('kyc_status IGNORED — a seller cannot un-verify or re-verify itself', $a['kyc_status'] === 'verified', $a['kyc_status']);
    check('commission_rate IGNORED — a seller cannot set its own cut', $a['commission_rate'] === null, var_export($a['commission_rate'], true));
    check('nic_number IGNORED', $a['nic_number'] === '912345678V', (string) $a['nic_number']);
    check('role IGNORED — no self-promotion to admin', $a['role'] === 'seller', $a['role']);

    $badProf = post('sellerA', 'seller/profile.php', ['business_name' => '', 'phone' => 'nope']);
    check('Invalid profile input refused per-field', $badProf['status'] === 422 && count($badProf['body']['fields'] ?? []) === 2);

    section('11. Dashboard stats and series');

    $dash = data(api('sellerA', 'GET', 'seller/dashboard.php'));

    check('stats block present', isset($dash['stats']['revenue_total'], $dash['stats']['orders_total']));
    check('payouts split into escrow / released / held', isset($dash['payouts']['escrow'], $dash['payouts']['released'], $dash['payouts']['held']));
    check('30-day series returned, one bucket per day', count($dash['series'] ?? []) === 30, count($dash['series'] ?? '') . ' buckets');
    check(
        'Quiet days are present as zeroes, not missing',
        count(array_filter($dash['series'], static fn(array $b): bool => (float) $b['revenue'] === 0.0)) > 0,
        count(array_filter($dash['series'], static fn(array $b): bool => (float) $b['revenue'] === 0.0)) . ' of 30 days are zero'
    );
    check(
        'The series ends today',
        ($dash['series'][count($dash['series']) - 1]['date'] ?? '') === date('Y-m-d'),
        $dash['series'][count($dash['series']) - 1]['date'] ?? '?'
    );

    $seven = data(api('sellerA', 'GET', 'seller/dashboard.php?days=7'));
    check('?days=7 returns a 7-day series', count($seven['series'] ?? []) === 7);

    check(
        'Product counts match the database',
        ($dash['stats']['products_total'] ?? -1) === 1,
        'reported ' . ($dash['stats']['products_total'] ?? '?')
    );
    check('commission_rate supplied for the payouts view', isset($dash['settings']['commission_rate']));
    check('category list supplied for the product form', count($dash['settings']['categories'] ?? []) > 0);

    section('12. Wrong-role and signed-out access');

    $endpoints = [
        ['GET',  'seller/dashboard.php'],
        ['POST', 'seller/product_save.php'],
        ['POST', 'seller/product_delete.php'],
        ['POST', 'seller/product_toggle.php'],
        ['POST', 'seller/stock.php'],
        ['POST', 'seller/sale.php'],
        ['POST', 'seller/order_status.php'],
        ['POST', 'seller/profile.php'],
        ['POST', 'seller/upload.php'],
    ];

    /* a signed-out guest */
    $guestBad = 0;
    foreach ($endpoints as [$m, $path]) {
        $r = $m === 'GET' ? api('fresh', 'GET', $path) : post('fresh', $path, []);
        if ($r['status'] !== 401) {
            $guestBad++;
            echo "         ↳ $path answered {$r['status']}, expected 401\n";
        }
    }
    check('All ' . count($endpoints) . ' seller endpoints refuse a signed-out guest (401)', $guestBad === 0);

    /* a customer */
    login('cust', 'nimal@example.com', $DEMO_PASSWORD);
    $custBad = 0;
    foreach ($endpoints as [$m, $path]) {
        $r = $m === 'GET' ? api('cust', 'GET', $path) : post('cust', $path, []);
        if ($r['status'] !== 403) {
            $custBad++;
            echo "         ↳ $path answered {$r['status']}, expected 403\n";
        }
    }
    check('All ' . count($endpoints) . ' seller endpoints refuse a CUSTOMER (403)', $custBad === 0);

    /* an admin — a different role, not a superset of this one */
    login('adm', 'admin@consult.lk', $DEMO_PASSWORD);
    $admBad = 0;
    foreach ($endpoints as [$m, $path]) {
        $r = $m === 'GET' ? api('adm', 'GET', $path) : post('adm', $path, []);
        if ($r['status'] !== 403) {
            $admBad++;
            echo "         ↳ $path answered {$r['status']}, expected 403\n";
        }
    }
    check('All ' . count($endpoints) . ' seller endpoints refuse an ADMIN (403)', $admBad === 0);

    /* CSRF */
    $noCsrf = api('sellerA', 'POST', 'seller/product_save.php', ['title' => 'x']);
    check('A seller write without a CSRF token is refused (403)', $noCsrf['status'] === 403);

    /* suspended */
    db()->prepare("UPDATE users SET status = 'suspended' WHERE id = ?")->execute([$sellerAId]);
    $susp = api('sellerA', 'GET', 'seller/dashboard.php');
    check('A SUSPENDED seller is locked out immediately (401)', $susp['status'] === 401, 'got ' . $susp['status']);
    db()->prepare("UPDATE users SET status = 'active' WHERE id = ?")->execute([$sellerAId]);

    /* ---- delete is a soft delete ---- */
    section('13. Deleting a listing is a soft delete');

    login('sellerA', $emailA, $DEMO_PASSWORD);
    $del = post('sellerA', 'seller/product_delete.php', ['id' => $pubA]);
    check('Listing deleted', $del['status'] === 200, 'status ' . $del['status']);

    $soft = db()->prepare('SELECT status, deleted_at FROM products WHERE public_id = ?');
    $soft->execute([$pubA]);
    $sRow = $soft->fetch();
    check('The row still EXISTS (order history references it)', $sRow !== false);
    check('deleted_at stamped and status inactive', $sRow && $sRow['deleted_at'] !== null && $sRow['status'] === 'inactive');
    check('It is gone from the storefront', api('guest', 'GET', 'products/detail.php?id=' . urlencode($pubA))['status'] === 404);

    $dashGone = data(api('sellerA', 'GET', 'seller/dashboard.php'));
    check('It is gone from the seller\'s own dashboard', !in_array($pubA, array_column($dashGone['products'] ?? [], 'id'), true));

    /* the order items that pointed at it still resolve */
    $itemsOk = db()->prepare(
        'SELECT COUNT(*) FROM order_items oi JOIN products p ON p.id = oi.product_id WHERE oi.sub_order_id = ?'
    );
    $itemsOk->execute([$testSubId]);
    check('The past order line still resolves to the product', (int) $itemsOk->fetchColumn() === 1);

    db()->prepare('DELETE FROM orders WHERE id = ?')->execute([$testOrderId]);
}

section('14. The shape seller-dashboard.js depends on');

$sellerB = 'techhub@example.com';
login('contract', $sellerB, $DEMO_PASSWORD);
$c = data(api('contract', 'GET', 'seller/dashboard.php'));

if (!$c) {
    check('Dashboard payload returned', false);
} else {
    /* ---- top level ---- */
    foreach (['seller', 'stats', 'series', 'payouts', 'products', 'orders', 'settings'] as $key) {
        check("payload.$key present", array_key_exists($key, $c));
    }

    /* ---- settings: read by SDB.settings() / commissionRate() ---- */
    check('settings.categories is a non-empty array', is_array($c['settings']['categories'] ?? null) && $c['settings']['categories']);
    check('settings.commission_rate is numeric', is_numeric($c['settings']['commission_rate'] ?? null));
    check('settings.commission_is_own is a bool', is_bool($c['settings']['commission_is_own'] ?? null));

    /* ---- payouts: read by the payouts view and the AI assistant ---- */
    foreach (['escrow', 'released', 'held'] as $key) {
        check("payouts.$key is numeric", is_numeric($c['payouts'][$key] ?? null));
    }

    /* ---- products: read by SDB.effectivePrice(), the grid and the form ---- */
    if (!empty($c['products'])) {
        $p = $c['products'][0];
        foreach (['id', 'title', 'category', 'price', 'stock', 'status', 'images', 'tags', 'specs', 'sale'] as $key) {
            check("product.$key present", array_key_exists($key, $p));
        }
        check('product.id is the public "p1" form', (bool) preg_match('/^p\d+$/', (string) $p['id']), (string) $p['id']);
        check('product.price is numeric', is_numeric($p['price']));
        check('product.stock is an int', is_int($p['stock']));
        check('product.images is an array', is_array($p['images']));
        check('product.sale is null or has sale_price/start/end/active', $p['sale'] === null || (
            isset($p['sale']['sale_price'], $p['sale']['start_date'], $p['sale']['end_date'])
            && array_key_exists('active', $p['sale'])
        ));
        check(
            'product.status is one of the four moderation states',
            in_array($p['status'], ['active', 'pending', 'rejected', 'inactive'], true),
            $p['status']
        );
    } else {
        check('Seller B has products to inspect', false);
    }

    /* ---- orders: read by the table, the kanban board and the modal ---- */
    if (!empty($c['orders'])) {
        $o = $c['orders'][0];
        foreach ([
            'id', 'key', 'order_id', 'status', 'status_updated_at', 'placed_at',
            'total', 'subtotal', 'delivery_fee', 'payout', 'shipping', 'items',
            'status_log', 'can', 'payout_released_at', 'refunded_at',
        ] as $key) {
            check("order.$key present", array_key_exists($key, $o));
        }

        check('order.key is a STRING (data-key round-trips as one)', is_string($o['key']), gettype($o['key']));

        check('order.id is the printed "SO-000004" reference', (bool) preg_match('/^SO-\d{6}$/', (string) $o['id']), (string) $o['id']);
        check('order.order_id is the printed "ORD-000001" reference', (bool) preg_match('/^ORD-\d{6}$/', (string) $o['order_id']), (string) $o['order_id']);

        check(
            'order.status is a known status',
            in_array($o['status'], ['pending', 'packed', 'shipped', 'delivered', 'cancelled', 'disputed', 'refunded'], true),
            $o['status']
        );

        foreach (['gross', 'rate', 'commission', 'net', 'state'] as $key) {
            check("order.payout.$key present", array_key_exists($key, $o['payout']));
        }
        check(
            'order.payout.state is one the UI has a pill for',
            in_array($o['payout']['state'], ['escrow', 'released', 'refunded', 'cancelled', 'held'], true),
            $o['payout']['state']
        );

        foreach (['pack', 'ship', 'cancel'] as $key) {
            check("order.can.$key is a bool", is_bool($o['can'][$key] ?? null));
        }

        check('order.shipping.name present (the board shows the customer)', isset($o['shipping']['name']));

        if (!empty($o['items'])) {
            $l = $o['items'][0];
            check('item.qty is an int', is_int($l['qty'] ?? null));
            check('item.product.category present (the AI panel groups by it)', isset($l['product']['category']));
            check('item.product.title present', isset($l['product']['title']));
        }

        if (!empty($o['status_log'])) {
            check('status_log entry has status/changed_by/changed_at', isset(
                $o['status_log'][0]['status'],
                $o['status_log'][0]['changed_by'],
                $o['status_log'][0]['changed_at']
            ));
        }

        /* Dates must be the MySQL form the dashboard's parseWhen() expects. */
        check(
            'placed_at is a MySQL datetime (parseWhen normalises it)',
            (bool) preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string) $o['placed_at']),
            (string) $o['placed_at']
        );
    } else {
        check('Seller B has orders to inspect', false);
    }

    /* ---- stats: every key the KPI strip reads ---- */
    foreach ([
        'revenue_total', 'revenue_30d', 'revenue_7d', 'orders_total', 'orders_open',
        'orders_new', 'orders_delivered', 'products_total', 'products_active',
        'low_stock', 'out_of_stock', 'units_sold', 'rating',
    ] as $key) {
        check("stats.$key is numeric", is_numeric($c['stats'][$key] ?? null), (string) ($c['stats'][$key] ?? 'missing'));
    }

    /* ---- series: one bucket per day, with every value the charts plot ---- */
    if (!empty($c['series'])) {
        $b = $c['series'][0];
        foreach (['date', 'label', 'revenue', 'orders', 'escrow', 'units'] as $key) {
            check("series bucket has $key", array_key_exists($key, $b));
        }
    }
}

section('Cleanup');

foreach ($madeProducts as $pub) {
    $pid = db()->prepare('SELECT id FROM products WHERE public_id = ?');
    $pid->execute([$pub]);
    $num = $pid->fetchColumn();
    if ($num) {
        db()->prepare('DELETE FROM order_items WHERE product_id = ?')->execute([(int) $num]);
        db()->prepare('DELETE FROM products WHERE id = ?')->execute([(int) $num]);
    }
}
check('Test listings removed', true, count($madeProducts) . ' listing(s)');

/* Remove the files this run uploaded, so uploads/ does not accumulate. */
$removed = 0;
foreach (glob(dirname(__DIR__, 2) . '/uploads/product-*') ?: [] as $f) {
    if (filemtime($f) >= time() - 600) {
        @unlink($f);
        $removed++;
    }
}
check('Uploaded test images removed', true, $removed . ' file(s)');

$testUsers = db()->query("SELECT id FROM users WHERE email LIKE 'selltest-%'")
    ->fetchAll(PDO::FETCH_COLUMN);
$testUsers = array_values(array_unique(array_merge(
    array_map('intval', $testUsers),
    array_filter($madeUsers)
)));

if ($testUsers) {
    $in = implode(',', array_fill(0, count($testUsers), '?'));

    // 1. whole orders that have a test seller on any sub-order
    //    (cascades to sub_orders → order_items → order_status_log)
    db()->prepare(
        "DELETE FROM orders
          WHERE id IN (SELECT order_id FROM sub_orders WHERE seller_id IN ($in))"
    )->execute($testUsers);

    // 2. orders a test user placed as the customer
    db()->prepare("DELETE FROM orders WHERE user_id IN ($in)")->execute($testUsers);

    // 3. order lines pointing at a test seller's products, then the products
    db()->prepare(
        "DELETE oi FROM order_items oi
           JOIN products p ON p.id = oi.product_id
          WHERE p.seller_id IN ($in)"
    )->execute($testUsers);
    db()->prepare("DELETE FROM products WHERE seller_id IN ($in)")->execute($testUsers);

    // 4. the accounts themselves (sellers cascades off users)
    db()->prepare("DELETE FROM users WHERE id IN ($in)")->execute($testUsers);
}

$left = db()->query("SELECT COUNT(*) FROM users WHERE email LIKE 'selltest-%'")->fetchColumn();
check(
    'Throwaway seller accounts and their rows removed',
    ((int) $left) === 0,
    $left ? $left . ' left behind' : count($testUsers) . ' account(s) cleaned'
);

foreach (glob($jarDir . '/*.txt') ?: [] as $f) {
    @unlink($f);
}
@rmdir($jarDir);

echo "\n", str_repeat('-', 68), "\n";
printf("  %d passed, %d failed\n", $pass, $fail);
echo str_repeat('-', 68), "\n";

exit($fail === 0 ? 0 : 1);
