<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit("This is a command-line script and will not run over HTTP.\n");
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/risk_ml.php';

date_default_timezone_set('Asia/Colombo');

$outPath = dirname(__DIR__, 2) . '/data/risk_training.csv';
$limit   = 0;

foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--out=')) {
        $outPath = substr($arg, 6);
    } elseif (str_starts_with($arg, '--limit=')) {
        $limit = max(0, (int) substr($arg, 8));
    } elseif ($arg === '--help' || $arg === '-h') {
        echo "Export order features to CSV for the anomaly model.\n\n";
        echo "  --out=PATH    where to write (default data/risk_training.csv)\n";
        echo "  --limit=N     only the N most recent orders\n";
        exit(0);
    } else {
        fwrite(STDERR, "Unknown argument: $arg (try --help)\n");
        exit(1);
    }
}

$db = db();

echo str_repeat('=', 72), "\n";
echo "  Risk Radar — training feature export\n";
echo str_repeat('=', 72), "\n\n";

$sql = risk_feature_sql(false);

if ($limit > 0) {
    $sql .= ' LIMIT ' . (int) $limit;       // an integer this script controls
}

$start = microtime(true);
echo "  querying…\n";

try {
    $rows = $db->query($sql)->fetchAll();
} catch (Throwable $e) {
    fwrite(STDERR, "Query failed: " . $e->getMessage() . "\n");
    exit(1);
}

if (!$rows) {
    fwrite(STDERR, "No orders found. Run api\\tools\\generate_demo_orders.php first.\n");
    exit(1);
}

const FEATURE_COLUMNS = RISK_FEATURE_COLUMNS;

$dir = dirname($outPath);
if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
    fwrite(STDERR, "Cannot create $dir\n");
    exit(1);
}

$handle = @fopen($outPath, 'w');
if ($handle === false) {
    fwrite(STDERR, "Cannot write $outPath\n");
    exit(1);
}

fputcsv($handle, array_merge(['order_id'], FEATURE_COLUMNS, ['label']));

$labelCounts = [];
$written = 0;

foreach ($rows as $row) {
    $line = [$row['order_id']];

    foreach (FEATURE_COLUMNS as $column) {
        $value = $row[$column];
        $line[] = $value === null ? '0' : (string) (0 + $value);
    }

    $label = (string) $row['label'];
    $line[] = $label;
    $labelCounts[$label === '' ? '(normal)' : $label] = ($labelCounts[$label === '' ? '(normal)' : $label] ?? 0) + 1;

    fputcsv($handle, $line);
    $written++;
}

fclose($handle);

printf("\n  %s rows written to %s (%.1f KB) in %.1fs\n",
    number_format($written), $outPath, filesize($outPath) / 1024, microtime(true) - $start);

echo "\n  features (" . count(FEATURE_COLUMNS) . "):\n";
foreach (FEATURE_COLUMNS as $i => $column) {
    printf("    %d. %s\n", $i + 1, $column);
}

echo "\n  label distribution:\n";
ksort($labelCounts);
foreach ($labelCounts as $label => $count) {
    printf("    %-26s %6s  %5.1f%%\n", $label, number_format($count), $count / $written * 100);
}

$anomalies = $written - ($labelCounts['(normal)'] ?? 0);
printf("\n  %s labelled anomalies (%.1f%% of rows)\n", number_format($anomalies), $anomalies / $written * 100);

if ($anomalies === 0) {
    echo "\n  ! No labelled anomalies. The evaluation in train_anomaly.py will have\n";
    echo "    nothing to measure against. Run generate_demo_orders.php first.\n";
}

echo "\n  feature ranges:\n";
foreach (FEATURE_COLUMNS as $column) {
    $values = array_map(static fn(array $r): float => (float) $r[$column], $rows);
    $min = min($values);
    $max = max($values);
    printf(
        "    %-24s %12s .. %-14s %s\n",
        $column,
        is_float($min) && $min != (int) $min ? number_format($min, 3) : number_format($min),
        is_float($max) && $max != (int) $max ? number_format($max, 3) : number_format($max),
        abs($max - $min) < 1e-9 ? '  ! constant — carries no information' : ''
    );
}

echo "\n", str_repeat('-', 72), "\n";
echo "  Next:\n";
echo "    cd C:\\E-commerce\\ai_service\n";
echo "    venv\\Scripts\\python.exe risk\\train_anomaly.py\n";
echo str_repeat('-', 72), "\n";
echo "\n  *** The `label` column is DEMO DATA: anomalies this project planted,\n";
echo "      not observed fraud. ***\n\n";

exit(0);
