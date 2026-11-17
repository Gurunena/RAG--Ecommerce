<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit("This is a command-line script and will not run over HTTP.\n");
}

require_once __DIR__ . '/../config.php';

$verbose = in_array('--verbose', $argv, true) || in_array('-v', $argv, true);

const EXPECTED = [
    /* ---- open to the world, on purpose ---- */
    'auth/csrf.php'        => ['public', 'Mints the CSRF token; there is nothing to protect yet'],
    'auth/login.php'       => ['public', 'You cannot be signed in to sign in'],
    'auth/logout.php'      => ['public', 'Must work even when the session has already gone'],
    'auth/register.php'    => ['public', 'Creating the first account'],
    'auth/me.php'          => ['public', 'Answers "nobody" rather than 401 — the nav asks on every page'],
    'products/list.php'    => ['public', 'The storefront is public'],
    'products/detail.php'  => ['public', 'The storefront is public'],
    'products/by_ids.php'  => ['public', 'Re-prices the AI chat cards; the chat is public'],
    'reviews/list.php'     => ['public', 'Reviews are shown to shoppers before they sign in'],
    'faqs.php'             => ['public', 'The FAQ is public; WRITING one is admin/faqs.php'],
    'settings/public.php'  => ['public', 'Category list for the signup form, before any session'],
    'contact_open.php'     => ['public', 'Stamps the form; people with a problem often cannot sign in'],
    'contact_submit.php'   => ['public', 'Same — but links to the account when there is one'],
    'ai/consult.php'       => ['public', 'Asking the consultant is something a visitor does'],
    'ai/health.php'        => ['public', 'Drives the chat status dot before sign-in'],
    'seller/register.php'  => ['public', 'A seller application; refuses if already signed in'],

    /* ---- any signed-in user ---- */
    'auth/avatar.php'          => ['login', 'Your own photo, whatever your role'],
    'auth/change_password.php' => ['login', 'Your own password'],
    'auth/update_profile.php'  => ['login', 'Your own profile'],
    'cart/get.php'       => ['login', 'Your own cart'],
    'cart/add.php'       => ['login', 'Your own cart'],
    'cart/update.php'    => ['login', 'Your own cart'],
    'cart/remove.php'    => ['login', 'Your own cart'],
    'cart/clear.php'     => ['login', 'Your own cart'],
    'cart/merge.php'     => ['login', 'Folds a guest cart into your own'],
    'wishlist/list.php'   => ['login', 'Your own wishlist'],
    'wishlist/toggle.php' => ['login', 'Your own wishlist'],
    'orders/checkout.php'         => ['login', 'Placing your own order'],
    'orders/list.php'             => ['login', 'Scoped to o.user_id in the WHERE'],
    'orders/detail.php'           => ['login', 'Scoped to o.user_id in the WHERE'],
    'orders/confirm_delivery.php' => ['login', 'Scoped by a join to orders.user_id'],

    /* ---- one role only ---- */
    'reviews/create.php' => ['customer', 'Only a customer reviews; sellers cannot review themselves'],

    'seller/dashboard.php'      => ['seller',  'A pending seller may see their own empty workspace'],
    'seller/profile.php'        => ['seller',  'A pending seller may still set their shop up'],
    'seller/product_delete.php' => ['seller',  'Withdrawing your own listing needs no verification'],
    // require_seller() first, then require_verified_seller() before storing —
    // so the effective gate is the stronger one.
    'seller/upload.php'         => ['seller+', 'A product image is a listing going in front of shoppers'],
    'seller/product_save.php'   => ['seller+', 'Publishing needs a verified seller'],
    'seller/product_toggle.php' => ['seller+', 'Publishing needs a verified seller'],
    'seller/stock.php'          => ['seller+', 'Changing what shoppers can buy'],
    'seller/sale.php'           => ['seller+', 'Changing what shoppers pay'],
    'seller/order_status.php'   => ['seller+', 'Moving a real order along'],

    'admin/dashboard.php'        => ['admin', ''],
    'admin/finance.php'          => ['admin', ''],
    'admin/seller_verify.php'    => ['admin', ''],
    'admin/product_moderate.php' => ['admin', ''],
    'admin/user_status.php'      => ['admin', ''],
    'admin/order_action.php'     => ['admin', ''],
    'admin/faqs.php'             => ['admin', ''],
    'admin/settings.php'         => ['admin', ''],

    /* ---- Risk Radar (Phase D) ---- */
    'admin/risk_overview.php'    => ['admin', 'KPIs and series for the Risk Radar screen'],
    'admin/risk_alerts.php'      => ['admin', 'The review queue; shows buyer names'],
    'admin/risk_alert_detail.php' => ['admin', 'Buyer email/phone and related accounts live here, not in the list'],
    'admin/risk_alert_decide.php' => ['admin', 'Records a human decision and can release a payout hold'],
    'admin/risk_rescore.php'     => ['admin', 'Re-scores an order or runs the sweep; both can apply holds'],
    'seller/risk_notices.php'    => ['seller', 'A seller sees ONLY their own holds: no score, no signals, no buyer'],
];

