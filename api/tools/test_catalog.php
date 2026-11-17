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
$jarDir = sys_get_temp_dir() . '/consult-cat-' . getmypid();
@mkdir($jarDir, 0777, true);

function section(string $t): void
{
    echo "\n", str_repeat('=', 70), "\n  ", $t, "\n", str_repeat('=', 70), "\n";
}

function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    printf("  [%s] %s%s\n", $ok ? 'PASS' : 'FAIL', $label, $detail !== '' ? "  ($detail)" : '');
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
    curl_close($ch);

    if ($raw === false) {
        return ['status' => 0, 'body' => null, 'raw' => ''];
    }
    return ['status' => $status, 'body' => json_decode($raw, true), 'raw' => $raw];
}

function csrf(string $jar): string
{
    return api($jar, 'GET', 'auth/csrf.php')['body']['data']['csrf_token'] ?? '';
}

function post(string $jar, string $path, array $body): array
{
    return api($jar, 'POST', $path, $body, ['X-CSRF-Token: ' . csrf($jar)]);
}

function signIn(string $jar, string $email): array
{
    global $DEMO_PASSWORD;
    return post($jar, 'auth/login.php', ['email' => $email, 'password' => $DEMO_PASSWORD]);
}

/** Every public id in a list/wishlist response, in order. */
function ids(array $res, string $key = 'items'): array
{
    return array_map(
        static fn(array $p): string => $p['id'],
        $res['body']['data'][$key] ?? []
    );
}

/* ================================================================ */

section('0. Environment');

$probe = api('guest', 'GET', 'products/list.php');
if ($probe['status'] !== 200) {
    echo "  Cannot reach {$BASE}/api/products/list.php (status {$probe['status']}).\n";
    echo "  Start Apache and MySQL in the XAMPP Control Panel, then run this again.\n";
    exit(1);
}
check('Apache serves the catalog API', true, $BASE);
check('MySQL reachable from PHP', (bool) db()->query('SELECT 1')->fetchColumn());

section('1. Catalog listing');

$all = api('guest', 'GET', 'products/list.php');
check('GET products/list.php returns the envelope', ($all['body']['ok'] ?? false) === true);
check('8 active products (the 2 pending ones are hidden)',
    ($all['body']['data']['total'] ?? 0) === 8, 'total ' . ($all['body']['data']['total'] ?? '?'));

$pendingLeak = array_intersect(ids($all), ['p9', 'p10']);
check('No pending listing leaks into the public catalog', $pendingLeak === [],
    implode(',', $pendingLeak));

$first = $all['body']['data']['items'][0] ?? [];
foreach (['id', 'sellerId', 'title', 'subtitle', 'price', 'stock', 'tags', 'specs',
          'rating', 'reviews', 'images', 'image', 'category', 'icon', 'seller'] as $field) {
    check('  shape has ' . $field, array_key_exists($field, $first));
}
check('  seller is a nested object with name/city/verified',
    isset($first['seller']['name'], $first['seller']['city'])
        && array_key_exists('verified', $first['seller']),
    $first['seller']['name'] ?? '-');
check('  specs is an object, never null (Object.entries would throw)',
    is_array($first['specs']) || is_object($first['specs']));

/* ---- filters ---- */
$laptops = api('guest', 'GET', 'products/list.php?category=laptop');
check('category=laptop returns only laptops', ids($laptops) === ['p1', 'p3', 'p2'],
    implode(',', ids($laptops)));

$two = api('guest', 'GET', 'products/list.php?category=laptop,audio&sort=price-asc');
check('category csv + sort=price-asc', ids($two) === ['p6', 'p2', 'p1', 'p3'],
    implode(',', ids($two)));

$cheap = api('guest', 'GET', 'products/list.php?max_price=130000');
check('max_price filters on the EFFECTIVE price', ids($cheap) === ['p7', 'p5', 'p6'],
    implode(',', ids($cheap)));

$search = api('guest', 'GET', 'products/list.php?search=oled');
check('search matches the subtitle/title', ids($search) === ['p2'], implode(',', ids($search)));

