<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

function app_log(string $message, array $context = []): void
{
    if (!is_dir(LOG_DIR)) {
        @mkdir(LOG_DIR, 0775, true);
    }
    $line = sprintf(
        "[%s] %s %s %s%s\n",
        date('Y-m-d H:i:s'),
        $_SERVER['REQUEST_METHOD'] ?? 'CLI',
        $_SERVER['REQUEST_URI'] ?? '-',
        $message,
        $context ? ' ' . json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : ''
    );
    @file_put_contents(LOG_FILE, $line, FILE_APPEND | LOCK_EX);
}

function json_ok($data = null, int $status = 200): void
{
    if (!headers_sent()) {
        http_response_code($status);
    }
    echo json_encode(
        ['ok' => true, 'data' => $data],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}

function json_fail(string $message, string $code = 'SERVER', int $status = 500, array $extra = []): void
{
    if (!headers_sent()) {
        http_response_code($status);
    }
    echo json_encode(
        array_merge(['ok' => false, 'error' => $message, 'code' => $code], $extra),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}

function fail_validation(array $fields, string $message = 'Please fix the highlighted fields.'): void
{
    json_fail($message, 'VALIDATION', 422, ['fields' => $fields]);
}

function require_method(string ...$allowed): void
{
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($method === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
    if (!in_array($method, $allowed, true)) {
        header('Allow: ' . implode(', ', $allowed));
        json_fail(
            'This endpoint does not accept ' . $method . ' requests.',
            'VALIDATION',
            405
        );
    }
}

/** The decoded JSON request body (falls back to form-encoded POST). */
function json_body(): array
{
    static $body = null;
    if ($body !== null) {
        return $body;
    }

    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        return $body = $_POST ?: [];
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        json_fail('The request body must be a JSON object.', 'VALIDATION', 400);
    }
    return $body = $decoded;
}

/** One trimmed string field out of the body. */
function body_str(string $key, string $default = ''): string
{
    $v = json_body()[$key] ?? $default;
    if (is_int($v) || is_float($v)) {
        $v = (string) $v;
    }
    return is_string($v) ? trim($v) : $default;
}

/** One raw (untrimmed) field — passwords must keep their spaces. */
function body_raw(string $key, string $default = ''): string
{
    $v = json_body()[$key] ?? $default;
    return is_string($v) ? $v : $default;
}

function body_bool(string $key): bool
{
    $v = json_body()[$key] ?? false;
    return $v === true || $v === 1 || $v === '1' || $v === 'true' || $v === 'on';
}

/** One trimmed string from the query string. */
function query_str(string $key, string $default = ''): string
{
    $v = $_GET[$key] ?? $default;
    return is_string($v) ? trim($v) : $default;
}

function query_int(string $key, ?int $default = null, ?int $min = null, ?int $max = null): ?int
{
    $raw = $_GET[$key] ?? null;
    if ($raw === null || $raw === '' || !is_numeric($raw)) {
        return $default;
    }
    $n = (int) $raw;
    if ($min !== null && $n < $min) {
        $n = $min;
    }
    if ($max !== null && $n > $max) {
        $n = $max;
    }
    return $n;
}

/** One float from the query string (prices), or $default. */
function query_float(string $key, ?float $default = null): ?float
{
    $raw = $_GET[$key] ?? null;
    if ($raw === null || $raw === '' || !is_numeric($raw)) {
        return $default;
    }
    return (float) $raw;
}

/** One integer from the request body, clamped the same way. */
function body_int(string $key, ?int $default = null, ?int $min = null, ?int $max = null): ?int
{
    $raw = json_body()[$key] ?? null;
    if ($raw === null || $raw === '' || !is_numeric($raw)) {
        return $default;
    }
    $n = (int) $raw;
    if ($min !== null && $n < $min) {
        $n = $min;
    }
    if ($max !== null && $n > $max) {
        $n = $max;
    }
    return $n;
}

function client_ip(): string
{
    // No proxy in front of XAMPP, so REMOTE_ADDR is the only value worth
    // trusting. X-Forwarded-For is deliberately ignored: a client can forge
    // it, and that would let an attacker sidestep the login rate limit.
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    return substr($ip, 0, 45);
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_check(): void
{
    $sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if ($sent === '') {
        $body = json_body();
        $sent = is_string($body['csrf_token'] ?? null) ? $body['csrf_token'] : '';
    }

    if ($sent === '' || empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $sent)) {
        app_log('CSRF check failed', ['ip' => client_ip()]);
        json_fail(
            'Your session has expired. Refresh the page and try again.',
            'FORBIDDEN',
            403
        );
    }
}

/** Give the signed-in user a brand-new session id — kills session fixation. */
function session_promote(int $userId, string $role): void
{
    session_regenerate_id(true);
    $_SESSION['uid']        = $userId;
    $_SESSION['role']       = $role;
    $_SESSION['started_at'] = time();
    // New session id, new CSRF token.
    unset($_SESSION['csrf']);
    csrf_token();
}

function session_kill(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires'  => time() - 42000,
            'path'     => $params['path'],
            'domain'   => $params['domain'],
            'secure'   => $params['secure'],
            'httponly' => $params['httponly'],
            'samesite' => $params['samesite'] ?? 'Lax',
        ]);
    }
    @session_destroy();
}

