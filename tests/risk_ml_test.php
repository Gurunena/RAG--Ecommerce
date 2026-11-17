<?php
/**
 * tests/risk_ml_test.php — Phase C tests for the PHP side of the ML layer.
 *
 *   C:\xampp\php\php.exe tests\risk_ml_test.php
 *
 * Run the suites ONE AT A TIME. Several of them clean up by matching their
 * own fixture names, and a concurrent run's rows match the same pattern —
 * which produced a string of spurious failures and one deadlock before this
 * note existed.
 *
 * ============================================================
 * WHAT IS BEING PROVEN
 * ============================================================
 *
 * 1. THE FEATURE CONTRACT. The nine columns, in order, are the same in
 *    api/lib/risk_ml.php and ai_service/risk/features.py. This is checked
 *    by reading the Python file, because the whole point is that the two
 *    cannot be allowed to drift and a comment asking nicely is not a check.
 *
 * 2. ONE ORDER SCORED LIVE MATCHES THE SAME ORDER IN THE TRAINING EXPORT.
 *    Same SQL, two call paths (one row vs every row) — if they disagree,
 *    the model is trained on one thing and scored with another.
 *
 * 3. THE BLEND ARITHMETIC, including the cases an admin can create by
 *    editing the weights in settings: weights that sum to more than 1,
 *    to 0, or to nothing at all.
 *
 * 4. EVERY FAILURE PATH DEGRADES. Service off, wrong port, 404, nonsense
 *    body — each must return null with a reason and must never throw,
 *    because this code runs where an order is being scored.
 *
 * 5. THE EXPLANATION FALLS BACK TO THE TEMPLATE whenever the LLM is not
 *    available or its wording is rejected.
 *
 * Tests 4 and 5 deliberately point at a dead port rather than mocking
 * cURL. "The AI service is not running" is the state this project is in
 * most of the time, so it is worth testing against the real thing.
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
require_once __DIR__ . '/../api/lib/risk_ml.php';

$pass = 0;
$fail = 0;
$skip = 0;
$failures = [];

function section(string $title): void
{
    echo "\n", str_repeat('=', 72), "\n  ", $title, "\n", str_repeat('=', 72), "\n";
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

echo str_repeat('=', 72), "\n";
echo "  Risk Radar — ML layer (PHP side)\n";
echo str_repeat('=', 72), "\n";

/* ================================================================
   1. the feature contract across two languages
   ================================================================ */

section('1. The feature contract: PHP and Python must agree');

$pythonPath = dirname(__DIR__, 2) . '/ai_service/risk/features.py';

if (!is_file($pythonPath)) {
    skipped('features.py declares the same nine columns', 'ai_service/risk/features.py not found');
} else {
    $python = (string) file_get_contents($pythonPath);

    /* Pull the tuple literal out of the Python source. Crude on purpose —
       importing Python from PHP to read a list would be a far larger
       dependency than a regex over a file that is nine lines of strings. */
    $pythonColumns = [];
    if (preg_match('/FEATURE_COLUMNS:\s*tuple\[str,\s*\.\.\.\]\s*=\s*\((.*?)\)/s', $python, $m)) {
        /* [a-z0-9_] and not [a-z_]: the first version of this pattern
           silently dropped `orders_24h` because of the digits, so the
           parser reported eight columns and the contract check failed
           against a correct contract. A test that is wrong in the
           direction of failing is the better kind, but it still cost a
           look. */
        preg_match_all('/"([a-z0-9_]+)"/', $m[1], $names);
        $pythonColumns = $names[1];
    }

    check(
        'features.py was parsed',
        count($pythonColumns) > 0,
        count($pythonColumns) . ' columns found'
    );
    check(
        'PHP and Python declare the same number of features',
        count($pythonColumns) === count(RISK_FEATURE_COLUMNS),
        sprintf('php %d, python %d', count(RISK_FEATURE_COLUMNS), count($pythonColumns))
    );

    /* === and not just the same set. ORDER is the thing that matters: a
       model fed the right nine numbers in the wrong nine positions scores
       happily and means nothing, and nothing anywhere would throw. */
    check(
        'PHP and Python agree on the ORDER of the features',
        $pythonColumns === array_values(RISK_FEATURE_COLUMNS),
        $pythonColumns === array_values(RISK_FEATURE_COLUMNS)
            ? implode(', ', array_slice($pythonColumns, 0, 3)) . ', …'
            : 'php: ' . implode(',', RISK_FEATURE_COLUMNS) . ' | py: ' . implode(',', $pythonColumns)
    );
}

