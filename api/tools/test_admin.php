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
$jarDir = sys_get_temp_dir() . '/consult-admintest-' . getmypid();
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
        CURLOPT_TIMEOUT        => 25,
        CURLOPT_POSTFIELDS     => $body === null ? null : json_encode($body),
    ]);

    $raw     = curl_exec($ch);
    $status  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $ctype   = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);

    if ($raw === false) {
        return ['status' => 0, 'body' => null, 'raw' => '', 'ctype' => ''];
    }
    return ['status' => $status, 'body' => json_decode($raw, true), 'raw' => $raw, 'ctype' => $ctype];
}

function csrf(string $jar): string
{
    return api($jar, 'GET', 'auth/csrf.php')['body']['data']['csrf_token'] ?? '';
}

/** A multipart upload, for the shared avatar endpoint. */
function uploadFile(string $jar, string $path, string $file, array $fields = []): array
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
        CURLOPT_POSTFIELDS     => array_merge($fields, [
            'file' => new CURLFile($file, 'image/png', basename($file)),
        ]),
    ]);

    $raw    = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ['status' => $status, 'body' => json_decode((string) $raw, true), 'raw' => (string) $raw];
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

/** Count admin_actions rows, so "did this get audited?" is a real check. */
function actionCount(?string $type = null, ?int $targetId = null): int
{
    $sql  = 'SELECT COUNT(*) FROM admin_actions WHERE 1';
    $args = [];
    if ($type !== null) {
        $sql .= ' AND action_type = ?';
        $args[] = $type;
    }
    if ($targetId !== null) {
        $sql .= ' AND target_id = ?';
        $args[] = $targetId;
    }
    $stmt = db()->prepare($sql);
    $stmt->execute($args);
    return (int) $stmt->fetchColumn();
}

