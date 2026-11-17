<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/_seller.php';

try {
    require_method('POST');
    csrf_check();

    $me       = require_verified_seller();
    $sellerId = (int) $me['seller']['id'];

    $id = body_str('id');
    if ($id === '') {
        json_fail('No listing was specified.', 'VALIDATION', 400);
    }

    $product = seller_find_product($sellerId, $id);
    if ($product === null) {
        json_fail('That listing could not be found.', 'NOT_FOUND', 404);
    }

    $productId = (int) $product['id'];

    if (body_bool('clear')) {
        $stmt = db()->prepare(
            'DELETE FROM product_sales WHERE product_id = ? AND end_date >= CURDATE()'
        );
        $stmt->execute([$productId]);

        json_ok([
            'product' => seller_find_product_shaped($sellerId, $product['public_id']),
            'message' => $stmt->rowCount() > 0
                ? 'Sale cancelled — the listing is back to its normal price.'
                : 'There was no scheduled sale to cancel.',
        ]);
    }

    $body  = json_body();
    $price = isset($body['sale_price']) && is_numeric($body['sale_price'])
        ? (float) $body['sale_price']
        : null;

    $start = body_str('start_date');
    $end   = body_str('end_date');

    $errors = [];

    $normal = (float) $product['price'];

    if ($price === null) {
        $errors['sale_price'] = 'Enter the sale price';
    } elseif ($price <= 0) {
        $errors['sale_price'] = 'The sale price must be more than zero';
    } elseif ($price >= $normal) {
        // Not a sale at all, and the storefront's strikethrough would read
        // as a price rise.
        $errors['sale_price'] = sprintf(
            'The sale price has to be below the normal price (%s)',
            number_format($normal, 2)
        );
    }

    // Validated as a real calendar date, not just the shape: '2026-02-31'
    // matches the pattern but is not a day.
    $isDate = static function (string $v): bool {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m)) {
            return false;
        }
        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    };

    if (!$isDate($start)) {
        $errors['start_date'] = 'Pick a valid start date';
    }
    if (!$isDate($end)) {
        $errors['end_date'] = 'Pick a valid end date';
    }

    if (!$errors) {
        if ($end < $start) {
            $errors['end_date'] = 'The sale cannot end before it starts';
        } elseif ($end < date('Y-m-d')) {
            $errors['end_date'] = 'That sale would already be over — pick a later end date';
        } elseif (strtotime($start) > strtotime('+2 years')) {
            $errors['start_date'] = 'Pick a start date within the next two years';
        }
    }

    if ($errors) {
        fail_validation($errors, 'Please check the sale details.');
    }

    db_transaction(function (PDO $pdo) use ($productId, $price, $start, $end): void {
        $pdo->prepare('DELETE FROM product_sales WHERE product_id = ? AND end_date >= CURDATE()')
            ->execute([$productId]);

        $pdo->prepare(
            'INSERT INTO product_sales (product_id, sale_price, start_date, end_date)
             VALUES (?, ?, ?, ?)'
        )->execute([$productId, $price, $start, $end]);
    });

    $saved   = seller_find_product_shaped($sellerId, $product['public_id']);
    $running = $saved['sale']['active'] ?? false;

    app_log('Sale scheduled', [
        'seller'  => $sellerId,
        'product' => $product['public_id'],
        'price'   => $price,
        'from'    => $start,
        'to'      => $end,
    ]);

    json_ok([
        'product' => $saved,
        'message' => $running
            ? sprintf('Sale is live now and runs until %s.', $end)
            : sprintf('Sale scheduled — it starts on %s.', $start),
    ]);
} catch (Throwable $e) {
    app_log('seller/sale.php failed: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    json_fail('Could not schedule that sale right now. Please try again.', 'SERVER', 500);
}
