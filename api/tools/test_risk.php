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
$skip = 0;
$failures = [];
$jarDir = sys_get_temp_dir() . '/consult-risktest-' . getmypid();
@mkdir($jarDir, 0777, true);

function section(string $title): void
{
    echo "\n", str_repeat('=', 70), "\n  ", $title, "\n", str_repeat('=', 70), "\n";
}

function check(string $label, bool $ok, string|int|float|bool|null $detail = ''): void
{
    global $pass, $fail, $failures;

    if ($detail === null) {
        $detail = 'null';
    } elseif (is_bool($detail)) {
        $detail = $detail ? 'true' : 'false';
    } else {
        $detail = (string) $detail;
    }

    if ($ok) {
        $pass++;
        printf("  [PASS] %s%s\n", $label, $detail !== '' ? "  ($detail)" : '');
    } else {
        $fail++;
        $failures[] = $label . ($detail !== '' ? ' — ' . $detail : '');
        printf("  [FAIL] %s%s\n", $label, $detail !== '' ? "  ($detail)" : '');
    }
}

function skipped(string $label, string $why): void
{
    global $skip;
    $skip++;
    printf("  [SKIP] %s  (%s)\n", $label, $why);
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
        // Longer than the other suites': a sweep over 48 hours is a real
        // amount of work and this suite triggers one.
        CURLOPT_TIMEOUT        => 300,
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

section('0. Environment');

$probe = api('guest', 'GET', 'auth/csrf.php');
if ($probe['status'] !== 200) {
    echo "  Cannot reach {$BASE}/api/auth/csrf.php (status {$probe['status']}).\n";
    echo "  Start Apache in the XAMPP Control Panel, then run this again.\n";
    exit(1);
}
check('Apache serves the API', true, $BASE);

try {
    db()->query('SELECT 1 FROM risk_alerts LIMIT 1');
    check('The risk tables exist', true);
} catch (Throwable $e) {
    check('The risk tables exist', false, 'import sql/risk.sql first');
    exit(1);
}

$adminLogin = login('admin', 'admin@consult.lk', $DEMO_PASSWORD);
$adminId = (int) (data($adminLogin)['user']['id'] ?? 0);
check('Seeded admin signs in', $adminId > 0, 'status ' . $adminLogin['status']);
if (!$adminId) {
    exit(1);
}

$sellerLogin = login('seller', 'techhub@example.com', $DEMO_PASSWORD);
$sellerId = (int) (data($sellerLogin)['user']['id'] ?? 0);
check('Seeded seller signs in', $sellerId > 0, 'status ' . $sellerLogin['status']);

$custLogin = login('cust', 'nimal@example.com', $DEMO_PASSWORD);
$custId = (int) (data($custLogin)['user']['id'] ?? 0);
check('Seeded customer signs in', $custId > 0, 'status ' . $custLogin['status']);

section('1. Role gates on every risk endpoint');

$adminOnly = [
    ['GET',  'admin/risk_overview.php'],
    ['GET',  'admin/risk_alerts.php'],
    ['GET',  'admin/risk_alert_detail.php?id=1'],
    ['POST', 'admin/risk_alert_decide.php'],
    ['POST', 'admin/risk_rescore.php'],
];

foreach ($adminOnly as [$method, $path]) {
    $short = explode('?', basename($path))[0];

    foreach (['guest' => [401, 403], 'cust' => [403], 'seller' => [403]] as $jar => $expect) {
        $res = $method === 'POST'
            ? post($jar, $path, ['alert_id' => 1, 'note' => 'should never be recorded'])
            : api($jar, $method, $path);

        check(
            sprintf('%-28s refuses %s', $short, $jar),
            in_array($res['status'], $expect, true),
            'status ' . $res['status']
        );
    }
}

foreach (['guest' => [401, 403], 'cust' => [403], 'admin' => [403]] as $jar => $expect) {
    $res = api($jar, 'GET', 'seller/risk_notices.php');
    check(
        sprintf('%-28s refuses %s', 'seller/risk_notices.php', $jar),
        in_array($res['status'], $expect, true),
        'status ' . $res['status']
    );
}

$res = api('seller', 'GET', 'seller/risk_notices.php');
check('seller/risk_notices.php          allows the seller', $res['status'] === 200, 'status ' . $res['status']);

section('2. A seller never sees a score, a signal or a buyer');

$notices = api('seller', 'GET', 'seller/risk_notices.php');
$rawJson = strtolower($notices['raw']);

$forbidden = [
    'score'       => 'the numeric score',
    'signal'      => 'the signal list',
    'risk_level'  => 'the level',
    'velocity'    => 'a signal code',
    'buyer'       => 'the buyer',
    'user_id'     => 'the buyer id',
    'awaiting review' => 'the internal hold reason',
];

foreach ($forbidden as $needle => $what) {
    check(
        sprintf('no %s in the seller payload', $what),
        !str_contains($rawJson, $needle),
        $needle
    );
}

$body = data($notices);
check(
    'it does return the seller\'s own hold count and total',
    is_array($body) && array_key_exists('count', $body) && array_key_exists('total_held', $body),
    sprintf('%d notice(s), LKR %s held', $body['count'] ?? -1, $body['total_held'] ?? '?')
);

if (($body['count'] ?? 0) > 0) {
    $first = $body['notices'][0];
    check(
        'each notice says only that the order is under routine review',
        str_contains(strtolower((string) $first['message']), 'routine review'),
        $first['message']
    );
    check(
        'and carries the amount and the date, which the seller can act on',
        isset($first['amount'], $first['since']) && $first['amount'] > 0,
        sprintf('LKR %s since %s', $first['amount'], $first['since'])
    );

    /* The security boundary: every row must belong to this seller. */
    $ids = array_column($body['notices'], 'sub_order_id');
    $in  = implode(',', array_fill(0, count($ids), '?'));
    $stmt = db()->prepare("SELECT COUNT(*) FROM sub_orders WHERE id IN ($in) AND seller_id <> ?");
    $stmt->execute(array_merge($ids, [$sellerId]));
    check(
        'every notice belongs to the logged-in seller',
        (int) $stmt->fetchColumn() === 0,
        count($ids) . ' checked against seller_id ' . $sellerId
    );
} else {
    skipped('notice contents', 'this seller has no held payouts right now');
}

section('3. admin/risk_overview.php');

$res = api('admin', 'GET', 'admin/risk_overview.php');
check('overview answers 200', $res['status'] === 200, 'status ' . $res['status']);

$over = data($res);
check('it returns the KPI block', isset($over['kpis']['open_alerts']), 'open: ' . ($over['kpis']['open_alerts'] ?? '?'));
check(
    'the 30-day series has exactly 30 points, quiet days included',
    is_array($over['series'] ?? null) && count($over['series']) === 30,
    count($over['series'] ?? []) . ' points'
);
check(
    'the series is in date order',
    (static function (array $series): bool {
        $dates = array_column($series, 'date');
        $sorted = $dates;
        sort($sorted);
        return $dates === $sorted;
    })($over['series'] ?? []),
    ($over['series'][0]['date'] ?? '?') . ' … ' . ($over['series'][29]['date'] ?? '?')
);
check(
    'the held amount is summed from sub-orders, not order totals',
    (static function (array $over): bool {
        $stmt = db()->query('SELECT COALESCE(SUM(subtotal),0) FROM sub_orders WHERE payout_hold = 1');
        return abs((float) $stmt->fetchColumn() - (float) ($over['kpis']['held_amount'] ?? -1)) < 0.01;
    })($over),
    'LKR ' . ($over['kpis']['held_amount'] ?? '?')
);
check(
    'dismissed_rate is null until something has been decided',
    ($over['kpis']['decided'] ?? 0) > 0
        ? is_float($over['kpis']['dismissed_rate']) || is_int($over['kpis']['dismissed_rate'])
        : $over['kpis']['dismissed_rate'] === null,
    'decided ' . ($over['kpis']['decided'] ?? 0) . ', rate ' . var_export($over['kpis']['dismissed_rate'] ?? null, true)
);
check(
    'the config block reports the live thresholds',
    isset($over['config']['medium_at'], $over['config']['high_at'])
        && $over['config']['high_at'] > $over['config']['medium_at'],
    sprintf('medium %s, high %s', $over['config']['medium_at'] ?? '?', $over['config']['high_at'] ?? '?')
);
check(
    'the response says the data is demo data',
    ($over['demo_data'] ?? null) === true
);

if (is_array($over['signals'] ?? null) && $over['signals']) {
    check(
        'the signal breakdown names real signal codes with labels',
        isset($over['signals'][0]['code'], $over['signals'][0]['label'], $over['signals'][0]['count']),
        $over['signals'][0]['code'] . ' × ' . $over['signals'][0]['count']
    );
} else {
    skipped('signal breakdown', 'no medium/high scores in the last 30 days');
}

section('4. admin/risk_alerts.php and the detail screen');

$res = api('admin', 'GET', 'admin/risk_alerts.php?status=unresolved&per_page=5');
check('the queue answers 200', $res['status'] === 200, 'status ' . $res['status']);
$queue = data($res);

check('it pages', isset($queue['page'], $queue['per_page'], $queue['total'], $queue['pages']),
    sprintf('page %s of %s, %s total', $queue['page'] ?? '?', $queue['pages'] ?? '?', $queue['total'] ?? '?'));
check('at most per_page rows come back', count($queue['alerts'] ?? []) <= 5, count($queue['alerts'] ?? []) . ' rows');
check('the tab counts are present', isset($queue['counts']['open']), 'open: ' . ($queue['counts']['open'] ?? '?'));

$res = api('admin', 'GET', 'admin/risk_alerts.php?status=whatever');
check('an unknown status filter is a 422', $res['status'] === 422, 'status ' . $res['status']);
$res = api('admin', 'GET', 'admin/risk_alerts.php?from=09-11-2026');
check('a malformed date is a 422', $res['status'] === 422, 'status ' . $res['status']);
$res = api('admin', 'GET', 'admin/risk_alerts.php?level=high&status=all');
check('filtering by level works', $res['status'] === 200
    && !array_filter($queueLevels = array_column(data($res)['alerts'] ?? [], 'level'), fn($l) => $l !== 'high'),
    count($queueLevels) . ' rows, all high');

$listRaw = strtolower($res['raw']);
check(
    'the LIST does not include buyer emails',
    !str_contains($listRaw, '@example.com') && !str_contains($listRaw, '"email"'),
);

$alertId = (int) (data(api('admin', 'GET', 'admin/risk_alerts.php?status=unresolved&per_page=1'))['alerts'][0]['id'] ?? 0);

if (!$alertId) {
    skipped('the detail screen', 'no unresolved alerts — run the sweep first');
} else {
    $res = api('admin', 'GET', 'admin/risk_alert_detail.php?id=' . $alertId);
    check('the detail answers 200', $res['status'] === 200, 'alert ' . $alertId);
    $detail = data($res);

    check('it returns the alert', ($detail['alert']['id'] ?? 0) === $alertId);
    check(
        'it returns the signal list WITH the raw evidence',
        is_array($detail['signals'] ?? null) && (
            $detail['signals'] === [] || isset($detail['signals'][0]['evidence'])
        ),
        count($detail['signals'] ?? []) . ' signal(s)'
    );
    check(
        'it returns an explanation in words',
        is_string($detail['explanation'] ?? null) && trim((string) $detail['explanation']) !== '',
        mb_substr((string) $detail['explanation'], 0, 54) . '…'
    );
    check(
        'it says whether the explanation came from the model or the template',
        in_array($detail['explanation_source'] ?? '', ['llm', 'template'], true),
        $detail['explanation_source'] ?? 'missing'
    );
    check(
        'it separates the rule score from the blended one',
        isset($detail['score']['rule_score'], $detail['score']['blended']),
        sprintf(
            'rule %s, ml %s, blended %s',
            $detail['score']['rule_score'] ?? '?',
            var_export($detail['score']['ml_score'] ?? null, true),
            var_export($detail['score']['blended'] ?? null, true)
        )
    );
    check(
        'it carries the buyer history so "is this out of character" is answerable',
        is_array($detail['buyer_history'] ?? null),
        count($detail['buyer_history'] ?? []) . ' past order(s)'
    );
    check(
        'it says which actions are available, so the UI cannot offer a refused one',
        isset($detail['actions']['dismissed']),
    );
    check(
        'the demo payment is labelled as a demo payment',
        ($detail['order']['payment']['is_demo'] ?? null) === true
            || ($detail['order'] ?? null) === null,
        $detail['order']['payment']['provider'] ?? 'no order'
    );

    $res = api('admin', 'GET', 'admin/risk_alert_detail.php?id=99999999');
    check('an unknown alert is a 404', $res['status'] === 404, 'status ' . $res['status']);
}

section('5. admin/risk_alert_decide.php — a note is mandatory');

$db = db();
$heldOrderId = (int) $db->query(
    'SELECT so.order_id FROM sub_orders so WHERE so.payout_hold = 1 ORDER BY so.id DESC LIMIT 1'
)->fetchColumn();

if (!$heldOrderId) {
    skipped('the decision flow', 'no held payouts to decide on — run the sweep first');
} else {
    $db->prepare(
        'INSERT INTO risk_alerts (alert_type, order_id, user_id, level, score, status)
         SELECT \'order\', o.id, o.user_id, \'high\', 99, \'open\' FROM orders o WHERE o.id = ?'
    )->execute([$heldOrderId]);
    $testAlert = (int) $db->lastInsertId();

    check('a throwaway alert was created for this test', $testAlert > 0, 'alert ' . $testAlert);

    $res = post('admin', 'admin/risk_alert_decide.php', ['alert_id' => $testAlert, 'decision' => 'dismissed']);
    check('a decision with no note is refused', $res['status'] === 422 || $res['status'] === 400, 'status ' . $res['status']);
    check(
        'and the message asks for a reason',
        str_contains(strtolower($res['raw']), 'note') || str_contains(strtolower($res['raw']), 'reason'),
    );

    $res = post('admin', 'admin/risk_alert_decide.php', [
        'alert_id' => $testAlert, 'decision' => 'dismissed', 'note' => 'ok',
    ]);
    check('a two-character note is refused', $res['status'] === 422 || $res['status'] === 400, 'status ' . $res['status']);

    $res = post('admin', 'admin/risk_alert_decide.php', [
        'alert_id' => $testAlert, 'decision' => 'definitely_a_crime', 'note' => 'a perfectly long note',
    ]);
    check('an unknown decision is refused', $res['status'] === 422 || $res['status'] === 400, 'status ' . $res['status']);

    /* ---- under_review changes nothing but the status ---- */
    $holdsBefore = (int) $db->query('SELECT COUNT(*) FROM sub_orders WHERE payout_hold = 1')->fetchColumn();

    $res = post('admin', 'admin/risk_alert_decide.php', [
        'alert_id' => $testAlert, 'decision' => 'under_review',
        'note'     => 'Taking a look at this one now.',
    ]);
    check('under_review is accepted', $res['status'] === 200, 'status ' . $res['status']);
    check(
        'under_review does NOT release the hold',
        (int) $db->query('SELECT COUNT(*) FROM sub_orders WHERE payout_hold = 1')->fetchColumn() === $holdsBefore,
        $holdsBefore . ' hold(s) before and after'
    );

    /* ---- dismissing releases ---- */
    $auditBefore = actionCount('risk_dismissed', $testAlert);

    $res = post('admin', 'admin/risk_alert_decide.php', [
        'alert_id' => $testAlert, 'decision' => 'dismissed',
        'note'     => 'Called the buyer, this is a genuine bulk order for an office.',
    ]);
    check('dismissing is accepted', $res['status'] === 200, 'status ' . $res['status']);
    $decision = data($res);
    check(
        'dismissing RELEASES the payout hold',
        ($decision['alert']['released'] ?? 0) > 0,
        ($decision['alert']['released'] ?? 0) . ' sub-order(s) released'
    );
    check(
        'the hold is actually gone from the table',
        (int) $db->query(
            'SELECT COUNT(*) FROM sub_orders WHERE order_id = ' . (int) $heldOrderId . ' AND payout_hold = 1'
        )->fetchColumn() === 0
    );
    check(
        'the release is written to the order timeline',
        (int) $db->query(
            'SELECT COUNT(*) FROM order_status_log l
               JOIN sub_orders so ON so.id = l.sub_order_id
              WHERE so.order_id = ' . (int) $heldOrderId . '
                AND l.note LIKE \'%hold released%\''
        )->fetchColumn() > 0
    );

    /* ---- the audit row ---- */
    check(
        'the decision is written to admin_actions',
        actionCount('risk_dismissed', $testAlert) === $auditBefore + 1,
    );
    $row = $db->query(
        'SELECT target_type, admin_id, notes FROM admin_actions
          WHERE action_type = \'risk_dismissed\' AND target_id = ' . (int) $testAlert . '
       ORDER BY id DESC LIMIT 1'
    )->fetch();
    check(
        'the audit row points at the ALERT, not at a NULL order',
        ($row['target_type'] ?? '') === 'risk_alert',
        $row['target_type'] ?? 'missing'
    );
    check(
        'the audit row names the admin who decided',
        (int) ($row['admin_id'] ?? 0) === $adminId,
    );
    check(
        'the audit row carries the note verbatim',
        str_contains((string) ($row['notes'] ?? ''), 'genuine bulk order'),
    );

    /* ---- and it cannot be decided twice ---- */
    $res = post('admin', 'admin/risk_alert_decide.php', [
        'alert_id' => $testAlert, 'decision' => 'confirmed_fraud',
        'note'     => 'Changed my mind about this one entirely.',
    ]);
    check('an already-decided alert cannot be re-decided', $res['status'] === 409, 'status ' . $res['status']);

    /* ---- confirmed_fraud does nothing destructive ---- */
    $db->prepare(
        'INSERT INTO risk_alerts (alert_type, order_id, user_id, level, score, status)
         SELECT \'order\', o.id, o.user_id, \'high\', 98, \'open\' FROM orders o WHERE o.id = ?'
    )->execute([$heldOrderId]);
    $confirmAlert = (int) $db->lastInsertId();

    $statusBefore = $db->query(
        'SELECT GROUP_CONCAT(status ORDER BY id) FROM sub_orders WHERE order_id = ' . (int) $heldOrderId
    )->fetchColumn();
    $buyerBefore = $db->query(
        'SELECT u.status FROM users u JOIN orders o ON o.user_id = u.id WHERE o.id = ' . (int) $heldOrderId
    )->fetchColumn();

    $res = post('admin', 'admin/risk_alert_decide.php', [
        'alert_id' => $confirmAlert, 'decision' => 'confirmed_fraud',
        'note'     => 'Three accounts at one address, all new, all maxing the same listing.',
    ]);
    check('confirming is accepted', $res['status'] === 200, 'status ' . $res['status']);
    $confirmed = data($res);

    /* THE CENTRAL DESIGN CLAIM OF THIS WHOLE FEATURE. */
    check(
        'confirming does NOT change any order status',
        $db->query(
            'SELECT GROUP_CONCAT(status ORDER BY id) FROM sub_orders WHERE order_id = ' . (int) $heldOrderId
        )->fetchColumn() === $statusBefore,
        (string) $statusBefore
    );
    check(
        'confirming does NOT suspend the buyer',
        $db->query(
            'SELECT u.status FROM users u JOIN orders o ON o.user_id = u.id WHERE o.id = ' . (int) $heldOrderId
        )->fetchColumn() === $buyerBefore,
        (string) $buyerBefore
    );
    check(
        'confirming does NOT refund anything',
        (int) $db->query(
            'SELECT COUNT(*) FROM sub_orders WHERE order_id = ' . (int) $heldOrderId . ' AND refunded_at IS NOT NULL'
        )->fetchColumn() === 0
    );
    check(
        'instead it OFFERS the destructive actions as separate steps',
        is_array($confirmed['next_actions'] ?? null) && count($confirmed['next_actions']) > 0,
        implode(', ', array_column($confirmed['next_actions'] ?? [], 'endpoint'))
    );
    check(
        'and says plainly that nothing was done automatically',
        str_contains(strtolower((string) ($confirmed['message'] ?? '')), 'nothing has been cancelled'),
        $confirmed['message'] ?? ''
    );

    $db->exec('DELETE FROM risk_alerts WHERE id IN (' . (int) $testAlert . ', ' . (int) $confirmAlert . ')');
    $db->exec('DELETE FROM admin_actions WHERE target_type = \'risk_alert\' AND target_id IN ('
        . (int) $testAlert . ', ' . (int) $confirmAlert . ')');
}

section('6. admin/risk_rescore.php');

$someOrder = (int) $db->query('SELECT id FROM orders ORDER BY id DESC LIMIT 1')->fetchColumn();

$res = post('admin', 'admin/risk_rescore.php', []);
check('a re-score with no target is refused', $res['status'] === 422 || $res['status'] === 400, 'status ' . $res['status']);

$res = post('admin', 'admin/risk_rescore.php', ['order_id' => 99999999]);
check('re-scoring an unknown order is a 404', $res['status'] === 404, 'status ' . $res['status']);

$auditBefore = actionCount('risk_rescore_order', $someOrder);
$res = post('admin', 'admin/risk_rescore.php', ['order_id' => $someOrder]);
check('re-scoring one order answers 200', $res['status'] === 200, 'order ' . $someOrder);
$rescored = data($res);
check(
    'it returns a score and a level',
    isset($rescored['score'], $rescored['level'])
        && in_array($rescored['level'], ['low', 'medium', 'high', 'unscored'], true),
    sprintf('%s (%s)', $rescored['level'] ?? '?', $rescored['score'] ?? '?')
);
check(
    'it says whether the model was involved',
    array_key_exists('blended', $rescored),
    'blended: ' . var_export($rescored['blended'] ?? null, true)
);
check(
    'the re-score is audited',
    actionCount('risk_rescore_order', $someOrder) === $auditBefore + 1,
);

/* The sweep, over a deliberately tiny window so this stays quick. */
$res = post('admin', 'admin/risk_rescore.php', ['sweep' => true, 'hours' => 1]);
check('the "run sweep now" button answers 200', $res['status'] === 200, 'status ' . $res['status']);
$sweep = data($res);
check(
    'it returns the sweep counters',
    isset($sweep['sweep']['orders_examined'], $sweep['sweep']['alerts_raised']),
    sprintf(
        '%s examined, %s scored, %s alert(s)',
        $sweep['sweep']['orders_examined'] ?? '?',
        $sweep['sweep']['orders_scored'] ?? '?',
        $sweep['sweep']['alerts_raised'] ?? '?'
    )
);
check('the sweep is audited', actionCount('risk_sweep') > 0);

section('7. The checkout hook does not break checkout');

$stamp = substr(bin2hex(random_bytes(4)), 0, 8);
$email = "hooktest-$stamp@example.com";

$res = post('buyer', 'auth/register.php', [
    'name'         => 'Hook Test ' . $stamp,
    'email'        => $email,
    'phone'        => '0770000' . random_int(100, 999),
    'password'     => $DEMO_PASSWORD,
    'accept_terms' => true,
]);
$newUserId = (int) (data($res)['user']['id'] ?? 0);
check('a throwaway customer registers', $newUserId > 0, 'status ' . $res['status']);

if (!$newUserId) {
    skipped('the checkout hook', 'could not create a test customer');
} else {
    $inStock = $db->query(
        'SELECT public_id FROM products WHERE stock > 2 AND status = \'active\' ORDER BY id LIMIT 1'
    )->fetchColumn();

    $added = post('buyer', 'cart/add.php', ['product_id' => $inStock, 'qty' => 1]);
    check('a product goes into the cart', $added['status'] === 200, $inStock . ', status ' . $added['status']);

    $scoresBefore = (int) $db->query('SELECT COUNT(*) FROM order_risk_scores')->fetchColumn();

    $res = post('buyer', 'orders/checkout.php', [
        'name'    => 'Hook Test ' . $stamp,
        'phone'   => '0770000111',
        'address' => '42 Test Lane',
        'city'    => 'Colombo',
        'zip'     => '00100',
    ]);

    check('checkout still succeeds', $res['status'] === 201, 'status ' . $res['status']);
    $newOrderId = (int) (data($res)['order']['id'] ?? 0);

    if ($newOrderId) {
        check(
            'the order was scored by the hook',
            (int) $db->query('SELECT COUNT(*) FROM order_risk_scores WHERE order_id = ' . $newOrderId)->fetchColumn() > 0,
            'order ' . $newOrderId
        );
        $score = $db->query(
            'SELECT score, level, scored_by, explanation FROM order_risk_scores
              WHERE order_id = ' . $newOrderId . ' ORDER BY id DESC LIMIT 1'
        )->fetch();
        check(
            'the score row says it came from checkout',
            ($score['scored_by'] ?? '') === 'checkout',
            sprintf('%s (%s) via %s', $score['level'] ?? '?', $score['score'] ?? '?', $score['scored_by'] ?? '?')
        );
        check(
            'it has an explanation in words',
            trim((string) ($score['explanation'] ?? '')) !== '',
            mb_substr((string) ($score['explanation'] ?? ''), 0, 60) . '…'
        );
        check(
            'the customer response says nothing about risk',
            !str_contains(strtolower($res['raw']), 'risk')
                && !str_contains(strtolower($res['raw']), 'flagged')
                && !str_contains(strtolower($res['raw']), 'review'),
        );

        $db->prepare(
            'UPDATE products p
                SET p.stock = p.stock + (
                    SELECT COALESCE(SUM(oi.quantity), 0)
                      FROM order_items oi
                      JOIN sub_orders so ON so.id = oi.sub_order_id
                     WHERE so.order_id = ? AND oi.product_id = p.id
                )
              WHERE p.id IN (
                    SELECT oi.product_id FROM order_items oi
                      JOIN sub_orders so ON so.id = oi.sub_order_id
                     WHERE so.order_id = ?
                )'
        )->execute([$newOrderId, $newOrderId]);

        $db->exec('DELETE FROM risk_alerts       WHERE order_id = ' . $newOrderId);
        $db->exec('DELETE FROM order_risk_scores WHERE order_id = ' . $newOrderId);
        $db->exec('DELETE FROM orders            WHERE id = ' . $newOrderId);

        check('the test order was cleaned up',
            (int) $db->query('SELECT COUNT(*) FROM orders WHERE id = ' . $newOrderId)->fetchColumn() === 0);
        check('its status log went with it, despite being append-only',
            (int) $db->query(
                'SELECT COUNT(*) FROM order_status_log l
                   LEFT JOIN sub_orders so ON so.id = l.sub_order_id
                  WHERE so.id IS NULL'
            )->fetchColumn() === 0,
            'no orphaned log rows');
    }

    $db->exec('DELETE FROM cart_items WHERE user_id = ' . $newUserId);
    $db->exec('DELETE FROM users WHERE id = ' . $newUserId);
    check('the test customer was cleaned up',
        (int) $db->query('SELECT COUNT(*) FROM users WHERE id = ' . $newUserId)->fetchColumn() === 0);
}

section('8. The risk settings refuse a nonsensical pair');

$before = $db->query(
    'SELECT setting_key, value FROM settings WHERE setting_key IN (\'risk_medium_at\', \'risk_high_at\')'
)->fetchAll(PDO::FETCH_KEY_PAIR);

$res = post('admin', 'admin/settings.php', ['risk_medium_at' => 80, 'risk_high_at' => 40]);
check('high below medium is refused', $res['status'] === 422 || $res['status'] === 400, 'status ' . $res['status']);

$res = post('admin', 'admin/settings.php', ['risk_medium_at' => 0]);
check('a threshold of 0 is refused', $res['status'] === 422 || $res['status'] === 400, 'status ' . $res['status']);

$res = post('admin', 'admin/settings.php', ['risk_rule_weight' => 1.4]);
check('a weight above 1 is refused', $res['status'] === 422 || $res['status'] === 400, 'status ' . $res['status']);

$res = post('admin', 'admin/settings.php', ['risk_rule_weight' => 0, 'risk_ml_weight' => 0]);
check('both weights zero is refused', $res['status'] === 422 || $res['status'] === 400, 'status ' . $res['status']);

$res = post('admin', 'admin/settings.php', ['risk_hold_at_level' => 'whenever']);
check('an unknown hold level is refused', $res['status'] === 422 || $res['status'] === 400, 'status ' . $res['status']);

/* A valid change, then put it straight back. */
$res = post('admin', 'admin/settings.php', ['risk_medium_at' => 36]);
check('a valid threshold change is accepted', $res['status'] === 200, 'status ' . $res['status']);
check(
    'and the admin is told it is not retroactive',
    str_contains(strtolower((string) (data($res)['notice'] ?? '')), 'from now on'),
    data($res)['notice'] ?? 'no notice'
);
check('the change is audited', actionCount('update_settings') > 0);

foreach ($before as $key => $value) {
    $db->prepare('UPDATE settings SET value = ? WHERE setting_key = ?')->execute([$value, $key]);
}
if (!isset($before['risk_medium_at'])) {
    $db->exec('DELETE FROM settings WHERE setting_key = \'risk_medium_at\'');
}
check(
    'the thresholds were restored',
    (string) $db->query('SELECT value FROM settings WHERE setting_key = \'risk_medium_at\'')->fetchColumn()
        === (string) ($before['risk_medium_at'] ?? '35'),
    'risk_medium_at back to ' . ($before['risk_medium_at'] ?? '35')
);

/* ================================================================ */

array_map('unlink', glob($jarDir . '/*.txt') ?: []);
@rmdir($jarDir);

echo "\n", str_repeat('-', 70), "\n";
printf('  %d passed, %d failed', $pass, $fail);
if ($skip) {
    printf(', %d skipped', $skip);
}
echo "\n", str_repeat('-', 70), "\n";

if ($failures) {
    echo "\n  Failures:\n";
    foreach ($failures as $f) {
        echo '    - ', $f, "\n";
    }
    echo "\n";
}

exit($fail === 0 ? 0 : 1);