$tagSearch = api('guest', 'GET', 'products/list.php?search=gaming');
check('search also matches product_tags', ids($tagSearch) === ['p3'], implode(',', ids($tagSearch)));

$shop = api('guest', 'GET', 'products/list.php?seller=s1');
check('seller=s1 returns only that seller', ids($shop) === ['p1', 'p6', 'p8'],
    implode(',', ids($shop)));

$stocked = api('guest', 'GET', 'products/list.php?in_stock=1&min_rating=4.6');
check('in_stock + min_rating combine', count(ids($stocked)) === 4, implode(',', ids($stocked)));

/* ---- pagination ---- */
$p1 = api('guest', 'GET', 'products/list.php?per_page=3&page=1&sort=price-desc');
$p2 = api('guest', 'GET', 'products/list.php?per_page=3&page=2&sort=price-desc');
$p3 = api('guest', 'GET', 'products/list.php?per_page=3&page=3&sort=price-desc');
check('Pagination: 3 pages of 3/3/2 with no overlap',
    count(ids($p1)) === 3 && count(ids($p2)) === 3 && count(ids($p3)) === 2
        && count(array_unique(array_merge(ids($p1), ids($p2), ids($p3)))) === 8,
    implode('|', [implode(',', ids($p1)), implode(',', ids($p2)), implode(',', ids($p3))]));
check('  ...and reports pages=3', ($p1['body']['data']['pages'] ?? 0) === 3);

/* ---- injection attempts ---- */
$badSort = api('guest', 'GET', 'products/list.php?sort=' . urlencode('price-asc; DROP TABLE products'));
check('An unknown sort falls back to relevance instead of reaching SQL',
    ($badSort['body']['data']['sort'] ?? '') === 'relevance'
        && ($badSort['body']['data']['total'] ?? 0) === 8,
    $badSort['body']['data']['sort'] ?? '?');
check('  ...and products still exists', (int) db()->query('SELECT COUNT(*) FROM products')->fetchColumn() === 10);

$wild = api('guest', 'GET', 'products/list.php?search=' . urlencode('%'));
check('A % in the search is a literal, not a LIKE wildcard',
    ($wild['body']['data']['total'] ?? -1) === 0, 'total ' . ($wild['body']['data']['total'] ?? '?'));

$quote = api('guest', 'GET', 'products/list.php?search=' . urlencode("' OR 1=1 -- "));
check('A quoted injection in the search returns nothing and does not error',
    $quote['status'] === 200 && ($quote['body']['data']['total'] ?? -1) === 0);

$junkPage = api('guest', 'GET', 'products/list.php?page=abc&per_page=9999');
check('Junk pagination is clamped, not an error',
    $junkPage['status'] === 200 && ($junkPage['body']['data']['page'] ?? 0) === 1
        && ($junkPage['body']['data']['per_page'] ?? 0) === 100,
    'page ' . ($junkPage['body']['data']['page'] ?? '?')
        . ' per_page ' . ($junkPage['body']['data']['per_page'] ?? '?'));

section('2. Scheduled sale pricing');

$sale = api('guest', 'GET', 'products/detail.php?id=p8')['body']['data']['product'] ?? [];
check('p8 has an active sale today', ($sale['onSale'] ?? false) === true);
check('  price is the sale price', (float) ($sale['price'] ?? 0) === 179000.0, (string) ($sale['price'] ?? '?'));
check('  oldPrice is the normal price, for the strikethrough',
    (float) ($sale['oldPrice'] ?? 0) === 189500.0, (string) ($sale['oldPrice'] ?? '?'));
check('  saleEndsAt is reported', !empty($sale['saleEndsAt']), $sale['saleEndsAt'] ?? '-');

$future = api('guest', 'GET', 'products/detail.php?id=p3')['body']['data']['product'] ?? [];
check('p3 has a FUTURE sale, so it is not discounted yet',
    ($future['onSale'] ?? true) === false
        && (float) ($future['price'] ?? 0) === 245000.0,
    'price ' . ($future['price'] ?? '?'));

