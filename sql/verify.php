<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$pdo = new PDO('mysql:host=127.0.0.1;dbname=consult;charset=utf8mb4', 'root', '', [
  PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$fail = 0;
function check(string $label, bool $ok, string $detail = ''): void {
  global $fail; if (!$ok) $fail++;
  printf("  [%s] %s%s\n", $ok ? 'PASS' : 'FAIL', $label, $detail !== '' ? " — $detail" : '');
}
$one = fn(string $sql, array $p = []) => (function() use ($pdo, $sql, $p) { $s = $pdo->prepare($sql); $s->execute($p); return $s->fetchColumn(); })();

echo "Server: " . $one('SELECT VERSION()') . "\n\nAuth\n";
$h = $one('SELECT password_hash FROM users WHERE email = ?', ['admin@consult.lk']);
check('admin hash is bcrypt', str_starts_with($h, '$2y$'));
check('admin password verifies', password_verify('Consult@2026', $h));
check('wrong password rejected', !password_verify('wrong-password', $h));
check('no plain-text passwords', (int)$one("SELECT COUNT(*) FROM users WHERE password_hash NOT LIKE '\$2y\$%'") === 0);
check('admin phone is a landline', $one('SELECT phone FROM users WHERE role = ?', ['admin']) === '0112000000');

echo "\nSellers\n";
$k = $pdo->query("SELECT kyc_status, COUNT(*) n FROM sellers GROUP BY kyc_status")->fetchAll(PDO::FETCH_KEY_PAIR);
check('4 verified, 1 pending, 1 rejected', ($k['verified'] ?? 0) == 4 && ($k['pending'] ?? 0) == 1 && ($k['rejected'] ?? 0) == 1, json_encode($k));
check('every product.json seller mapped (s1..s4)', (int)$one("SELECT COUNT(*) FROM sellers WHERE public_id IN ('s1','s2','s3','s4')") === 4);

echo "\nCatalogue\n";
check('p1..p8 present and active', (int)$one("SELECT COUNT(*) FROM products WHERE public_id IN ('p1','p2','p3','p4','p5','p6','p7','p8') AND status = 'active'") === 8);
check('2 listings waiting for moderation', (int)$one("SELECT COUNT(*) FROM products WHERE status = 'pending'") === 2);
check('specs stored as valid JSON', (int)$one('SELECT COUNT(*) FROM products WHERE specs IS NOT NULL AND JSON_VALID(specs) = 0') === 0);
check('JSON_EXTRACT works on specs', $one("SELECT JSON_UNQUOTE(JSON_EXTRACT(specs, '$.RAM')) FROM products WHERE public_id = 'p1'") === '16GB DDR4');
try { $pdo->exec("UPDATE products SET specs = 'not json' WHERE public_id = 'p1'"); check('invalid JSON rejected by DB', false); }
catch (PDOException $e) { check('invalid JSON rejected by DB', true, 'CHECK constraint'); }

// active price picked by date in SQL — the query the API will use
$priceSql = "SELECT p.price,
               (SELECT s.sale_price FROM product_sales s WHERE s.product_id = p.id
                  AND CURDATE() BETWEEN s.start_date AND s.end_date ORDER BY s.sale_price LIMIT 1) AS sale_price
             FROM products p WHERE p.public_id = ?";
$s = $pdo->prepare($priceSql);
$s->execute(['p8']); $p8 = $s->fetch();
check('p8 sale is live today', (float)$p8['sale_price'] === 179000.0, "price {$p8['price']} -> sale {$p8['sale_price']}");
$s->execute(['p3']); $p3 = $s->fetch();
check('p3 sale is scheduled, not live yet', $p3['sale_price'] === null, "price {$p3['price']}, sale starts next week");
try { $pdo->exec("INSERT INTO product_sales (product_id, sale_price, start_date, end_date) VALUES (1, 100, CURDATE(), CURDATE() - INTERVAL 1 DAY)"); check('end-before-start sale rejected', false); }
catch (PDOException $e) { check('end-before-start sale rejected', true, 'CHECK constraint'); }

echo "\nOrders, split and escrow\n";
$split = $pdo->query("SELECT o.id, COUNT(so.id) subs, COUNT(DISTINCT so.seller_id) sellers, o.total_amount,
                        SUM(so.subtotal) sub_total, (SELECT COUNT(*) FROM payments p WHERE p.order_id = o.id) payments
                      FROM orders o JOIN sub_orders so ON so.order_id = o.id GROUP BY o.id HAVING subs > 1")->fetchAll();
check('a 2-seller order exists: 1 order, 1 payment, 2 sub-orders', count($split) === 1 && $split[0]['payments'] == 1 && $split[0]['sellers'] == 2, json_encode($split[0] ?? []));
check('every order total = sum of its sub-orders', (int)$one('SELECT COUNT(*) FROM orders o WHERE o.total_amount <> (SELECT SUM(subtotal + delivery_fee) FROM sub_orders WHERE order_id = o.id)') === 0);
check('every sub-order total = sum of its line items', (int)$one('SELECT COUNT(*) FROM sub_orders so WHERE so.subtotal <> (SELECT SUM(quantity * unit_price) FROM order_items WHERE sub_order_id = so.id)') === 0);
check('escrow released ONLY on delivered sub-orders', (int)$one("SELECT COUNT(*) FROM sub_orders WHERE (status = 'delivered') <> (payout_released_at IS NOT NULL)") === 0);
check('delivery confirmed by the customer in the log', (int)$one("SELECT COUNT(*) FROM order_status_log WHERE status = 'delivered' AND changed_by <> 'customer'") === 0);
check('every sub-order has a log entry for its current status', (int)$one('SELECT COUNT(*) FROM sub_orders so WHERE NOT EXISTS (SELECT 1 FROM order_status_log l WHERE l.sub_order_id = so.id AND l.status = so.status)') === 0);
$st = $pdo->query('SELECT status, COUNT(*) n FROM sub_orders GROUP BY status')->fetchAll(PDO::FETCH_KEY_PAIR);
check('sub-orders cover every stage incl. a dispute and a refund', isset($st['pending'], $st['packed'], $st['shipped'], $st['delivered'], $st['disputed'], $st['refunded']), json_encode($st));
check('commission rate snapshotted on every sub-order', (int)$one('SELECT COUNT(*) FROM sub_orders WHERE commission_rate IS NULL') === 0);

echo "\nAudit trail\n";
try { $pdo->exec("UPDATE order_status_log SET note = 'tampered' WHERE id = 1"); check('status log UPDATE blocked', false); }
catch (PDOException $e) { check('status log UPDATE blocked', str_contains($e->getMessage(), 'append-only'), 'trigger'); }
try { $pdo->exec('DELETE FROM order_status_log WHERE id = 1'); check('status log DELETE blocked', false); }
catch (PDOException $e) { check('status log DELETE blocked', str_contains($e->getMessage(), 'append-only'), 'trigger'); }
check('admin actions recorded', (int)$one('SELECT COUNT(*) FROM admin_actions') > 0);
try { $pdo->exec('DELETE FROM users WHERE id = 1'); check('admin with audit history cannot be deleted', false); }
catch (PDOException $e) { check('admin with audit history cannot be deleted', true, 'FK RESTRICT'); }

echo "\nSettings\n";
check('commission_rate setting = 8', (string)json_decode($one("SELECT value FROM settings WHERE setting_key = 'commission_rate'")) === '8');
check('categories setting is a JSON list', is_array(json_decode($one("SELECT value FROM settings WHERE setting_key = 'categories'"), true)));

echo $fail ? "\n$fail CHECK(S) FAILED\n" : "\nALL CHECKS PASSED\n";
exit($fail ? 1 : 0);
