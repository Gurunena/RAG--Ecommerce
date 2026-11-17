<?php
/**
 * tests/risk_engine_test.php — Phase B tests for the Risk Radar rule engine.
 *
 *   C:\xampp\php\php.exe tests\risk_engine_test.php
 *   C:\xampp\php\php.exe tests\risk_engine_test.php --verbose
 *
 * ============================================================
 * HOW THIS AVOIDS LEAVING ANYTHING BEHIND
 * ============================================================
 *
 * Every scenario runs inside a TRANSACTION THAT IS ALWAYS ROLLED BACK.
 * Nothing is committed, so the real tables are untouched even if a scenario
 * throws half way through — which is also why the scenarios can insert
 * deliberately absurd data (fifty orders in an hour, a seller buying from
 * itself) without a cleanup routine that could itself fail.
 *
 * The one thing to know: MySQL DDL is not transactional, so a scenario must
 * not create or alter tables. None do — they only INSERT.
 *
 * ============================================================
 * WHAT IS BEING PROVEN
 * ============================================================
 *
 * 1. Each of the 13 signals fires when its condition is genuinely met.
 * 2. Each one does NOT fire just below its threshold — a rule that always
 *    fires is as useless as one that never does.
 * 3. THE MINIMUM-SAMPLE GUARDS HOLD. This is the important one: the 8
 *    seeded orders must produce no alerts at all, and a scenario with the
 *    right shape but too little history must stay quiet.
 * 4. The scoring arithmetic, the level boundaries, and the median/MAD
 *    helpers.
 * 5. The LLM explanation guard rejects invented numbers and accusations.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit("This is a command-line script and will not run over HTTP.\n");
}

require_once __DIR__ . '/../api/config.php';
require_once __DIR__ . '/../api/db.php';
require_once __DIR__ . '/../api/lib/risk_engine.php';

$verbose = in_array('--verbose', $argv, true) || in_array('-v', $argv, true);

$pass = 0;
$fail = 0;
$skip = 0;
$failures = [];

function section(string $title): void
{
    echo "\n", str_repeat('=', 72), "\n  ", $title, "\n", str_repeat('=', 72), "\n";
}

/**
 * $detail takes anything scalar on purpose. It is almost always a number
 * read straight out a signal's evidence, and a harness that throws a
 * TypeError on a float turns one assertion into a fatal that hides every
 * check after it.
 */
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

/**
 * A check that cannot be made meaningfully right now, with the reason.
 *
 * Counted separately from a pass: a scenario that needs a near-empty
 * database proves nothing once 1,700 demo orders are loaded, and reporting
 * it as PASS would claim a guard was verified when it was not.
 */
function skipped(string $label, string $why): void
{
    global $skip;
    $skip++;
    printf("  [SKIP] %s  (%s)\n", $label, $why);
}

/** Did a signal with this code fire? */
function fired(array $result, string $code): bool
{
    foreach ($result['signals'] ?? [] as $signal) {
        if ($signal['code'] === $code) {
            return true;
        }
    }
    return false;
}

/** The codes that fired, for a readable failure message. */
function codes(array $result): string
{
    $c = array_column($result['signals'] ?? [], 'code');
    return $c ? implode(', ', $c) : 'none';
}

/** One signal's evidence, for asserting the numbers are right. */
function evidence(array $result, string $code): array
{
    foreach ($result['signals'] ?? [] as $signal) {
        if ($signal['code'] === $code) {
            return $signal['evidence'];
        }
    }
    return [];
}

/**
 * Run $scenario inside a transaction and roll it back.
 *
 * The callable gets the PDO and a \Closure bag of fixture builders. Whatever
 * it returns is handed back to the caller, so a scenario can return a score
 * result for assertions outside the transaction.
 */
function scenario(callable $body)
{
    $db = db();
    $db->beginTransaction();

    try {
        return $body($db, new Fixtures($db));
    } finally {
        // ALWAYS. Even on an exception — the finally is the whole safety
        // guarantee of this file.
        if ($db->inTransaction()) {
            $db->rollBack();
        }
    }
}

/**
 * Fixture builders.
 *
 * Deliberately thin wrappers over INSERT: a test that builds its data
 * through the application's own endpoints would be an integration test, and
 * what is being tested here is the engine's arithmetic against known rows.
 */
final class Fixtures
{
    private int $seq = 0;

    public function __construct(private PDO $db)
    {
    }

