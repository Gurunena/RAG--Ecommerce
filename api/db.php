<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        config('db_host'),
        (int) config('db_port'),
        config('db_name')
    );

    $pdo = new PDO($dsn, (string) config('db_user'), (string) config('db_pass'), [
        // Any SQL problem becomes an exception the endpoint's try/catch can log,
        // instead of a silently false return value that corrupts data later.
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        // Real server-side prepares. With emulation on, PDO interpolates the
        // values itself, which is both slower here and the thing we are
        // claiming not to do.
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::ATTR_STRINGIFY_FETCHES  => false,
        // One connection per request; no persistence, so a transaction can
        // never leak into the next visitor's request.
        PDO::ATTR_PERSISTENT         => false,
    ]);

    // Make MariaDB strict for this connection: a too-long string or a bad
    // date becomes an error, not a silently truncated row.
    $pdo->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
    $pdo->exec("SET SESSION time_zone = '+05:30'");  // Asia/Colombo: NOW() matches the shop's clock

    return $pdo;
}

function db_transaction(callable $fn)
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $result = $fn($pdo);
        $pdo->commit();
        return $result;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}