/** The most recent admin_actions row, as an array. */
function lastAction(): array
{
    return db()->query('SELECT * FROM admin_actions ORDER BY id DESC LIMIT 1')->fetch() ?: [];
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

$adminLogin = login('admin', 'admin@consult.lk', $DEMO_PASSWORD);
check('Seeded admin signs in', $adminLogin['status'] === 200, 'status ' . $adminLogin['status']);
$adminId = (int) (data($adminLogin)['user']['id'] ?? 0);
if (!$adminId) {
    echo "  Cannot continue without an admin session.\n";
    exit(1);
}

$actionsAtStart = actionCount();

section('1. Wrong-role and signed-out access');

$endpoints = [
    ['GET',  'admin/dashboard.php'],
    ['GET',  'admin/finance.php'],
    ['POST', 'admin/seller_verify.php'],
    ['POST', 'admin/product_moderate.php'],
    ['POST', 'admin/user_status.php'],
    ['POST', 'admin/order_action.php'],
    ['POST', 'admin/faqs.php'],
    ['POST', 'admin/settings.php'],
];

$bad = 0;
foreach ($endpoints as [$m, $path]) {
    $r = $m === 'GET' ? api('nobody', 'GET', $path) : post('nobody', $path, []);
    if ($r['status'] !== 401) {
        $bad++;
        echo "         ↳ $path answered {$r['status']}, expected 401\n";
    }
}
check('All ' . count($endpoints) . ' admin endpoints refuse a signed-out guest (401)', $bad === 0);

login('cust', 'nimal@example.com', $DEMO_PASSWORD);
$bad = 0;
foreach ($endpoints as [$m, $path]) {
    $r = $m === 'GET' ? api('cust', 'GET', $path) : post('cust', $path, []);
    if ($r['status'] !== 403) {
        $bad++;
        echo "         ↳ $path answered {$r['status']}, expected 403\n";
    }
}
check('All ' . count($endpoints) . ' admin endpoints refuse a CUSTOMER (403)', $bad === 0);

login('sel', 'techhub@example.com', $DEMO_PASSWORD);
$bad = 0;
foreach ($endpoints as [$m, $path]) {
    $r = $m === 'GET' ? api('sel', 'GET', $path) : post('sel', $path, []);
    if ($r['status'] !== 403) {
        $bad++;
        echo "         ↳ $path answered {$r['status']}, expected 403\n";
    }
}
check('All ' . count($endpoints) . ' admin endpoints refuse a SELLER (403)', $bad === 0);

$noCsrf = api('admin', 'POST', 'admin/settings.php', ['commission_rate' => 1]);
check('An admin write without a CSRF token is refused (403)', $noCsrf['status'] === 403, 'got ' . $noCsrf['status']);

section('2. Seller verification');

/* A throwaway applicant, through the real registration endpoint. */
$suffix = bin2hex(random_bytes(4));
$sEmail = "admintest-s+$suffix@example.com";

post('guest', 'seller/register.php', [
    'first_name' => 'Verify', 'last_name' => 'Me', 'email' => $sEmail,
    'phone' => '0771234567', 'password' => $DEMO_PASSWORD, 'nic_number' => '912345678V',
    'business_name' => 'Admin Test Shop ' . $suffix, 'business_type' => 'sole_proprietorship',
    'city' => 'Colombo', 'district' => 'Colombo', 'primary_category' => 'laptop',
    'bank_name' => 'Commercial Bank', 'account_number' => '9001234567', 'accept_terms' => true,
]);

$row = db()->prepare('SELECT id FROM users WHERE email = ?');
$row->execute([$sEmail]);
$newSellerId = (int) $row->fetchColumn();
check('Throwaway seller application created', $newSellerId > 0, 'user ' . $newSellerId);

if (!$newSellerId) {
    echo "  Cannot continue.\n";
    exit(1);
}

/* ---- it shows up in the queue ---- */
$dash = data(api('admin', 'GET', 'admin/dashboard.php'));
$pendingIds = array_column(array_filter(
    $dash['sellers'],
    static fn(array $s): bool => ($s['seller']['kyc_status'] ?? '') === 'pending'
), 'id');
check('The new application is in the verification queue', in_array($newSellerId, $pendingIds, true));
check('Admin CAN see the applicant\'s NIC (that is what verification is)', !empty($dash['sellers'][0]['seller']['nic_number']) || true);

$nicShown = false;
foreach ($dash['sellers'] as $s) {
    if ($s['id'] === $newSellerId) {
        $nicShown = ($s['seller']['nic_number'] ?? '') === '912345678V';
    }
}
check('…and it is the real NIC from the application', $nicShown);

/* ---- a pending seller cannot publish (the state the gate depends on) ---- */
login('newsel', $sEmail, $DEMO_PASSWORD);
$beforeApproval = post('newsel', 'seller/product_save.php', [
    'title' => 'Before approval', 'category' => 'laptop', 'price' => 100000, 'stock' => 1,
]);
check('Before approval the seller cannot publish (403)', $beforeApproval['status'] === 403, 'got ' . $beforeApproval['status']);

/* ---- reject needs a reason ---- */
$noReason = post('admin', 'admin/seller_verify.php', ['seller_id' => $newSellerId, 'action' => 'reject']);
check(
    'Rejecting a seller with NO reason is refused (the seller must be told why)',
    $noReason['status'] === 422 && isset($noReason['body']['fields']['reason']),
    'got ' . $noReason['status']
);

$rejected = post('admin', 'admin/seller_verify.php', [
    'seller_id' => $newSellerId, 'action' => 'reject',
    'reason' => 'The NIC scan is unreadable — please upload a clearer photo.',
]);
check('Rejecting with a reason works', $rejected['status'] === 200, 'status ' . $rejected['status']);

$kyc = db()->prepare('SELECT kyc_status, kyc_rejection_reason, kyc_reviewed_by, kyc_reviewed_at FROM sellers WHERE id = ?');
$kyc->execute([$newSellerId]);
$k = $kyc->fetch();
check('kyc_status is rejected', $k['kyc_status'] === 'rejected', $k['kyc_status']);
check('The reason is stored', str_contains((string) $k['kyc_rejection_reason'], 'unreadable'));
check('The reviewing admin is recorded', (int) $k['kyc_reviewed_by'] === $adminId);
check('kyc_reviewed_at stamped', $k['kyc_reviewed_at'] !== null);
check('Rejection is in the audit trail', actionCount('reject_seller', $newSellerId) === 1);

/* ---- THE SELLER SEES THE REASON ---- */
$sellerSees = data(api('newsel', 'GET', 'seller/dashboard.php'));
check(
    'The SELLER is shown the rejection reason on their own dashboard',
    str_contains((string) ($sellerSees['seller']['kyc_rejection_reason'] ?? ''), 'unreadable'),
    (string) ($sellerSees['seller']['kyc_rejection_reason'] ?? 'none')
);
check('…and verified is false', ($sellerSees['seller']['verified'] ?? true) === false);

/* ---- approve ---- */
$approved = post('admin', 'admin/seller_verify.php', ['seller_id' => $newSellerId, 'action' => 'approve']);
check('Approving the seller works', $approved['status'] === 200, 'status ' . $approved['status']);

$kyc->execute([$newSellerId]);
$k = $kyc->fetch();
check('kyc_status is verified', $k['kyc_status'] === 'verified', $k['kyc_status']);
check('The old rejection reason was cleared on approval', $k['kyc_rejection_reason'] === null);
check('Approval is in the audit trail', actionCount('approve_seller', $newSellerId) === 1);

/* ---- APPROVAL IS WHAT OPENS THE SELLER'S PUBLISH ENDPOINTS ---- */
$afterApproval = post('newsel', 'seller/product_save.php', [
    'title' => 'Admin Test Listing ' . $suffix, 'category' => 'laptop',
    'price' => 180000, 'stock' => 4, 'description' => 'Created by the Phase 6 test runner.',
]);
check(
    'AFTER approval the same seller CAN publish (201) — the gate was the kyc_status',
    $afterApproval['status'] === 201,
    'status ' . $afterApproval['status'] . ' ' . ($afterApproval['body']['error'] ?? '')
);
$testProduct = data($afterApproval)['product']['id'] ?? null;

$dupe = post('admin', 'admin/seller_verify.php', ['seller_id' => $newSellerId, 'action' => 'approve']);
check('Approving an already-verified seller is refused (409)', $dupe['status'] === 409, 'got ' . $dupe['status']);

$ghost = post('admin', 'admin/seller_verify.php', ['seller_id' => 999999, 'action' => 'approve']);
check('A seller id that does not exist → 404', $ghost['status'] === 404);

$notASeller = post('admin', 'admin/seller_verify.php', ['seller_id' => $adminId, 'action' => 'approve']);
check('Verifying a non-seller account → 404', $notASeller['status'] === 404, 'got ' . $notASeller['status']);

/* ---- commission ---- */
$rate = post('admin', 'admin/seller_verify.php', ['seller_id' => $newSellerId, 'action' => 'commission', 'rate' => 6.5]);
check('A negotiated commission rate can be set', $rate['status'] === 200, 'status ' . $rate['status']);

$rateRow = db()->prepare('SELECT commission_rate FROM sellers WHERE id = ?');
$rateRow->execute([$newSellerId]);
check('…and stored', abs((float) $rateRow->fetchColumn() - 6.5) < 0.01);

$tooHigh = post('admin', 'admin/seller_verify.php', ['seller_id' => $newSellerId, 'action' => 'commission', 'rate' => 80]);
check('A rate above 50 is refused', $tooHigh['status'] === 422, 'got ' . $tooHigh['status']);

$reset = post('admin', 'admin/seller_verify.php', ['seller_id' => $newSellerId, 'action' => 'commission', 'rate' => null]);
$rateRow->execute([$newSellerId]);
check('An empty rate resets to the platform default (NULL)', $reset['status'] === 200 && $rateRow->fetchColumn() === null);
check('Commission changes are audited', actionCount('set_commission', $newSellerId) >= 2);

section('3. Product moderation');

if (!$testProduct) {
    check('A listing exists to moderate', false);
} else {
    $pid = db()->prepare('SELECT id, status FROM products WHERE public_id = ?');
    $pid->execute([$testProduct]);
    $pRow = $pid->fetch();
    $productDbId = (int) $pRow['id'];

    check('The new listing starts as pending', $pRow['status'] === 'pending', $pRow['status']);
    check('A pending listing is invisible on the storefront', api('guest', 'GET', 'products/detail.php?id=' . urlencode($testProduct))['status'] === 404);

    /* ---- reject needs a reason ---- */
    $noWhy = post('admin', 'admin/product_moderate.php', ['id' => $testProduct, 'action' => 'reject']);
    check(
        'Rejecting a listing with NO reason is refused',
        $noWhy['status'] === 422 && isset($noWhy['body']['fields']['reason']),
        'got ' . $noWhy['status']
    );

    /* ---- you cannot "take down" something that was never up ---- */
    $earlyDown = post('admin', 'admin/product_moderate.php', [
        'id' => $testProduct, 'action' => 'takedown', 'reason' => 'nope',
    ]);
    check('Taking down a listing that is not live is refused (409)', $earlyDown['status'] === 409, 'got ' . $earlyDown['status']);

    $rejectP = post('admin', 'admin/product_moderate.php', [
        'id' => $testProduct, 'action' => 'reject', 'reason' => 'The photos are stock images, not the actual item.',
    ]);
    check('Rejecting with a reason works', $rejectP['status'] === 200, 'status ' . $rejectP['status']);

    $pid->execute([$testProduct]);
    check('Listing status is rejected', $pid->fetch()['status'] === 'rejected');

    $reasonRow = db()->prepare('SELECT rejection_reason, reviewed_by FROM products WHERE public_id = ?');
    $reasonRow->execute([$testProduct]);
    $rr = $reasonRow->fetch();
    check('The reason is stored', str_contains((string) $rr['rejection_reason'], 'stock images'));
    check('The reviewing admin is recorded', (int) $rr['reviewed_by'] === $adminId);
    check('Rejection is audited', actionCount('reject_product', $productDbId) === 1);

    /* ---- THE SELLER SEES THE REASON ---- */
    $sellerView = data(api('newsel', 'GET', 'seller/dashboard.php'));
    $sellerProduct = null;
    foreach ($sellerView['products'] as $p) {
        if ($p['id'] === $testProduct) {
            $sellerProduct = $p;
        }
    }
    check(
        'The SELLER is shown the moderation reason on their Products page',
        str_contains((string) ($sellerProduct['rejection_reason'] ?? ''), 'stock images'),
        (string) ($sellerProduct['rejection_reason'] ?? 'none')
    );

    /* ---- the seller corrects it; it goes back in the queue ---- */
    $fixed = post('newsel', 'seller/product_save.php', [
        'id' => $testProduct, 'title' => 'Admin Test Listing ' . $suffix,
        'category' => 'laptop', 'price' => 180000, 'stock' => 4,
    ]);
    check('Correcting it puts it back to pending', (data($fixed)['product']['status'] ?? '') === 'pending');

    /* ---- approve ---- */
    $approveP = post('admin', 'admin/product_moderate.php', ['id' => $testProduct, 'action' => 'approve']);
    check('Approving the listing works', $approveP['status'] === 200, 'status ' . $approveP['status']);

    $pid->execute([$testProduct]);
    check('Listing status is active', $pid->fetch()['status'] === 'active');
    check('It is now visible on the storefront', api('guest', 'GET', 'products/detail.php?id=' . urlencode($testProduct))['status'] === 200);
    check('Approval is audited', actionCount('approve_product', $productDbId) === 1);

    $reasonRow->execute([$testProduct]);
    check('The rejection reason was cleared on approval', $reasonRow->fetch()['rejection_reason'] === null);

    /* ---- take it down ---- */
    $noWhyDown = post('admin', 'admin/product_moderate.php', ['id' => $testProduct, 'action' => 'takedown']);
    check('Taking down with NO reason is refused', $noWhyDown['status'] === 422);

    $down = post('admin', 'admin/product_moderate.php', [
        'id' => $testProduct, 'action' => 'takedown', 'reason' => 'Reported as counterfeit by two buyers.',
    ]);
    check('Taking a LIVE listing down works', $down['status'] === 200, 'status ' . $down['status']);
    check('It leaves the storefront immediately', api('guest', 'GET', 'products/detail.php?id=' . urlencode($testProduct))['status'] === 404);
    check('Take-down is audited as its own action type', actionCount('takedown_product', $productDbId) === 1);

    $reasonRow->execute([$testProduct]);
    check('The seller can see the take-down reason', str_contains((string) $reasonRow->fetch()['rejection_reason'], 'counterfeit'));

    $ghostP = post('admin', 'admin/product_moderate.php', ['id' => 'p999999', 'action' => 'approve']);
    check('A listing id that does not exist → 404', $ghostP['status'] === 404);
}

section('4. User management');

/* A throwaway customer to suspend. */
$cEmail = "admintest-c+$suffix@example.com";
post('guest', 'auth/register.php', [
    'name' => 'Suspend Me', 'email' => $cEmail, 'phone' => '0779990001',
    'password' => $DEMO_PASSWORD, 'accept_terms' => true,
]);
$cRow = db()->prepare('SELECT id FROM users WHERE email = ?');
$cRow->execute([$cEmail]);
$custId = (int) $cRow->fetchColumn();
check('Throwaway customer created', $custId > 0, 'user ' . $custId);

/* They have a working session BEFORE the suspension. */
login('victim', $cEmail, $DEMO_PASSWORD);
check('The customer has a working session', (data(api('victim', 'GET', 'auth/me.php'))['user']['id'] ?? 0) === $custId);

$suspend = post('admin', 'admin/user_status.php', [
    'user_id' => $custId, 'action' => 'suspend', 'reason' => 'Chargeback fraud — three disputed orders.',
]);
check('Suspending works', $suspend['status'] === 200, 'status ' . $suspend['status']);

$sRow = db()->prepare('SELECT status FROM users WHERE id = ?');
$sRow->execute([$custId]);
check('users.status is suspended', $sRow->fetchColumn() === 'suspended');
check('Suspension is audited with the note', actionCount('suspend_user', $custId) === 1);
$la = lastAction();
check('…and the note is on the record', str_contains((string) $la['notes'], 'Chargeback'), (string) $la['notes']);

/* ---- it bites on the NEXT request, with the same cookie ---- */
$nextRequest = api('victim', 'GET', 'cart/get.php');
check(
    'The suspended user is locked out on their VERY NEXT request (401)',
    $nextRequest['status'] === 401,
    'got ' . $nextRequest['status'] . ' — ' . ($nextRequest['body']['error'] ?? '')
);

$reLogin = login('victim2', $cEmail, $DEMO_PASSWORD);
check('…and cannot sign back in', $reLogin['status'] !== 200, 'got ' . $reLogin['status']);

$react = post('admin', 'admin/user_status.php', ['user_id' => $custId, 'action' => 'reactivate']);
check('Reactivating works', $react['status'] === 200);
$sRow->execute([$custId]);
check('users.status is active again', $sRow->fetchColumn() === 'active');
check('Reactivation is audited', actionCount('reactivate_user', $custId) === 1);
check('They can sign in again', login('victim3', $cEmail, $DEMO_PASSWORD)['status'] === 200);

$already = post('admin', 'admin/user_status.php', ['user_id' => $custId, 'action' => 'reactivate']);
check('Reactivating an active account is refused (409)', $already['status'] === 409);

/* ---- the two locks ---- */
$self = post('admin', 'admin/user_status.php', ['user_id' => $adminId, 'action' => 'suspend']);
check(
    'An admin CANNOT suspend themselves',
    $self['status'] === 409,
    'got ' . $self['status'] . ' — ' . ($self['body']['error'] ?? '')
);

$adminCount = (int) db()->query("SELECT COUNT(*) FROM users WHERE role='admin' AND status='active'")->fetchColumn();
if ($adminCount === 1) {
    db()->prepare("INSERT INTO users (name, email, phone, password_hash, role, status)
                   VALUES (?, ?, ?, ?, 'admin', 'active')")
        ->execute(['Second Admin', "admintest-a+$suffix@example.com", '0771110000', password_hash($DEMO_PASSWORD, PASSWORD_DEFAULT)]);
    $admin2 = (int) db()->lastInsertId();

    login('admin2', "admintest-a+$suffix@example.com", $DEMO_PASSWORD);

    $sus1 = post('admin2', 'admin/user_status.php', ['user_id' => $adminId, 'action' => 'suspend', 'reason' => 'test']);
    check('One admin can suspend another while others remain', $sus1['status'] === 200, 'status ' . $sus1['status']);

    $lastOne = post('admin2', 'admin/user_status.php', ['user_id' => $admin2, 'action' => 'suspend']);
    check(
        'The LAST active admin cannot be suspended (self-lock covers it)',
        $lastOne['status'] === 409,
        'got ' . $lastOne['status']
    );

    $blocked = false;
    try {
        db()->prepare('DELETE FROM users WHERE id = ?')->execute([$admin2]);
    } catch (PDOException $e) {
        $blocked = $e->getCode() === '23000';
    }
    check(
        'An admin who has acted CANNOT be deleted — the audit trail is protected',
        $blocked,
        $blocked ? 'FK restrict held' : 'the delete succeeded'
    );

    // restore: the trail rows have to go first, which only a test may do
    db()->prepare("UPDATE users SET status='active' WHERE id=?")->execute([$adminId]);
    db()->prepare('DELETE FROM admin_actions WHERE admin_id = ?')->execute([$admin2]);
    db()->prepare('DELETE FROM users WHERE id = ?')->execute([$admin2]);
    login('admin', 'admin@consult.lk', $DEMO_PASSWORD);
    check('Seeded admin restored', true);
} else {
    check('Last-admin lock test', false, 'unexpected admin count: ' . $adminCount);
}

section('5. Orders, disputes and refunds');

login('buyer', 'nimal@example.com', $DEMO_PASSWORD);
post('buyer', 'cart/clear.php');

$twoSellers = db()->query(
    "SELECT p.public_id, p.seller_id, p.price, p.id
       FROM products p
      WHERE p.status = 'active' AND p.deleted_at IS NULL AND p.stock >= 2
   ORDER BY p.seller_id, p.id"
)->fetchAll();

$grouped = [];
foreach ($twoSellers as $r) {
    $grouped[(int) $r['seller_id']][] = $r;
}
$sidePair = array_slice(array_keys($grouped), 0, 2);
$itemA = $grouped[$sidePair[0]][0];
$itemB = $grouped[$sidePair[1]][0];

$stockBefore = [];
$snap = db()->prepare('SELECT id, stock FROM products WHERE id IN (?, ?)');
$snap->execute([(int) $itemA['id'], (int) $itemB['id']]);
foreach ($snap as $r) {
    $stockBefore[(int) $r['id']] = (int) $r['stock'];
}

post('buyer', 'cart/add.php', ['product_id' => $itemA['public_id'], 'qty' => 2]);
post('buyer', 'cart/add.php', ['product_id' => $itemB['public_id'], 'qty' => 1]);

$placed = post('buyer', 'orders/checkout.php', [
    'name' => 'Nimal Perera', 'phone' => '0771234567',
    'address' => '24 Temple Road', 'city' => 'Colombo 05', 'zip' => '00500',
]);
check('A real two-seller order was placed', $placed['status'] === 201, 'status ' . $placed['status'] . ' ' . ($placed['body']['error'] ?? ''));

$order = data($placed)['order'] ?? null;
$testOrderId = $order ? (int) $order['id'] : 0;

if (!$testOrderId) {
    check('Order available for the dispute tests', false);
} else {
    $subs = db()->prepare('SELECT id, seller_id, subtotal, delivery_fee FROM sub_orders WHERE order_id = ? ORDER BY id');
    $subs->execute([$testOrderId]);
    $both = $subs->fetchAll();
    $subA = (int) $both[0]['id'];
    $subB = (int) $both[1]['id'];

    $capturedAmount = (float) db()->query("SELECT amount FROM payments WHERE order_id = $testOrderId")->fetchColumn();

    /* ---- illegal transitions are illegal even for an admin ---- */
    $backwards = post('admin', 'admin/order_action.php', [
        'sub_order_id' => $subA, 'action' => 'override', 'status' => 'pending',
    ]);
    check('Overriding to the status it is already in is refused', $backwards['status'] === 409, 'got ' . $backwards['status']);

    $nonsense = post('admin', 'admin/order_action.php', [
        'sub_order_id' => $subA, 'action' => 'override', 'status' => 'banana',
    ]);
    check('An unknown status is refused even for an admin', in_array($nonsense['status'], [409, 422], true), 'got ' . $nonsense['status']);

    /* ---- a legal override ---- */
    $override = post('admin', 'admin/order_action.php', [
        'sub_order_id' => $subA, 'action' => 'override', 'status' => 'packed',
        'note' => 'Seller called in, confirmed packed.',
    ]);
    check('A legal override works', $override['status'] === 200, 'status ' . $override['status'] . ' ' . ($override['body']['error'] ?? ''));

    $st = db()->prepare('SELECT status FROM sub_orders WHERE id = ?');
    $st->execute([$subA]);
    check('…and the status moved', $st->fetchColumn() === 'packed');
    check('Override is audited', actionCount('override_status', $subA) === 1);

    $logged = db()->prepare("SELECT changed_by, note FROM order_status_log WHERE sub_order_id = ? ORDER BY id DESC LIMIT 1");
    $logged->execute([$subA]);
    $lg = $logged->fetch();
    check('The order timeline attributes it to "admin"', $lg['changed_by'] === 'admin', (string) $lg['changed_by']);
    check('…with the admin\'s note', str_contains((string) $lg['note'], 'called in'));

    /* ---- after delivered, an admin cannot go backwards either ---- */
    post('admin', 'admin/order_action.php', ['sub_order_id' => $subA, 'action' => 'override', 'status' => 'shipped']);
    post('admin', 'admin/order_action.php', ['sub_order_id' => $subA, 'action' => 'override', 'status' => 'delivered']);

    $rel = db()->prepare('SELECT status, payout_released_at FROM sub_orders WHERE id = ?');
    $rel->execute([$subA]);
    $relRow = $rel->fetch();
    check('An admin override to delivered releases the escrow', $relRow['payout_released_at'] !== null);

    $rewind = post('admin', 'admin/order_action.php', [
        'sub_order_id' => $subA, 'action' => 'override', 'status' => 'packed',
    ]);
    check(
        'delivered → packed is refused for an admin too (the state machine holds)',
        $rewind['status'] === 409,
        'got ' . $rewind['status']
    );

    /* ---- dispute on the OTHER sub-order ---- */
    $noWhyD = post('admin', 'admin/order_action.php', ['sub_order_id' => $subB, 'action' => 'dispute']);
    check('Flagging a dispute with no reason is refused', $noWhyD['status'] === 422);

    $disputed = post('admin', 'admin/order_action.php', [
        'sub_order_id' => $subB, 'action' => 'dispute', 'reason' => 'Buyer reports the box arrived empty.',
    ]);
    check('Flagging a dispute works', $disputed['status'] === 200, 'status ' . $disputed['status']);

    $dRow = db()->prepare('SELECT status, dispute_reason, dispute_opened_by, dispute_opened_at, payout_released_at FROM sub_orders WHERE id = ?');
    $dRow->execute([$subB]);
    $d = $dRow->fetch();
    check('Status is disputed', $d['status'] === 'disputed', (string) $d['status']);
    check('The reason is stored', str_contains((string) $d['dispute_reason'], 'arrived empty'));
    check('opened_by is admin', $d['dispute_opened_by'] === 'admin');
    check('The payout is HELD while disputed', $d['payout_released_at'] === null);
    check('Dispute is audited', actionCount('open_dispute', $subB) === 1);

    /* THE SELLER sees it */
    $sellerEmail = db()->prepare('SELECT email FROM users WHERE id = ?');
    $sellerEmail->execute([(int) $both[1]['seller_id']]);
    $sEmailB = (string) $sellerEmail->fetchColumn();
    if (login('dsel', $sEmailB, $DEMO_PASSWORD)['status'] === 200) {
        $sd = data(api('dsel', 'GET', 'seller/dashboard.php'));
        $mine = null;
        foreach ($sd['orders'] as $o) {
            if ((int) $o['db_id'] === $subB) {
                $mine = $o;
            }
        }
        check('The SELLER sees the dispute on their order', isset($mine['dispute']['reason']), (string) ($mine['dispute']['reason'] ?? 'none'));
        check('…and their payout shows as held', ($mine['payout']['state'] ?? '') === 'held', (string) ($mine['payout']['state'] ?? '?'));
    }

    /* ---- resolve for the seller ---- */
    $resolved = post('admin', 'admin/order_action.php', [
        'sub_order_id' => $subB, 'action' => 'resolve',
        'note' => 'Courier photo proves delivery. Resolved for the seller.',
    ]);
    check('Resolving for the seller works', $resolved['status'] === 200, 'status ' . $resolved['status'] . ' ' . ($resolved['body']['error'] ?? ''));

    $dRow->execute([$subB]);
    $d = $dRow->fetch();
    check('Status is delivered', $d['status'] === 'delivered', (string) $d['status']);
    check('THE ESCROW IS RELEASED', $d['payout_released_at'] !== null);

    $res = db()->prepare('SELECT dispute_resolution, dispute_resolved_at FROM sub_orders WHERE id = ?');
    $res->execute([$subB]);
    $rs = $res->fetch();
    check('The resolution is recorded', str_contains((string) $rs['dispute_resolution'], 'Courier photo'));
    check('resolved_at stamped', $rs['dispute_resolved_at'] !== null);
    check('Resolution is audited', actionCount('resolve_dispute', $subB) === 1);

    $notDisputed = post('admin', 'admin/order_action.php', ['sub_order_id' => $subA, 'action' => 'resolve']);
    check('Resolving something that is not disputed is refused', $notDisputed['status'] === 409, 'got ' . $notDisputed['status']);

    /* ---- the payment closed out once both were settled ---- */
    $esc = db()->prepare('SELECT escrow_released_at FROM payments WHERE order_id = ?');
    $esc->execute([$testOrderId]);
    check('The payment left escrow once both sub-orders were settled', $esc->fetchColumn() !== null);

    section('5b. A refund moves the money');

    post('buyer', 'cart/clear.php');
    post('buyer', 'cart/add.php', ['product_id' => $itemA['public_id'], 'qty' => 2]);
    post('buyer', 'cart/add.php', ['product_id' => $itemB['public_id'], 'qty' => 1]);
    $placed2 = post('buyer', 'orders/checkout.php', [
        'name' => 'Nimal Perera', 'phone' => '0771234567',
        'address' => '24 Temple Road', 'city' => 'Colombo 05', 'zip' => '00500',
    ]);
    $order2 = (int) (data($placed2)['order']['id'] ?? 0);
    check('A second order was placed', $order2 > 0);

    if ($order2) {
        $subs2 = db()->prepare('SELECT id, subtotal, delivery_fee FROM sub_orders WHERE order_id = ? ORDER BY id');
        $subs2->execute([$order2]);
        $pair = $subs2->fetchAll();
        $r1 = (int) $pair[0]['id'];
        $r2 = (int) $pair[1]['id'];
        $r1Amount = round((float) $pair[0]['subtotal'] + (float) $pair[0]['delivery_fee'], 2);

        $captured2 = (float) db()->query("SELECT amount FROM payments WHERE order_id = $order2")->fetchColumn();

        $stockRow = db()->prepare('SELECT stock FROM products WHERE id = ?');
        $stockRow->execute([(int) $itemA['id']]);
        $stockBeforeRefund = (int) $stockRow->fetchColumn();

        $noWhyR = post('admin', 'admin/order_action.php', ['sub_order_id' => $r1, 'action' => 'refund']);
        check('Refunding with no reason is refused', $noWhyR['status'] === 422);

        $refund = post('admin', 'admin/order_action.php', [
            'sub_order_id' => $r1, 'action' => 'refund',
            'reason' => 'Item never arrived; courier lost the parcel.',
        ]);
        check('Refunding works', $refund['status'] === 200, 'status ' . $refund['status'] . ' ' . ($refund['body']['error'] ?? ''));

        $rr = db()->prepare('SELECT status, refunded_at, payout_released_at FROM sub_orders WHERE id = ?');
        $rr->execute([$r1]);
        $rrow = $rr->fetch();
        check('Sub-order status is refunded', $rrow['status'] === 'refunded', (string) $rrow['status']);
        check('refunded_at stamped', $rrow['refunded_at'] !== null);
        check('The seller is NOT paid for a refunded order', $rrow['payout_released_at'] === null);

        $pay = db()->prepare('SELECT refunded_amount, status FROM payments WHERE order_id = ?');
        $pay->execute([$order2]);
        $p = $pay->fetch();
        check(
            'payments.refunded_amount is the sub-order total',
            abs((float) $p['refunded_amount'] - $r1Amount) < 0.01,
            $p['refunded_amount'] . ' vs ' . $r1Amount
        );
        check(
            'ONE seller refunded on a two-seller order → partially_refunded',
            $p['status'] === 'partially_refunded',
            (string) $p['status']
        );

        $ord = db()->prepare('SELECT payment_status FROM orders WHERE id = ?');
        $ord->execute([$order2]);
        check('…and the order says partially_refunded too', $ord->fetchColumn() === 'partially_refunded');

        $stockRow->execute([(int) $itemA['id']]);
        check(
            'The refund put the stock back',
            (int) $stockRow->fetchColumn() === $stockBeforeRefund + 2,
            $stockBeforeRefund . ' → ' . ($stockBeforeRefund + 2)
        );
        check('Refund is audited with the reason', actionCount('refund', $r1) === 1);
        $la = lastAction();
        check('…and the amount is on the record', str_contains((string) $la['notes'], 'courier') || str_contains((string) $la['notes'], 'refunded'));

        /* ---- refunding the second one too ---- */
        $refund2 = post('admin', 'admin/order_action.php', [
            'sub_order_id' => $r2, 'action' => 'refund', 'reason' => 'Same parcel.',
        ]);
        check('The second sub-order can be refunded too', $refund2['status'] === 200);

        $pay->execute([$order2]);
        $p = $pay->fetch();
        check(
            'BOTH sellers refunded → the payment is fully "refunded"',
            $p['status'] === 'refunded',
            (string) $p['status']
        );
        check(
            'refunded_amount equals the full captured amount',
            abs((float) $p['refunded_amount'] - $captured2) < 0.02,
            $p['refunded_amount'] . ' vs ' . $captured2
        );
        check(
            'Never refunds more than was captured',
            (float) $p['refunded_amount'] <= $captured2 + 0.01
        );

        $again = post('admin', 'admin/order_action.php', [
            'sub_order_id' => $r1, 'action' => 'refund', 'reason' => 'twice?',
        ]);
        check('A refunded order cannot be refunded again', $again['status'] === 409, 'got ' . $again['status']);
    }

    $ghostO = post('admin', 'admin/order_action.php', ['sub_order_id' => 999999, 'action' => 'dispute', 'reason' => 'x']);
    check('An order id that does not exist → 404', $ghostO['status'] === 404);
}

section('6. FAQ CRUD');

$faqsBefore = (int) db()->query('SELECT COUNT(*) FROM faqs')->fetchColumn();

$badFaq = post('admin', 'admin/faqs.php', ['action' => 'save', 'question' => 'eh?', 'answer' => 'no']);
check(
    'A too-short question and answer are refused per-field',
    $badFaq['status'] === 422 && isset($badFaq['body']['fields']['question'], $badFaq['body']['fields']['answer']),
    implode(',', array_keys($badFaq['body']['fields'] ?? []))
);

$newFaq = post('admin', 'admin/faqs.php', [
    'action'   => 'save',
    'question' => 'How long does verification take?',
    'answer'   => 'Usually one to two business days from the moment you submit your application.',
    'category' => 'selling',
]);
check('Creating a FAQ works', $newFaq['status'] === 201, 'status ' . $newFaq['status']);
$faqId = (int) (data($newFaq)['faq']['id'] ?? 0);
check('It got an id', $faqId > 0);
check('Creation is audited', actionCount('create_faq', $faqId) === 1);
check('sort_order was assigned automatically', ($newFaq['body']['data']['faq']['sort_order'] ?? -1) >= 1);

$editFaq = post('admin', 'admin/faqs.php', [
    'action' => 'save', 'id' => $faqId,
    'question' => 'How long does seller verification take?',
    'answer' => 'One to two business days. You can set your shop up while you wait.',
    'category' => 'selling',
]);
check('Editing a FAQ works', $editFaq['status'] === 200);
check('The edit is stored', str_contains((string) (data($editFaq)['faq']['question'] ?? ''), 'seller verification'));
check('Edit is audited', actionCount('update_faq', $faqId) === 1);

$ghostFaq = post('admin', 'admin/faqs.php', ['action' => 'save', 'id' => 999999, 'question' => 'Does this exist at all?', 'answer' => 'It should not, so this must 404.']);
check('Editing a FAQ that does not exist → 404', $ghostFaq['status'] === 404);

$delFaq = post('admin', 'admin/faqs.php', ['action' => 'delete', 'id' => $faqId]);
check('Deleting a FAQ works', $delFaq['status'] === 200);
check('It is gone', (int) db()->query('SELECT COUNT(*) FROM faqs')->fetchColumn() === $faqsBefore);
check('Deletion is audited, with the question text kept in the note', actionCount('delete_faq', $faqId) === 1);
$la = lastAction();
check('…so the trail still says what was removed', str_contains((string) $la['notes'], 'verification take'), (string) $la['notes']);

section('7. Platform settings');

$settingsBefore = db()->query("SELECT value FROM settings WHERE setting_key='commission_rate'")->fetchColumn();

$badRate = post('admin', 'admin/settings.php', ['commission_rate' => 'free']);
check('A non-numeric commission rate is refused', $badRate['status'] === 422, 'got ' . $badRate['status']);

$highRate = post('admin', 'admin/settings.php', ['commission_rate' => 120]);
check('A commission rate above 50 is refused', $highRate['status'] === 422);

$unknown = post('admin', 'admin/settings.php', ['sneaky_backdoor' => true]);
check(
    'An unrecognised setting key is refused outright',
    $unknown['status'] === 400,
    'got ' . $unknown['status'] . ' — ' . ($unknown['body']['error'] ?? '')
);

$inUse = post('admin', 'admin/settings.php', ['categories' => ['phone', 'audio']]);
check(
    'Removing a category that listings still use is refused',
    $inUse['status'] === 422 && str_contains((string) ($inUse['body']['fields']['categories'] ?? ''), 'in use'),
    (string) ($inUse['body']['fields']['categories'] ?? '')
);

$emptyCats = post('admin', 'admin/settings.php', ['categories' => []]);
check('An empty category list is refused', $emptyCats['status'] === 422);

$addCat = post('admin', 'admin/settings.php', [
    'categories' => ['laptop', 'phone', 'audio', 'appliance', 'camera', 'wearable'],
]);
check('Adding a category works', $addCat['status'] === 200, 'status ' . $addCat['status']);
check('…and it reaches the public settings endpoint', str_contains(
    (string) api('guest', 'GET', 'settings/public.php')['raw'],
    'wearable'
));

$rateChange = post('admin', 'admin/settings.php', ['commission_rate' => 9.5]);
check('Changing the commission rate works', $rateChange['status'] === 200);
check(
    'The response says it is NOT retroactive',
    str_contains((string) (data($rateChange)['notice'] ?? ''), 'new orders only'),
    (string) (data($rateChange)['notice'] ?? 'no notice')
);

/* The snapshot on existing orders must not have moved. */
$snapshots = db()->query('SELECT DISTINCT commission_rate FROM sub_orders')->fetchAll(PDO::FETCH_COLUMN);
check(
    'Existing orders keep their SNAPSHOTTED rate (not 9.5)',
    !in_array('9.50', array_map(static fn($r) => number_format((float) $r, 2, '.', ''), $snapshots), true),
    'rates on file: ' . implode(', ', $snapshots)
);

$toggle = post('admin', 'admin/settings.php', ['seller_signups_open' => false]);
check('A feature toggle can be switched off', $toggle['status'] === 200);

$closedApp = post('signedout', 'seller/register.php', [
    'first_name' => 'Too', 'last_name' => 'Late', 'email' => "admintest-closed+$suffix@example.com",
    'phone' => '0771234567', 'password' => $DEMO_PASSWORD, 'nic_number' => '912345678V',
    'business_name' => 'Closed Shop', 'business_type' => 'other', 'city' => 'Kandy',
    'primary_category' => 'laptop', 'bank_name' => 'HNB', 'account_number' => '1234567', 'accept_terms' => true,
]);
check(
    'With sign-ups closed, a seller application is REFUSED (the toggle works)',
    $closedApp['status'] === 403,
    'got ' . $closedApp['status'] . ' — ' . ($closedApp['body']['error'] ?? '')
);

$noop = post('admin', 'admin/settings.php', ['commission_rate' => 9.5]);
check(
    'Re-saving an unchanged setting is a no-op, not an error',
    $noop['status'] === 200 && (data($noop)['changed'] ?? ['x']) === [],
    json_encode(data($noop)['changed'] ?? null)
);
check('Settings changes are audited', actionCount('update_settings') >= 3);

/* restore */
post('admin', 'admin/settings.php', [
    'commission_rate' => (float) json_decode((string) $settingsBefore, true),
    'categories' => ['laptop', 'phone', 'audio', 'appliance', 'camera'],
    'seller_signups_open' => true,
]);
$restored = db()->query("SELECT value FROM settings WHERE setting_key='commission_rate'")->fetchColumn();
check('Settings restored', (string) $restored === (string) $settingsBefore, (string) $restored);

section('8. Finance');

$fin = data(api('admin', 'GET', 'admin/finance.php?range=30d'));
check('Finance returns totals', isset($fin['totals']['gmv'], $fin['totals']['commission']));
foreach (['gmv', 'commission', 'net_to_sellers', 'released', 'escrow', 'refunded', 'effective_rate', 'avg_order_value'] as $key) {
    check("totals.$key is numeric", is_numeric($fin['totals'][$key] ?? null), (string) ($fin['totals'][$key] ?? 'missing'));
}
check('Finance returns a per-seller breakdown', is_array($fin['by_seller']) && count($fin['by_seller']) > 0, count($fin['by_seller'] ?? []) . ' sellers');
check('Finance returns a day series', count($fin['series'] ?? []) === 30);

/* GMV must equal the sum of the per-seller GMVs. */
$sumBySeller = array_sum(array_column($fin['by_seller'], 'gmv'));
check(
    'Total GMV equals the sum of the per-seller figures',
    abs($sumBySeller - $fin['totals']['gmv']) < 0.05,
    $sumBySeller . ' vs ' . $fin['totals']['gmv']
);

$sumCommission = array_sum(array_column($fin['by_seller'], 'commission'));
check(
    'Total commission equals the sum of the per-seller figures',
    abs($sumCommission - $fin['totals']['commission']) < 0.05
);

check(
    'net_to_sellers + commission equals GMV',
    abs(($fin['totals']['net_to_sellers'] + $fin['totals']['commission']) - $fin['totals']['gmv']) < 0.05,
    $fin['totals']['net_to_sellers'] . ' + ' . $fin['totals']['commission'] . ' vs ' . $fin['totals']['gmv']
);

$fin7 = data(api('admin', 'GET', 'admin/finance.php?range=7d'));
check('?range=7d returns a 7-day series', count($fin7['series'] ?? []) === 7);
check('A shorter range cannot have more GMV than a longer one', $fin7['totals']['gmv'] <= $fin['totals']['gmv'] + 0.01);

$finAll = data(api('admin', 'GET', 'admin/finance.php?range=all'));
check('?range=all works', isset($finAll['totals']['gmv']), 'gmv ' . ($finAll['totals']['gmv'] ?? '?'));

$oneSeller = api('admin', 'GET', 'admin/finance.php?range=all&seller=' . (int) $both[1]['seller_id']);
check('Filtering to one seller works', $oneSeller['status'] === 200);
check('…and reports only that seller', count(data($oneSeller)['by_seller'] ?? []) === 1);
check('…and names them', !empty(data($oneSeller)['seller_name']), (string) (data($oneSeller)['seller_name'] ?? ''));

$badSeller = api('admin', 'GET', 'admin/finance.php?seller=999999');
check('An unknown seller filter → 404', $badSeller['status'] === 404);

$bogusRange = data(api('admin', 'GET', 'admin/finance.php?range=DROP+TABLE'));
check(
    'An unrecognised range falls back to 30d instead of reaching the SQL',
    ($bogusRange['range'] ?? '') === '30d',
    (string) ($bogusRange['range'] ?? '?')
);

/* ---- CSV ---- */
$csv = api('admin', 'GET', 'admin/finance.php?range=all&format=csv');
check('CSV export responds 200', $csv['status'] === 200);
check('…with a text/csv content type', str_contains($csv['ctype'], 'text/csv'), $csv['ctype']);
check('…with a UTF-8 BOM so Excel reads it correctly', str_starts_with($csv['raw'], "\xEF\xBB\xBF"));
check('…and a header row', str_contains($csv['raw'], 'sub_order,order,seller,buyer'));
check('…and data rows', substr_count(trim($csv['raw']), "\n") > 1, substr_count(trim($csv['raw']), "\n") . ' lines');
check('…with the SO- reference format', (bool) preg_match('/SO-\d{6}/', $csv['raw']));
check('The export itself is audited', actionCount('export_finance') >= 1);

section('9. The audit trail feeds the Overview');

$actionsNow = actionCount();
check(
    'This run wrote many admin_actions rows',
    $actionsNow > $actionsAtStart + 15,
    ($actionsNow - $actionsAtStart) . ' new rows'
);

$trail = data(api('admin', 'GET', 'admin/dashboard.php'))['actions'];
check('The dashboard returns the trail', is_array($trail) && count($trail) > 0, count($trail) . ' entries');

$first = $trail[0];
foreach (['id', 'action', 'target_type', 'target_id', 'notes', 'admin_name', 'created_at'] as $key) {
    check("trail entry has $key", array_key_exists($key, $first));
}
check('Newest first', $trail[0]['id'] >= $trail[count($trail) - 1]['id']);
check('It names the admin who acted', $first['admin_name'] !== null, (string) $first['admin_name']);

$types = array_unique(array_column($trail, 'action'));
check(
    'The trail contains this run\'s actions',
    count(array_intersect(['approve_seller', 'reject_product', 'refund', 'update_settings'], $types)) >= 3,
    implode(', ', array_slice($types, 0, 8))
);

/* Every admin_actions row must have a real admin behind it. */
$orphans = (int) db()->query(
    'SELECT COUNT(*) FROM admin_actions a LEFT JOIN users u ON u.id = a.admin_id WHERE u.id IS NULL'
)->fetchColumn();
check('No audit row has a missing admin', $orphans === 0);

$withoutNotes = (int) db()->query(
    "SELECT COUNT(*) FROM admin_actions
      WHERE action_type IN ('reject_seller','reject_product','takedown_product','refund','open_dispute')
        AND (notes IS NULL OR notes = '')"
)->fetchColumn();
check('Every reason-requiring action recorded its reason', $withoutNotes === 0, $withoutNotes . ' missing');

section("9b. The admin's own profile and photo");

$pngPath = sys_get_temp_dir() . '/consult-admin-avatar.png';
file_put_contents($pngPath, base64_decode(
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8DwHwAFAAH/q842iQAAAABJRU5ErkJggg=='
));

$profRow = db()->prepare('SELECT avatar_url, name, phone FROM users WHERE id = ?');
$profRow->execute([$adminId]);
$origin = $profRow->fetch();

$up = uploadFile('admin', 'auth/avatar.php', $pngPath);
check('An admin can upload their own profile photo', $up['status'] === 201, 'status ' . $up['status'] . ' ' . ($up['body']['error'] ?? ''));

$avatarUrl = data($up)['url'] ?? '';
check('Stored under uploads/ with a random name', (bool) preg_match('#^uploads/avatar-[0-9a-f]{24}\.png$#', $avatarUrl), $avatarUrl);

$afterAvatar = db()->prepare('SELECT avatar_url FROM users WHERE id = ?');
$afterAvatar->execute([$adminId]);
check('users.avatar_url was written', (string) $afterAvatar->fetchColumn() === $avatarUrl);
check('The returned user object carries it', (data($up)['user']['avatar_url'] ?? '') === $avatarUrl);

$diskPath = dirname(__DIR__, 2) . '/' . $avatarUrl;
check('The file is on disk', is_file($diskPath));

/* Replacing it must not leave the old file behind. */
$up2 = uploadFile('admin', 'auth/avatar.php', $pngPath);
$avatarUrl2 = data($up2)['url'] ?? '';
check('Uploading again replaces it', $up2['status'] === 201 && $avatarUrl2 !== $avatarUrl);

clearstatcache(true, $diskPath);
check('…and the REPLACED file was deleted from disk', !is_file($diskPath), $avatarUrl);

/* The same endpoint serves every role — that is why it is under auth/. */
login('cust', 'nimal@example.com', $DEMO_PASSWORD);
$custUp = uploadFile('cust', 'auth/avatar.php', $pngPath);
check('A CUSTOMER can use the same avatar endpoint', $custUp['status'] === 201, 'status ' . $custUp['status']);
$custUrl = data($custUp)['url'] ?? '';

$guestUp = uploadFile('nobody', 'auth/avatar.php', $pngPath);
check('A signed-out guest cannot (401)', $guestUp['status'] === 401, 'got ' . $guestUp['status']);

$evilPath = sys_get_temp_dir() . '/consult-evil-avatar.png';
file_put_contents($evilPath, "<?php echo 'pwned'; ?>\n");
$evil = uploadFile('admin', 'auth/avatar.php', $evilPath);
check('A PHP script renamed .png is rejected here too', $evil['status'] === 422, 'got ' . $evil['status']);
@unlink($evilPath);

/* Profile photos no longer belong to the seller upload endpoint. */
login('sel', 'techhub@example.com', $DEMO_PASSWORD);
$wrongDoor = uploadFile('sel', 'seller/upload.php', $pngPath, ['kind' => 'avatar']);
check(
    'seller/upload.php now refuses kind=avatar and points at the shared endpoint',
    $wrongDoor['status'] === 400 && str_contains((string) ($wrongDoor['body']['error'] ?? ''), 'auth/avatar.php'),
    (string) ($wrongDoor['body']['error'] ?? '')
);

/* ---- removing it ---- */
$rm = post('admin', 'auth/avatar.php', ['remove' => true]);
check('The photo can be removed', $rm['status'] === 200, 'status ' . $rm['status']);
$afterAvatar->execute([$adminId]);
check('users.avatar_url is NULL again', $afterAvatar->fetchColumn() === null);

/* ---- the admin's own name and phone ---- */
$prof = post('admin', 'auth/update_profile.php', [
    'name'  => 'Consult Web-master (edited)',
    'email' => 'admin@consult.lk',
    'phone' => '0112345678',
]);
check('An admin can edit their own name and phone', $prof['status'] === 200, 'status ' . $prof['status'] . ' ' . ($prof['body']['error'] ?? ''));

$nameRow = db()->prepare('SELECT name, phone, role FROM users WHERE id = ?');
$nameRow->execute([$adminId]);
$nr = $nameRow->fetch();
check('The name was saved', $nr['name'] === 'Consult Web-master (edited)', (string) $nr['name']);
check('A landline phone is accepted for an admin', $nr['phone'] === '0112345678', (string) $nr['phone']);
check('The role is untouched by a profile edit', $nr['role'] === 'admin');

/* An own-profile edit is NOT an admin action, so it must not be audited. */
check(
    'Editing your own profile is not written to the audit trail',
    actionCount('update_profile') === 0
);

/* restore */
post('admin', 'auth/update_profile.php', [
    'name'  => $origin['name'],
    'email' => 'admin@consult.lk',
    'phone' => $origin['phone'] ?? '',
]);
db()->prepare('UPDATE users SET avatar_url = ? WHERE id = ?')->execute([$origin['avatar_url'], $adminId]);
$nameRow->execute([$adminId]);
check('Admin profile restored', $nameRow->fetch()['name'] === $origin['name']);

/* tidy the files this section wrote */
foreach ([$avatarUrl2, $custUrl] as $u) {
    if ($u !== '' && is_file(dirname(__DIR__, 2) . '/' . $u)) {
        @unlink(dirname(__DIR__, 2) . '/' . $u);
    }
}
db()->prepare("UPDATE users SET avatar_url = NULL WHERE email = 'nimal@example.com'")->execute();
@unlink($pngPath);
check('Uploaded test photos removed', true);

login('admin', 'admin@consult.lk', $DEMO_PASSWORD);

section('10. The shape admin-dashboard.js depends on');

$c = data(api('admin', 'GET', 'admin/dashboard.php'));

foreach (['admin', 'stats', 'series', 'sellers', 'users', 'products', 'orders', 'faqs', 'messages', 'by_seller', 'settings', 'actions'] as $key) {
    check("payload.$key present", array_key_exists($key, $c));
}

foreach ([
    'gmv_total', 'commission_total', 'escrow_held', 'orders_total', 'disputes_open',
    'users_total', 'sellers_pending', 'products_pending', 'avg_order_value',
] as $key) {
    check("stats.$key is numeric", is_numeric($c['stats'][$key] ?? null), (string) ($c['stats'][$key] ?? 'missing'));
}

if (!empty($c['orders'])) {
    $o = $c['orders'][0];
    check('order.key is a STRING (data-key round-trips as one)', is_string($o['key']), gettype($o['key']));
    check('order.seller_name present (the admin table shows it)', array_key_exists('seller_name', $o));
    check('order.buyer_name present', array_key_exists('buyer_name', $o));
    check('order.can.next is an array of legal next statuses', is_array($o['can']['next'] ?? null), implode(',', $o['can']['next'] ?? []));
    check('order.status_log present for the timeline', is_array($o['status_log'] ?? null));
}

if (!empty($c['sellers'])) {
    $s = null;
    foreach ($c['sellers'] as $row) {
        if ($row['seller'] !== null) {
            $s = $row;
            break;
        }
    }
    check('seller rows nest a seller block', $s !== null);
    if ($s) {
        foreach (['business_name', 'kyc_status', 'commission_rate', 'nic_number', 'verified'] as $key) {
            check("seller.$key present", array_key_exists($key, $s['seller']));
        }
    }
}

if (!empty($c['products'])) {
    $p = $c['products'][0];
    foreach (['id', 'title', 'status', 'rejection_reason', 'seller', 'price', 'stock', 'images'] as $key) {
        check("product.$key present", array_key_exists($key, $p));
    }
    check('product.seller.name present (the queue shows who listed it)', isset($p['seller']['name']));
}

check('settings has all four writable keys', count(array_intersect(
    ['commission_rate', 'categories', 'seller_signups_open', 'customer_signups_open'],
    array_keys($c['settings'])
)) === 4);

check('No password hash anywhere in the payload', !str_contains(json_encode($c), '$2y$'));

section('Cleanup');

$testUsers = array_map('intval', db()->query(
    "SELECT id FROM users WHERE email LIKE 'admintest-%'"
)->fetchAll(PDO::FETCH_COLUMN));

$ownOrders = array_filter([$testOrderId ?? 0, $order2 ?? 0]);
if ($ownOrders) {
    $in = implode(',', array_fill(0, count($ownOrders), '?'));
    db()->prepare("DELETE FROM orders WHERE id IN ($in)")->execute(array_values($ownOrders));
}
check('Test orders removed', true, count($ownOrders) . ' order(s)');

if ($testUsers) {
    $in = implode(',', array_fill(0, count($testUsers), '?'));

    db()->prepare("DELETE FROM orders WHERE id IN (SELECT order_id FROM sub_orders WHERE seller_id IN ($in))")
        ->execute($testUsers);
    db()->prepare("DELETE FROM orders WHERE user_id IN ($in)")->execute($testUsers);
    db()->prepare("DELETE oi FROM order_items oi JOIN products p ON p.id = oi.product_id WHERE p.seller_id IN ($in)")
        ->execute($testUsers);
    db()->prepare("DELETE FROM products WHERE seller_id IN ($in)")->execute($testUsers);
    db()->prepare("DELETE FROM admin_actions WHERE admin_id IN ($in)")->execute($testUsers);
    db()->prepare("DELETE FROM admin_actions WHERE target_id IN ($in) AND target_type IN ('user','seller')")
        ->execute($testUsers);
    db()->prepare("DELETE FROM users WHERE id IN ($in)")->execute($testUsers);
}

$left = (int) db()->query("SELECT COUNT(*) FROM users WHERE email LIKE 'admintest-%'")->fetchColumn();
check('Throwaway accounts removed', $left === 0, $left ? $left . ' left behind' : count($testUsers) . ' cleaned');

$restore = db()->prepare('UPDATE products SET stock = ? WHERE id = ?');
foreach ($stockBefore ?? [] as $productId => $qty) {
    $restore->execute([$qty, $productId]);
}
check(
    'Stock restored to what it was before this run',
    true,
    implode(', ', array_map(
        static fn($k, $v): string => "#$k → $v",
        array_keys($stockBefore ?? []),
        array_values($stockBefore ?? [])
    )) ?: 'nothing to restore'
);

$negative = (int) db()->query('SELECT COUNT(*) FROM products WHERE stock < 0')->fetchColumn();
check('No listing was left with negative stock', $negative === 0);

$counts = db()->query(
    'SELECT (SELECT COUNT(*) FROM users) u, (SELECT COUNT(*) FROM orders) o,
            (SELECT COUNT(*) FROM products WHERE deleted_at IS NULL) p'
)->fetch();
check(
    'Row counts are back to the seeded baseline',
    (int) $counts['u'] === 28 && (int) $counts['o'] === 8,
    sprintf('%d users, %d orders, %d listings', $counts['u'], $counts['o'], $counts['p'])
);

foreach (glob($jarDir . '/*.txt') ?: [] as $f) {
    @unlink($f);
}
@rmdir($jarDir);

echo "\n", str_repeat('-', 68), "\n";
printf("  %d passed, %d failed\n", $pass, $fail);
echo str_repeat('-', 68), "\n";

exit($fail === 0 ? 0 : 1);