// Prove the date logic rather than trusting the seed: move p3's sale to today.
db()->exec("UPDATE product_sales SET start_date = CURDATE() - INTERVAL 1 DAY, end_date = CURDATE() + INTERVAL 1 DAY WHERE product_id = 3");
$nowOn = api('guest', 'GET', 'products/detail.php?id=p3')['body']['data']['product'] ?? [];
check('Moving that sale into today makes it active with no code change',
    ($nowOn['onSale'] ?? false) === true && (float) ($nowOn['price'] ?? 0) === 229000.0,
    'price ' . ($nowOn['price'] ?? '?'));

db()->exec("UPDATE product_sales SET start_date = CURDATE() - INTERVAL 10 DAY, end_date = CURDATE() - INTERVAL 3 DAY WHERE product_id = 3");
$expired = api('guest', 'GET', 'products/detail.php?id=p3')['body']['data']['product'] ?? [];
check('Moving it into the past ends the sale, again with no code change',
    ($expired['onSale'] ?? true) === false && (float) ($expired['price'] ?? 0) === 245000.0,
    'price ' . ($expired['price'] ?? '?'));

db()->exec("UPDATE product_sales SET start_date = '2026-10-12', end_date = '2026-10-19' WHERE product_id = 3");
check('  ...seeded sale dates restored', true);

section('3. Product detail');

$d = api('guest', 'GET', 'products/detail.php?id=p1')['body']['data']['product'] ?? [];
check('Detail returns the specs table', count((array) $d['specs']) === 5, (string) count((array) $d['specs']));
check('Detail returns the `why` blurb', !empty($d['why']));
check('Detail returns ratingBreakdown', isset($d['ratingBreakdown']['5']));
check('Detail returns reviewsList', count($d['reviewsList'] ?? []) === 5,
    (string) count($d['reviewsList'] ?? []));
check('  review dates are ISO 8601 (Safari-safe)',
    (bool) preg_match('/^\d{4}-\d{2}-\d{2}T/', $d['reviewsList'][0]['date'] ?? ''),
    $d['reviewsList'][0]['date'] ?? '-');
check('  reviews carry no email address', strpos(json_encode($d['reviewsList']), '@') === false);

$multi = api('guest', 'GET', 'products/detail.php?id=p5')['body']['data']['product'] ?? [];
check('A product with 4 photos returns all 4 in order',
    count($multi['images'] ?? []) === 4 && ($multi['image'] ?? '') === $multi['images'][0],
    (string) count($multi['images'] ?? []));

$byNumeric = api('guest', 'GET', 'products/detail.php?id=1')['body']['data']['product'] ?? [];
check('Detail also accepts the numeric id', ($byNumeric['id'] ?? '') === 'p1');

$pending = api('guest', 'GET', 'products/detail.php?id=p9');
check('A pending listing is 404 on the public detail endpoint',
    $pending['status'] === 404 && ($pending['body']['code'] ?? '') === 'NOT_FOUND',
    'got ' . $pending['status']);

$missing = api('guest', 'GET', 'products/detail.php?id=' . urlencode("p1' OR '1'='1"));
check('An injected id is 404, not a leak', $missing['status'] === 404);

$noId = api('guest', 'GET', 'products/detail.php');
check('No id is a 400 VALIDATION', $noId['status'] === 400, 'got ' . $noId['status']);

section('4. Cart');

$anonCart = api('anon', 'GET', 'cart/get.php');
check('cart/get.php signed out is 401 AUTH',
    $anonCart['status'] === 401 && ($anonCart['body']['code'] ?? '') === 'AUTH',
    'got ' . $anonCart['status']);

$anonAdd = post('anon', 'cart/add.php', ['product_id' => 'p1', 'qty' => 1]);
check('cart/add.php signed out is 401', $anonAdd['status'] === 401, 'got ' . $anonAdd['status']);