    /** A customer, created $ageHours ago. */
    public function user(float $ageHours = 24 * 365, string $role = 'customer'): int
    {
        $this->seq++;
        $this->db->prepare(
            'INSERT INTO users (name, email, phone, password_hash, role, status, created_at)
             VALUES (?, ?, ?, ?, ?, \'active\', NOW() - INTERVAL ? MINUTE)'
        )->execute([
            'Risk Test ' . $this->seq,
            sprintf('risk-test-%d-%d@example.invalid', getmypid(), $this->seq),
            '07' . str_pad((string) (10000000 + $this->seq), 8, '0'),
            password_hash('irrelevant', PASSWORD_DEFAULT),
            $role,
            (int) round($ageHours * 60),
        ]);
        return (int) $this->db->lastInsertId();
    }

    /** A verified seller with one active product. Returns [sellerId, productId]. */
    public function seller(string $phone = '0119999999'): array
    {
        $userId = $this->user(24 * 400, 'seller');
        $this->seq++;

        $this->db->prepare('UPDATE users SET phone = ? WHERE id = ?')->execute([$phone, $userId]);

        $this->db->prepare(
            'INSERT INTO sellers (id, public_id, business_name, business_type, nic_number,
                                  city, kyc_status, commission_rate, rating)
             VALUES (?, ?, ?, \'other\', ?, \'Colombo\', \'verified\', NULL, 4.5)'
        )->execute([
            $userId,
            'st' . $userId,
            'Risk Test Shop ' . $this->seq,
            '9' . str_pad((string) $userId, 8, '0') . 'V',
        ]);

        $this->db->prepare(
            'INSERT INTO products (public_id, seller_id, title, category, icon, price, stock, status)
             VALUES (?, ?, ?, \'laptop\', \'laptop\', 150000, 500, \'active\')'
        )->execute(['pt' . $userId, $userId, 'Risk Test Product ' . $this->seq]);

        return [$userId, (int) $this->db->lastInsertId()];
    }

    /**
     * One order with one sub-order and one line.
     *
     * $minutesAgo places it in the past so velocity and baseline windows can
     * be set up precisely.
     */
    public function order(
        int $userId,
        int $sellerId,
        int $productId,
        float $unitPrice = 150000,
        int $qty = 1,
        int $minutesAgo = 0,
        string $status = 'delivered',
        ?string $address = null,
        ?string $phone = null
    ): int {
        $total = $unitPrice * $qty;

        /* The default address and phone are UNIQUE PER ORDER, not a shared
           constant. A shared default meant any scenario that created
           several buyers also tripped SHARED_ADDRESS_MANY_ACCOUNTS — the
           assertions still passed, because they test for a named signal,
           but the fixtures were firing a rule nobody asked about. A test
           that shares an address now says so by passing one. */
        $this->seq++;

        $this->db->prepare(
            'INSERT INTO orders (user_id, total_amount, currency, payment_status,
                                 shipping_name, shipping_phone, shipping_address, shipping_city,
                                 created_at)
             VALUES (?, ?, \'LKR\', \'paid\', \'Risk Test Buyer\', ?, ?, \'Colombo 03\',
                     NOW() - INTERVAL ? MINUTE)'
        )->execute([
            $userId,
            $total,
            $phone ?? sprintf('07%08d', 30000000 + $this->seq),
            $address ?? sprintf('%d Unique Test Lane', 1000 + $this->seq),
            $minutesAgo,
        ]);
        $orderId = (int) $this->db->lastInsertId();

        $this->db->prepare(
            'INSERT INTO sub_orders (order_id, seller_id, status, subtotal, delivery_fee, commission_rate)
             VALUES (?, ?, ?, ?, 450, 8.00)'
        )->execute([$orderId, $sellerId, $status, $total]);
        $subOrderId = (int) $this->db->lastInsertId();

        $this->db->prepare(
            'INSERT INTO order_items (sub_order_id, product_id, product_title, quantity, unit_price)
             VALUES (?, ?, \'Risk Test Product\', ?, ?)'
        )->execute([$subOrderId, $productId, $qty, $unitPrice]);

        return $orderId;
    }

    /** Mark an order's payment as failed, for the declined-card rule. */
    public function failedPayment(int $orderId): void
    {
        $this->db->prepare(
            'INSERT INTO payments (order_id, provider, provider_ref, amount, status)
             VALUES (?, \'demo\', ?, 1000, \'failed\')'
        )->execute([$orderId, 'TEST-FAIL-' . $orderId]);
    }

    /** A 5-star review by $userId on $productId, $daysAgo days ago. */
    public function review(int $productId, int $userId, int $daysAgo = 1, int $rating = 5): void
    {
        $this->db->prepare(
            'INSERT INTO reviews (product_id, user_id, rating, review_text, is_verified_buyer, created_at)
             VALUES (?, ?, ?, \'Risk test review text, long enough to be real.\', 1,
                     NOW() - INTERVAL ? DAY)'
        )->execute([$productId, $userId, $rating, $daysAgo]);
    }

    /**
     * Pad the orders table so the population-dependent rules have a
     * baseline. Several rules guard on "at least 20 orders on the
     * platform"; without this they correctly stay silent and the scenario
     * would prove nothing.
     */
    public function platformBaseline(int $count = 25, float $value = 100000): void
    {
        [$sellerId, $productId] = $this->seller('0118888888');

        for ($i = 0; $i < $count; $i++) {
            $buyer = $this->user(24 * 200);

            /* A DISTINCT address and phone each. The first version of this
               gave all 25 the default '42 Galle Road', which meant the
               baseline itself tripped SHARED_ADDRESS_MANY_ACCOUNTS — so
               every scenario that needed a platform median also carried a
               signal it had not asked for. The assertions still passed,
               but a fixture that fires rules is a fixture that will
               eventually make one pass for the wrong reason. */
            $this->order(
                $buyer,
                $sellerId,
                $productId,
                $value,
                1,
                60 * 24 * (30 + $i),
                'delivered',
                sprintf('%d Baseline Avenue', 100 + $i),
                sprintf('07%08d', 60000000 + $i)
            );
        }
    }
}

echo str_repeat('=', 72), "\n";
echo "  Risk Radar — rule engine tests\n";
echo "  Every scenario runs in a transaction that is rolled back.\n";
echo str_repeat('=', 72), "\n";

/* Where the database stands BEFORE anything runs. The final section proves
   the scenarios changed none of it — a delta, so the suite works the same
   on a freshly seeded database and on one with the demo set loaded. */
$startCounts = db()->query(
    'SELECT (SELECT COUNT(*) FROM users)      AS users,
            (SELECT COUNT(*) FROM orders)     AS orders,
            (SELECT COUNT(*) FROM products)   AS products,
            (SELECT COUNT(*) FROM sub_orders) AS sub_orders,
            (SELECT COUNT(*) FROM reviews)    AS reviews,
            (SELECT COUNT(*) FROM order_risk_scores) AS scores,
            (SELECT COUNT(*) FROM risk_alerts)       AS alerts,
            (SELECT COUNT(*) FROM sub_orders WHERE payout_hold = 1) AS holds'
)->fetch(PDO::FETCH_ASSOC);
$startCounts = array_map('intval', $startCounts);

/* ================================================================
   0. the helpers
   ================================================================ */

section('0. Median and MAD');

check('median of an odd-length list', risk_median([1, 5, 3]) === 3.0, '1,5,3 -> ' . var_export(risk_median([1, 5, 3]), true));
check('median of an even-length list', risk_median([1, 2, 3, 4]) === 2.5);
check('median of an empty list is null', risk_median([]) === null);

/* The property that makes MAD the right choice: one extreme value barely
   moves it, where it would move a standard deviation enormously. */
/* Slight natural variation, so the MAD is a real non-zero number and the
   comparison below means something rather than 0 == 0. */
$steady    = [100, 104, 98, 102, 96, 101, 103];
$withSpike = [100, 104, 98, 102, 96, 101, 5000];

$madSteady = risk_mad($steady);
$madSpike  = risk_mad($withSpike);

$sd = static function (array $v): float {
    $mean = array_sum($v) / count($v);
    $var  = array_sum(array_map(static fn($x) => ($x - $mean) ** 2, $v)) / count($v);
    return sqrt($var);
};

/* The claim is COMPARATIVE, not absolute. One extreme value does move the
   MAD a little — the median of the deviations shifts — so "unmoved" would
   be an overstatement. What matters is the ratio: the MAD grows by a small
   factor where the standard deviation grows by hundreds, which is why an
   attacker cannot raise their own threshold out of reach by placing one
   enormous order first. */
$madGrowth = $madSpike / max($madSteady, 0.01);
$sdGrowth  = $sd($withSpike) / max($sd($steady), 0.01);

check(
    'MAD barely moves when one huge outlier is added',
    $madSteady !== null && $madSpike !== null && $madGrowth < 3,
    sprintf('MAD %.1f -> %.1f (x%.1f)', $madSteady, $madSpike, $madGrowth)
);
check(
    '…where standard deviation explodes',
    $sdGrowth > 100,
    sprintf('SD %.1f -> %.1f (x%.0f)', $sd($steady), $sd($withSpike), $sdGrowth)
);
check(
    'SD is at least 100x more sensitive to the outlier than MAD (why MAD is used)',
    $sdGrowth > 100 * $madGrowth,
    sprintf('MAD grew x%.1f, SD grew x%.0f', $madGrowth, $sdGrowth)
);

section('0b. Score to level');

check('0 is low', risk_level_for(0) === 'low');
check('34 is low (just under medium)', risk_level_for(34) === 'low');
check('35 is medium (the boundary is inclusive)', risk_level_for(35) === 'medium');
check('64 is medium', risk_level_for(64) === 'medium');
check('65 is high', risk_level_for(65) === 'high');
check('100 is high', risk_level_for(100) === 'high');

$heaviest = max(array_column(RISK_SIGNALS, 'weight'));
check(
    'No single signal can reach high on its own',
    $heaviest < risk_config()['high_at'],
    sprintf('heaviest signal is %d, high starts at %d — a flag always means several things at once', $heaviest, risk_config()['high_at'])
);

check('All 13 signals are catalogued', count(RISK_SIGNALS) === 13, count(RISK_SIGNALS) . ' defined');

section('0c. Address and phone normalisation');

$sameAddress = [
    'No. 42, Galle Road',
    '42 galle rd.',
    '42  GALLE  ROAD',
    'no.42 Galle Rd',
];
$keys = array_map('risk_normalise_address', $sameAddress);
check(
    'The same address written four ways normalises to one key',
    count(array_unique($keys)) === 1,
    implode(' | ', array_unique($keys))
);
check(
    'A different address does not collide',
    risk_normalise_address('43 Galle Road') !== risk_normalise_address('42 Galle Road')
);
check('Phone: spaces are ignored', risk_normalise_phone('077 123 4567') === risk_normalise_phone('0771234567'));
check('Phone: +94 folds onto the local form', risk_normalise_phone('+94771234567') === '0771234567', risk_normalise_phone('+94771234567'));

/* ================================================================
   1. THE SEEDED DATABASE MUST PRODUCE NOTHING

   The headline guard test. The 8 original orders are ordinary, and an
   engine that flags them is an engine nobody will trust.
   ================================================================ */

section('1. The original seeded orders produce NO alerts');

/* The ORIGINAL orders — the ones that came from seed.sql — identified by
   NOT being in risk_demo_data. The first version of this selected every row
   in `orders` and called them "the seeded 8", which was true only while the
   database was almost empty. Once the demo generator had run, the same
   query swept up 1,748 synthetic orders (including the deliberately
   anomalous ones) and the test failed for being wrong about its own
   premise.

   Scoping it this way means the suite says the same thing whether or not
   demo data is loaded, which is the property a test should have. */
$seeded = db()->query(
    'SELECT o.id FROM orders o
      WHERE NOT EXISTS (
            SELECT 1 FROM risk_demo_data d
             WHERE d.object_type = \'order\' AND d.object_id = o.id
      )
   ORDER BY o.id'
)->fetchAll(PDO::FETCH_COLUMN);

$demoLoaded = (int) db()->query(
    "SELECT COUNT(*) FROM risk_demo_data WHERE object_type = 'order'"
)->fetchColumn();

if ($demoLoaded > 0) {
    printf("  (%s demo orders are loaded and are excluded from this section)\n", number_format($demoLoaded));
}

check('Found the original seeded orders', count($seeded) === 8, count($seeded) . ' of them');

$flagged = [];
$scores  = [];

foreach ($seeded as $orderId) {
    $result = score_order(db(), (int) $orderId);
    $scores[(int) $orderId] = $result;

    if ($result['level'] !== 'low') {
        $flagged[] = sprintf('order %d scored %d (%s): %s', $orderId, $result['score'], $result['level'], codes($result));
    }
}

check(
    'Every seeded order scores LOW',
    !$flagged,
    $flagged ? implode(' | ', $flagged) : 'all ' . count($seeded) . ' orders below the medium threshold'
);

if ($verbose) {
    foreach ($scores as $orderId => $r) {
        printf("         order %-3d score %-3d %-6s %s\n", $orderId, $r['score'], $r['level'], codes($r));
    }
}

$withSignals = array_filter($scores, static fn(array $r): bool => !empty($r['signals']));
check(
    '…and none of them fires a single signal',
    !$withSignals,
    $withSignals
        ? 'fired on orders: ' . implode(', ', array_keys($withSignals))
        : 'the minimum-sample guards are doing their job'
);

check(
    'A non-existent order returns "unscored" rather than throwing',
    score_order(db(), 999999)['level'] === 'unscored'
);

/* ================================================================
   2. EACH SIGNAL FIRES WHEN IT SHOULD
   ================================================================ */

section('2. VELOCITY_USER');

$r = scenario(function (PDO $db, Fixtures $f) {
    [$sellerId, $productId] = $f->seller();
    $buyer = $f->user(24 * 100);

    // Four orders inside 40 minutes — the threshold is 3 in an hour.
    $f->order($buyer, $sellerId, $productId, 50000, 1, 38);
    $f->order($buyer, $sellerId, $productId, 50000, 1, 25);
    $f->order($buyer, $sellerId, $productId, 50000, 1, 12);
    $target = $f->order($buyer, $sellerId, $productId, 50000, 1, 0);

    return score_order($db, $target);
});

check('fires on 4 orders within 40 minutes', fired($r, 'VELOCITY_USER'), codes($r));
$e = evidence($r, 'VELOCITY_USER');
check('  evidence reports the count', ($e['orders_last_hour'] ?? 0) >= 3, 'orders_last_hour = ' . ($e['orders_last_hour'] ?? '?'));
check('  evidence reports the window', isset($e['window_minutes']), 'window_minutes = ' . ($e['window_minutes'] ?? '?'));

$r = scenario(function (PDO $db, Fixtures $f) {
    [$sellerId, $productId] = $f->seller();
    $buyer = $f->user(24 * 100);

    // Two in the hour. One below the threshold.
    $f->order($buyer, $sellerId, $productId, 50000, 1, 30);
    $target = $f->order($buyer, $sellerId, $productId, 50000, 1, 0);

    return score_order($db, $target);
});
check('does NOT fire on 2 orders in an hour', !fired($r, 'VELOCITY_USER'), codes($r));

$r = scenario(function (PDO $db, Fixtures $f) {
    [$sellerId, $productId] = $f->seller();
    $buyer = $f->user(24 * 100);

    // Six spread across 20 hours: under the hourly rule, over the daily one.
    for ($h = 20; $h >= 4; $h -= 4) {
        $f->order($buyer, $sellerId, $productId, 50000, 1, $h * 60);
    }
    $target = $f->order($buyer, $sellerId, $productId, 50000, 1, 0);

    return score_order($db, $target);
});
check('fires on 6 orders across 24 hours (the second window)', fired($r, 'VELOCITY_USER'), codes($r));

section('3. NEW_ACCOUNT_HIGH_VALUE');

/* The order value is computed from the LIVE platform median, not hard-coded.
   A fixed 400,000 fired while the database held only the seeded orders
   (median ~141,000) and stopped firing once the demo data pushed the median
   to ~311,000 — the rule was right and the test was brittle. */
$r = scenario(function (PDO $db, Fixtures $f) {
    $f->platformBaseline(25, 100000);
    [$sellerId, $productId] = $f->seller();
    $buyer = $f->user(3);                        // 3 hours old

    $median = (float) $db->query('SELECT MEDIAN(total_amount) OVER () FROM orders LIMIT 1')->fetchColumn();
    $big    = max(400000, $median * 4);          // comfortably past the 2.5x threshold

    return score_order($db, $f->order($buyer, $sellerId, $productId, $big, 1, 0));
});

check('fires for a 3-hour-old account at 4x the platform median', fired($r, 'NEW_ACCOUNT_HIGH_VALUE'), codes($r));
$e = evidence($r, 'NEW_ACCOUNT_HIGH_VALUE');
check('  evidence reports the account age', ($e['account_age_hours'] ?? 99) < 24, 'age = ' . ($e['account_age_hours'] ?? '?') . 'h');
check('  evidence reports the multiple', ($e['times_median'] ?? 0) >= 2.5, 'times_median = ' . ($e['times_median'] ?? '?'));

$r = scenario(function (PDO $db, Fixtures $f) {
    $f->platformBaseline(25, 100000);
    [$sellerId, $productId] = $f->seller();
    $buyer = $f->user(24 * 90);                  // an established account

    $median = (float) $db->query('SELECT MEDIAN(total_amount) OVER () FROM orders LIMIT 1')->fetchColumn();
    $big    = max(400000, $median * 4);

    return score_order($db, $f->order($buyer, $sellerId, $productId, $big, 1, 0));
});
check('does NOT fire for an established account spending the same', !fired($r, 'NEW_ACCOUNT_HIGH_VALUE'), codes($r));

/* THE GUARD: too little platform history for a median to mean anything.
   This can only be demonstrated when the database is genuinely small — with
   the demo set loaded there are 1,700 orders and the guard is satisfied, so
   the scenario would prove nothing and is skipped with a reason rather than
   passing vacuously. */
$platformOrders = (int) db()->query('SELECT COUNT(*) FROM orders')->fetchColumn();

if ($platformOrders < 20) {
    $r = scenario(function (PDO $db, Fixtures $f) {
        [$sellerId, $productId] = $f->seller();
        $buyer = $f->user(3);

        return score_order($db, $f->order($buyer, $sellerId, $productId, 400000, 1, 0));
    });
    check(
        'GUARD: stays silent when the platform has too few orders to have a median',
        !fired($r, 'NEW_ACCOUNT_HIGH_VALUE'),
        codes($r)
    );
} else {
    skipped(
        'GUARD: silent when the platform has too few orders for a median',
        sprintf('needs a near-empty database; %s orders are loaded', number_format($platformOrders))
    );
}

section('4. FAILED_PAYMENTS_BEFORE_SUCCESS');

$r = scenario(function (PDO $db, Fixtures $f) {
    [$sellerId, $productId] = $f->seller();
    $buyer = $f->user(24 * 10);

    // Three declines in the preceding half hour, then a success.
    foreach ([30, 20, 10] as $minutes) {
        $failedOrder = $f->order($buyer, $sellerId, $productId, 50000, 1, $minutes);
        $f->failedPayment($failedOrder);
    }

    return score_order($db, $f->order($buyer, $sellerId, $productId, 50000, 1, 0));
});

check('fires on 3 failed payments in the preceding hour', fired($r, 'FAILED_PAYMENTS_BEFORE_SUCCESS'), codes($r));
check('  evidence reports how many', (evidence($r, 'FAILED_PAYMENTS_BEFORE_SUCCESS')['failed_payments'] ?? 0) >= 2);

$r = scenario(function (PDO $db, Fixtures $f) {
    [$sellerId, $productId] = $f->seller();
    $buyer = $f->user(24 * 10);

    $failedOrder = $f->order($buyer, $sellerId, $productId, 50000, 1, 20);
    $f->failedPayment($failedOrder);

    return score_order($db, $f->order($buyer, $sellerId, $productId, 50000, 1, 0));
});
check('does NOT fire on a single fumbled payment', !fired($r, 'FAILED_PAYMENTS_BEFORE_SUCCESS'), codes($r));

section('5. HIGH_CANCEL_REFUND_RATE');

$r = scenario(function (PDO $db, Fixtures $f) {
    [$sellerId, $productId] = $f->seller();
    $buyer = $f->user(24 * 100);

    // 8 prior orders, 5 of them cancelled or refunded = 62.5%
    for ($i = 0; $i < 5; $i++) {
        $f->order($buyer, $sellerId, $productId, 50000, 1, 60 * 24 * (10 + $i), 'cancelled');
    }
    for ($i = 0; $i < 3; $i++) {
        $f->order($buyer, $sellerId, $productId, 50000, 1, 60 * 24 * (20 + $i), 'delivered');
    }

    return score_order($db, $f->order($buyer, $sellerId, $productId, 50000, 1, 0));
});

check('fires at a 62% cancellation rate over 8 orders', fired($r, 'HIGH_CANCEL_REFUND_RATE'), codes($r));
$e = evidence($r, 'HIGH_CANCEL_REFUND_RATE');
check('  evidence reports the rate', ($e['rate_percent'] ?? 0) > 40, ($e['rate_percent'] ?? '?') . '%');

/* THE GUARD: the same 100% rate, but only 3 prior orders. */
$r = scenario(function (PDO $db, Fixtures $f) {
    [$sellerId, $productId] = $f->seller();
    $buyer = $f->user(24 * 100);

    for ($i = 0; $i < 3; $i++) {
        $f->order($buyer, $sellerId, $productId, 50000, 1, 60 * 24 * (10 + $i), 'cancelled');
    }

    return score_order($db, $f->order($buyer, $sellerId, $productId, 50000, 1, 0));
});
check(
    'GUARD: stays silent at 100% when there are only 3 prior orders',
    !fired($r, 'HIGH_CANCEL_REFUND_RATE'),
    codes($r)
);

section('6. VALUE_OUTLIER');

$r = scenario(function (PDO $db, Fixtures $f) {
    [$sellerId, $productId] = $f->seller();
    $buyer = $f->user(24 * 200);

    // Eight steady orders around 20,000, then one at 500,000.
    for ($i = 0; $i < 8; $i++) {
        $f->order($buyer, $sellerId, $productId, 20000 + $i * 500, 1, 60 * 24 * (10 + $i));
    }

    return score_order($db, $f->order($buyer, $sellerId, $productId, 500000, 1, 0));
});

check('fires on 500,000 against a ~20,000 history', fired($r, 'VALUE_OUTLIER'), codes($r));
$e = evidence($r, 'VALUE_OUTLIER');
check('  evidence reports the buyer median', ($e['buyer_median'] ?? 0) > 0, 'median = ' . ($e['buyer_median'] ?? '?'));
check('  evidence names the method used', !empty($e['method']), $e['method'] ?? '?');

$r = scenario(function (PDO $db, Fixtures $f) {
    [$sellerId, $productId] = $f->seller();
    $buyer = $f->user(24 * 200);

    for ($i = 0; $i < 8; $i++) {
        $f->order($buyer, $sellerId, $productId, 20000 + $i * 500, 1, 60 * 24 * (10 + $i));
    }

    // 25,000 against a ~22,000 median is an ordinary bigger basket.
    return score_order($db, $f->order($buyer, $sellerId, $productId, 25000, 1, 0));
});
check('does NOT fire on a slightly larger order', !fired($r, 'VALUE_OUTLIER'), codes($r));

/* THE PROPERTY THAT MOTIVATES MAD: one huge order first must not raise the
   threshold enough to hide the next one. With mean + 3SD it would. */
$r = scenario(function (PDO $db, Fixtures $f) {
    [$sellerId, $productId] = $f->seller();
    $buyer = $f->user(24 * 200);

    for ($i = 0; $i < 7; $i++) {
        $f->order($buyer, $sellerId, $productId, 20000, 1, 60 * 24 * (20 + $i));
    }
    // The attacker's first big order, already in the history.
    $f->order($buyer, $sellerId, $productId, 500000, 1, 60 * 24 * 5);

    // The second one must still be caught.
    return score_order($db, $f->order($buyer, $sellerId, $productId, 500000, 1, 0));
});
check(
    'still fires on a SECOND huge order (the reason MAD is used, not SD)',
    fired($r, 'VALUE_OUTLIER'),
    codes($r)
);

/* THE GUARD: 4 prior orders is one short of the minimum. */
$r = scenario(function (PDO $db, Fixtures $f) {
    [$sellerId, $productId] = $f->seller();
    $buyer = $f->user(24 * 200);

    for ($i = 0; $i < 4; $i++) {
        $f->order($buyer, $sellerId, $productId, 20000, 1, 60 * 24 * (10 + $i));
    }

    return score_order($db, $f->order($buyer, $sellerId, $productId, 500000, 1, 0));
});
check(
    'GUARD: stays silent with only 4 prior orders to compare against',
    !fired($r, 'VALUE_OUTLIER'),
    codes($r)
);

section('7. QTY_OUTLIER');

$r = scenario(function (PDO $db, Fixtures $f) {
    [$sellerId, $productId] = $f->seller();

    // Twelve past sales of 1 or 2 units.
    for ($i = 0; $i < 12; $i++) {
        $buyer = $f->user(24 * 100);
        $f->order($buyer, $sellerId, $productId, 150000, ($i % 2) + 1, 60 * 24 * (10 + $i));
    }

    $buyer = $f->user(24 * 100);
    return score_order($db, $f->order($buyer, $sellerId, $productId, 150000, 20, 0));
});

check('fires on 20 units where history is 1-2', fired($r, 'QTY_OUTLIER'), codes($r));
$e = evidence($r, 'QTY_OUTLIER');
check('  evidence reports the quantity and the usual max', ($e['quantity'] ?? 0) === 20 && isset($e['usual_95th']), 'qty ' . ($e['quantity'] ?? '?') . ', p95 ' . ($e['usual_95th'] ?? '?'));
check('  evidence reports how much history it had', ($e['past_sales_seen'] ?? 0) >= 10, ($e['past_sales_seen'] ?? '?') . ' past sales');

$r = scenario(function (PDO $db, Fixtures $f) {
    [$sellerId, $productId] = $f->seller();

    for ($i = 0; $i < 12; $i++) {
        $buyer = $f->user(24 * 100);
        $f->order($buyer, $sellerId, $productId, 150000, ($i % 2) + 1, 60 * 24 * (10 + $i));
    }

    $buyer = $f->user(24 * 100);
    return score_order($db, $f->order($buyer, $sellerId, $productId, 150000, 2, 0));
});
check('does NOT fire on an ordinary quantity of 2', !fired($r, 'QTY_OUTLIER'), codes($r));

/* THE GUARD: the same 20 units, but the product has only 3 past sales. */
$r = scenario(function (PDO $db, Fixtures $f) {
    [$sellerId, $productId] = $f->seller();

    for ($i = 0; $i < 3; $i++) {
        $buyer = $f->user(24 * 100);
        $f->order($buyer, $sellerId, $productId, 150000, 1, 60 * 24 * (10 + $i));
    }

    $buyer = $f->user(24 * 100);
    return score_order($db, $f->order($buyer, $sellerId, $productId, 150000, 20, 0));
});
check(
    'GUARD: stays silent when the product has only 3 past sales',
    !fired($r, 'QTY_OUTLIER'),
    codes($r)
);

section('8. SHARED_ADDRESS_MANY_ACCOUNTS');

$r = scenario(function (PDO $db, Fixtures $f) {
    [$sellerId, $productId] = $f->seller();

    // Four different accounts, one address, written inconsistently on
    // purpose — the normaliser has to see through that.
    $spellings = ['No. 7, Flower Road', '7 flower rd', '7  FLOWER  ROAD', 'no.7 Flower Rd.'];
    foreach ($spellings as $i => $spelling) {
        $buyer = $f->user(24 * 50);
        $f->order($buyer, $sellerId, $productId, 50000, 1, 60 * 24 * (5 + $i), 'delivered', $spelling);
    }

    $buyer = $f->user(24 * 50);
    return score_order($db, $f->order($buyer, $sellerId, $productId, 50000, 1, 0, 'delivered', '7 Flower Road'));
});

check('fires on 5 accounts at one address, spelled four ways', fired($r, 'SHARED_ADDRESS_MANY_ACCOUNTS'), codes($r));
$e = evidence($r, 'SHARED_ADDRESS_MANY_ACCOUNTS');
check('  evidence counts the accounts', ($e['accounts_at_address'] ?? 0) >= 3, ($e['accounts_at_address'] ?? '?') . ' accounts');
check('  evidence says what it matched on', !empty($e['matched_on']), $e['matched_on'] ?? '?');

/* THE GUARD: two accounts at one address is a household. */
$r = scenario(function (PDO $db, Fixtures $f) {
    [$sellerId, $productId] = $f->seller();

    $buyer = $f->user(24 * 50);
    $f->order($buyer, $sellerId, $productId, 50000, 1, 60 * 24 * 5, 'delivered', '7 Flower Road');

    $buyer2 = $f->user(24 * 50);
    return score_order($db, $f->order($buyer2, $sellerId, $productId, 50000, 1, 0, 'delivered', '7 Flower Road'));
});
check(
    'GUARD: stays silent for 2 accounts at one address (a household)',
    !fired($r, 'SHARED_ADDRESS_MANY_ACCOUNTS'),
    codes($r)
);

section('9. BUYER_MATCHES_SELLER');

$r = scenario(function (PDO $db, Fixtures $f) {
    [$sellerId, $productId] = $f->seller('0712223334');
    $buyer = $f->user(24 * 50);

    // A second account, shipping to the seller's own phone number.
    return score_order($db, $f->order($buyer, $sellerId, $productId, 150000, 1, 0, 'delivered', '1 Somewhere', '0712223334'));
});

check('fires when the buyer ships to the seller\'s own phone', fired($r, 'BUYER_MATCHES_SELLER'), codes($r));
check('  evidence names the seller', !empty(evidence($r, 'BUYER_MATCHES_SELLER')['seller']));

$r = scenario(function (PDO $db, Fixtures $f) {
    [$sellerId, $productId] = $f->seller('0712223334');
    $buyer = $f->user(24 * 50);

    return score_order($db, $f->order($buyer, $sellerId, $productId, 150000, 1, 0, 'delivered', '1 Somewhere', '0779998888'));
});
check('does NOT fire for an unrelated buyer', !fired($r, 'BUYER_MATCHES_SELLER'), codes($r));

section('10. SELLER_ORDER_SPIKE');

$r = scenario(function (PDO $db, Fixtures $f) {
    [$sellerId, $productId] = $f->seller();

    // 14-day baseline of ~1/day, then 10 orders today.
    for ($d = 1; $d <= 14; $d++) {
        $buyer = $f->user(24 * 100);
        $f->order($buyer, $sellerId, $productId, 50000, 1, 60 * 24 * $d);
    }
    for ($i = 0; $i < 9; $i++) {
        $buyer = $f->user(24 * 100);
        $f->order($buyer, $sellerId, $productId, 50000, 1, $i * 5);
    }

    $buyer = $f->user(24 * 100);
    return score_order($db, $f->order($buyer, $sellerId, $productId, 50000, 1, 0));
});

check('fires on 10 orders today against a 1/day baseline', fired($r, 'SELLER_ORDER_SPIKE'), codes($r));
$e = evidence($r, 'SELLER_ORDER_SPIKE');
check('  evidence reports today vs the average', isset($e['orders_today'], $e['daily_average_14d']), 'today ' . ($e['orders_today'] ?? '?') . ' vs avg ' . ($e['daily_average_14d'] ?? '?'));

/* THE GUARD that matters most on a small marketplace: a tiny shop going
   from almost nothing to 3 orders is a 20x "spike" by ratio, and must not
   fire. The absolute floor is 4 orders today. */
$r = scenario(function (PDO $db, Fixtures $f) {
    [$sellerId, $productId] = $f->seller();

    for ($d = 1; $d <= 14; $d++) {
        $buyer = $f->user(24 * 100);
        $f->order($buyer, $sellerId, $productId, 50000, 1, 60 * 24 * $d);
    }
    // Only 3 today — a ratio of 3x, but below the absolute floor.
    for ($i = 0; $i < 2; $i++) {
        $buyer = $f->user(24 * 100);
        $f->order($buyer, $sellerId, $productId, 50000, 1, $i * 5);
    }

    $buyer = $f->user(24 * 100);
    return score_order($db, $f->order($buyer, $sellerId, $productId, 50000, 1, 0));
});
check(
    'GUARD: stays silent at 3 orders today (the absolute floor is 4)',
    !fired($r, 'SELLER_ORDER_SPIKE'),
    codes($r)
);

/* THE OTHER GUARD: a brand-new shop with no baseline at all. */
$r = scenario(function (PDO $db, Fixtures $f) {
    [$sellerId, $productId] = $f->seller();

    for ($i = 0; $i < 9; $i++) {
        $buyer = $f->user(24 * 100);
        $f->order($buyer, $sellerId, $productId, 50000, 1, $i * 5);
    }

    $buyer = $f->user(24 * 100);
    return score_order($db, $f->order($buyer, $sellerId, $productId, 50000, 1, 0));
});
check(
    'GUARD: stays silent for a new shop with no 14-day baseline',
    !fired($r, 'SELLER_ORDER_SPIKE'),
    codes($r)
);

section('11. SELLER_FEW_BUYERS');

$r = scenario(function (PDO $db, Fixtures $f) {
    [$sellerId, $productId] = $f->seller();

    // 10 orders in a month from exactly 2 accounts.
    $a = $f->user(24 * 100);
    $b = $f->user(24 * 100);
    for ($i = 0; $i < 5; $i++) {
        $f->order($a, $sellerId, $productId, 50000, 1, 60 * 24 * (3 + $i));
        $f->order($b, $sellerId, $productId, 50000, 1, 60 * 24 * (4 + $i));
    }

    return score_order($db, $f->order($a, $sellerId, $productId, 50000, 1, 0));
});

check('fires when a month of orders comes from 2 accounts', fired($r, 'SELLER_FEW_BUYERS'), codes($r));
$e = evidence($r, 'SELLER_FEW_BUYERS');
check('  evidence reports the concentration', ($e['top_two_share_pct'] ?? 0) >= 80, ($e['top_two_share_pct'] ?? '?') . '%');

/* THE GUARD: a new shop's first 4 orders from one happy customer. */
$r = scenario(function (PDO $db, Fixtures $f) {
    [$sellerId, $productId] = $f->seller();

    $a = $f->user(24 * 100);
    for ($i = 0; $i < 3; $i++) {
        $f->order($a, $sellerId, $productId, 50000, 1, 60 * 24 * (3 + $i));
    }

    return score_order($db, $f->order($a, $sellerId, $productId, 50000, 1, 0));
});
check(
    'GUARD: stays silent on a new shop\'s first 4 orders from one buyer',
    !fired($r, 'SELLER_FEW_BUYERS'),
    codes($r)
);

section('12. SELLER_DISPUTE_RATE');

$r = scenario(function (PDO $db, Fixtures $f) {
    [$sellerId, $productId] = $f->seller();

    // 12 orders, 5 disputed = 41%
    for ($i = 0; $i < 5; $i++) {
        $buyer = $f->user(24 * 100);
        $f->order($buyer, $sellerId, $productId, 50000, 1, 60 * 24 * (3 + $i), 'disputed');
    }
    for ($i = 0; $i < 7; $i++) {
        $buyer = $f->user(24 * 100);
        $f->order($buyer, $sellerId, $productId, 50000, 1, 60 * 24 * (10 + $i), 'delivered');
    }

    $buyer = $f->user(24 * 100);
    return score_order($db, $f->order($buyer, $sellerId, $productId, 50000, 1, 0));
});

check('fires at a 41% dispute rate over 12 orders', fired($r, 'SELLER_DISPUTE_RATE'), codes($r));
$e = evidence($r, 'SELLER_DISPUTE_RATE');
check('  evidence reports the rate and the sample', isset($e['rate_percent'], $e['orders_30d']), ($e['rate_percent'] ?? '?') . '% of ' . ($e['orders_30d'] ?? '?'));

/* THE GUARD: 1 dispute out of 3 orders is 33%, and means nothing. */
$r = scenario(function (PDO $db, Fixtures $f) {
    [$sellerId, $productId] = $f->seller();

    $buyer = $f->user(24 * 100);
    $f->order($buyer, $sellerId, $productId, 50000, 1, 60 * 24 * 3, 'disputed');
    $f->order($buyer, $sellerId, $productId, 50000, 1, 60 * 24 * 4, 'delivered');

    return score_order($db, $f->order($buyer, $sellerId, $productId, 50000, 1, 0));
});
check(
    'GUARD: stays silent at 1 dispute in 3 orders (33%, but meaningless)',
    !fired($r, 'SELLER_DISPUTE_RATE'),
    codes($r)
);

section('13. REVIEW_BURST_NEW_ACCOUNTS');

$r = scenario(function (PDO $db, Fixtures $f) {
    [$sellerId, $productId] = $f->seller();

    // Six 5-star reviews from accounts 1-2 days old, all this week.
    for ($i = 0; $i < 6; $i++) {
        $reviewer = $f->user(36);                // 36 hours old
        $f->review($productId, $reviewer, 2);
    }

    $buyer = $f->user(24 * 100);
    return score_order($db, $f->order($buyer, $sellerId, $productId, 50000, 1, 0));
});

check('fires on 6 five-star reviews from day-old accounts', fired($r, 'REVIEW_BURST_NEW_ACCOUNTS'), codes($r));
$e = evidence($r, 'REVIEW_BURST_NEW_ACCOUNTS');
check('  evidence reports the count and the product', isset($e['five_star_reviews'], $e['product']), ($e['five_star_reviews'] ?? '?') . ' reviews');

/* THE GUARD: 3 is below the threshold of 4. */
$r = scenario(function (PDO $db, Fixtures $f) {
    [$sellerId, $productId] = $f->seller();

    for ($i = 0; $i < 3; $i++) {
        $reviewer = $f->user(36);
        $f->review($productId, $reviewer, 2);
    }

    $buyer = $f->user(24 * 100);
    return score_order($db, $f->order($buyer, $sellerId, $productId, 50000, 1, 0));
});
check('GUARD: stays silent on 3 such reviews', !fired($r, 'REVIEW_BURST_NEW_ACCOUNTS'), codes($r));

/* Established reviewers are not a burst, however many of them there are. */
$r = scenario(function (PDO $db, Fixtures $f) {
    [$sellerId, $productId] = $f->seller();

    for ($i = 0; $i < 8; $i++) {
        $reviewer = $f->user(24 * 200);          // long-standing accounts
        $f->review($productId, $reviewer, 2);
    }

    $buyer = $f->user(24 * 100);
    return score_order($db, $f->order($buyer, $sellerId, $productId, 50000, 1, 0));
});
check('does NOT fire on 8 reviews from established accounts', !fired($r, 'REVIEW_BURST_NEW_ACCOUNTS'), codes($r));

section('14. HIGH_VALUE_ELECTRONICS_COMBO');

$r = scenario(function (PDO $db, Fixtures $f) {
    $f->platformBaseline(25, 100000);
    [$sellerId, $productId] = $f->seller();
    $buyer = $f->user(12);                        // half a day old

    // The rule needs the category's value at >= 2x the platform median.
    $median = (float) $db->query('SELECT MEDIAN(total_amount) OVER () FROM orders LIMIT 1')->fetchColumn();
    $unit   = max(150000, ($median * 2.5) / 4);

    return score_order($db, $f->order($buyer, $sellerId, $productId, $unit, 4, 0));
});

check('fires on 4 laptops from a 12-hour-old account', fired($r, 'HIGH_VALUE_ELECTRONICS_COMBO'), codes($r));
$e = evidence($r, 'HIGH_VALUE_ELECTRONICS_COMBO');
check('  evidence reports the category and units', isset($e['category'], $e['units']), ($e['units'] ?? '?') . ' x ' . ($e['category'] ?? '?'));

$r = scenario(function (PDO $db, Fixtures $f) {
    $f->platformBaseline(25, 100000);
    [$sellerId, $productId] = $f->seller();
    $buyer = $f->user(24 * 200);

    $median = (float) $db->query('SELECT MEDIAN(total_amount) OVER () FROM orders LIMIT 1')->fetchColumn();
    $unit   = max(150000, ($median * 2.5) / 4);

    // The same order from an established buyer with history: a business
    // customer, not a signal.
    for ($i = 0; $i < 4; $i++) {
        $f->order($buyer, $sellerId, $productId, $unit, 4, 60 * 24 * (10 + $i));
    }

    return score_order($db, $f->order($buyer, $sellerId, $productId, $unit, 4, 0));
});
check(
    'does NOT fire for an established buyer who always buys in fours',
    !fired($r, 'HIGH_VALUE_ELECTRONICS_COMBO'),
    codes($r)
);

/* ================================================================
   15. scoring arithmetic
   ================================================================ */

section('15. Scoring and levels');

$r = scenario(function (PDO $db, Fixtures $f) {
    $f->platformBaseline(25, 100000);
    [$sellerId, $productId] = $f->seller('0712223334');
    $buyer = $f->user(2);                        // 2 hours old

    // Stack several things at once: velocity, a new account spending big,
    // failed payments, and the seller's own phone.
    $median = (float) $db->query('SELECT MEDIAN(total_amount) OVER () FROM orders LIMIT 1')->fetchColumn();
    $big    = max(400000, $median * 4);

    foreach ([40, 28, 15] as $minutes) {
        $failedOrder = $f->order($buyer, $sellerId, $productId, $big, 1, $minutes, 'delivered', '9 Test Lane', '0712223334');
        $f->failedPayment($failedOrder);
    }

    return score_order($db, $f->order($buyer, $sellerId, $productId, $big, 1, 0, 'delivered', '9 Test Lane', '0712223334'));
});

check('a stacked scenario fires several signals', count($r['signals']) >= 3, count($r['signals']) . ' fired: ' . codes($r));
check('…and reaches HIGH', $r['level'] === 'high', 'score ' . $r['score'] . ' (' . $r['level'] . ')');

$sum = array_sum(array_column($r['signals'], 'weight'));
check(
    'the score is the capped sum of the weights',
    $r['score'] === min(100, $sum),
    'weights sum to ' . $sum . ', score is ' . $r['score']
);
check('the score never exceeds 100', $r['score'] <= 100);

check(
    'signals come back heaviest first (the table shows the top one)',
    (function (array $signals): bool {
        for ($i = 1, $n = count($signals); $i < $n; $i++) {
            if ($signals[$i - 1]['weight'] < $signals[$i]['weight']) {
                return false;
            }
        }
        return true;
    })($r['signals']),
    implode(' > ', array_column($r['signals'], 'weight'))
);

$uniqueCodes = array_column($r['signals'], 'code');
check('no signal is counted twice', count($uniqueCodes) === count(array_unique($uniqueCodes)));

check(
    'every signal carries a label, a weight, a category and evidence',
    (function (array $signals): bool {
        foreach ($signals as $s) {
            if (!isset($s['code'], $s['label'], $s['weight'], $s['category']) || !array_key_exists('evidence', $s)) {
                return false;
            }
            if ($s['label'] === '' || !is_array($s['evidence'])) {
                return false;
            }
        }
        return true;
    })($r['signals'])
);

/* The signals JSON must survive a round trip, since that is how it is
   stored (MariaDB validates it, so an un-encodable signal would be an
   insert failure at checkout time). */
$json = json_encode($r['signals'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
check('the signal list encodes to valid JSON for storage', $json !== false && json_decode($json, true) !== null, strlen((string) $json) . ' bytes');

/* ================================================================
   16. score_user and score_seller
   ================================================================ */

section('16. score_user() and score_seller()');

$r = scenario(function (PDO $db, Fixtures $f) {
    [$sellerId, $productId] = $f->seller();
    $buyer = $f->user(24 * 100);

    // 8 orders in a day, 5 of them cancelled.
    for ($i = 0; $i < 5; $i++) {
        $f->order($buyer, $sellerId, $productId, 50000, 1, 60 * ($i + 1), 'cancelled');
    }
    for ($i = 0; $i < 3; $i++) {
        $f->order($buyer, $sellerId, $productId, 50000, 1, 60 * ($i + 7), 'delivered');
    }

    return score_user($db, $buyer);
});

check('score_user fires velocity over 24 hours', fired($r, 'VELOCITY_USER'), codes($r));
check('score_user fires the cancellation rate', fired($r, 'HIGH_CANCEL_REFUND_RATE'), codes($r));
check('score_user returns the buyer context', isset($r['user']['id'], $r['user']['orders']), 'orders = ' . ($r['user']['orders'] ?? '?'));
check('score_user on a missing user is "unscored"', score_user(db(), 999999)['level'] === 'unscored');

$r = scenario(function (PDO $db, Fixtures $f) {
    [$sellerId, $productId] = $f->seller();

    for ($i = 0; $i < 5; $i++) {
        $buyer = $f->user(24 * 100);
        $f->order($buyer, $sellerId, $productId, 50000, 1, 60 * 24 * (3 + $i), 'disputed');
    }
    for ($i = 0; $i < 7; $i++) {
        $buyer = $f->user(24 * 100);
        $f->order($buyer, $sellerId, $productId, 50000, 1, 60 * 24 * (10 + $i), 'delivered');
    }

    return score_seller($db, $sellerId);
});

check('score_seller fires the dispute rate', fired($r, 'SELLER_DISPUTE_RATE'), codes($r));
check('score_seller returns the seller context', isset($r['seller']['id'], $r['seller']['name']));
check('score_seller on a missing seller is "unscored"', score_seller(db(), 999999)['level'] === 'unscored');

/* The same rules in both paths — a seller signal must come out identically
   whether reached through an order or directly. */
$both = scenario(function (PDO $db, Fixtures $f) {
    [$sellerId, $productId] = $f->seller();

    for ($i = 0; $i < 5; $i++) {
        $buyer = $f->user(24 * 100);
        $f->order($buyer, $sellerId, $productId, 50000, 1, 60 * 24 * (3 + $i), 'disputed');
    }
    for ($i = 0; $i < 7; $i++) {
        $buyer = $f->user(24 * 100);
        $f->order($buyer, $sellerId, $productId, 50000, 1, 60 * 24 * (10 + $i), 'delivered');
    }

    $buyer = $f->user(24 * 100);
    $orderId = $f->order($buyer, $sellerId, $productId, 50000, 1, 0);

    return [
        'via_order'  => evidence(score_order($db, $orderId), 'SELLER_DISPUTE_RATE'),
        'via_seller' => evidence(score_seller($db, $sellerId), 'SELLER_DISPUTE_RATE'),
    ];
});

check(
    'a seller rule gives the same evidence via an order and directly',
    ($both['via_order']['rate_percent'] ?? 'a') === ($both['via_seller']['rate_percent'] ?? 'b'),
    'order: ' . ($both['via_order']['rate_percent'] ?? '?') . '%, seller: ' . ($both['via_seller']['rate_percent'] ?? '?') . '%'
);

/* ================================================================
   17. the explanation and its guard
   ================================================================ */

section('17. Explanations');

$r = scenario(function (PDO $db, Fixtures $f) {
    $f->platformBaseline(25, 100000);
    [$sellerId, $productId] = $f->seller();
    $buyer = $f->user(3);

    $median = (float) $db->query('SELECT MEDIAN(total_amount) OVER () FROM orders LIMIT 1')->fetchColumn();
    $big    = max(400000, $median * 4);

    $f->order($buyer, $sellerId, $productId, $big, 1, 38);
    $f->order($buyer, $sellerId, $productId, $big, 1, 20);

    return score_order($db, $f->order($buyer, $sellerId, $productId, $big, 1, 0));
});

$template = risk_template_explanation($r);
echo "\n  Template explanation produced:\n    \"", $template, "\"\n\n";

check('the template produces a sentence', $template !== '' && str_ends_with(trim($template), '.'));
check('…which is neutral, not accusatory', !preg_match('/\b(fraud|criminal|stolen|scam)\b/i', $template), 'no accusing language');
check('…and contains the real numbers', (bool) preg_match('/\d/', $template));
check(
    'an order with no signals gets an honest "nothing unusual"',
    str_contains(risk_template_explanation(['signals' => []]), 'Nothing unusual')
);

/* The guard that stops an LLM inventing facts. */
check(
    'the guard accepts the template itself',
    risk_explanation_problem($template, $r) === null,
    risk_explanation_problem($template, $r) ?? 'accepted'
);

/* The number has to be one that is genuinely absent from the evidence.
   Hard-coding 47 failed here once the fixtures changed — 47 turned out to
   be the order's own score (25 + 22), which IS an allowed value, so the
   guard was right and the test was wrong. Pick the number by searching for
   one that is not allowed, so the test cannot collide again. */
$allowed  = risk_evidence_numbers($r);
$invented = null;
for ($candidate = 9001; $candidate < 9999; $candidate++) {
    if (!in_array((string) $candidate, $allowed, true)) {
        $invented = (string) $candidate;
        break;
    }
}

check('a number absent from the evidence could be found to test with', $invented !== null, (string) $invented);

$problem = risk_explanation_problem(
    sprintf('This order shows unusual patterns across %s separate accounts.', $invented),
    $r
);
check(
    'the guard REJECTS an invented number',
    $problem !== null && str_contains($problem, (string) $invented),
    $problem ?? 'wrongly accepted'
);

/* And the other direction: a number that IS in the evidence must pass, or
   the guard would reject every true sentence the model writes. */
$realNumber = (string) ($r['signals'][0]['evidence']['orders_last_hour']
    ?? $r['signals'][0]['evidence']['account_age_hours']
    ?? $r['score']);

check(
    'the guard ACCEPTS a number that is in the evidence',
    risk_explanation_problem(
        sprintf('This order shows unusual patterns involving %s orders.', $realNumber),
        $r
    ) === null,
    'used ' . $realNumber
);

$accusing = 'This buyer is committing fraud with a stolen card.';
$problem  = risk_explanation_problem($accusing, $r);
check(
    'the guard REJECTS accusatory language',
    $problem !== null,
    $problem ?? 'wrongly accepted'
);

check('the guard rejects an empty string', risk_explanation_problem('', $r) === 'empty');
check(
    'the guard rejects an essay',
    risk_explanation_problem(str_repeat('words and more words ', 40), $r) !== null
);
check(
    'the guard allows a bare "1" or "2" as wording, not a claim',
    risk_explanation_problem('This order shows 1 unusual pattern worth a look.', $r) === null,
    risk_explanation_problem('This order shows 1 unusual pattern worth a look.', $r) ?? 'allowed'
);

$numbers = risk_evidence_numbers($r);
check('the evidence number list is populated', count($numbers) > 5, count($numbers) . ' allowed values');

/* ================================================================
   18. nothing was left behind
   ================================================================ */

section('18. The database is as it was found');

$leftovers = (int) db()->query(
    "SELECT COUNT(*) FROM users WHERE email LIKE 'risk-test-%@example.invalid'"
)->fetchColumn();
check('No test users were committed', $leftovers === 0, $leftovers ? $leftovers . ' left behind' : 'every scenario rolled back');

$counts = db()->query(
    'SELECT (SELECT COUNT(*) FROM users)      AS users,
            (SELECT COUNT(*) FROM orders)     AS orders,
            (SELECT COUNT(*) FROM products)   AS products,
            (SELECT COUNT(*) FROM sub_orders) AS sub_orders,
            (SELECT COUNT(*) FROM reviews)    AS reviews'
)->fetch();

printf(
    "  %d users, %d products, %d orders, %d sub-orders, %d reviews\n",
    $counts['users'], $counts['products'], $counts['orders'], $counts['sub_orders'], $counts['reviews']
);

/* A DELTA against where this run started, not fixed numbers. Hard-coding
   28/8/10 made the section fail the moment the demo generator had run —
   which is not "the test left rows behind", it is "the database legitimately
   has more in it". What this section is actually for is proving the
   scenarios rolled back, and that is a delta of zero. */
check(
    'users unchanged by this run',
    (int) $counts['users'] === $startCounts['users'],
    sprintf('%d -> %d', $startCounts['users'], $counts['users'])
);
check(
    'orders unchanged by this run',
    (int) $counts['orders'] === $startCounts['orders'],
    sprintf('%d -> %d', $startCounts['orders'], $counts['orders'])
);
check(
    'products unchanged by this run',
    (int) $counts['products'] === $startCounts['products'],
    sprintf('%d -> %d', $startCounts['products'], $counts['products'])
);
check(
    'reviews unchanged by this run',
    (int) $counts['reviews'] === $startCounts['reviews'],
    sprintf('%d -> %d', $startCounts['reviews'], $counts['reviews'])
);

check(
    'No risk rows were written by this run (the engine is pure — it only reads)',
    (int) db()->query('SELECT COUNT(*) FROM order_risk_scores')->fetchColumn() === $startCounts['scores']
        && (int) db()->query('SELECT COUNT(*) FROM risk_alerts')->fetchColumn() === $startCounts['alerts'],
    sprintf('scores %d, alerts %d', $startCounts['scores'], $startCounts['alerts'])
);
check(
    'No payout was held by this run (nothing here writes a hold)',
    (int) db()->query('SELECT COUNT(*) FROM sub_orders WHERE payout_hold = 1')->fetchColumn() === $startCounts['holds']
);

/* ================================================================ */

echo "\n", str_repeat('-', 72), "\n";
printf("  %d passed, %d failed", $pass, $fail);
if ($skip) {
    printf(", %d skipped", $skip);
}
echo "\n";
echo str_repeat('-', 72), "\n";

if ($failures) {
    echo "\n  Failures:\n";
    foreach ($failures as $f) {
        echo "    - ", $f, "\n";
    }
    echo "\n";
}

exit($fail === 0 ? 0 : 1);
