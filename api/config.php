<?php
declare(strict_types=1);

$defaults = [
    // --- database (XAMPP MariaDB defaults) ---
    'db_host' => '127.0.0.1',
    'db_port' => 3306,
    'db_name' => 'consult',
    'db_user' => 'root',
    'db_pass' => '',

    // 'local' turns on the extra diagnostics in logs/app.log. Never 'local' on a real host.
    'app_env' => 'local',

    // --- auth rules (mirrored by the client-side validators) ---
    'password_min_length'  => 8,
    // bcrypt silently ignores anything past 72 bytes, so reject it instead of
    // letting two different long passwords unlock the same account.
    'password_max_length'  => 72,
    'login_max_attempts'   => 5,
    'login_window_minutes' => 10,

    // --- uploads (Phase 5) ---
    'upload_max_bytes' => 2 * 1024 * 1024,
    'upload_mime'      => ['image/jpeg', 'image/png', 'image/webp'],
    'upload_ext'       => ['jpg', 'jpeg', 'png', 'webp'],

    // --- the Python AI service the PHP proxy forwards to (Phase 7) ---
    // The ONLY place the port lives now. api/ai/consult.php forwards here and
    // api/ai/health.php derives /health from it, so nothing the browser is
    // served mentions 8001 and the service can move without touching any JS.
    'ai_service_url'     => 'http://localhost:8001/consult',
    // Seconds to wait for an ANSWER. A local Ollama takes ~15-20s warm and
    // can take twice that on the first call of the session while the model
    // loads, so 25 was too tight and produced spurious "taking too long"
    // errors on the first question. Being generous here costs nothing when
    // the service is OFF: consult.php uses a separate 3-second connect
    // timeout, and "nothing is listening on that port" is answered by the OS
    // almost instantly.
    'ai_service_timeout' => 45,

    // --- Risk Radar's optional ML endpoints (Phase C) ---
    // Separate keys from ai_service_url, not derived from it, because these
    // are a different kind of call with a different cost of being slow.
    // /consult is a person waiting for an answer and can afford 45 seconds;
    // these run while an order is being scored, so the timeout is short and
    // a miss is free — api/lib/risk_ml.php returns null and the PHP rule
    // score stands on its own.
    'risk_ml_url'      => 'http://localhost:8001/risk/score',
    'risk_explain_url' => 'http://localhost:8001/risk/explain',
    // 8 seconds for an ANSWER. Scoring itself is a millisecond of NumPy;
    // the rephrasing call is an LLM and is the one that can be slow, and
    // 8 seconds is the point past which a stiffer-but-instant template
    // sentence is simply the better outcome. risk_ml.php uses its own
    // 2-second connect timeout on top, because the usual reason for a miss
    // is that nobody started the Python service.
    'risk_ai_timeout'  => 8,

    // Own cookie name so this app's session never collides with another
    // project sharing htdocs.
    'session_name' => 'CONSULTSESSID',
];

$local = [];
if (is_file(__DIR__ . '/config.local.php')) {
    $loaded = require __DIR__ . '/config.local.php';
    if (is_array($loaded)) {
        $local = $loaded;
    }
}

$GLOBALS['CONSULT_CONFIG'] = array_replace($defaults, $local);

/** Read one configuration value. */
function config(string $key, $fallback = null)
{
    return $GLOBALS['CONSULT_CONFIG'][$key] ?? $fallback;
}

define('APP_ROOT',   dirname(__DIR__));          // consult-project/
define('API_ROOT',   __DIR__);                   // consult-project/api/
define('LOG_DIR',    APP_ROOT . '/logs');
define('LOG_FILE',   LOG_DIR . '/app.log');
define('UPLOAD_DIR', APP_ROOT . '/uploads');
define('UPLOAD_URL', 'uploads');                 // relative to the site root, for the browser