$noCsrf = api('anon', 'POST', 'cart/add.php', ['product_id' => 'p1']);
check('cart/add.php with no CSRF token is 403', $noCsrf['status'] === 403, 'got ' . $noCsrf['status']);

check('Customer signs in', signIn('buyer', 'nimal@example.com')['status'] === 200);

// Start from a known-empty cart.
post('buyer', 'cart/clear.php', []);
$empty = api('buyer', 'GET', 'cart/get.php')['body']['data'] ?? [];
check('An empty cart reports count 0 and total 0',
    ($empty['count'] ?? -1) === 0 && (float) ($empty['total'] ?? -1) === 0.0);

$add1 = post('buyer', 'cart/add.php', ['product_id' => 'p1', 'qty' => 2]);
$c = $add1['body']['data'] ?? [];
check('Add 2 x p1', ($c['count'] ?? 0) === 2 && count($c['lines'] ?? []) === 1, 'count ' . ($c['count'] ?? '?'));
check('  line carries the whole product object incl. nested seller',
    isset($c['lines'][0]['product']['seller']['name'], $c['lines'][0]['product']['sellerId']),
    $c['lines'][0]['product']['seller']['name'] ?? '-');

$add2 = post('buyer', 'cart/add.php', ['product_id' => 'p1', 'qty' => 1]);
check('Adding the same product tops up the existing line, not a second one',
    count($add2['body']['data']['lines'] ?? []) === 1 && ($add2['body']['data']['count'] ?? 0) === 3,
    'lines ' . count($add2['body']['data']['lines'] ?? []));

// p4 is Island Mobile, p1 is Colombo Tech Hub — two sellers.
$add3 = post('buyer', 'cart/add.php', ['product_id' => 'p4', 'qty' => 1]);
$c = $add3['body']['data'] ?? [];
check('Two sellers in the cart are counted as 2', ($c['sellers'] ?? 0) === 2, 'sellers ' . ($c['sellers'] ?? '?'));
check('  delivery is 450 per seller', (float) ($c['delivery'] ?? 0) === 900.0, (string) ($c['delivery'] ?? '?'));
$expect = 172500 * 3 + 139000 + 900;
check('  total = subtotal + delivery, priced from the DB',
    (float) ($c['total'] ?? 0) === (float) $expect,
    ($c['total'] ?? '?') . ' vs ' . $expect);

$upd = post('buyer', 'cart/update.php', ['product_id' => 'p1', 'qty' => 1]);
check('update.php sets an absolute quantity', ($upd['body']['data']['count'] ?? 0) === 2,
    'count ' . ($upd['body']['data']['count'] ?? '?'));

$zero = post('buyer', 'cart/update.php', ['product_id' => 'p4', 'qty' => 0]);
check('update.php with qty 0 removes the line',
    count($zero['body']['data']['lines'] ?? []) === 1,
    'lines ' . count($zero['body']['data']['lines'] ?? []));

/* ---- stock is the ceiling ---- */
// p8 has stock 2.
post('buyer', 'cart/add.php', ['product_id' => 'p8', 'qty' => 99]);
$clamped = api('buyer', 'GET', 'cart/get.php')['body']['data'] ?? [];
$p8line = null;
foreach ($clamped['lines'] as $l) {
    if ($l['product']['id'] === 'p8') {
        $p8line = $l;
    }
}
check('Asking for 99 of a 2-in-stock item gives 2, not 99',
    ($p8line['qty'] ?? 0) === 2, 'qty ' . ($p8line['qty'] ?? '?'));

$overUpdate = post('buyer', 'cart/update.php', ['product_id' => 'p8', 'qty' => 50]);
$p8after = null;
foreach ($overUpdate['body']['data']['lines'] as $l) {
    if ($l['product']['id'] === 'p8') {
        $p8after = $l;
    }
}
check('  update is clamped too, with a notice',
    ($p8after['qty'] ?? 0) === 2 && !empty($overUpdate['body']['data']['notices']),
    $overUpdate['body']['data']['notices'][0] ?? '-');

