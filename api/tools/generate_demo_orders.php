<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit("This is a command-line script and will not run over HTTP.\n");
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../catalog.php';

date_default_timezone_set('Asia/Colombo');

$normalOrders   = 1500;
$perAnomaly     = 40;
$days           = 90;
$clear          = false;
$listBatches    = false;
$clearBatch     = null;

foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--clear') {
        $clear = true;
    } elseif ($arg === '--list') {
        $listBatches = true;
    } elseif (str_starts_with($arg, '--clear=')) {
        $clear = true;
        $clearBatch = substr($arg, 8);
    } elseif (str_starts_with($arg, '--orders=')) {
        $normalOrders = max(0, (int) substr($arg, 9));
    } elseif (str_starts_with($arg, '--anomalies=')) {
        $perAnomaly = max(0, (int) substr($arg, 12));
    } elseif (str_starts_with($arg, '--days=')) {
        $days = max(7, (int) substr($arg, 7));
    } elseif ($arg === '--help' || $arg === '-h') {
        echo "Generate synthetic order history for Risk Radar.\n\n";
        echo "  --orders=N        ordinary orders to create (default 1500)\n";
        echo "  --anomalies=N     injected anomalies PER TYPE (default 40)\n";
        echo "  --days=N          spread them over this many days (default 90)\n";
        echo "  --clear           delete every generated batch\n";
        echo "  --clear=BATCH     delete one batch\n";
        echo "  --list            show the batches on record\n";
        exit(0);
    } else {
        fwrite(STDERR, "Unknown argument: $arg (try --help)\n");
        exit(1);
    }
}

$db = db();

if ($listBatches) {
    $rows = $db->query(
        "SELECT batch,
                SUM(object_type = 'order')  AS orders,
                SUM(object_type = 'user')   AS users,
                SUM(object_type = 'review') AS reviews,
                SUM(anomaly IS NOT NULL)    AS anomalies,
                MIN(created_at)             AS created
           FROM risk_demo_data
       GROUP BY batch
       ORDER BY created"
    )->fetchAll();

    if (!$rows) {
        echo "No demo batches on record. The database holds only its original seeded data.\n";
        exit(0);
    }

    printf("  %-28s %8s %8s %8s %10s  %s\n", 'BATCH', 'ORDERS', 'USERS', 'REVIEWS', 'ANOMALIES', 'CREATED');
    foreach ($rows as $r) {
        printf(
            "  %-28s %8d %8d %8d %10d  %s\n",
            $r['batch'], $r['orders'], $r['users'], $r['reviews'], $r['anomalies'], $r['created']
        );
    }
    echo "\n  Remove one with --clear=BATCH, or all of them with --clear\n";
    exit(0);
}