function current_user(bool $refresh = false): ?array
{
    static $cached = false;
    static $user = null;

    if ($cached && !$refresh) {
        return $user;
    }
    $cached = true;
    $user = null;

    if (empty($_SESSION['uid'])) {
        return null;
    }

    $stmt = db()->prepare(
        'SELECT id, name, email, phone, role, status, avatar_url, created_at, last_login_at
           FROM users
          WHERE id = ?'
    );
    $stmt->execute([(int) $_SESSION['uid']]);
    $row = $stmt->fetch();

    if (!$row) {
        // The account was deleted under us.
        session_kill();
        return null;
    }

    // Keep the session's copy of the role honest if an admin changed it.
    $_SESSION['role'] = $row['role'];
    return $user = $row;
}

/** 401 unless somebody is signed in. Returns the user row. */
function require_login(): array
{
    $user = current_user();
    if (!$user) {
        json_fail('Please sign in to continue.', 'AUTH', 401);
    }
    if ($user['status'] === 'suspended') {
        session_kill();
        json_fail('This account has been suspended. Contact support.', 'AUTH', 401);
    }
    return $user;
}

function require_role(string ...$roles): array
{
    $user = require_login();
    if (!in_array($user['role'], $roles, true)) {
        app_log('Role check failed', [
            'user'  => $user['id'],
            'has'   => $user['role'],
            'needs' => implode('|', $roles),
        ]);
        json_fail('Your account does not have access to this.', 'FORBIDDEN', 403);
    }
    return $user;
}

function public_user(array $row): array
{
    $user = [
        'id'         => (int) $row['id'],
        'name'       => $row['name'],
        'email'      => $row['email'],
        'phone'      => $row['phone'],
        'role'       => $row['role'],
        'status'     => $row['status'],
        'avatar_url' => $row['avatar_url'],
        'created_at' => $row['created_at'],
        'seller'     => null,
    ];

    if ($row['role'] !== 'seller') {
        return $user;
    }

    $stmt = db()->prepare(
        'SELECT public_id, business_name, business_type, registration_no, city, district,
                primary_category, bank_name, account_number, branch,
                kyc_status, kyc_rejection_reason, kyc_reviewed_at, commission_rate, rating
           FROM sellers
          WHERE id = ?'
    );
    $stmt->execute([(int) $row['id']]);
    $seller = $stmt->fetch();

    if ($seller) {
        $seller['commission_rate'] = $seller['commission_rate'] === null
            ? null
            : (float) $seller['commission_rate'];
        $seller['rating']   = (float) $seller['rating'];
        // Convenience flag the nav and product cards already read.
        $seller['verified'] = $seller['kyc_status'] === 'verified';
        $user['seller'] = $seller;
    }

    return $user;
}