/* ---- a cart cannot hold a non-public product ---- */
$addPending = post('buyer', 'cart/add.php', ['product_id' => 'p9', 'qty' => 1]);
check('A pending listing cannot be added to the cart',
    $addPending['status'] === 404, 'got ' . $addPending['status']);

/* ---- the cart heals itself when a product goes away ---- */
db()->exec("UPDATE products SET status = 'inactive' WHERE public_id = 'p8'");
$healed = api('buyer', 'GET', 'cart/get.php')['body']['data'] ?? [];
$stillThere = array_filter($healed['lines'], static fn(array $l): bool => $l['product']['id'] === 'p8');
check('A taken-down product is dropped from the cart with a notice',
    $stillThere === [] && !empty($healed['notices']),
    $healed['notices'][0] ?? '-');
db()->exec("UPDATE products SET status = 'active' WHERE public_id = 'p8'");

db()->exec("UPDATE products SET stock = 0 WHERE public_id = 'p6'");
post('buyer', 'cart/add.php', ['product_id' => 'p1', 'qty' => 1]);
$soldOut = post('buyer', 'cart/add.php', ['product_id' => 'p6', 'qty' => 1]);
check('A sold-out product cannot be added (409)', $soldOut['status'] === 409, 'got ' . $soldOut['status']);
db()->exec("UPDATE products SET stock = 25 WHERE public_id = 'p6'");

/* ---- carts are per user ---- */
check('A second customer signs in', signIn('buyer2', 'sanduni@example.com')['status'] === 200);
post('buyer2', 'cart/clear.php', []);
post('buyer2', 'cart/add.php', ['product_id' => 'p6', 'qty' => 1]);
$mine   = api('buyer',  'GET', 'cart/get.php')['body']['data'] ?? [];
$theirs = api('buyer2', 'GET', 'cart/get.php')['body']['data'] ?? [];
check('Each account sees only its own cart',
    ids(['body' => ['data' => ['items' => array_column($theirs['lines'], 'product')]]]) === ['p6']
        && !in_array('p6', array_column(array_column($mine['lines'], 'product'), 'id'), true),
    'other cart: ' . implode(',', array_column(array_column($theirs['lines'], 'product'), 'id')));

$clear = post('buyer2', 'cart/clear.php', []);
check('clear.php empties the cart', ($clear['body']['data']['count'] ?? -1) === 0);

/* ---- guest cart merge ---- */
section('5. Guest cart merge on sign-in');

post('buyer', 'cart/clear.php', []);
post('buyer', 'cart/add.php', ['product_id' => 'p1', 'qty' => 1]);

$merge = post('buyer', 'cart/merge.php', ['items' => [
    ['product_id' => 'p1', 'qty' => 3],      // bigger than the account's 1 -> wins
    ['product_id' => 'p6', 'qty' => 2],      // new line
    ['product_id' => 'p9', 'qty' => 1],      // pending -> skipped
    ['product_id' => 'nope', 'qty' => 1],    // nonsense -> skipped
]]);
$m = $merge['body']['data'] ?? [];
$byId = [];
foreach ($m['lines'] ?? [] as $l) {
    $byId[$l['product']['id']] = $l['qty'];
}
check('Merge takes the larger quantity, it does not add them',
    ($byId['p1'] ?? 0) === 3, 'p1 qty ' . ($byId['p1'] ?? '?'));
check('Merge brings over a line the account did not have', ($byId['p6'] ?? 0) === 2);
check('Merge silently skips unavailable items rather than failing the sign-in',
    ($m['skipped'] ?? 0) === 2 && !isset($byId['p9']), 'skipped ' . ($m['skipped'] ?? '?'));

$mergeSmaller = post('buyer', 'cart/merge.php', ['items' => [['product_id' => 'p1', 'qty' => 1]]]);
$byId2 = [];
foreach ($mergeSmaller['body']['data']['lines'] as $l) {
    $byId2[$l['product']['id']] = $l['qty'];
}
check('  a smaller guest quantity never reduces the account cart', ($byId2['p1'] ?? 0) === 3);

post('buyer', 'cart/clear.php', []);

