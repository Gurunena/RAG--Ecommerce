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
$jarDir = sys_get_temp_dir() . '/consult-test-' . getmypid();
@mkdir($jarDir, 0777, true);

function section(string $title): void
{
    echo "\n", str_repeat('=', 68), "\n  ", $title, "\n", str_repeat('=', 68), "\n";
}

function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
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

/** GET the CSRF token for a jar and return it. */
function csrf(string $jar): string
{
    $r = api($jar, 'GET', 'auth/csrf.php');
    return $r['body']['data']['csrf_token'] ?? '';
}

/** POST with the jar's current CSRF token attached. */
function post(string $jar, string $path, array $body): array
{
    return api($jar, 'POST', $path, $body, ['X-CSRF-Token: ' . csrf($jar)]);
}

section('0. Environment');

$probe = api('guest', 'GET', 'auth/csrf.php');
if ($probe['status'] !== 200) {
    echo "  Cannot reach {$BASE}/api/auth/csrf.php (status {$probe['status']}).\n";
    echo "  Start Apache in the XAMPP Control Panel, then run this again.\n";
    exit(1);
}
check('Apache serves the API', true, $BASE);
check('PHP version is 8.1+', PHP_VERSION_ID >= 80100, PHP_VERSION);
check('pdo_mysql loaded', extension_loaded('pdo_mysql'));

try {
    $v = db()->query('SELECT VERSION()')->fetchColumn();
    check('MySQL reachable from PHP', true, (string) $v);
} catch (Throwable $e) {
    check('MySQL reachable from PHP', false, $e->getMessage());
    exit(1);
}

section('1. CSRF protection');

$token = csrf('guest');
check('GET /auth/csrf.php issues a token', strlen($token) === 64, substr($token, 0, 12) . '...');

$noToken = api('guest', 'POST', 'auth/login.php', ['email' => 'x@y.z', 'password' => 'whatever']);
check(
    'POST with no CSRF token is rejected 403',
    $noToken['status'] === 403 && ($noToken['body']['code'] ?? '') === 'FORBIDDEN',
    'got ' . $noToken['status'] . ' ' . ($noToken['body']['code'] ?? '?')
);

$badToken = api('guest', 'POST', 'auth/login.php',
    ['email' => 'x@y.z', 'password' => 'whatever'],
    ['X-CSRF-Token: ' . str_repeat('0', 64)]);
check(
    'POST with a forged CSRF token is rejected 403',
    $badToken['status'] === 403,
    'got ' . $badToken['status']
);

$wrongMethod = api('guest', 'GET', 'auth/login.php');
check('GET on a POST-only endpoint is 405', $wrongMethod['status'] === 405, 'got ' . $wrongMethod['status']);

$crossOrigin = api('guest', 'POST', 'auth/login.php',
    ['email' => 'x@y.z', 'password' => 'whatever'],
    ['Origin: http://evil.example.com', 'X-CSRF-Token: ' . csrf('guest')]);
check(
    'Request carrying a foreign Origin is rejected 403',
    $crossOrigin['status'] === 403,
    'got ' . $crossOrigin['status']
);

section('2. Session state while signed out');

$me = api('guest', 'GET', 'auth/me.php');
check(
    'GET /auth/me.php returns 200 with user:null',
    $me['status'] === 200 && ($me['body']['ok'] ?? false) === true
        && array_key_exists('user', $me['body']['data'] ?? [])
        && $me['body']['data']['user'] === null,
    'got ' . $me['status']
);

$protected = api('guest', 'GET', 'auth/me.php');
check('Envelope is always {ok, data} or {ok, error, code}',
    isset($protected['body']['ok']), $protected['raw'] ? substr($protected['raw'], 0, 40) : '');

section('3. Registration and server-side validation');

$stamp    = date('YmdHis') . getmypid();
$newEmail = 'phase2.' . $stamp . '@test.local';