const SQL_CONCAT_OK = [
    'product_select_list()'  => 'a constant column list',
    'product_base_sql()'     => 'a constant SELECT ... FROM',
    'product_public_where()' => 'a constant WHERE fragment',
    'seller_product_select()' => 'a constant column list',
    'admin_user_sql()'       => 'a constant SELECT ... FROM',
    'order_base_sql()'       => 'a constant SELECT ... FROM',
    'SALE_PRICE_SQL'         => 'a constant correlated subquery',
    'SALE_END_SQL'           => 'a constant correlated subquery',
    'SELLER_LIVE_SQL'        => 'a constant WHERE fragment',
    'ADMIN_LIVE_SQL'         => 'a constant WHERE fragment',
    'FIN_LIVE'               => 'a constant WHERE fragment',
    '$in'                    => 'a ?,?,? placeholder list from array_fill',
    '$placeholders'          => 'a ?,?,? placeholder list from array_fill',
    '$whereSql'              => 'assembled from constant fragments; values are bound',
    '$where'                 => 'assembled from constant fragments; values are bound',
    'implode(\', \', $sets)' => 'column names from a whitelist; values are bound',
    'implode(\', \', $userSets)'   => 'column names from a whitelist; values are bound',
    'implode(\', \', $sellerSets)' => 'column names from a whitelist; values are bound',
    '(int)'                  => 'an integer this code cast itself',
    '$limit'                 => 'an integer clamped by query_int()',
    '$days'                  => 'an integer clamped by query_int()',
    '$from'                  => 'an integer derived from a clamped query_int()',
    '$seriesDays'            => 'an integer from a whitelist',
    'sprintf(\'LIMIT %d OFFSET %d\'' => '%d on integers from query_int()',
];

const WRITES_REVIEWED = [
    'auth/avatar.php' => 'Two UPDATEs on alternative paths (remove vs upload); one runs per request',
    'cart/update.php' => 'DELETE when the quantity is 0, UPDATE otherwise; one runs per request',
];

$apiRoot = dirname(__DIR__);
$files   = [];

$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($apiRoot));
foreach ($rii as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') {
        continue;
    }
    $rel = str_replace('\\', '/', substr($file->getPathname(), strlen($apiRoot) + 1));

    if (str_starts_with($rel, 'tools/') || str_starts_with($rel, 'lib/')) {
        continue;
    }
    $base = basename($rel);
    if (str_starts_with($base, '_')
        || in_array($base, ['config.php', 'config.local.php', 'bootstrap.php', 'db.php', 'helpers.php', 'catalog.php'], true)) {
        continue;
    }
    $files[] = $rel;
}
sort($files);

$problems = [];
$notes    = [];
$rows     = [];

/** Record a finding. */
function problem(string $file, string $what, string $detail = ''): void
{
    global $problems;
    $problems[] = ['file' => $file, 'what' => $what, 'detail' => $detail];
}