if ($clear) {
    $where = $clearBatch === null ? '' : ' AND batch = ?';
    $args  = $clearBatch === null ? [] : [$clearBatch];

    $ids = static function (string $type) use ($db, $where, $args): array {
        $stmt = $db->prepare("SELECT object_id FROM risk_demo_data WHERE object_type = ?$where");
        $stmt->execute(array_merge([$type], $args));
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    };

    $reviewIds = $ids('review');
    $orderIds  = $ids('order');
    $userIds   = $ids('user');

    if (!$reviewIds && !$orderIds && !$userIds) {
        echo $clearBatch === null
            ? "Nothing to clear — no demo data on record.\n"
            : "Nothing to clear for batch '$clearBatch'.\n";
        exit(0);
    }

    printf(
        "Clearing %s: %d orders, %d reviews, %d accounts…\n",
        $clearBatch === null ? 'every demo batch' : "batch '$clearBatch'",
        count($orderIds), count($reviewIds), count($userIds)
    );

    $snapStmt = $db->prepare(
        "SELECT object_id, meta FROM risk_demo_data
          WHERE object_type = 'product' AND meta IS NOT NULL$where"
    );
    $snapStmt->execute($args);

    $restoreProducts = [];
    foreach ($snapStmt as $row) {
        $meta = json_decode((string) $row['meta'], true);
        if (is_array($meta) && isset($meta['rating'], $meta['review_count'])) {
            $restoreProducts[(int) $row['object_id']] = $meta;
        }
    }

    $db->beginTransaction();
    try {
        /** Delete in chunks — a 1,700-placeholder IN list is not a good idea. */
        $deleteIn = static function (string $sql, array $list) use ($db): int {
            $total = 0;
            foreach (array_chunk($list, 500) as $chunk) {
                $in = implode(',', array_fill(0, count($chunk), '?'));
                $stmt = $db->prepare(str_replace('{IN}', $in, $sql));
                $stmt->execute($chunk);
                $total += $stmt->rowCount();
            }
            return $total;
        };

        if ($reviewIds) {
            $deleteIn('DELETE FROM reviews WHERE id IN ({IN})', $reviewIds);
        }
        if ($orderIds) {
            $deleteIn('DELETE FROM orders WHERE id IN ({IN})', $orderIds);
        }
        if ($userIds) {
            $deleteIn('DELETE FROM products WHERE seller_id IN ({IN})', $userIds);
            $deleteIn('DELETE FROM users WHERE id IN ({IN})', $userIds);
        }

        $stmt = $db->prepare("DELETE FROM risk_demo_data WHERE 1 = 1" . ($clearBatch === null ? '' : ' AND batch = ?'));
        $stmt->execute($clearBatch === null ? [] : [$clearBatch]);

        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        fwrite(STDERR, "Clear failed, nothing was deleted: " . $e->getMessage() . "\n");
        exit(1);
    }

    foreach ($restoreProducts as $productId => $meta) {
        $db->prepare('UPDATE products SET rating = ?, review_count = ? WHERE id = ?')
            ->execute([$meta['rating'], $meta['review_count'], $productId]);
    }
    if ($restoreProducts) {
        printf("Restored the displayed rating on %d product(s).\n", count($restoreProducts));
    }

    $remaining = $db->query('SELECT COUNT(*) FROM orders')->fetchColumn();
    printf("Done. %d orders remain (the seeded set is 8).\n", $remaining);

    if ((int) $remaining !== 8) {
        echo "\nNote: that is not 8. Either another batch is still present (--list),\n";
        echo "or real orders have been placed since the database was seeded.\n";
    }
    exit(0);
}

$batch = 'demo-' . date('Ymd-His') . '-' . bin2hex(random_bytes(2));

echo str_repeat('=', 72), "\n";
echo "  Risk Radar — synthetic order history\n";
echo "  *** DEMO DATA. Not real orders. Not evidence about real fraud. ***\n";
echo str_repeat('=', 72), "\n\n";
printf("  batch              %s\n", $batch);
printf("  ordinary orders    %d over %d days\n", $normalOrders, $days);
printf("  injected anomalies %d of each of 6 types\n\n", $perAnomaly);

/* ---------- what is already in the database to build on ---------- */

$sellers = $db->query(
    "SELECT s.id, s.business_name, u.phone
       FROM sellers s
       JOIN users u ON u.id = s.id
      WHERE s.kyc_status = 'verified'
        AND EXISTS (SELECT 1 FROM products p
                     WHERE p.seller_id = s.id AND p.status = 'active' AND p.deleted_at IS NULL)"
)->fetchAll();

$products = $db->query(
    "SELECT p.id, p.seller_id, p.price, p.category, p.title
       FROM products p
      WHERE p.status = 'active' AND p.deleted_at IS NULL"
)->fetchAll();

if (count($sellers) < 2 || count($products) < 4) {
    fwrite(STDERR, "Need at least 2 verified sellers with 4 active products. Import sql/seed.sql first.\n");
    exit(1);
}

$productsBySeller = [];
foreach ($products as $p) {
    $productsBySeller[(int) $p['seller_id']][] = $p;
}
$sellerIds = array_keys($productsBySeller);

printf("  building on        %d sellers, %d active products\n\n", count($sellerIds), count($products));

