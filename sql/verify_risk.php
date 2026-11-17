<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit("This is a command-line script and will not run over HTTP.\n");
}

require_once __DIR__ . '/../api/config.php';
require_once __DIR__ . '/../api/db.php';

$pass = 0;
$fail = 0;

function check(string $label, bool $ok, ?string $detail = ''): void
{
    global $pass, $fail;
    $detail = $detail ?? '';
    if ($ok) {
        $pass++;
        printf("  [PASS] %s%s\n", $label, $detail !== '' ? "  ($detail)" : '');
    } else {
        $fail++;
        printf("  [FAIL] %s%s\n", $label, $detail !== '' ? "  ($detail)" : '');
    }
}

function section(string $title): void
{
    echo "\n", str_repeat('=', 68), "\n  ", $title, "\n", str_repeat('=', 68), "\n";
}

/** Column name => type fragment, for one table. */
function columns(string $table): array
{
    try {
        $rows = db()->query("SHOW COLUMNS FROM `$table`")->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
    $out = [];
    foreach ($rows as $r) {
        $out[$r['Field']] = $r;
    }
    return $out;
}

echo str_repeat('=', 68), "\n";
echo "  Risk Radar — schema verification\n";
echo str_repeat('=', 68), "\n";

try {
    $version = (string) db()->query('SELECT VERSION()')->fetchColumn();
    check('MySQL/MariaDB reachable', true, $version);
} catch (Throwable $e) {
    check('MySQL/MariaDB reachable', false, $e->getMessage());
    echo "\nStart MySQL in the XAMPP Control Panel and try again.\n";
    exit(1);
}

try {
    db()->query('SELECT MEDIAN(id) OVER () FROM orders LIMIT 1')->fetchColumn();
    check('MEDIAN() OVER () is available (used by the outlier rules)', true);
} catch (Throwable $e) {
    check(
        'MEDIAN() OVER () is available (used by the outlier rules)',
        false,
        'not supported — the VALUE_OUTLIER and QTY_OUTLIER rules need it'
    );
}

section('1. New tables');

$expected = [
    'order_risk_scores' => [
        'id', 'order_id', 'sub_order_id', 'score', 'level', 'rule_score', 'ml_score',
        'signals', 'explanation', 'explanation_source', 'model_version', 'scored_by', 'created_at',
    ],
    'risk_alerts' => [
        'id', 'alert_type', 'order_id', 'sub_order_id', 'seller_id', 'user_id',
        'level', 'score', 'risk_score_id', 'status', 'reviewed_by', 'review_note',
        'created_at', 'reviewed_at',
    ],
    'risk_demo_data' => [
        'id', 'batch', 'object_type', 'object_id', 'anomaly', 'created_at',
    ],
];

foreach ($expected as $table => $wantColumns) {
    $have = columns($table);

    if (!$have) {
        check("table $table exists", false, 'run sql/risk.sql first');
        continue;
    }
    check("table $table exists", true, count($have) . ' columns');

    $missing = array_diff($wantColumns, array_keys($have));
    check("  …has every column the engine reads", !$missing, $missing ? 'missing: ' . implode(', ', $missing) : 'all ' . count($wantColumns));
}

$levelType = columns('order_risk_scores')['level']['Type'] ?? '';
check(
    'order_risk_scores.level has the four levels',
    str_contains($levelType, 'low') && str_contains($levelType, 'medium')
        && str_contains($levelType, 'high') && str_contains($levelType, 'unscored'),
    $levelType
);

$statusType = columns('risk_alerts')['status']['Type'] ?? '';
check(
    'risk_alerts.status has the four decisions',
    str_contains($statusType, 'open') && str_contains($statusType, 'under_review')
        && str_contains($statusType, 'confirmed_fraud') && str_contains($statusType, 'dismissed'),
    $statusType
);

$typeType = columns('risk_alerts')['alert_type']['Type'] ?? '';
check(
    'risk_alerts.alert_type covers order / buyer / seller',
    str_contains($typeType, 'order') && str_contains($typeType, 'buyer') && str_contains($typeType, 'seller'),
    $typeType
);

section('2. Indexes the queue depends on');

/** Does $table have an index covering $firstColumn as its first column? */
function hasIndexOn(string $table, string $firstColumn): bool
{
    try {
        $rows = db()->query("SHOW INDEX FROM `$table`")->fetchAll();
    } catch (Throwable $e) {
        return false;
    }
    foreach ($rows as $r) {
        if ((int) $r['Seq_in_index'] === 1 && $r['Column_name'] === $firstColumn) {
            return true;
        }
    }
    return false;
}

check('risk_alerts is indexed by status (the open-queue read)', hasIndexOn('risk_alerts', 'status'));
check('risk_alerts is indexed by order_id', hasIndexOn('risk_alerts', 'order_id'));
check('order_risk_scores is indexed by order_id', hasIndexOn('order_risk_scores', 'order_id'));
check('order_risk_scores is indexed by level', hasIndexOn('order_risk_scores', 'level'));
check('sub_orders is indexed by payout_hold', hasIndexOn('sub_orders', 'payout_hold'));

section('3. Foreign keys');

$fks = db()->prepare(
    "SELECT k.TABLE_NAME, k.COLUMN_NAME, k.REFERENCED_TABLE_NAME, r.DELETE_RULE
       FROM information_schema.KEY_COLUMN_USAGE k
       JOIN information_schema.REFERENTIAL_CONSTRAINTS r
            ON r.CONSTRAINT_NAME = k.CONSTRAINT_NAME
           AND r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA
      WHERE k.TABLE_SCHEMA = ?
        AND k.TABLE_NAME IN ('order_risk_scores','risk_alerts')"
);
$fks->execute([config('db_name')]);
$rules = [];
foreach ($fks as $row) {
    $rules[$row['TABLE_NAME'] . '.' . $row['COLUMN_NAME']] = [
        'ref'  => $row['REFERENCED_TABLE_NAME'],
        'rule' => $row['DELETE_RULE'],
    ];
}

check('order_risk_scores.order_id → orders', ($rules['order_risk_scores.order_id']['ref'] ?? '') === 'orders');
check('risk_alerts.order_id → orders', ($rules['risk_alerts.order_id']['ref'] ?? '') === 'orders');
check('risk_alerts.user_id → users', ($rules['risk_alerts.user_id']['ref'] ?? '') === 'users');
check('risk_alerts.seller_id → sellers', ($rules['risk_alerts.seller_id']['ref'] ?? '') === 'sellers');

check(
    'risk_alerts.reviewed_by is ON DELETE RESTRICT (a judgement keeps its author)',
    ($rules['risk_alerts.reviewed_by']['rule'] ?? '') === 'RESTRICT',
    $rules['risk_alerts.reviewed_by']['rule'] ?? 'no FK found'
);

section('4. The payout hold on sub_orders');

$sub = columns('sub_orders');

foreach (['payout_hold', 'payout_hold_at', 'payout_hold_reason', 'payout_hold_alert_id'] as $col) {
    check("sub_orders.$col exists", isset($sub[$col]));
}

check(
    'payout_hold defaults to 0 (nothing is held until something decides to)',
    ($sub['payout_hold']['Default'] ?? null) === '0',
    'default: ' . var_export($sub['payout_hold']['Default'] ?? null, true)
);
check(
    'payout_hold is NOT NULL (no third "unknown" state)',
    ($sub['payout_hold']['Null'] ?? '') === 'NO'
);

/* The existing escrow fields must be untouched by all of this. */
foreach (['payout_released_at', 'delivered_at', 'dispute_reason', 'dispute_opened_at'] as $col) {
    check("the original escrow column sub_orders.$col is still there", isset($sub[$col]));
}

section('5. Settings');

$rows = db()->query("SELECT setting_key, value FROM settings WHERE setting_key LIKE 'risk_%'")->fetchAll();
$settings = [];
foreach ($rows as $r) {
    $settings[$r['setting_key']] = json_decode((string) $r['value'], true);
}

foreach ([
    'risk_medium_at', 'risk_high_at', 'risk_rule_weight', 'risk_ml_weight',
    'risk_hold_at_level', 'risk_holds_enabled', 'risk_llm_explain',
] as $key) {
    check("setting $key is present", array_key_exists($key, $settings), isset($settings[$key]) ? json_encode($settings[$key]) : 'missing');
}

$medium = $settings['risk_medium_at'] ?? null;
$high   = $settings['risk_high_at'] ?? null;

check(
    'the thresholds are numeric and in order (medium < high)',
    is_numeric($medium) && is_numeric($high) && $medium > 0 && $medium < $high && $high <= 100,
    "medium at $medium, high at $high"
);

$rw = $settings['risk_rule_weight'] ?? null;
$mw = $settings['risk_ml_weight'] ?? null;

check(
    'the blend weights sum to 1.0',
    is_numeric($rw) && is_numeric($mw) && abs(($rw + $mw) - 1.0) < 0.001,
    "rules $rw + ml $mw = " . (is_numeric($rw) && is_numeric($mw) ? $rw + $mw : '?')
);
check(
    'the rule engine is weighted at least as heavily as the model',
    is_numeric($rw) && is_numeric($mw) && $rw >= $mw,
    'explainable signals should not be outvoted by an opaque score'
);

section('6. The existing shop is untouched');

$counts = db()->query(
    'SELECT (SELECT COUNT(*) FROM users)      AS users,
            (SELECT COUNT(*) FROM products)   AS products,
            (SELECT COUNT(*) FROM orders)     AS orders,
            (SELECT COUNT(*) FROM sub_orders) AS sub_orders,
            (SELECT COUNT(*) FROM payments)   AS payments'
)->fetch();

printf(
    "  %d users, %d products, %d orders, %d sub-orders, %d payments\n",
    $counts['users'], $counts['products'], $counts['orders'],
    $counts['sub_orders'], $counts['payments']
);

$heldCount = (int) db()->query('SELECT COUNT(*) FROM sub_orders WHERE payout_hold = 1')->fetchColumn();

$heldAndReleased = (int) db()->query(
    'SELECT COUNT(*) FROM sub_orders
      WHERE payout_hold = 1 AND payout_released_at IS NOT NULL'
)->fetchColumn();

check(
    'No already-released payout is marked as held',
    $heldAndReleased === 0,
    $heldCount . ' hold(s) in place, none on released money'
);

$holdsWithoutReason = (int) db()->query(
    'SELECT COUNT(*) FROM sub_orders
      WHERE payout_hold = 1 AND (payout_hold_reason IS NULL OR payout_hold_reason = \'\')'
)->fetchColumn();

check(
    'Every payout hold has a reason recorded',
    $holdsWithoutReason === 0,
    $holdsWithoutReason === 0 ? 'all explainable' : $holdsWithoutReason . ' with no reason'
);
check(
    'No escrow release was disturbed by the ALTER',
    (int) db()->query('SELECT COUNT(*) FROM sub_orders WHERE payout_released_at IS NOT NULL')->fetchColumn() > 0,
    'previously-released payouts are still marked released'
);

section('7. Data the risk features assume');

$impossible = (int) db()->query(
    'SELECT COUNT(*) FROM orders o JOIN users u ON u.id = o.user_id
      WHERE o.created_at < u.created_at'
)->fetchColumn();

check(
    'No order predates the account that placed it',
    $impossible === 0,
    $impossible
        ? $impossible . ' order(s) — account_age_hours would be negative'
        : 'account_age_hours is always >= 0'
);

$orphanItems = (int) db()->query(
    'SELECT COUNT(*) FROM order_items oi
       LEFT JOIN products p ON p.id = oi.product_id
      WHERE p.id IS NULL'
)->fetchColumn();
check('Every order line still resolves to a product', $orphanItems === 0, (string) $orphanItems);

$orphanSubs = (int) db()->query(
    'SELECT COUNT(*) FROM sub_orders so
       LEFT JOIN orders o ON o.id = so.order_id
      WHERE o.id IS NULL'
)->fetchColumn();
check('Every sub-order belongs to an order', $orphanSubs === 0, (string) $orphanSubs);

/* ================================================================ */

echo "\n", str_repeat('-', 68), "\n";
printf("  %d passed, %d failed\n", $pass, $fail);
echo str_repeat('-', 68), "\n";

if ($fail === 0) {
    echo "\n  Schema is ready. Next: api/lib/risk_engine.php (Phase B).\n";
}

exit($fail === 0 ? 0 : 1);
