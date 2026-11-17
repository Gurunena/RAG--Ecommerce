<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit("This is a command-line script and will not run over HTTP.\n");
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';

date_default_timezone_set('Asia/Colombo');

$dryRun = false;
$outPath = APP_ROOT . '/data/products.json';

foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--dry-run' || $arg === '-n') {
        $dryRun = true;
    } elseif (str_starts_with($arg, '--out=')) {
        $outPath = substr($arg, 6);
    } elseif ($arg === '--help' || $arg === '-h') {
        echo "Regenerate data/products.json from MySQL.\n\n";
        echo "  --dry-run, -n   show what would be written, write nothing\n";
        echo "  --out=PATH      write somewhere else\n";
        exit(0);
    } else {
        fwrite(STDERR, "Unknown argument: $arg (try --help)\n");
        exit(1);
    }
}

echo str_repeat('=', 68), "\n";
echo "  Exporting the AI catalogue snapshot from MySQL\n";
echo str_repeat('=', 68), "\n\n";

try {
    db()->query('SELECT 1');
} catch (Throwable $e) {
    fwrite(STDERR, "Cannot reach MySQL: " . $e->getMessage() . "\n");
    fwrite(STDERR, "Start MySQL in the XAMPP Control Panel and try again.\n");
    exit(1);
}

$sellerStmt = db()->query(
    "SELECT s.public_id, s.business_name, s.city, s.rating,
            (s.kyc_status = 'verified') AS verified
       FROM sellers s
      WHERE s.public_id IS NOT NULL
        AND EXISTS (
            SELECT 1 FROM products p
             WHERE p.seller_id = s.id
               AND p.status = 'active'
               AND p.deleted_at IS NULL
        )
   ORDER BY s.public_id"
);

$sellers = [];
foreach ($sellerStmt as $row) {
    $sellers[] = [
        'id'       => $row['public_id'],
        'name'     => $row['business_name'],
        'city'     => $row['city'] ?? '',
        'rating'   => (float) $row['rating'],
        'verified' => (bool) $row['verified'],
    ];
}

printf("  sellers with live listings : %d\n", count($sellers));

$productStmt = db()->query(
    "SELECT p.id, p.public_id, p.category, p.icon, p.title, p.subtitle,
            p.description, p.price, p.old_price, p.currency, p.stock,
            p.specs, p.rating_breakdown, p.badge, p.rating, p.review_count,
            s.public_id AS seller_public_id,
            (SELECT ps.sale_price
               FROM product_sales ps
              WHERE ps.product_id = p.id
                AND CURDATE() BETWEEN ps.start_date AND ps.end_date
           ORDER BY ps.sale_price ASC
              LIMIT 1) AS sale_price
       FROM products p
       JOIN sellers s ON s.id = p.seller_id
      WHERE p.status = 'active'
        AND p.deleted_at IS NULL
        AND s.public_id IS NOT NULL
   ORDER BY CAST(SUBSTRING(p.public_id, 2) AS UNSIGNED), p.id"
);

$productRows = $productStmt->fetchAll();
$productIds  = array_map(static fn(array $r): int => (int) $r['id'], $productRows);

/* ---------- the child rows, one query each rather than per product ---------- */

/** [productId => ['images/a.webp', ...]] */
function export_images(array $ids): array
{
    if (!$ids) {
        return [];
    }
    $in = implode(',', array_fill(0, count($ids), '?'));
    $stmt = db()->prepare(
        "SELECT product_id, image_url FROM product_images
          WHERE product_id IN ($in) ORDER BY product_id, sort_order, id"
    );
    $stmt->execute($ids);

    $out = [];
    foreach ($stmt as $row) {
        $out[(int) $row['product_id']][] = $row['image_url'];
    }
    return $out;
}

/** [productId => ['programming', ...]] */
function export_tags(array $ids): array
{
    if (!$ids) {
        return [];
    }
    $in = implode(',', array_fill(0, count($ids), '?'));
    $stmt = db()->prepare(
        "SELECT product_id, tag FROM product_tags
          WHERE product_id IN ($in) ORDER BY product_id, id"
    );
    $stmt->execute($ids);

    $out = [];
    foreach ($stmt as $row) {
        $out[(int) $row['product_id']][] = $row['tag'];
    }
    return $out;
}

function export_reviews(array $ids): array
{
    if (!$ids) {
        return [];
    }
    $in = implode(',', array_fill(0, count($ids), '?'));
    $stmt = db()->prepare(
        "SELECT r.product_id, r.rating, r.review_text, r.is_verified_buyer,
                r.helpful_count, DATE(r.created_at) AS created_on, u.name
           FROM reviews r
           JOIN users u ON u.id = r.user_id
          WHERE r.product_id IN ($in)
       ORDER BY r.product_id, r.helpful_count DESC, r.created_at DESC"
    );
    $stmt->execute($ids);

    $out = [];
    foreach ($stmt as $row) {
        $parts = preg_split('/\s+/', trim((string) $row['name'])) ?: [];
        $display = $parts[0] ?? 'A shopper';
        if (count($parts) > 1) {
            $display .= ' ' . mb_strtoupper(mb_substr(end($parts), 0, 1)) . '.';
        }

        $out[(int) $row['product_id']][] = [
            'name'     => $display,
            'rating'   => (int) $row['rating'],
            'date'     => $row['created_on'],
            'verified' => (bool) $row['is_verified_buyer'],
            'helpful'  => (int) $row['helpful_count'],
            'text'     => (string) $row['review_text'],
        ];
    }
    return $out;
}