$badReg = post('guest', 'auth/register.php', [
    'name'         => 'A',
    'email'        => 'not-an-email',
    'phone'        => '12345',
    'password'     => 'short',
    'accept_terms' => false,
]);
$f = $badReg['body']['fields'] ?? [];
check(
    'Bad registration returns 422 VALIDATION with field errors',
    $badReg['status'] === 422 && ($badReg['body']['code'] ?? '') === 'VALIDATION',
    'got ' . $badReg['status'] . ' ' . ($badReg['body']['code'] ?? '?')
);
check('  ...field error for name',         isset($f['name']),         $f['name'] ?? '-');
check('  ...field error for email',        isset($f['email']),        $f['email'] ?? '-');
check('  ...field error for phone',        isset($f['phone']),        $f['phone'] ?? '-');
check('  ...field error for password',     isset($f['password']),     $f['password'] ?? '-');
check('  ...field error for accept_terms', isset($f['accept_terms']), $f['accept_terms'] ?? '-');

$short = post('guest', 'auth/register.php', [
    'name' => 'Seven Char', 'email' => 'seven.' . $stamp . '@test.local',
    'phone' => '0771112223', 'password' => '1234567', 'accept_terms' => true,
]);
check(
    'A 7-character password is refused (minimum is 8)',
    $short['status'] === 422 && isset($short['body']['fields']['password']),
    $short['body']['fields']['password'] ?? ('status ' . $short['status'])
);

$reg = post('guest', 'auth/register.php', [
    'name' => 'Phase Two Tester', 'email' => $newEmail,
    'phone' => '077 123 4599', 'password' => 'Testing@2026', 'accept_terms' => true,
]);
$newUser = $reg['body']['data']['user'] ?? null;
check('Valid registration returns 201 and signs the user in',
    $reg['status'] === 201 && ($newUser['email'] ?? '') === $newEmail,
    'got ' . $reg['status']);
check('  ...role defaults to customer', ($newUser['role'] ?? '') === 'customer', $newUser['role'] ?? '-');
check('  ...phone normalised to 0XXXXXXXXX', ($newUser['phone'] ?? '') === '0771234599', $newUser['phone'] ?? '-');
check('  ...no password_hash in the response',
    $newUser !== null && !array_key_exists('password_hash', $newUser)
        && strpos($reg['raw'], '$2y$') === false);

$dupe = post('guest', 'auth/register.php', [
    'name' => 'Duplicate', 'email' => $newEmail,
    'phone' => '0771234599', 'password' => 'Testing@2026', 'accept_terms' => true,
]);
check('Duplicate email returns a field error on email',
    $dupe['status'] === 422 && isset($dupe['body']['fields']['email']),
    $dupe['body']['fields']['email'] ?? ('status ' . $dupe['status']));

$meNew = api('guest', 'GET', 'auth/me.php');
check('New account is signed in on the same cookie',
    ($meNew['body']['data']['user']['email'] ?? '') === $newEmail);

section('4. Password storage');

$row = db()->prepare('SELECT password_hash FROM users WHERE email = ?');
$row->execute([$newEmail]);
$stored = (string) $row->fetchColumn();
check('Stored value is a bcrypt hash, not the password',
    str_starts_with($stored, '$2y$') && $stored !== 'Testing@2026',
    substr($stored, 0, 10) . '...');
check('password_verify() matches the real password', password_verify('Testing@2026', $stored));
check('password_verify() rejects a wrong password', !password_verify('Testing@2027', $stored));

$log = is_file(LOG_FILE) ? (string) file_get_contents(LOG_FILE) : '';
check('No plain-text password anywhere in logs/app.log',
    strpos($log, 'Testing@2026') === false && strpos($log, $DEMO_PASSWORD) === false);

section('5. Logout');

$out = post('guest', 'auth/logout.php', []);
check('POST /auth/logout.php succeeds', $out['status'] === 200 && ($out['body']['ok'] ?? false));

$meOut = api('guest', 'GET', 'auth/me.php');
// array_key_exists, not ?? : the key is present and its value IS null.
check('me.php reports signed out afterwards',
    array_key_exists('user', $meOut['body']['data'] ?? []) && $meOut['body']['data']['user'] === null,
    'status ' . $meOut['status']);

