<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/_orders.php';
require_once __DIR__ . '/../lib/risk_persist.php';

/** Delivery is charged per seller — the same rule api/cart/_cart.php quotes. */
const DELIVERY_FEE_PER_SELLER = 450.00;

final class CheckoutProblem extends RuntimeException
{
    public function __construct(string $message, private int $httpStatus = 409)
    {
        parent::__construct($message);
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }
}

try {
    require_method('POST');
    csrf_check();
    $me = require_login();

    $userId = (int) $me['id'];

    // Accept both {shipping:{...}} and a flat body, so the shape the page
    // sends is not a thing that can silently break checkout.
    $body     = json_body();
    $shipping = is_array($body['shipping'] ?? null) ? $body['shipping'] : $body;

    $field = static function (string $key) use ($shipping): string {
        $v = $shipping[$key] ?? '';
        if (is_int($v) || is_float($v)) {
            $v = (string) $v;
        }
        return is_string($v) ? trim($v) : '';
    };

    $name    = $field('name');
    $phone   = $field('phone');
    $address = $field('address');
    $city    = $field('city');
    $zip     = $field('zip');
    $note    = $field('note');

    $errors = [];

    if ($e = invalid_name($name, 'Full name')) {
        $errors['name'] = $e;
    }
    if ($e = invalid_phone($phone)) {
        $errors['phone'] = $e;
    }
    if ($address === '') {
        $errors['address'] = 'Delivery address is required';
    } elseif (mb_strlen($address) > 255) {
        $errors['address'] = 'Address is too long (255 characters max)';
    }
    if ($city === '') {
        $errors['city'] = 'City is required';
    } elseif (mb_strlen($city) > 80) {
        $errors['city'] = 'City is too long (80 characters max)';
    }
    // Optional (the column is NULL-able), but validated when it is given.
    if ($zip !== '' && !preg_match('/^\d{4,6}$/', $zip)) {
        $errors['zip'] = 'Enter a 4–6 digit postal code';
    }
    if (mb_strlen($note) > 255) {
        $errors['note'] = 'Delivery note is too long (255 characters max)';
    }

    if ($errors) {
        fail_validation($errors, 'Please check your delivery details.');
    }

    $defaultRate = platform_commission_rate();

    $orderId = db_transaction(function (PDO $pdo) use ($userId, $name, $phone, $address, $city, $zip, $note, $defaultRate): int {

        $stmt = $pdo->prepare(
            'SELECT ci.product_id, ci.quantity,
                    p.title, p.stock, p.price, p.status, p.deleted_at, p.seller_id,
                    COALESCE(' . SALE_PRICE_SQL . ', p.price) AS unit_price,
                    s.business_name, s.commission_rate
               FROM cart_items ci
               JOIN products p ON p.id = ci.product_id
               JOIN sellers  s ON s.id = p.seller_id
              WHERE ci.user_id = ?
           ORDER BY p.seller_id, ci.id
             FOR UPDATE'
        );
        $stmt->execute([$userId]);
        $lines = $stmt->fetchAll();

        if (!$lines) {
            throw new CheckoutProblem('Your cart is empty.');
        }

        /* ---------- validate every line before writing anything ---------- */
        foreach ($lines as $line) {
            $title = $line['title'];

            if ($line['status'] !== 'active' || $line['deleted_at'] !== null) {
                throw new CheckoutProblem(
                    $title . ' is no longer available. Please remove it from your cart.'
                );
            }
            if ((int) $line['stock'] < (int) $line['quantity']) {
                throw new CheckoutProblem(
                    (int) $line['stock'] > 0
                        ? sprintf('Only %d of %s left — please reduce the quantity.', (int) $line['stock'], $title)
                        : $title . ' has just sold out. Please remove it from your cart.'
                );
            }
            // A seller buying their own listing would create a sub-order
            // where the buyer and the payee are the same account, and would
            // release its own escrow by confirming delivery.
            if ((int) $line['seller_id'] === $userId) {
                throw new CheckoutProblem('You cannot buy your own listing (' . $title . ').');
            }
        }

        /* ---------- group by seller; every figure comes from the DB ---------- */
        $bySeller = [];
        foreach ($lines as $line) {
            $sellerId = (int) $line['seller_id'];
            $qty      = (int) $line['quantity'];
            $unit     = (float) $line['unit_price'];     // sale price if one is running today

            if (!isset($bySeller[$sellerId])) {
                $bySeller[$sellerId] = [
                    'subtotal' => 0.0,
                    // The seller's negotiated rate, or the platform default.
                    'rate'     => $line['commission_rate'] === null
                        ? $defaultRate
                        : (float) $line['commission_rate'],
                    'lines'    => [],
                ];
            }

            $bySeller[$sellerId]['subtotal'] += $unit * $qty;
            $bySeller[$sellerId]['lines'][]   = [
                'product_id' => (int) $line['product_id'],
                'title'      => $line['title'],
                'qty'        => $qty,
                'unit_price' => $unit,
            ];
        }

        $goods    = array_sum(array_column($bySeller, 'subtotal'));
        $delivery = count($bySeller) * DELIVERY_FEE_PER_SELLER;
        $total    = round($goods + $delivery, 2);

        $pdo->prepare(
            'INSERT INTO orders
                (user_id, total_amount, currency, payment_status,
                 shipping_name, shipping_phone, shipping_address, shipping_city,
                 shipping_zip, shipping_note)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $userId,
            $total,
            'LKR',
            'paid',                      // the simulated gateway captured it
            $name,
            $phone,
            $address,
            $city,
            $zip === '' ? null : $zip,
            $note === '' ? null : $note,
        ]);

        $orderId = (int) $pdo->lastInsertId();

        /* ---------- one sub-order per seller, with its items ---------- */
        $insertSub = $pdo->prepare(
            'INSERT INTO sub_orders
                (order_id, seller_id, status, subtotal, delivery_fee, commission_rate)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $insertItem = $pdo->prepare(
            'INSERT INTO order_items
                (sub_order_id, product_id, product_title, quantity, unit_price)
             VALUES (?, ?, ?, ?, ?)'
        );
        // Conditional on stock as well as id: even holding the lock, this
        // refuses to write a negative stock rather than trusting the check
        // above to still be true. CHECK (stock >= 0) is the last line.
        $decStock = $pdo->prepare(
            'UPDATE products SET stock = stock - ? WHERE id = ? AND stock >= ?'
        );

        foreach ($bySeller as $sellerId => $group) {
            $insertSub->execute([
                $orderId,
                $sellerId,
                'pending',                               // the flow starts here
                round($group['subtotal'], 2),
                DELIVERY_FEE_PER_SELLER,
                $group['rate'],                          // snapshot, not a live lookup
            ]);
            $subOrderId = (int) $pdo->lastInsertId();

            foreach ($group['lines'] as $line) {
                $insertItem->execute([
                    $subOrderId,
                    $line['product_id'],
                    $line['title'],                      // snapshot: survives a rename
                    $line['qty'],
                    $line['unit_price'],                 // snapshot: survives a reprice
                ]);

                $decStock->execute([$line['qty'], $line['product_id'], $line['qty']]);
                if ($decStock->rowCount() !== 1) {
                    // Someone bought the last one between the lock and here,
                    // or the row vanished. Abort the whole checkout.
                    throw new RuntimeException(
                        'Stock decrement failed for product ' . $line['product_id']
                    );
                }
            }

            /* The audit trail starts at the same instant as the sub-order. */
            order_log_status(
                $pdo,
                $subOrderId,
                'pending',
                'system',
                null,
                'Order placed · payment held in escrow (demo)'
            );
        }

        $pdo->prepare(
            'INSERT INTO payments
                (order_id, provider, provider_ref, amount, status, captured_at)
             VALUES (?, ?, ?, ?, ?, NOW())'
        )->execute([
            $orderId,
            'demo',
            // Clearly marked as simulated so nobody mistakes it for a
            // gateway reference in the database either.
            'DEMO-' . strtoupper(bin2hex(random_bytes(5))),
            $total,
            'captured',
        ]);

        /* ---------- the cart is now the order ---------- */
        $pdo->prepare('DELETE FROM cart_items WHERE user_id = ?')->execute([$userId]);

        return $orderId;
    });

    app_log('Order placed', ['order' => $orderId, 'user' => $userId]);

    if (function_exists('risk_score_order')) {
        try {
            $risk = risk_score_order($orderId, 'checkout');

            if (!$risk['ok']) {
                app_log('Order scored as unscored', [
                    'order'  => $orderId,
                    'reason' => $risk['reason'],
                ]);
            } elseif ($risk['held'] > 0) {
                app_log('Payout held pending review', [
                    'order'      => $orderId,
                    'level'      => $risk['level'],
                    'score'      => $risk['score'],
                    'sub_orders' => $risk['held'],
                ]);
            }
        } catch (Throwable $e) {
            // Never reached in normal operation; see above.
            app_log('Risk hook threw despite its own catch: ' . $e->getMessage());
        }
    }

    $order = order_find_for_user($orderId, $userId);

    json_ok(['order' => $order], 201);
} catch (CheckoutProblem $e) {
    // Everything this transaction wrote has already been rolled back.
    app_log('Checkout refused: ' . $e->getMessage(), ['user' => $_SESSION['uid'] ?? null]);
    json_fail($e->getMessage(), 'VALIDATION', $e->httpStatus());
} catch (Throwable $e) {
    app_log('orders/checkout.php failed: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    json_fail(
        'We could not complete your order. Nothing has been charged — please try again.',
        'SERVER',
        500
    );
}
