<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

// XAMPP's php.ini leaves date.timezone on UTC, so without this logs/app.log
// would be stamped 5.5 hours behind every row MySQL writes (db.php puts the
// connection on +05:30). Matching them is what makes "find the log line for
// this order" possible.
date_default_timezone_set('Asia/Colombo');

if (!is_dir(LOG_DIR)) {
    @mkdir(LOG_DIR, 0775, true);
}
ini_set('error_log', LOG_FILE);

// A notice or warning becomes an exception, so a typo'd array key can never
// quietly half-complete a write.
set_error_handler(static function (int $no, string $msg, string $file = '', int $line = 0) {
    if (!(error_reporting() & $no)) {
        return false;   // silenced with @ — leave it alone
    }
    throw new ErrorException($msg, 0, $no, $file, $line);
});

// Anything that escapes an endpoint's own try/catch still returns the
// envelope, with the detail going to the log instead of the client.
set_exception_handler(static function (Throwable $e) {
    app_log(sprintf(
        'UNCAUGHT %s: %s @ %s:%d',
        get_class($e),
        $e->getMessage(),
        $e->getFile(),
        $e->getLine()
    ), ['trace' => config('app_env') === 'local' ? $e->getTraceAsString() : null]);

    json_fail('Something went wrong on our side. Please try again.', 'SERVER', 500);
});

// set_exception_handler cannot catch a fatal (out of memory, parse error in an
// included file), so catch it on the way out and still answer with JSON.
register_shutdown_function(static function () {
    $last = error_get_last();
    $fatal = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];

    if ($last && in_array($last['type'], $fatal, true)) {
        app_log(sprintf('FATAL %s @ %s:%d', $last['message'], $last['file'], $last['line']));
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode([
            'ok'    => false,
            'error' => 'Something went wrong on our side. Please try again.',
            'code'  => 'SERVER',
        ]);
    }
});

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');          // no MIME sniffing our JSON into HTML
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Referrer-Policy: same-origin');
header('X-Frame-Options: DENY');
header_remove('X-Powered-By');                      // don't advertise the PHP version

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '') {
    $host       = strtolower($_SERVER['HTTP_HOST'] ?? '');
    $originHost = strtolower((string) parse_url($origin, PHP_URL_HOST));
    $originPort = parse_url($origin, PHP_URL_PORT);
    $originHostPort = $originHost . ($originPort ? ':' . $originPort : '');

    // Treat a bare host and host:80 as the same place.
    $normalise = static function (string $v): string {
        return preg_replace('/:80$/', '', $v) ?? $v;
    };

    if ($normalise($originHostPort) !== $normalise($host)) {
        app_log('Blocked cross-origin request', ['origin' => $origin, 'host' => $host]);
        json_fail('Cross-origin requests are not allowed.', 'FORBIDDEN', 403);
    }
}

/** '/consult-project/' for a script at '/consult-project/api/auth/login.php'. */
function app_base_path(): string
{
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '/');
    $pos    = strpos($script, '/api/');
    return $pos === false ? '/' : substr($script, 0, $pos + 1);
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    ini_set('session.use_only_cookies', '1');
    // Refuse a session id the server never issued, so an attacker cannot
    // plant one in the victim's browser and then reuse it after they log in.
    ini_set('session.use_strict_mode', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.gc_maxlifetime', '7200');

    session_name((string) config('session_name'));
    session_set_cookie_params([
        'lifetime' => 0,                                  // dies with the browser session
        'path'     => app_base_path(),
        'domain'   => '',
        'secure'   => !empty($_SERVER['HTTPS']),          // set this true behind HTTPS
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    if (!session_start()) {
        // Almost always an unwritable session.save_path.
        app_log('session_start() failed', ['save_path' => session_save_path()]);
        json_fail('Sessions are not working on this server. Check session.save_path.', 'SERVER', 500);
    }
}