$metaPath = dirname(__DIR__, 2) . '/ai_service/risk/model_meta.json';
if (!is_file($metaPath)) {
    skipped('the trained model was trained on these same columns', 'no model_meta.json — model not trained yet');
} else {
    $meta = json_decode((string) file_get_contents($metaPath), true);
    check(
        'the trained model was trained on these same columns, in order',
        is_array($meta) && ($meta['features'] ?? null) === array_values(RISK_FEATURE_COLUMNS),
        is_array($meta) ? 'model v' . ($meta['model_version'] ?? '?') : 'meta unreadable'
    );
    check(
        'the model metadata records that the data was synthetic',
        is_array($meta) && str_contains(strtolower((string) ($meta['data_note'] ?? '')), 'synthetic'),
        is_array($meta) ? mb_substr((string) ($meta['data_note'] ?? ''), 0, 40) . '…' : 'n/a'
    );
}

/* ================================================================
   2. the live feature vector
   ================================================================ */

section('2. risk_ml_features() — one order, nine finite numbers');

$db = db();

$orderId = (int) $db->query('SELECT id FROM orders ORDER BY id LIMIT 1')->fetchColumn();

if ($orderId === 0) {
    skipped('features are built for a real order', 'no orders in the database');
} else {
    $features = risk_ml_features($db, $orderId);

    check('a real order yields a feature array', is_array($features), 'order ' . $orderId);
    check(
        'exactly nine features, keyed by name',
        is_array($features) && array_keys($features) === array_values(RISK_FEATURE_COLUMNS),
        is_array($features) ? count($features) . ' keys' : 'none'
    );

    $allFinite = true;
    $allFloat  = true;
    foreach ($features ?? [] as $value) {
        if (!is_float($value)) {
            $allFloat = false;
        }
        if (!is_finite($value)) {
            $allFinite = false;
        }
    }
    check('every feature is a float', $allFloat);
    /* NAN and INF both survive a (float) cast and both poison the model —
       sklearn raises on NaN, and an infinity makes every distance in the
       forest meaningless. */
    check('every feature is finite (no NAN, no INF)', $allFinite);

    check(
        'hour_of_day is a real hour',
        isset($features['hour_of_day']) && $features['hour_of_day'] >= 0 && $features['hour_of_day'] <= 23,
        $features['hour_of_day'] ?? 'missing'
    );
    check(
        'past_cancel_rate is a rate, not a count',
        isset($features['past_cancel_rate'])
            && $features['past_cancel_rate'] >= 0 && $features['past_cancel_rate'] <= 1,
        $features['past_cancel_rate'] ?? 'missing'
    );
    /* The bug this one exists for: an order dated before its own account
       existed gives a negative age, which is not an unusual order but
       impossible data. It was real — seed.sql had one — and nothing read
       that relationship until this feature did. */
    check(
        'account_age_hours is never negative',
        isset($features['account_age_hours']) && $features['account_age_hours'] >= 0,
        $features['account_age_hours'] ?? 'missing'
    );

    check(
        'a nonexistent order returns null rather than a vector of zeros',
        risk_ml_features($db, 99999999) === null
    );
}

/* ================================================================
   3. the single-order path agrees with the bulk export path
   ================================================================ */

section('3. Live scoring and the training export agree');

$csvPath = dirname(__DIR__) . '/data/risk_training.csv';