section('6. Wishlist');

$anonWish = post('anon', 'wishlist/toggle.php', ['product_id' => 'p1']);
check('wishlist/toggle.php signed out is 401', $anonWish['status'] === 401, 'got ' . $anonWish['status']);

$on = post('buyer', 'wishlist/toggle.php', ['product_id' => 'p2']);
check('First toggle saves it', ($on['body']['data']['saved'] ?? false) === true
    && in_array('p2', $on['body']['data']['ids'] ?? [], true));

$off = post('buyer', 'wishlist/toggle.php', ['product_id' => 'p2']);
check('Second toggle removes it', ($off['body']['data']['saved'] ?? true) === false
    && !in_array('p2', $off['body']['data']['ids'] ?? [], true));

post('buyer', 'wishlist/toggle.php', ['product_id' => 'p2']);
post('buyer', 'wishlist/toggle.php', ['product_id' => 'p7']);

$listed = api('buyer', 'GET', 'wishlist/list.php')['body']['data'] ?? [];
check('list.php returns the saved products, newest first',
    ($listed['count'] ?? 0) === 2 && ($listed['items'][0]['id'] ?? '') === 'p7',
    implode(',', array_column($listed['items'] ?? [], 'id')));
check('  with the full product shape incl. seller',
    isset($listed['items'][0]['seller']['name'], $listed['items'][0]['price']));

$idsOnly = api('buyer', 'GET', 'wishlist/list.php?ids_only=1')['body']['data'] ?? [];
check('ids_only=1 returns just the ids, for drawing the hearts',
    count($idsOnly['ids'] ?? []) === 2 && !isset($idsOnly['items']),
    implode(',', $idsOnly['ids'] ?? []));

$otherWish = api('buyer2', 'GET', 'wishlist/list.php?ids_only=1')['body']['data'] ?? [];
check('Another account does not see this wishlist', ($otherWish['ids'] ?? ['x']) === [],
    implode(',', $otherWish['ids'] ?? []));

$wishPending = post('buyer', 'wishlist/toggle.php', ['product_id' => 'p9']);
check('A pending listing cannot be wishlisted', $wishPending['status'] === 404,
    'got ' . $wishPending['status']);

db()->prepare('DELETE FROM wishlist WHERE user_id = (SELECT id FROM users WHERE email = ?)')
    ->execute(['nimal@example.com']);
check('Wishlist rows cleaned up', true);

section('7. Reviews');

$rl = api('guest', 'GET', 'reviews/list.php?product=p1')['body']['data'] ?? [];
check('reviews/list.php is public and paginates',
    ($rl['total'] ?? 0) === 5 && count($rl['items'] ?? []) === 5,
    'total ' . ($rl['total'] ?? '?'));
check('  summary carries the product aggregate',
    (float) ($rl['summary']['rating'] ?? 0) === 4.7);

$rlPage = api('guest', 'GET', 'reviews/list.php?product=p1&per_page=2&page=2')['body']['data'] ?? [];
check('  page 2 of 2-per-page returns 2 different reviews',
    count($rlPage['items'] ?? []) === 2
        && ($rlPage['items'][0]['id'] ?? 0) !== ($rl['items'][0]['id'] ?? 0));

$perm = static function (string $jar, string $product): array {
    return api($jar, 'GET', 'products/detail.php?id=' . $product)['body']['data']['product']['review_permission'] ?? [];
};

check('Signed out: cannot review', ($perm('guest', 'p1')['reason'] ?? '') === 'signed_out');
check('Customer with a delivered order for p1: CAN review',
    ($perm('buyer', 'p1')['can_review'] ?? false) === true, $perm('buyer', 'p1')['reason'] ?? '-');
check('Same customer, p4 only shipped: cannot review',
    ($perm('buyer', 'p4')['reason'] ?? '') === 'not_delivered');
check('Same customer, p7 disputed: cannot review',
    ($perm('buyer', 'p7')['reason'] ?? '') === 'not_delivered');