function note(string $file, string $what, string $detail = ''): void
{
    global $notes;
    $notes[] = ['file' => $file, 'what' => $what, 'detail' => $detail];
}

function sql_literals_with_interpolation(string $line): array
{
    // Every single- or double-quoted literal on the line, with its quote char.
    if (!preg_match_all('/([\'"])((?:\\\\.|(?!\1)[^\\\\])*)\1/', $line, $m, PREG_SET_ORDER)) {
        return [];
    }

    $hits = [];

    foreach ($m as $match) {
        [$whole, $quote, $body] = $match;

        $isSql = preg_match('/^\s*(SELECT|INSERT\s+INTO|UPDATE|DELETE\s+FROM|REPLACE\s+INTO)\b/i', $body)
            || preg_match('/(^|\s)(FROM|WHERE|JOIN|SET|VALUES|ORDER\s+BY|GROUP\s+BY|HAVING|LIMIT|OFFSET|AND|ON)\s/i', $body)
                && preg_match('/[=?()]|\*/', $body);

        if (!$isSql) {
            continue;
        }

        // A $var interpolated inside a double-quoted SQL string.
        if ($quote === '"' && preg_match('/\$\w+|\{\$/', $body)) {
            $hits[] = $whole;
            continue;
        }

        // The literal glued to a variable or an expression with `.`
        $pos   = strpos($line, $whole);
        $after = $pos === false ? '' : substr($line, $pos + strlen($whole));
        $before = $pos === false ? '' : substr($line, 0, $pos);

        if (preg_match('/^\s*\.\s*\S/', $after) || preg_match('/\.\s*$/', $before)) {
            $hits[] = $whole;
        }
    }

    return $hits;
}

/** Source with comments stripped, so a docblock mentioning a gate is not read as calling one. */
function code_only(string $source): string
{
    // Block comments, then line comments. Crude but right for this codebase:
    // no endpoint contains '//' inside a string literal.
    $noBlock = preg_replace('#/\*.*?\*/#s', '', $source) ?? $source;
    $lines   = [];
    foreach (explode("\n", $noBlock) as $line) {
        $stripped = preg_replace('#//.*$#', '', $line);
        $lines[] = $stripped ?? $line;
    }
    return implode("\n", $lines);
}