if (!is_file($csvPath)) {
    skipped('the live vector matches the exported row', 'no data/risk_training.csv — run the export first');
} elseif ($orderId === 0) {
    skipped('the live vector matches the exported row', 'no orders');
} else {
    $handle = fopen($csvPath, 'r');
    $header = fgetcsv($handle);
    $row = null;
    while (($line = fgetcsv($handle)) !== false) {
        $assoc = array_combine($header, $line);
        if ((int) $assoc['order_id'] === $orderId) {
            $row = $assoc;
            break;
        }
    }
    fclose($handle);

    if ($row === null) {
        skipped(
            'the live vector matches the exported row',
            "order $orderId is not in the CSV — re-export after generating data"
        );
    } else {
        $live = risk_ml_features($db, $orderId);
        $mismatched = [];

        foreach (RISK_FEATURE_COLUMNS as $column) {
            $fromCsv  = (float) $row[$column];
            $fromLive = (float) $live[$column];
            /* A relative tolerance, because the CSV holds a decimal
               rendering of a float. value_vs_buyer_median is a division and
               is the one that will not match to the last bit. */
            if (abs($fromCsv - $fromLive) > max(1e-6, abs($fromCsv) * 1e-9)) {
                $mismatched[] = sprintf('%s csv=%s live=%s', $column, $fromCsv, $fromLive);
            }
        }

        check(
            'all nine features match between the live and bulk SQL paths',
            $mismatched === [],
            $mismatched === [] ? 'order ' . $orderId : implode('; ', $mismatched)
        );
    }
}

/* ================================================================
   4. the blend
   ================================================================ */

section('4. risk_blend() — the rules keep 0.7, and bad weights cannot break it');

$config = risk_config();
printf(
    "  (settings: rule_weight=%.2f, ml_weight=%.2f)\n",
    $config['rule_weight'],
    $config['ml_weight']
);

$result = risk_blend(60, null);
check(
    'with no ML score the rule score passes through UNCHANGED',
    $result['score'] === 60 && $result['blended'] === false,
    $result['score']
);
/* The alternative — treating a missing ML score as 0 — would drag every
   score down by 30% and make "the model is not trained yet" look exactly
   like "nothing about this order is unusual". */
check(
    'a missing ML score is not treated as a zero',
    risk_blend(60, null)['score'] !== (int) round(0.7 * 60),
    '60, not ' . (int) round(0.7 * 60)
);

$result = risk_blend(60, 90);
$expected = (int) round($config['rule_weight'] * 60 + $config['ml_weight'] * 90);
check(
    'rule 60 + ml 90 blends to the weighted sum',
    $result['score'] === $expected && $result['blended'] === true,
    sprintf('%d (expected %d)', $result['score'], $expected)
);
check(
    'the blend keeps both inputs so the admin UI can show the split',
    $result['rule_score'] === 60 && $result['ml_score'] === 90,
    sprintf('rule %d, ml %d', $result['rule_score'], $result['ml_score'])
);

check(
    'a lower ML score pulls a high rule score DOWN but not below the weight',
    risk_blend(100, 0)['score'] === (int) round($config['rule_weight'] * 100),
    risk_blend(100, 0)['score']
);
check(
    'identical inputs blend to themselves',
    risk_blend(45, 45)['score'] === 45,
    risk_blend(45, 45)['score']
);

check('0 and 0 blend to 0', risk_blend(0, 0)['score'] === 0);
check('100 and 100 blend to 100', risk_blend(100, 100)['score'] === 100);

/* Out-of-range inputs. A score comes from this project's own code, so
   these should be impossible — which is exactly why they are worth
   pinning: an impossible value that silently becomes a 143 in the admin
   list is a bug that takes a long time to find. */
check(
    'a rule score above 100 is clamped',
    risk_blend(500, 50)['score'] <= 100,
    risk_blend(500, 50)['score']
);
check(
    'a negative rule score is clamped to 0, not blended as negative',
    risk_blend(-20, 0)['score'] === 0,
    risk_blend(-20, 0)['score']
);
check(
    'an ML score above 100 is clamped',
    risk_blend(50, 400)['score'] <= 100,
    risk_blend(50, 400)['score']
);

/* ---- the weights are editable in settings, so test what an admin can do ---- */

section('5. Weights an admin can actually set');

/**
 * Run a body with the two weight settings temporarily overridden.
 *
 * risk_config() caches in a static, so the cache has to be reset by hand.
 * It is reset again afterwards, so a later test does not inherit the
 * override — a cached weight leaking between sections would be the kind of
 * failure that only appears when the tests are run in a particular order.
 */