check('Customer with no order for p2 at all: cannot review',
    ($perm('buyer', 'p2')['reason'] ?? '') === 'not_delivered');

check('Seller signs in', signIn('seller', 'techhub@example.com')['status'] === 200);
check('A seller account cannot review', ($perm('seller', 'p1')['reason'] ?? '') === 'not_customer');

/* ---- creating one ---- */
$noAuth = post('anon', 'reviews/create.php', ['product_id' => 'p1', 'rating' => 5, 'text' => 'Lovely thing to own']);
check('create.php signed out is 401', $noAuth['status'] === 401, 'got ' . $noAuth['status']);

$asSeller = post('seller', 'reviews/create.php', ['product_id' => 'p1', 'rating' => 5, 'text' => 'Lovely thing to own']);
check('create.php as a seller is 403 FORBIDDEN',
    $asSeller['status'] === 403 && ($asSeller['body']['code'] ?? '') === 'FORBIDDEN',
    'got ' . $asSeller['status']);

$notDelivered = post('buyer', 'reviews/create.php',
    ['product_id' => 'p4', 'rating' => 5, 'text' => 'Never actually arrived yet']);
check('Reviewing a product that was never delivered is 403',
    $notDelivered['status'] === 403, 'got ' . $notDelivered['status']);

$badRating = post('buyer', 'reviews/create.php', ['product_id' => 'p1', 'rating' => 9]);
check('A rating of 9 is a 422 field error',
    $badRating['status'] === 422 && isset($badRating['body']['fields']['rating']),
    $badRating['body']['fields']['rating'] ?? ('status ' . $badRating['status']));

$shortText = post('buyer', 'reviews/create.php', ['product_id' => 'p1', 'rating' => 5, 'text' => 'ok']);
check('A 2-character comment is a 422 field error',
    $shortText['status'] === 422 && isset($shortText['body']['fields']['text']),
    $shortText['body']['fields']['text'] ?? ('status ' . $shortText['status']));

// Remember the aggregate so it can be put back.
$before = db()->query("SELECT rating, review_count, rating_breakdown FROM products WHERE public_id = 'p1'")->fetch();

$created = post('buyer', 'reviews/create.php', [
    'product_id' => 'p1',
    'rating'     => 4,
    'text'       => 'Phase 3 automated test review — this row is deleted again at the end of the run.',
]);
check('A delivered customer can create a review (201)',
    $created['status'] === 201 && ($created['body']['data']['created'] ?? false) === true,
    'got ' . $created['status']);