foreach ($files as $rel) {
    $source = (string) file_get_contents($apiRoot . '/' . $rel);
    $code   = code_only($source);

    /* ---------- what gate does it actually apply? ---------- */
    $gate = 'public';
    if (str_contains($code, 'require_admin()')) {
        $gate = 'admin';
    } elseif (str_contains($code, 'require_verified_seller()')) {
        $gate = 'seller+';
    } elseif (str_contains($code, 'require_seller()')) {
        $gate = 'seller';
    } elseif (preg_match("/require_role\(\s*'customer'\s*\)/", $code)) {
        $gate = 'customer';
    } elseif (preg_match('/require_role\(/', $code)) {
        $gate = 'role';
    } elseif (str_contains($code, 'require_login()')) {
        $gate = 'login';
    }

    $methods = [];
    if (preg_match('/require_method\(([^)]*)\)/', $code, $m)) {
        preg_match_all("/'([A-Z]+)'/", $m[1], $mm);
        $methods = $mm[1];
    }

    $isPost = in_array('POST', $methods, true);
    $hasCsrf = str_contains($code, 'csrf_check()');

    $rows[] = [
        'file'   => $rel,
        'method' => $methods ? implode('|', $methods) : '—',
        'gate'   => $gate,
        'csrf'   => $hasCsrf,
    ];

    if (!isset(EXPECTED[$rel])) {
        problem($rel, 'NOT DECLARED', 'Add it to EXPECTED in api/tools/audit.php with its intended gate and why');
    } else {
        [$want, $why] = EXPECTED[$rel];
        if ($gate !== $want) {
            problem($rel, 'WRONG GATE', sprintf('declared %s, applies %s', $want, $gate));
        }
    }

    if (str_starts_with($rel, 'admin/') && $gate !== 'admin') {
        problem($rel, 'MISSING ROLE CHECK', 'under admin/ but does not call require_admin()');
    }
    if (str_starts_with($rel, 'seller/') && $rel !== 'seller/register.php'
        && !in_array($gate, ['seller', 'seller+'], true)) {
        problem($rel, 'MISSING ROLE CHECK', 'under seller/ but does not call require_seller()');
    }

    if (!$methods) {
        problem($rel, 'NO require_method()', 'any verb would be accepted');
    }
    if ($isPost && !$hasCsrf) {
        problem($rel, 'POST WITHOUT CSRF', 'a cross-site form could trigger this');
    }
    if (!$isPost && $hasCsrf) {
        note($rel, 'CSRF on a read', 'harmless, but says the method list may be wrong');
    }

    foreach (explode("\n", $code) as $n => $line) {
        foreach (sql_literals_with_interpolation($line) as $hit) {
            $excused = null;
            foreach (SQL_CONCAT_OK as $needle => $reason) {
                if (str_contains($line, $needle)) {
                    $excused = $needle . ' — ' . $reason;
                    break;
                }
            }

            if ($excused === null) {
                problem($rel, 'SQL BUILT BY CONCATENATION', sprintf('line %d: %s', $n + 1, trim($line)));
            } elseif ($verbose) {
                note($rel, 'SQL interpolation reviewed', sprintf('line %d: %s', $n + 1, $excused));
            }
            break;      // one finding per line is enough
        }
    }

    if (preg_match_all('/\$_(GET|REQUEST)\b/', $code, $raw)) {
        problem($rel, 'RAW $_' . $raw[1][0], count($raw[0]) . ' use(s) — use query_str()/query_int() instead');
    }
    if (preg_match_all('/\$_POST\b/', $code, $rawPost)) {
        // Only acceptable where the body really is multipart.
        if (!str_contains($code, 'store_uploaded_image')) {
            problem($rel, 'RAW $_POST', count($rawPost[0]) . ' use(s) — use body_str()/body_int() instead');
        } elseif ($verbose) {
            note($rel, 'RAW $_POST reviewed', 'multipart upload; there is no JSON body to read');
        }
    }

    if (preg_match('/SELECT\s+\*/i', $code)) {
        problem($rel, 'SELECT *', 'name the columns, or the next migration leaks one');
    }

    /* password_hash is only ever read to verify it, never returned. */
    if (str_contains($code, 'password_hash')
        && !preg_match('/password_verify|password_hash\(\$/', $code)) {
        problem($rel, 'SELECTS password_hash', 'must never leave the server');
    }

    $nicRead = preg_match('/SELECT[^;]{0,400}\bnic_number\b/is', $code)
        || preg_match('/[\'"]nic_number[\'"]\s*=>/', $code);

    if ($nicRead && !str_starts_with($rel, 'admin/')) {
        problem($rel, 'RETURNS nic_number', 'only admin/ may return it');
    } elseif (str_contains($code, 'nic_number') && !str_starts_with($rel, 'admin/') && $verbose) {
        note($rel, 'writes nic_number', 'stored, never returned — fine');
    }

    if (!preg_match('/catch\s*\(\s*Throwable/', $code)) {
        problem($rel, 'NO catch (Throwable)', 'an unexpected error would not be shaped into the envelope');
    }

    if (preg_match('/catch\s*\(\s*Throwable\s+\$(\w+)\s*\)\s*\{(.*?)\n\}/s', $code, $cm)) {
        $var  = $cm[1];
        $body = $cm[2];

        if (preg_match('/json_fail\([^;]*\$' . preg_quote($var, '/') . '->get(Message|TraceAsString|File|Line)/', $body)) {
            problem($rel, 'LEAKS THE EXCEPTION', 'catch (Throwable) passes the detail to json_fail()');
        }
    }

    $writeCount = preg_match_all('/\b(INSERT INTO|UPDATE|DELETE FROM)\b/i', $code);
    if ($writeCount >= 3
        && !str_contains($code, 'db_transaction')
        && !str_contains($code, 'beginTransaction')) {
        if (isset(WRITES_REVIEWED[$rel])) {
            if ($verbose) {
                note($rel, 'multiple writes, reviewed', WRITES_REVIEWED[$rel]);
            }
        } else {
            note($rel, 'MULTIPLE WRITES, NO TRANSACTION', $writeCount . ' write statements — review, then record the reason in WRITES_REVIEWED');
        }
    }
}

