<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit("This is a command-line script and will not run over HTTP.\n");
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../helpers.php';

require_once __DIR__ . '/../lib/risk_sweep_lib.php';

$hours   = SWEEP_DEFAULT_HOURS;
$orders  = true;
$sellers = true;
$buyers  = true;
$force   = false;
$dryRun  = false;
$quiet   = false;

foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--hours=')) {
        $hours = (int) substr($arg, 8);
    } elseif ($arg === '--orders') {
        $sellers = $buyers = false;
    } elseif ($arg === '--no-orders') {
        $orders = false;
    } elseif ($arg === '--no-sellers') {
        $sellers = false;
    } elseif ($arg === '--no-buyers') {
        $buyers = false;
    } elseif ($arg === '--force') {
        $force = true;
    } elseif ($arg === '--dry-run') {
        $dryRun = true;
    } elseif ($arg === '--quiet' || $arg === '-q') {
        $quiet = true;
    } elseif ($arg === '--help' || $arg === '-h') {
        echo <<<'HELP'
Re-score recent orders, sellers and buyers.

  --hours=N      window to re-score (default 48)
  --orders       orders only, skip sellers and buyers
  --no-orders    skip orders
  --no-sellers   skip the seller-level scorer
  --no-buyers    skip the buyer-level scorer
  --force        re-score orders that already have a score
  --dry-run      count what would be scored, write nothing
  --quiet        one summary line only (for Task Scheduler)

SCHEDULING IT ON WINDOWS, EVERY 15 MINUTES

  Run this once, in an ADMINISTRATOR Command Prompt (one line):

    schtasks /Create /TN "Consult Risk Sweep" /SC MINUTE /MO 15 ^
      /TR "C:\xampp\php\php.exe C:\E-commerce\consult-project\api\tools\risk_sweep.php --quiet" ^
      /RL LIMITED /F

  Then check it and run it once by hand:

    schtasks /Query /TN "Consult Risk Sweep"
    schtasks /Run   /TN "Consult Risk Sweep"

  To see what it did, redirect to a log instead:

    schtasks /Create /TN "Consult Risk Sweep" /SC MINUTE /MO 15 ^
      /TR "cmd /c C:\xampp\php\php.exe C:\E-commerce\consult-project\api\tools\risk_sweep.php --quiet >> C:\E-commerce\risk_sweep.log 2>&1" ^
      /RL LIMITED /F

  To remove it:

    schtasks /Delete /TN "Consult Risk Sweep" /F

  Notes that will save you time:

  * USE THE FULL PATH TO php.exe. A scheduled task does not inherit your
    PATH, so a bare `php` fails with no useful message.
  * /RL LIMITED, not /RL HIGHEST. This needs no privileges at all, and a
    scheduled task running as admin is worth avoiding on principle.
  * MySQL MUST BE RUNNING. If XAMPP is stopped the sweep exits non-zero and
    Task Scheduler records "last result 0x1". That is correct behaviour,
    not a bug — but it is why the window is 48 hours and not 24: a few
    hours of downtime cannot leave a permanent hole.
  * The task runs whether or not anyone is logged in only if you create it
    with /RU and a stored password. Without that it runs on your session,
    which is fine for a development machine.

HELP;
        exit(0);
    } else {
        fwrite(STDERR, "Unknown argument: $arg (try --help)\n");
        exit(1);
    }
}

try {
    $stats = risk_sweep_run([
        'hours'   => $hours,
        'orders'  => $orders,
        'sellers' => $sellers,
        'buyers'  => $buyers,
        'force'   => $force,
        'dry_run' => $dryRun,
    ]);
} catch (Throwable $e) {
    fwrite(STDERR, 'Sweep failed: ' . $e->getMessage() . "\n");
    app_log('risk_sweep.php failed: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    exit(1);
}

if ($quiet) {
    printf(
        "[%s] scored %d, skipped %d, failed %d | alerts %d, holds %d | sellers %d, buyers %d | %.2fs%s\n",
        $stats['as_of'],
        $stats['orders_scored'],
        $stats['orders_skipped'],
        $stats['orders_failed'],
        $stats['alerts_raised'],
        $stats['payouts_held'],
        $stats['sellers_checked'],
        $stats['buyers_checked'],
        $stats['seconds'],
        $stats['dry_run'] ? ' (dry run)' : ''
    );
    exit($stats['orders_failed'] > 0 ? 1 : 0);
}

$rule = str_repeat('=', 72);
echo $rule, "\n  Risk Radar — sweep\n", $rule, "\n\n";

printf("  window        last %d hours (as of %s, from the DB clock)\n", $stats['window_hours'], $stats['as_of']);
printf("  took          %.2fs\n", $stats['seconds']);
if ($stats['dry_run']) {
    echo "  MODE          DRY RUN — nothing was written\n";
}

echo "\n  orders\n";
printf("    examined    %d\n", $stats['orders_examined']);
printf("    scored      %d\n", $stats['orders_scored']);
printf("    skipped     %d  (already scored; --force to re-score)\n", $stats['orders_skipped']);
printf("    failed      %d\n", $stats['orders_failed']);

if ($stats['orders_scored'] > 0) {
    echo "\n  levels of the newly scored\n";
    foreach ($stats['by_level'] as $level => $count) {
        printf("    %-10s  %d\n", $level, $count);
    }
}

echo "\n  outcomes\n";
printf("    alerts raised   %d\n", $stats['alerts_raised']);
printf("    payouts held    %d sub-order(s)\n", $stats['payouts_held']);
printf("    sellers checked %d\n", $stats['sellers_checked']);
printf("    buyers checked  %d\n", $stats['buyers_checked']);

if ($stats['orders_scored'] > 0 || $stats['ml_missing'] > 0) {
    echo "\n  the model\n";
    printf("    blended with an ML score   %d\n", $stats['ml_used']);
    printf("    rule score only            %d\n", $stats['ml_missing']);
    if ($stats['ml_missing'] > 0 && $stats['ml_used'] === 0) {
        echo "    (the Python service is not answering — this is a supported state,\n";
        echo "     and the rule engine scored every order on its own)\n";
    }
}

if ($stats['errors']) {
    echo "\n  first few problems\n";
    foreach ($stats['errors'] as $error) {
        echo '    - ', $error, "\n";
    }
}

echo "\n", str_repeat('-', 72), "\n";
echo "  Nothing was cancelled, refunded or suspended. A hold delays an escrow\n";
echo "  payout that had not been released yet, and an admin releases it.\n";
echo str_repeat('-', 72), "\n\n";

exit($stats['orders_failed'] > 0 ? 1 : 0);