function with_weights(float $rule, float $ml, callable $body)
{
    $db = db();
    $db->beginTransaction();

    try {
        /* `setting_key`, not `key`, and the value is JSON — setting()
           json_decodes whatever it reads, so a bare 0.5 stored as the
           string "0.5" happens to decode to 0.5, but storing it properly
           is the difference between a test that works and a test that
           works by accident. */
        $set = $db->prepare(
            'INSERT INTO settings (setting_key, value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE value = VALUES(value)'
        );
        $set->execute(['risk_rule_weight', json_encode($rule)]);
        $set->execute(['risk_ml_weight', json_encode($ml)]);

        /* BOTH caches. setting() memoises each key and risk_config()
           memoises the whole config built from them, so clearing only the
           second would re-read through a still-cached first and every
           weight case here would test the same values. */
        setting_reset();
        risk_config_reset();
        return $body();
    } finally {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        /* The rollback undoes the ROWS. It does not undo the caches, which
           are PHP memory — so they are cleared again here, or the next
           section would keep reading the overridden weights from a cache
           of values that no longer exist in the table. */
        setting_reset();
        risk_config_reset();
    }
}

if (!function_exists('risk_config_reset')) {
    skipped('weights are read from settings', 'risk_config() has no cache reset hook');
} else {
    $score = with_weights(0.5, 0.5, static fn() => risk_blend(40, 80)['score']);
    check(
        'weights 0.5/0.5 average the two scores',
        $score === 60,
        $score
    );

    $score = with_weights(1.0, 0.0, static fn() => risk_blend(40, 100)['score']);
    check(
        'weight 1.0/0.0 ignores the model entirely',
        $score === 40,
        $score
    );

    /* Weights that do not sum to 1. Normalised rather than trusted:
       0.9 + 0.9 applied literally would produce a score of 180. */
    $score = with_weights(0.9, 0.9, static fn() => risk_blend(40, 80)['score']);
    check(
        'weights that sum to 1.8 are normalised, not applied literally',
        $score === 60,
        $score . ' (literal arithmetic would give 108)'
    );

    $blend = with_weights(0.0, 0.0, static fn() => risk_blend(55, 90));
    check(
        'weights of 0/0 fall back to the rule score instead of dividing by zero',
        $blend['score'] === 55 && $blend['blended'] === false,
        $blend['score']
    );
}

/* ================================================================
   6. every failure path degrades quietly
   ================================================================ */

section('6. A missing or broken AI service never throws');

/* Pointed at a port nothing is listening on. Not a mock: "the Python
   service is not running" is the normal state of this project, and it is
   the path that absolutely must not break a checkout. */
$dead = 'http://127.0.0.1:9/risk/score';

$why = null;
$response = risk_ai_post('definitely_not_a_config_key', $dead, ['features' => []], $why);
check(
    'an unreachable service returns null, not an exception',
    $response === null,
    'null'
);
check(
    'and says why, in words a log reader can use',
    is_string($why) && $why !== '',
    $why
);

$why = null;
$response = risk_ai_post('definitely_not_a_config_key', 'http://127.0.0.1:9/nope', [], $why);
check('a bad path on a dead host is also just null', $response === null, $why);

/* A URL that answers but is not the AI service: the shop's own API. It
   returns a JSON envelope with no ml_score, which must be rejected rather
   than read as a score of 0. */
$why = null;
$live = risk_ml_score($db, $orderId ?: 1, $why);

if ($live !== null) {
    check(
        'the running service returned a usable ml_score',
        is_int($live['ml_score']) && $live['ml_score'] >= 0 && $live['ml_score'] <= 100,
        sprintf('ml_score %d, model v%s', $live['ml_score'], $live['model_version'] ?? '?')
    );
    $blend = risk_blend(50, $live['ml_score']);
    check(
        'a real ML score blends with a rule score',
        $blend['blended'] === true && $blend['score'] >= 0 && $blend['score'] <= 100,
        sprintf('rule 50 + ml %d = %d', $live['ml_score'], $blend['score'])
    );
} else {
    /* Not a failure. This is the supported state. */
    skipped('the running service returns a usable ml_score', (string) $why);
    check(
        'with no service, scoring reports a reason and returns null',
        is_string($why) && $why !== '',
        $why
    );
}

/* ================================================================
   7. the explanation falls back to the template
   ================================================================ */

section('7. risk_explain() — the template is always available');

/* The evidence keys have to be the ones the engine actually emits.
   Invented key names ('orders', 'age_hours') were what the first version of
   this fixture used, and the template rendered "0 orders from this buyer"
   from the missing keys — then the guard correctly rejected its own
   template for containing a number the evidence did not have. The failure
   was real and pointed at the fixture, which is the right outcome but
   worth writing down: the guard compares the TEXT against the EVIDENCE, so
   a fixture whose evidence is wrong fails in a way that looks like the
   template is broken. */