$images  = export_images($productIds);
$tags    = export_tags($productIds);
$reviews = export_reviews($productIds);

/** A JSON column → an object the file can carry, or null. */
function export_json_object(?string $raw): ?object
{
    if ($raw === null || $raw === '') {
        return null;
    }
    $decoded = json_decode($raw);
    return is_object($decoded) ? $decoded : null;
}

$products   = [];
$onSale     = 0;
$noImage    = 0;
$thinSpecs  = 0;

foreach ($productRows as $row) {
    $pid  = (int) $row['id'];
    $base = (float) $row['price'];
    $sale = $row['sale_price'] === null ? null : (float) $row['sale_price'];

    if ($sale !== null) {
        $onSale++;
    }

    $gallery = $images[$pid] ?? [];
    if (!$gallery) {
        $noImage++;
    }

    $specs = export_json_object($row['specs']);
    if ($specs === null || count((array) $specs) < 3) {
        $thinSpecs++;
    }

    $products[] = [
        'id'       => $row['public_id'],
        'sellerId' => $row['seller_public_id'],
        'category' => $row['category'],
        'icon'     => $row['icon'] ?? 'laptop',
        'title'    => $row['title'],
        'subtitle' => $row['subtitle'] ?? '',
        'image'    => $gallery[0] ?? null,

        // What a shopper pays today, with the normal price struck through —
        // the same rule the storefront applies.
        'price'    => $sale ?? $base,
        'oldPrice' => $sale !== null
            ? $base
            : ($row['old_price'] === null ? null : (float) $row['old_price']),
        'currency' => $row['currency'],
        'stock'    => (int) $row['stock'],

        'tags'  => $tags[$pid] ?? [],
        // An object, never null: main.py calls p.get("specs", {}).items().
        'specs' => $specs ?? new stdClass(),

        // products.json has always called the description `why`.
        'why' => $row['description'] ?? '',

        'rating'  => (float) $row['rating'],
        'reviews' => (int) $row['review_count'],
        'badge'   => $sale !== null ? 'deal' : $row['badge'],

        'images'           => $gallery,
        'ratingBreakdown'  => export_json_object($row['rating_breakdown']),
        'reviewsList'      => $reviews[$pid] ?? [],
    ];
}

printf("  active products            : %d\n", count($products));
printf("  …on sale today             : %d\n", $onSale);

if ($noImage) {
    printf("  !  %d product(s) have no image — the storefront falls back to an icon\n", $noImage);
}
if ($thinSpecs) {
    printf("  !  %d product(s) have fewer than 3 specs — the AI compares them poorly\n", $thinSpecs);
}

if (!$products) {
    fwrite(STDERR, "\nNo active products found. Import sql/seed.sql first, or approve some listings.\n");
    fwrite(STDERR, "Refusing to write an empty catalogue — the AI service would have nothing to answer from.\n");
    exit(1);
}

$payload = [
    'meta' => [
        'generated_at' => date('c'),
        'generated_by' => 'api/tools/export_products_json.php',
        'source'       => 'MySQL database "' . config('db_name') . '"',
        'products'     => count($products),
        'sellers'      => count($sellers),
        'note'         => 'Regenerate with: php api/tools/export_products_json.php — then RESTART the AI service, '
                        . 'which only reads this file at startup.',
    ],
    'sellers'  => $sellers,
    'products' => $products,
];

$json = json_encode(
    $payload,
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
);

if ($json === false) {
    fwrite(STDERR, "\nCould not encode the catalogue as JSON: " . json_last_error_msg() . "\n");
    exit(1);
}

echo "\n";

if ($dryRun) {
    printf("  DRY RUN — nothing written. %s would have gained %d bytes.\n", $outPath, strlen($json));
    echo "\n  First product:\n";
    echo '  ' . str_replace("\n", "\n  ", json_encode($products[0], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)), "\n";
    exit(0);
}

$dir = dirname($outPath);
if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
    fwrite(STDERR, "Cannot create $dir\n");
    exit(1);
}

if (is_file($outPath)) {
    if (!@copy($outPath, $outPath . '.bak')) {
        fwrite(STDERR, "Warning: could not write the .bak backup — continuing anyway.\n");
    } else {
        printf("  previous version kept as  : %s\n", basename($outPath) . '.bak');
    }
}

$tmp = $outPath . '.tmp';

if (@file_put_contents($tmp, $json) === false) {
    fwrite(STDERR, "Cannot write $tmp — check folder permissions.\n");
    exit(1);
}
if (!@rename($tmp, $outPath)) {
    @unlink($tmp);
    fwrite(STDERR, "Cannot move $tmp into place.\n");
    exit(1);
}

printf("  written                    : %s (%s KB)\n", $outPath, number_format(strlen($json) / 1024, 1));

echo "\n", str_repeat('-', 68), "\n";
echo "  NEXT STEP — restart the AI service, or nothing changes:\n\n";
echo "    cd C:\\E-commerce\\ai_service\n";
echo "    venv\\Scripts\\activate\n";
echo "    python -m uvicorn main:app --port 8001\n\n";
echo "  It reads this file once at startup and rebuilds its vector index\n";
echo "  from it. A new file with the old process still running does nothing.\n";
echo str_repeat('-', 68), "\n";

exit(0);