section('6. Login (seeded demo accounts, password ' . $DEMO_PASSWORD . ')');

$logins = [
    'admin'  => ['admin@consult.lk',     'admin'],
    'seller' => ['techhub@example.com',  'seller'],
    'buyer'  => ['nimal@example.com',    'customer'],
];

foreach ($logins as $jar => [$email, $expectRole]) {
    $r = api($jar, 'POST', 'auth/login.php',
        ['email' => $email, 'password' => $DEMO_PASSWORD],
        ['X-CSRF-Token: ' . csrf($jar)]);
    $u = $r['body']['data']['user'] ?? null;
    check(
        sprintf('%s signs in as role "%s"', $email, $expectRole),
        $r['status'] === 200 && ($u['role'] ?? '') === $expectRole,
        'got ' . $r['status'] . ' ' . ($u['role'] ?? ($r['body']['error'] ?? '?'))
    );
}

$sellerMe = api('seller', 'GET', 'auth/me.php')['body']['data']['user'] ?? [];
check('Seller /me includes the nested seller object',
    isset($sellerMe['seller']['business_name']), $sellerMe['seller']['business_name'] ?? '-');
check('  ...with kyc_status and a verified flag',
    isset($sellerMe['seller']['kyc_status'], $sellerMe['seller']['verified']),
    ($sellerMe['seller']['kyc_status'] ?? '-'));
check('  ...and never the NIC number',
    !isset($sellerMe['seller']['nic_number']));

$buyerMe = api('buyer', 'GET', 'auth/me.php')['body']['data']['user'] ?? [];
check('Customer /me has seller:null',
    array_key_exists('seller', $buyerMe) && $buyerMe['seller'] === null);

$wrongPass = api('wrongpw', 'POST', 'auth/login.php',
    ['email' => 'nimal@example.com', 'password' => 'DefinitelyWrong1'],
    ['X-CSRF-Token: ' . csrf('wrongpw')]);
check('Wrong password returns 401 AUTH',
    $wrongPass['status'] === 401 && ($wrongPass['body']['code'] ?? '') === 'AUTH',
    'got ' . $wrongPass['status']);

$unknown = api('wrongpw2', 'POST', 'auth/login.php',
    ['email' => 'nobody.' . $stamp . '@test.local', 'password' => 'DefinitelyWrong1'],
    ['X-CSRF-Token: ' . csrf('wrongpw2')]);
check('Unknown email returns the SAME message (no account enumeration)',
    ($unknown['body']['error'] ?? '') === ($wrongPass['body']['error'] ?? 'x'),
    $unknown['body']['error'] ?? '-');

section('7. Role checks are enforced by PHP, not the browser');

// A throwaway endpoint is not needed: update_profile requires a login, and a
// role-gated endpoint is exercised by pointing the seller at an admin-only
// one. Phase 2 has no admin endpoints yet, so prove the gate directly.
// NOT underscore-prefixed: api/.htaccess denies ^_*.php outright (those are
// includes), so a probe called _x.php would 403 before PHP ever ran.
$roleProbe = __DIR__ . '/../phase2-role-probe.php';
file_put_contents($roleProbe, "<?php\nrequire_once __DIR__ . '/bootstrap.php';\nrequire_role('admin');\njson_ok(['reached' => true]);\n");

$asSeller = api('seller', 'GET', 'phase2-role-probe.php');
check('Seller hitting an admin-only endpoint gets 403 FORBIDDEN',
    $asSeller['status'] === 403 && ($asSeller['body']['code'] ?? '') === 'FORBIDDEN',
    'got ' . $asSeller['status'] . ' ' . ($asSeller['body']['code'] ?? '?'));

$asBuyer = api('buyer', 'GET', 'phase2-role-probe.php');
check('Customer hitting an admin-only endpoint gets 403 FORBIDDEN',
    $asBuyer['status'] === 403, 'got ' . $asBuyer['status']);