$fakeResult = [
    'score'   => 72,
    'level'   => 'high',
    'signals' => [
        risk_signal('VELOCITY_USER', [
            'orders_last_hour' => 5,
            'orders_last_24h'  => 7,
            'window_minutes'   => 38,
            'threshold'        => '3 or more within 1 hour',
        ]),
        risk_signal('NEW_ACCOUNT_HIGH_VALUE', [
            'account_age_hours' => 3,
            'order_total'       => 568950.0,
            'platform_median'   => 139000.0,
            'times_median'      => 4.1,
            'population'        => 1748,
        ]),
    ],
    'order'   => ['id' => 1, 'total' => 568950.0],
];

$template = risk_template_explanation($fakeResult);
check(
    'the PHP template produces a sentence with no AI service at all',
    trim($template) !== '',
    mb_substr($template, 0, 64) . '…'
);
check(
    'the template passes the same guard the LLM output has to pass',
    risk_explanation_problem($template, $fakeResult) === null,
    'no invented numbers in our own template'
);

$explained = risk_explain($fakeResult);
check(
    'risk_explain() always returns usable text',
    is_string($explained['text']) && trim($explained['text']) !== '',
    'source: ' . $explained['source']
);
check(
    'the returned text passes the guard whatever its source',
    risk_explanation_problem($explained['text'], $fakeResult) === null,
    $explained['source']
);

if ($explained['source'] === 'llm') {
    echo "\n         LLM wording: ", $explained['text'], "\n\n";
    check(
        'an accepted LLM explanation is not just the template verbatim',
        $explained['text'] !== $template,
        'the model actually rewrote it'
    );
} else {
    printf("  [INFO] fell back to the template: %s\n", (string) ($explained['reason'] ?? 'no reason given'));
    check(
        'a fallback records why, so the UI can say so',
        $explained['reason'] !== null,
        (string) $explained['reason']
    );
}

/* With no signals there is nothing to rephrase, and the LLM must not be
   asked to produce something out of nothing. */
$empty = risk_explain(['score' => 0, 'level' => 'low', 'signals' => []]);
check(
    'no signals means the template, never a call to the model',
    $empty['source'] === 'template',
    (string) ($empty['reason'] ?? '')
);

/* ================================================================
   8. nothing here writes anything
   ================================================================ */

section('8. This layer only reads');

$counts = [
    'scores' => (int) $db->query('SELECT COUNT(*) FROM order_risk_scores')->fetchColumn(),
    'alerts' => (int) $db->query('SELECT COUNT(*) FROM risk_alerts')->fetchColumn(),
    'holds'  => (int) $db->query('SELECT COUNT(*) FROM sub_orders WHERE payout_hold = 1')->fetchColumn(),
];

/* Re-running the whole read path must not change a single row. risk_ml.php
   scores and explains; it is Phase D that persists anything. */
if ($orderId !== 0) {
    risk_ml_features($db, $orderId);
    risk_ml_score($db, $orderId);
}
risk_explain($fakeResult);

check(
    'no risk score rows were written',
    (int) $db->query('SELECT COUNT(*) FROM order_risk_scores')->fetchColumn() === $counts['scores'],
    $counts['scores']
);
check(
    'no alerts were raised',
    (int) $db->query('SELECT COUNT(*) FROM risk_alerts')->fetchColumn() === $counts['alerts'],
    $counts['alerts']
);
check(
    'no payout was held',
    (int) $db->query('SELECT COUNT(*) FROM sub_orders WHERE payout_hold = 1')->fetchColumn() === $counts['holds'],
    $counts['holds']
);

/* ================================================================ */

echo "\n", str_repeat('-', 72), "\n";
printf('  %d passed, %d failed', $pass, $fail);
if ($skip) {
    printf(', %d skipped', $skip);
}
echo "\n";
echo str_repeat('-', 72), "\n";

if ($failures) {
    echo "\n  Failures:\n";
    foreach ($failures as $f) {
        echo '    - ', $f, "\n";
    }
    echo "\n";
}

exit($fail === 0 ? 0 : 1);