check('  it comes back marked as a verified buyer', (bool) db()->query(
    "SELECT is_verified_buyer FROM reviews r JOIN users u ON u.id = r.user_id
      WHERE r.product_id = 1 AND u.email = 'nimal@example.com'")->fetchColumn());
check('  products.review_count is recomputed from the reviews table',
    (int) db()->query("SELECT review_count FROM products WHERE public_id = 'p1'")->fetchColumn() === 6,
    (string) db()->query("SELECT review_count FROM products WHERE public_id = 'p1'")->fetchColumn());
check('  products.rating is recomputed as the real average',
    (float) db()->query("SELECT rating FROM products WHERE public_id = 'p1'")->fetchColumn() === 4.5,
    (string) db()->query("SELECT rating FROM products WHERE public_id = 'p1'")->fetchColumn());
check('  the stale seeded breakdown is cleared so the bars come from real rows',
    db()->query("SELECT rating_breakdown FROM products WHERE public_id = 'p1'")->fetchColumn() === null);

$derived = api('guest', 'GET', 'products/detail.php?id=p1')['body']['data']['product'] ?? [];
check('  detail.php now derives the breakdown from the 6 real reviews',
    array_sum((array) $derived['ratingBreakdown']) >= 99
        && array_sum((array) $derived['ratingBreakdown']) <= 101,
    json_encode($derived['ratingBreakdown']));
check('  the new review is marked `mine` for its author',
    (bool) array_filter(
        api('buyer', 'GET', 'products/detail.php?id=p1')['body']['data']['product']['reviewsList'],
        static fn(array $r): bool => $r['mine'] === true
    ));

$twice = post('buyer', 'reviews/create.php',
    ['product_id' => 'p1', 'rating' => 1, 'text' => 'Trying to review the same product a second time']);
check('A second review for the same product is refused (409)',
    $twice['status'] === 409, 'got ' . $twice['status']);
check('  ...and still only one row exists', (int) db()->query(
    "SELECT COUNT(*) FROM reviews r JOIN users u ON u.id = r.user_id
      WHERE r.product_id = 1 AND u.email = 'nimal@example.com'")->fetchColumn() === 1);

$permAfter = $perm('buyer', 'p1');
check('  permission now reports already_reviewed',
    ($permAfter['reason'] ?? '') === 'already_reviewed');

section('8. No leaked fields');

$bodies = [
    api('guest', 'GET', 'products/list.php')['raw'],
    api('guest', 'GET', 'products/detail.php?id=p1')['raw'],
    api('buyer', 'GET', 'cart/get.php')['raw'],
    api('buyer', 'GET', 'wishlist/list.php')['raw'],
    api('guest', 'GET', 'reviews/list.php?product=p1')['raw'],
];
$joined = implode("\n", $bodies);

check('No password hash in any catalog response', strpos($joined, '$2y$') === false);
check('No NIC number in any catalog response', stripos($joined, 'nic_number') === false);
check('No bank/account details in any catalog response',
    stripos($joined, 'account_number') === false && stripos($joined, 'bank_name') === false);
check('No email address in any catalog response', strpos($joined, '@') === false);
check('No PHP warning or stack frame in any catalog response',
    stripos($joined, 'Warning') === false && stripos($joined, 'Fatal') === false
        && strpos($joined, '#0 ') === false && strpos($joined, 'C:\\') === false);

section('Cleanup');

db()->exec("DELETE r FROM reviews r JOIN users u ON u.id = r.user_id
             WHERE r.product_id = 1 AND u.email = 'nimal@example.com'");
$restore = db()->prepare('UPDATE products SET rating = ?, review_count = ?, rating_breakdown = ? WHERE public_id = ?');
$restore->execute([$before['rating'], $before['review_count'], $before['rating_breakdown'], 'p1']);

$check = db()->query("SELECT rating, review_count FROM products WHERE public_id = 'p1'")->fetch();
check('Test review deleted and p1 aggregates restored',
    (float) $check['rating'] === (float) $before['rating']
        && (int) $check['review_count'] === (int) $before['review_count'],
    $check['rating'] . ' / ' . $check['review_count']);

db()->exec('DELETE FROM cart_items WHERE user_id IN (SELECT id FROM users WHERE email IN (\'nimal@example.com\', \'sanduni@example.com\'))');
db()->exec('DELETE FROM wishlist  WHERE user_id IN (SELECT id FROM users WHERE email IN (\'nimal@example.com\', \'sanduni@example.com\'))');
db()->exec("DELETE FROM login_attempts WHERE ip_address IN ('127.0.0.1', '::1')");
check('Cart / wishlist / rate-limit rows cleaned up',
    (int) db()->query('SELECT COUNT(*) FROM cart_items')->fetchColumn() === 0
        && (int) db()->query('SELECT COUNT(*) FROM wishlist')->fetchColumn() === 0);

$stocks = db()->query("SELECT public_id, stock, status FROM products WHERE public_id IN ('p6','p8')")->fetchAll();
check('Product stock and status restored',
    array_reduce($stocks, static fn(bool $c, array $r): bool => $c && $r['status'] === 'active', true)
        && (int) db()->query("SELECT stock FROM products WHERE public_id='p6'")->fetchColumn() === 25);

foreach (glob($jarDir . '/*.txt') ?: [] as $f) {
    @unlink($f);
}
@rmdir($jarDir);

echo "\n", str_repeat('-', 70), "\n";
printf("  %d passed, %d failed\n", $pass, $fail);
echo str_repeat('-', 70), "\n";

exit($fail === 0 ? 0 : 1);