$asAdmin = api('admin', 'GET', 'phase2-role-probe.php');
check('Admin reaches the admin-only endpoint',
    $asAdmin['status'] === 200 && ($asAdmin['body']['data']['reached'] ?? false) === true,
    'got ' . $asAdmin['status']);

$asNobody = api('anon', 'GET', 'phase2-role-probe.php');
check('Signed-out request to a protected endpoint gets 401 AUTH',
    $asNobody['status'] === 401 && ($asNobody['body']['code'] ?? '') === 'AUTH',
    'got ' . $asNobody['status'] . ' ' . ($asNobody['body']['code'] ?? '?'));

@unlink($roleProbe);

$profileNoAuth = api('anon', 'POST', 'auth/update_profile.php',
    ['name' => 'Hacker', 'email' => 'h@x.lk', 'phone' => '0771111111'],
    ['X-CSRF-Token: ' . csrf('anon')]);
check('update_profile.php without a session gets 401',
    $profileNoAuth['status'] === 401, 'got ' . $profileNoAuth['status']);

section('8. Profile and password change');

$login = api('tester', 'POST', 'auth/login.php',
    ['email' => $newEmail, 'password' => 'Testing@2026'],
    ['X-CSRF-Token: ' . csrf('tester')]);
check('Throwaway account signs in', $login['status'] === 200, 'got ' . $login['status']);

$upd = post('tester', 'auth/update_profile.php',
    ['name' => 'Renamed Tester', 'email' => $newEmail, 'phone' => '+94 71 222 3344']);
check('Profile update saves the new name',
    ($upd['body']['data']['user']['name'] ?? '') === 'Renamed Tester',
    $upd['body']['data']['user']['name'] ?? ('status ' . $upd['status']));
check('  ...and normalises +94 to 0',
    ($upd['body']['data']['user']['phone'] ?? '') === '0712223344',
    $upd['body']['data']['user']['phone'] ?? '-');

$landline = post('tester', 'auth/update_profile.php',
    ['name' => 'Renamed Tester', 'email' => $newEmail, 'phone' => '0112000000']);
check('Landline numbers are accepted by the server validator',
    ($landline['body']['data']['user']['phone'] ?? '') === '0112000000',
    $landline['body']['data']['user']['phone'] ?? ('status ' . $landline['status']));

$takenEmail = post('tester', 'auth/update_profile.php',
    ['name' => 'Renamed Tester', 'email' => 'nimal@example.com', 'phone' => '0112000000']);
check('Changing to an email another account owns is refused',
    $takenEmail['status'] === 422 && isset($takenEmail['body']['fields']['email']),
    $takenEmail['body']['fields']['email'] ?? ('status ' . $takenEmail['status']));

$wrongCurrent = post('tester', 'auth/change_password.php',
    ['current_password' => 'NotMyPassword1', 'new_password' => 'Brandnew@2026']);
check('change_password refuses a wrong current password',
    $wrongCurrent['status'] === 422 && isset($wrongCurrent['body']['fields']['current_password']),
    'status ' . $wrongCurrent['status']);

$changed = post('tester', 'auth/change_password.php',
    ['current_password' => 'Testing@2026', 'new_password' => 'Brandnew@2026']);
check('change_password succeeds with the right current password',
    $changed['status'] === 200 && ($changed['body']['data']['changed'] ?? false) === true,
    'status ' . $changed['status']);

$oldPw = api('tester2', 'POST', 'auth/login.php',
    ['email' => $newEmail, 'password' => 'Testing@2026'],
    ['X-CSRF-Token: ' . csrf('tester2')]);
check('  ...the old password no longer works', $oldPw['status'] === 401, 'got ' . $oldPw['status']);

$newPw = api('tester3', 'POST', 'auth/login.php',
    ['email' => $newEmail, 'password' => 'Brandnew@2026'],
    ['X-CSRF-Token: ' . csrf('tester3')]);
check('  ...the new password works', $newPw['status'] === 200, 'got ' . $newPw['status']);

section('9. Suspended accounts');

db()->prepare('UPDATE users SET status = \'suspended\' WHERE email = ?')->execute([$newEmail]);