function invalid_name(string $value, string $label = 'Full name'): ?string
{
    if ($value === '') {
        return $label . ' is required';
    }
    if (mb_strlen($value) < 2) {
        return $label . ' is too short';
    }
    if (mb_strlen($value) > 120) {
        return $label . ' must be 120 characters or fewer';
    }
    return null;
}

function invalid_email(string $value): ?string
{
    if ($value === '') {
        return 'Email is required';
    }
    if (mb_strlen($value) > 150) {
        return 'Email must be 150 characters or fewer';
    }
    if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
        return 'Enter a valid email address';
    }
    return null;
}

function invalid_password(string $value, string $label = 'Password'): ?string
{
    $min = (int) config('password_min_length');
    $max = (int) config('password_max_length');

    if ($value === '') {
        return $label . ' is required';
    }
    if (mb_strlen($value) < $min) {
        return $label . ' must be at least ' . $min . ' characters';
    }
    if (strlen($value) > $max) {
        // Byte length: bcrypt stops at 72 bytes, so anything longer would be
        // accepted here and then not actually be checked in full.
        return $label . ' must be ' . $max . ' characters or fewer';
    }
    return null;
}

function normalize_phone_lk(string $value): ?string
{
    $digits = preg_replace('/[\s\-().]/', '', $value) ?? '';

    if (preg_match('/^\+?94(\d{9})$/', $digits, $m)) {
        return '0' . $m[1];
    }
    if (preg_match('/^0(\d{9})$/', $digits, $m)) {
        return '0' . $m[1];
    }
    return null;
}

function invalid_phone(string $value, bool $required = true): ?string
{
    if ($value === '') {
        return $required ? 'Phone number is required' : null;
    }
    if (normalize_phone_lk($value) === null) {
        return 'Enter a valid 10-digit Sri Lankan number (07X XXX XXXX or 011 XXX XXXX)';
    }
    return null;
}

/** Drop the nulls so only real errors reach fail_validation(). */
function collect_errors(array $candidates): array
{
    return array_filter($candidates, static function ($v) {
        return $v !== null;
    });
}

function setting(string $key, $fallback = null)
{
    static $cache = [];

    if ($key === '') {
        $cache = [];
        return null;
    }

    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    $stmt = db()->prepare('SELECT value FROM settings WHERE setting_key = ?');
    $stmt->execute([$key]);
    $raw = $stmt->fetchColumn();

    if ($raw === false) {
        return $cache[$key] = $fallback;
    }
    $decoded = json_decode((string) $raw, true);
    return $cache[$key] = ($decoded === null && json_last_error() !== JSON_ERROR_NONE)
        ? $fallback
        : $decoded;
}

function setting_reset(): void
{
    setting('');
}

function login_is_rate_limited(string $email, string $ip): bool
{
    $max    = (int) config('login_max_attempts');
    $window = (int) config('login_window_minutes');

    // INTERVAL ? MINUTE cannot be parameterised on MariaDB, so the window
    // comes in as an integer cast from config — never from the request.
    $sql = 'SELECT MAX(hits) FROM (
                SELECT COUNT(*) AS hits FROM login_attempts
                 WHERE email = ? AND attempted_at > (NOW() - INTERVAL ' . $window . ' MINUTE)
                UNION ALL
                SELECT COUNT(*) AS hits FROM login_attempts
                 WHERE ip_address = ? AND attempted_at > (NOW() - INTERVAL ' . $window . ' MINUTE)
            ) AS counted';

    $stmt = db()->prepare($sql);
    $stmt->execute([$email, $ip]);

    return ((int) $stmt->fetchColumn()) >= $max;
}

function login_record_failure(string $email, string $ip): void
{
    $stmt = db()->prepare('INSERT INTO login_attempts (email, ip_address) VALUES (?, ?)');
    $stmt->execute([mb_substr($email, 0, 150), $ip]);

    // Keep the table from growing forever; nothing older than a day matters.
    db()->exec('DELETE FROM login_attempts WHERE attempted_at < (NOW() - INTERVAL 1 DAY)');
}

function login_clear_failures(string $email): void
{
    $stmt = db()->prepare('DELETE FROM login_attempts WHERE email = ?');
    $stmt->execute([$email]);
}