$insUser = $db->prepare(
    'INSERT INTO users (name, email, phone, password_hash, role, status, created_at)
     VALUES (?, ?, ?, ?, \'customer\', \'active\', ?)'
);
$insOrder = $db->prepare(
    'INSERT INTO orders (user_id, total_amount, currency, payment_status,
                         shipping_name, shipping_phone, shipping_address, shipping_city,
                         shipping_zip, created_at)
     VALUES (?, ?, \'LKR\', ?, ?, ?, ?, ?, ?, ?)'
);
$insSub = $db->prepare(
    'INSERT INTO sub_orders (order_id, seller_id, status, subtotal, delivery_fee,
                             commission_rate, status_updated_at, delivered_at,
                             payout_released_at, refunded_at)
     VALUES (?, ?, ?, ?, 450, 8.00, ?, ?, ?, ?)'
);
$insItem = $db->prepare(
    'INSERT INTO order_items (sub_order_id, product_id, product_title, quantity, unit_price)
     VALUES (?, ?, ?, ?, ?)'
);
$insPayment = $db->prepare(
    'INSERT INTO payments (order_id, provider, provider_ref, amount, status, captured_at, escrow_released_at)
     VALUES (?, \'demo\', ?, ?, ?, ?, ?)'
);
$insLog = $db->prepare(
    'INSERT INTO order_status_log (sub_order_id, status, changed_by, note, changed_at)
     VALUES (?, ?, ?, ?, ?)'
);
$insReview = $db->prepare(
    'INSERT INTO reviews (product_id, user_id, rating, review_text, is_verified_buyer, helpful_count, created_at)
     VALUES (?, ?, ?, ?, 1, ?, ?)'
);
$insDemo = $db->prepare(
    'INSERT INTO risk_demo_data (batch, object_type, object_id, anomaly) VALUES (?, ?, ?, ?)'
);
/* Separate statement for the rows that carry a snapshot to restore. */
$insDemoMeta = $db->prepare(
    'INSERT INTO risk_demo_data (batch, object_type, object_id, anomaly, meta)
     VALUES (?, ?, ?, ?, ?)
     ON DUPLICATE KEY UPDATE meta = COALESCE(risk_demo_data.meta, VALUES(meta))'
);

$userSeq = 0;

/** A demo buyer created $ageDays ago. Recorded in risk_demo_data. */
$makeBuyer = function (float $ageDays, ?string $anomaly = null) use (
    $db, $insUser, $insDemo, $batch, &$userSeq
): array {
    $userSeq++;
    $createdAt = date('Y-m-d H:i:s', strtotime(sprintf('-%d minutes', (int) round($ageDays * 1440))));

    $name  = DEMO_FIRST[array_rand(DEMO_FIRST)] . ' ' . DEMO_LAST[array_rand(DEMO_LAST)];
    $email = sprintf('demo-%s-%d@demo.invalid', substr($batch, 5, 13), $userSeq);
    $phone = sprintf('07%08d', 70000000 + $userSeq);

    $insUser->execute([$name, $email, $phone, password_hash('DemoOnly@2026', PASSWORD_DEFAULT), $createdAt]);
    $id = (int) $db->lastInsertId();

    $insDemo->execute([$batch, 'user', $id, $anomaly]);

    return ['id' => $id, 'name' => $name, 'phone' => $phone, 'created_at' => $createdAt];
};

$timestampFor = static function (float $daysAgo): string {
    // Weighted hours: index = hour, value = relative likelihood.
    static $weights = [
        0 => 1, 1 => 1, 2 => 1, 3 => 1, 4 => 1, 5 => 2,
        6 => 4, 7 => 6, 8 => 8, 9 => 10, 10 => 12, 11 => 12,
        12 => 14, 13 => 12, 14 => 11, 15 => 11, 16 => 12, 17 => 14,
        18 => 18, 19 => 24, 20 => 26, 21 => 22, 22 => 14, 23 => 6,
    ];
    static $total = null;
    $total ??= array_sum($weights);

    $pick = random_int(1, $total);
    $hour = 23;
    foreach ($weights as $h => $w) {
        $pick -= $w;
        if ($pick <= 0) {
            $hour = $h;
            break;
        }
    }

    $date = date('Y-m-d', strtotime(sprintf('-%d days', (int) floor($daysAgo))));
    return sprintf('%s %02d:%02d:%02d', $date, $hour, random_int(0, 59), random_int(0, 59));
};

/** A quantity that looks like a real basket: mostly 1, sometimes 2. */
$typicalQty = static function (): int {
    $roll = random_int(1, 100);
    if ($roll <= 72) {
        return 1;
    }
    if ($roll <= 93) {
        return 2;
    }
    return 3;
};

$addressSeq = 0;

/** A plausible, distinct Sri Lankan address. */
$makeAddress = static function () use (&$addressSeq): array {
    $addressSeq++;
    $street = DEMO_STREETS[array_rand(DEMO_STREETS)];
    $city   = DEMO_CITIES[array_rand(DEMO_CITIES)];

    return [
        'address' => sprintf('%d %s', random_int(1, 480), $street),
        'city'    => $city[0],
        'zip'     => $city[1],
    ];
};

$makeOrder = function (
    array $buyer,
    array $lines,            // [['product' => row, 'qty' => n], ...]
    string $placedAt,
    string $outcome = 'delivered',
    ?array $shipTo = null,
    ?string $overridePhone = null,
    ?string $anomaly = null
) use (
    $db, $insOrder, $insSub, $insItem, $insPayment, $insLog, $insDemo, $batch
): int {
    $buyerCreated = strtotime($buyer['created_at']);
    if (strtotime($placedAt) < $buyerCreated) {
        $placedAt = date('Y-m-d H:i:s', $buyerCreated + random_int(120, 5400));
    }

    $bySeller = [];
    foreach ($lines as $line) {
        $bySeller[(int) $line['product']['seller_id']][] = $line;
    }

    $goods = 0.0;
    foreach ($lines as $line) {
        $goods += (float) $line['product']['price'] * $line['qty'];
    }
    $delivery = count($bySeller) * 450;
    $total    = round($goods + $delivery, 2);

    $ship = $shipTo ?? ['address' => '1 Unnamed Road', 'city' => 'Colombo 03', 'zip' => '00300'];

    $paymentStatus = match ($outcome) {
        'refunded'  => 'refunded',
        'cancelled' => 'paid',
        default     => 'paid',
    };

    $insOrder->execute([
        $buyer['id'],
        $total,
        $paymentStatus,
        $buyer['name'],
        $overridePhone ?? $buyer['phone'],
        $ship['address'],
        $ship['city'],
        $ship['zip'],
        $placedAt,
    ]);
    $orderId = (int) $db->lastInsertId();

    foreach ($bySeller as $sellerId => $sellerLines) {
        $subtotal = 0.0;
        foreach ($sellerLines as $line) {
            $subtotal += (float) $line['product']['price'] * $line['qty'];
        }

        [$status, $deliveredAt, $releasedAt, $refundedAt] = match ($outcome) {
            'delivered' => [
                'delivered',
                date('Y-m-d H:i:s', strtotime($placedAt) + 86400 * 3),
                date('Y-m-d H:i:s', strtotime($placedAt) + 86400 * 3),
                null,
            ],
            'shipped'   => ['shipped', null, null, null],
            'packed'    => ['packed', null, null, null],
            'pending'   => ['pending', null, null, null],
            'cancelled' => ['cancelled', null, null, null],
            'refunded'  => ['refunded', null, null, date('Y-m-d H:i:s', strtotime($placedAt) + 86400 * 5)],
            'disputed'  => ['disputed', null, null, null],
            default     => ['delivered', null, null, null],
        };

        $statusAt = $deliveredAt ?? date('Y-m-d H:i:s', strtotime($placedAt) + 3600);

        $insSub->execute([
            $orderId, $sellerId, $status, round($subtotal, 2),
            $statusAt, $deliveredAt, $releasedAt, $refundedAt,
        ]);
        $subOrderId = (int) $db->lastInsertId();

        foreach ($sellerLines as $line) {
            $insItem->execute([
                $subOrderId,
                (int) $line['product']['id'],
                $line['product']['title'],
                $line['qty'],
                (float) $line['product']['price'],
            ]);
        }

        $path = match ($status) {
            'delivered' => ['pending', 'packed', 'shipped', 'delivered'],
            'shipped'   => ['pending', 'packed', 'shipped'],
            'packed'    => ['pending', 'packed'],
            'cancelled' => ['pending', 'cancelled'],
            'refunded'  => ['pending', 'packed', 'shipped', 'refunded'],
            'disputed'  => ['pending', 'packed', 'shipped', 'disputed'],
            default     => ['pending'],
        };

        foreach ($path as $i => $step) {
            $insLog->execute([
                $subOrderId,
                $step,
                $i === 0 ? 'system' : ($step === 'delivered' ? 'customer' : ($step === 'refunded' ? 'admin' : 'seller')),
                $i === 0 ? 'Order placed · payment held in escrow (demo)' : null,
                date('Y-m-d H:i:s', strtotime($placedAt) + 3600 * ($i * 8)),
            ]);
        }
    }

    $allReleased = $outcome === 'delivered';
    $insPayment->execute([
        $orderId,
        'DEMO-' . strtoupper(bin2hex(random_bytes(5))),
        $total,
        $outcome === 'refunded' ? 'refunded' : 'captured',
        $placedAt,
        $allReleased ? date('Y-m-d H:i:s', strtotime($placedAt) + 86400 * 3) : null,
    ]);

    $insDemo->execute([$batch, 'order', $orderId, $anomaly]);

    return $orderId;
};

/* Name and place pools, as constants so the closures can see them. */
define('DEMO_FIRST', ['Nimal', 'Kamal', 'Sunil', 'Ruwan', 'Chamara', 'Dinuka', 'Sanduni',
    'Ishara', 'Menaka', 'Tharindu', 'Hasini', 'Amaya', 'Praveen', 'Nadeesha', 'Lakmal',
    'Roshan', 'Ayesha', 'Sachini', 'Kavindu', 'Dilshan']);
define('DEMO_LAST', ['Perera', 'Fernando', 'Silva', 'Jayawardena', 'Gunasekara',
    'Bandara', 'Wickramasinghe', 'Rajapaksa', 'Dissanayake', 'Herath']);
define('DEMO_STREETS', ['Galle Road', 'Temple Road', 'Flower Road', 'Duplication Road',
    'Baseline Road', 'High Level Road', 'Kandy Road', 'Marine Drive', 'Havelock Road',
    'Dharmapala Mawatha', 'Negombo Road', 'Peradeniya Road']);
define('DEMO_CITIES', [
    ['Colombo 03', '00300'], ['Colombo 05', '00500'], ['Colombo 07', '00700'],
    ['Dehiwala', '10350'], ['Moratuwa', '10400'], ['Kandy', '20000'],
    ['Galle', '80000'], ['Negombo', '11500'], ['Kurunegala', '60000'], ['Gampaha', '11000'],
]);

$db->beginTransaction();

$startTime = microtime(true);
$counts = ['normal' => 0];

try {
    echo "  generating ordinary traffic…\n";

    $buyerPool = [];
    $poolSize  = max(40, (int) ($normalOrders / 8));

    for ($i = 0; $i < $poolSize; $i++) {
        // Accounts aged between the start of the window and 2 days ago.
        $buyerPool[] = $makeBuyer(random_int(3, $days + 120) + 0.0);
    }

    $buyerAddress = [];
    foreach ($buyerPool as $b) {
        $buyerAddress[$b['id']] = $makeAddress();
    }

    for ($i = 0; $i < $normalOrders; $i++) {
        $buyer = $buyerPool[array_rand($buyerPool)];

        // An order cannot predate its buyer's account.
        $buyerAgeDays = (time() - strtotime($buyer['created_at'])) / 86400;
        $maxAgo       = min($days, max(0.5, $buyerAgeDays - 0.2));
        $placedAt     = $timestampFor((float) random_int(0, (int) $maxAgo));

        // One seller most of the time, two occasionally — the split case.
        $lineCount = random_int(1, 100) <= 18 ? 2 : 1;
        $lines = [];
        $used  = [];
        for ($l = 0; $l < $lineCount; $l++) {
            $p = $products[array_rand($products)];
            if (isset($used[$p['id']])) {
                continue;
            }
            $used[$p['id']] = true;
            $lines[] = ['product' => $p, 'qty' => $typicalQty()];
        }

        $roll = random_int(1, 100);
        $outcome = match (true) {
            $roll <= 78 => 'delivered',
            $roll <= 85 => 'shipped',
            $roll <= 89 => 'packed',
            $roll <= 92 => 'pending',
            $roll <= 96 => 'cancelled',
            $roll <= 99 => 'refunded',
            default     => 'disputed',
        };

        $makeOrder($buyer, $lines, $placedAt, $outcome, $buyerAddress[$buyer['id']]);
        $counts['normal']++;

        if ($counts['normal'] % 250 === 0) {
            printf("    %d / %d\n", $counts['normal'], $normalOrders);
        }
    }

    echo "\n  injecting labelled anomalies…\n";

    /* ---- (a) velocity burst: a few buyers placing many orders fast ---- */
    $counts['velocity_burst'] = 0;
    $bursts = max(1, (int) ceil($perAnomaly / 5));
    for ($b = 0; $b < $bursts; $b++) {
        $buyer   = $makeBuyer(random_int(1, 40) + 0.0, 'velocity_burst');
        $address = $makeAddress();
        $dayAgo  = random_int(0, max(0, $days - 1));
        $base    = strtotime($timestampFor((float) $dayAgo));

        // 5 orders inside about 40 minutes.
        for ($k = 0; $k < 5 && $counts['velocity_burst'] < $perAnomaly; $k++) {
            $p = $products[array_rand($products)];
            $makeOrder(
                $buyer,
                [['product' => $p, 'qty' => 1]],
                date('Y-m-d H:i:s', $base + $k * random_int(300, 600)),
                'pending',
                $address,
                null,
                'velocity_burst'
            );
            $counts['velocity_burst']++;
        }
    }

    /* ---- (b) new account, unusually large order ---- */
    $counts['new_account_high_value'] = 0;
    $dear = $products;
    usort($dear, static fn(array $a, array $b): int => (float) $b['price'] <=> (float) $a['price']);
    $dearest = array_slice($dear, 0, max(1, (int) ceil(count($dear) / 3)));

    for ($i = 0; $i < $perAnomaly; $i++) {
        $buyer = $makeBuyer(random_int(1, 20) / 24, 'new_account_high_value');   // 1-20 HOURS old
        $p     = $dearest[array_rand($dearest)];

        $makeOrder(
            $buyer,
            [['product' => $p, 'qty' => random_int(3, 6)]],
            // Within the buyer's short lifetime.
            date('Y-m-d H:i:s', strtotime($buyer['created_at']) + random_int(600, 3000)),
            'pending',
            $makeAddress(),
            null,
            'new_account_high_value'
        );
        $counts['new_account_high_value']++;
    }

    /* ---- (c) one address, many accounts ---- */
    $counts['shared_address'] = 0;
    $clusters = max(1, (int) ceil($perAnomaly / 5));
    for ($c = 0; $c < $clusters; $c++) {
        $shared = $makeAddress();

        for ($k = 0; $k < 5 && $counts['shared_address'] < $perAnomaly; $k++) {
            $buyer = $makeBuyer(random_int(1, 30) + 0.0, 'shared_address');
            $p     = $products[array_rand($products)];

            $variants = [
                $shared['address'],
                mb_strtolower($shared['address']),
                str_replace('Road', 'Rd', $shared['address']),
                'No. ' . $shared['address'],
                str_replace(' ', '  ', $shared['address']),
            ];

            $makeOrder(
                $buyer,
                [['product' => $p, 'qty' => $typicalQty()]],
                $timestampFor((float) random_int(0, max(0, $days - 1))),
                'delivered',
                ['address' => $variants[$k % count($variants)], 'city' => $shared['city'], 'zip' => $shared['zip']],
                null,
                'shared_address'
            );
            $counts['shared_address']++;
        }
    }

    /* ---- (d) self-purchase: the buyer ships to the seller's own phone ---- */
    $counts['self_purchase'] = 0;
    for ($i = 0; $i < $perAnomaly; $i++) {
        $seller = $sellers[array_rand($sellers)];
        $sellerProducts = $productsBySeller[(int) $seller['id']] ?? null;
        if (!$sellerProducts || empty($seller['phone'])) {
            continue;
        }

        $buyer = $makeBuyer(random_int(1, 25) + 0.0, 'self_purchase');
        $p     = $sellerProducts[array_rand($sellerProducts)];

        $makeOrder(
            $buyer,
            [['product' => $p, 'qty' => 1]],
            $timestampFor((float) random_int(0, max(0, $days - 1))),
            'delivered',
            $makeAddress(),
            $seller['phone'],              // the tell
            'self_purchase'
        );
        $counts['self_purchase']++;
    }

    /* ---- (e) seller order spike: one seller's volume jumps on one day ---- */
    $counts['seller_spike'] = 0;

    $volume = $db->prepare(
        'SELECT so.seller_id, COUNT(*) / 14 AS per_day
           FROM sub_orders so
           JOIN orders o ON o.id = so.order_id
          WHERE o.created_at >= CURDATE() - INTERVAL 14 DAY
            AND so.seller_id IN (' . implode(',', array_map('intval', $sellerIds)) . ')
       GROUP BY so.seller_id
       ORDER BY per_day ASC'
    );
    $volume->execute();
    $volumes = $volume->fetchAll();

    if (!$volumes) {
        echo "    (no recent seller volume — skipping the spike injection)\n";
    } else {
        $quietest     = $volumes[0];
        $spikeSeller  = (int) $quietest['seller_id'];
        $dailyAverage = (float) $quietest['per_day'];
        $needed       = max(6, (int) ceil($dailyAverage * 5));

        if ($needed > $perAnomaly) {
            printf(
                "    ! the quietest seller already averages %.1f orders/day, so a 4x spike needs\n" .
                "      %d orders in one day — more than the %d-order budget for this type.\n" .
                "      Raising the budget for this one type to %d so the anomaly is real.\n",
                $dailyAverage, $needed, $perAnomaly, $needed
            );
        }

        $budget   = max($perAnomaly, $needed);
        $spikeDay = random_int(0, 2);
        $base     = strtotime(date('Y-m-d', strtotime("-$spikeDay days")) . ' 08:00:00');

        for ($k = 0; $k < $budget; $k++) {
            $buyer = $makeBuyer(random_int(2, 60) + 0.0, 'seller_spike');
            $p     = $productsBySeller[$spikeSeller][array_rand($productsBySeller[$spikeSeller])];

            $makeOrder(
                $buyer,
                [['product' => $p, 'qty' => $typicalQty()]],
                date('Y-m-d H:i:s', $base + (int) ($k * (12 * 3600 / max(1, $budget)))),
                'pending',
                $makeAddress(),
                null,
                'seller_spike'
            );
            $counts['seller_spike']++;
        }

        printf(
            "    seller %d: %.1f orders/day normally, %d injected on %s\n",
            $spikeSeller, $dailyAverage, $counts['seller_spike'],
            date('Y-m-d', strtotime("-$spikeDay days"))
        );
    }

    $counts['review_burst'] = 0;
    $reviewProducts = array_slice($products, 0, max(1, (int) ceil($perAnomaly / 6)));

    foreach ($reviewProducts as $p) {
        $snap = $db->prepare('SELECT rating, review_count FROM products WHERE id = ?');
        $snap->execute([(int) $p['id']]);
        $before = $snap->fetch();

        $insDemoMeta->execute([
            $batch,
            'product',
            (int) $p['id'],
            'review_burst',
            json_encode([
                'rating'       => (float) $before['rating'],
                'review_count' => (int) $before['review_count'],
            ]),
        ]);
    }

    foreach ($reviewProducts as $p) {
        for ($k = 0; $k < 6 && $counts['review_burst'] < $perAnomaly; $k++) {
            $reviewer = $makeBuyer(random_int(6, 70) / 24, 'review_burst');   // HOURS old
            $daysAgo  = random_int(0, 5);

            $makeOrder(
                $reviewer,
                [['product' => $p, 'qty' => 1]],
                date('Y-m-d H:i:s', strtotime($reviewer['created_at']) + 1800),
                'delivered',
                $makeAddress(),
                null,
                'review_burst'
            );

            $insReview->execute([
                (int) $p['id'],
                $reviewer['id'],
                5,
                'Excellent product, exactly as described. Fast delivery and well packaged.',
                random_int(0, 3),
                date('Y-m-d H:i:s', strtotime("-$daysAgo days")),
            ]);
            $insDemo->execute([$batch, 'review', (int) $db->lastInsertId(), 'review_burst']);
            $counts['review_burst']++;
        }
    }

    $db->commit();
} catch (Throwable $e) {
    $db->rollBack();
    fwrite(STDERR, "\nGeneration failed and was rolled back: " . $e->getMessage() . "\n");
    fwrite(STDERR, $e->getFile() . ':' . $e->getLine() . "\n");
    exit(1);
}

foreach ($reviewProducts as $p) {
    refresh_product_rating((int) $p['id']);
}

$elapsed = microtime(true) - $startTime;

echo "\n", str_repeat('-', 72), "\n";
printf("  Generated in %.1f seconds\n\n", $elapsed);

printf("  %-28s %7s\n", 'ordinary orders', number_format($counts['normal']));
foreach ([
    'velocity_burst'         => 'velocity bursts',
    'new_account_high_value' => 'new account, big order',
    'shared_address'         => 'shared address',
    'self_purchase'          => 'self-purchase',
    'seller_spike'           => 'seller order spike',
    'review_burst'           => 'review burst',
] as $key => $label) {
    printf("  %-28s %7s  (labelled '%s')\n", $label, number_format($counts[$key] ?? 0), $key);
}

$totalOrders = (int) $db->query('SELECT COUNT(*) FROM orders')->fetchColumn();
$demoOrders  = (int) $db->query("SELECT COUNT(*) FROM risk_demo_data WHERE object_type = 'order'")->fetchColumn();
$demoUsers   = (int) $db->query("SELECT COUNT(*) FROM risk_demo_data WHERE object_type = 'user'")->fetchColumn();
$anomalies   = (int) $db->query("SELECT COUNT(*) FROM risk_demo_data WHERE object_type = 'order' AND anomaly IS NOT NULL")->fetchColumn();

echo "\n";
printf("  orders in the database     %s   (%d generated, 8 originally seeded)\n", number_format($totalOrders), $demoOrders);
printf("  demo accounts              %s\n", number_format($demoUsers));
printf("  labelled anomalous orders  %s  (%.1f%% of the generated set)\n",
    number_format($anomalies), $demoOrders > 0 ? $anomalies / $demoOrders * 100 : 0);

echo "\n", str_repeat('-', 72), "\n";
echo "  batch: $batch\n\n";
echo "  Remove it with:\n";
echo "    php api\\tools\\generate_demo_orders.php --clear\n\n";
echo "  Next:\n";
echo "    php api\\tools\\export_training_csv.php\n";
echo "    ai_service\\venv\\Scripts\\python.exe ai_service\\risk\\train_anomaly.py\n";
echo str_repeat('-', 72), "\n";
echo "\n  *** Everything above is DEMO DATA. The anomalies were planted by\n";
echo "      this script. Any evaluation against them measures the pipeline,\n";
echo "      not real-world fraud detection. ***\n\n";

exit(0);