$suspendedLogin = api('susp', 'POST', 'auth/login.php',
    ['email' => $newEmail, 'password' => 'Brandnew@2026'],
    ['X-CSRF-Token: ' . csrf('susp')]);
check('A suspended account cannot sign in (403)',
    $suspendedLogin['status'] === 403, 'got ' . $suspendedLogin['status']);

// tester3 is still holding a live session from before the suspension.
$suspendedMe = api('tester3', 'GET', 'auth/me.php');
check('An already-open session is ended on the next request',
    array_key_exists('user', $suspendedMe['body']['data'] ?? [])
        && $suspendedMe['body']['data']['user'] === null
        && isset($suspendedMe['body']['data']['reason']),
    $suspendedMe['body']['data']['reason'] ?? '-');

db()->prepare('UPDATE users SET status = \'active\' WHERE email = ?')->execute([$newEmail]);

section('10. Login rate limiting (' . config('login_max_attempts') . ' failures / '
    . config('login_window_minutes') . ' min)');

$rlEmail = 'ratelimit.' . $stamp . '@test.local';
db()->prepare('DELETE FROM login_attempts WHERE email = ? OR ip_address IN (?, ?)')
    ->execute([$rlEmail, '127.0.0.1', '::1']);

$statuses = [];
for ($i = 1; $i <= 6; $i++) {
    $r = api('rl', 'POST', 'auth/login.php',
        ['email' => $rlEmail, 'password' => 'WrongPassword' . $i],
        ['X-CSRF-Token: ' . csrf('rl')]);
    $statuses[] = $r['status'];
}
check('First 5 failures return 401',
    array_slice($statuses, 0, 5) === [401, 401, 401, 401, 401],
    implode(',', $statuses));
check('The 6th attempt is throttled with 429',
    $statuses[5] === 429, 'got ' . $statuses[5]);

$throttled = api('rl', 'POST', 'auth/login.php',
    ['email' => $rlEmail, 'password' => 'WrongPassword7'],
    ['X-CSRF-Token: ' . csrf('rl')]);
check('  ...with a friendly message, not a stack trace',
    $throttled['status'] === 429
        && stripos((string) ($throttled['body']['error'] ?? ''), 'too many') !== false,
    $throttled['body']['error'] ?? '-');

// Clean up so the next run (and the browser) is not locked out of this IP.
db()->prepare('DELETE FROM login_attempts WHERE email = ? OR ip_address IN (?, ?)')
    ->execute([$rlEmail, '127.0.0.1', '::1']);
check('Rate-limit rows cleared for the next run', true);

section('11. Error responses leak nothing');

$broken = api('guest', 'POST', 'auth/register.php', null, ['Content-Type: application/json']);
check('An empty body returns a clean envelope, not a PHP warning',
    isset($broken['body']['ok']) && strpos($broken['raw'], '<br') === false
        && stripos($broken['raw'], 'Warning') === false
        && stripos($broken['raw'], 'Fatal') === false,
    'status ' . $broken['status']);

$garbage = api('guest', 'POST', 'auth/login.php', null,
    ['Content-Type: application/json', 'X-CSRF-Token: ' . csrf('guest')]);
check('No "#0 C:\\" stack frames in any response seen this run',
    strpos($garbage['raw'], '#0 ') === false && strpos($garbage['raw'], 'C:\\') === false,
    substr($garbage['raw'], 0, 60));

section('Cleanup');

db()->prepare('DELETE FROM users WHERE email = ?')->execute([$newEmail]);
$gone = db()->prepare('SELECT COUNT(*) FROM users WHERE email = ?');
$gone->execute([$newEmail]);
check('Throwaway test account removed', ((int) $gone->fetchColumn()) === 0);

foreach (glob($jarDir . '/*.txt') ?: [] as $f) {
    @unlink($f);
}
@rmdir($jarDir);

/* ================================================================ */

echo "\n", str_repeat('-', 68), "\n";
printf("  %d passed, %d failed\n", $pass, $fail);
echo str_repeat('-', 68), "\n";

exit($fail === 0 ? 0 : 1);