if (!is_file(__DIR__ . '/.htaccess')) {
    problem('tools/.htaccess', 'MISSING', 'without it every CLI script here is served over HTTP');
}

if (is_dir(dirname(__DIR__) . '/lib') && !is_file(dirname(__DIR__) . '/lib/.htaccess')) {
    problem('lib/.htaccess', 'MISSING', 'files in api/lib/ are served over HTTP without it');
}

$testsDir = dirname(__DIR__, 2) . '/tests';
if (is_dir($testsDir)) {
    if (!is_file($testsDir . '/.htaccess')) {
        problem('tests/.htaccess', 'MISSING', 'test suites are served over HTTP without it');
    }
    foreach (glob($testsDir . '/*.php') ?: [] as $suite) {
        if (!str_contains((string) file_get_contents($suite), "PHP_SAPI !== 'cli'")) {
            problem('tests/' . basename($suite), 'NO CLI GUARD', 'add the PHP_SAPI check');
        }
    }
}

foreach (glob(__DIR__ . '/*.php') ?: [] as $script) {
    $name = 'tools/' . basename($script);
    $body = (string) file_get_contents($script);

    if (!str_contains($body, "PHP_SAPI !== 'cli'")) {
        problem($name, 'NO CLI GUARD', 'add the PHP_SAPI check — .htaccess alone needs AllowOverride All');
    }
}

echo str_repeat('=', 74), "\n";
echo "  Endpoint hardening audit — ", count($files), " endpoints\n";
echo str_repeat('=', 74), "\n\n";

printf("  %-32s %-6s %-9s %s\n", 'ENDPOINT', 'METHOD', 'GATE', 'CSRF');
echo '  ', str_repeat('-', 70), "\n";
foreach ($rows as $row) {
    printf(
        "  %-32s %-6s %-9s %s\n",
        $row['file'],
        $row['method'],
        $row['gate'],
        $row['csrf'] ? 'yes' : ($row['method'] === 'GET' ? '—' : 'NO')
    );
}

/* Which endpoints are declared but no longer exist. */
$missing = array_diff(array_keys(EXPECTED), $files);
foreach ($missing as $gone) {
    problem($gone, 'DECLARED BUT MISSING', 'in EXPECTED but the file is gone — remove the entry');
}

echo "\n", str_repeat('-', 74), "\n";

if ($notes) {
    echo "\n  NOTES (reviewed, not problems)\n\n";
    foreach ($notes as $n) {
        printf("    %-32s %s\n", $n['file'], $n['what'] . ($n['detail'] !== '' ? ' — ' . $n['detail'] : ''));
    }
}

if (!$problems) {
    echo "\n  No problems found.\n\n";
    printf("  %d endpoints checked for: missing auth, missing role check, SQL\n", count($files));
    echo "  concatenation, raw request input, SELECT *, leaked columns,\n";
    echo "  unshaped errors and POSTs without CSRF.\n";
    echo "\n", str_repeat('=', 74), "\n";
    exit(0);
}

printf("\n  %d PROBLEM(S)\n\n", count($problems));
foreach ($problems as $p) {
    printf("    [%s]\n      %s\n", $p['file'], $p['what'] . ($p['detail'] !== '' ? ': ' . $p['detail'] : ''));
}
echo "\n", str_repeat('=', 74), "\n";

exit(1);
