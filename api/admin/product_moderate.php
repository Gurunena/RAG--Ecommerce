<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/_admin.php';

try {
    require_method('POST');
    csrf_check();

    $me      = require_admin();
    $adminId = (int) $me['id'];

    $id     = body_str('id');
    $action = body_str('action');
    $reason = body_str('reason');

    if ($id === '') {
        json_fail('No listing was specified.', 'VALIDATION', 400);
    }
    if (!in_array($action, ['approve', 'reject', 'takedown'], true)) {
        json_fail('Unknown action.', 'VALIDATION', 400);
    }

    $product = admin_find_product($id);
    if ($product === null) {
        json_fail('That listing could not be found.', 'NOT_FOUND', 404);
    }

    $productId = (int) $product['id'];
    $wasStatus = $product['status'];

    /* ---------- approve ---------- */
    if ($action === 'approve') {
        if ($wasStatus === 'active') {
            json_fail($product['title'] . ' is already live.', 'VALIDATION', 409);
        }

        db_transaction(function (PDO $pdo) use ($productId, $adminId, $product, $wasStatus): void {
            $pdo->prepare(
                "UPDATE products
                    SET status = 'active',
                        rejection_reason = NULL,
                        reviewed_at = NOW(),
                        reviewed_by = ?
                  WHERE id = ?"
            )->execute([$adminId, $productId]);

            admin_log(
                $pdo,
                $adminId,
                'approve_product',
                'product',
                $productId,
                sprintf('%s approved (was %s)', $product['title'], $wasStatus)
            );
        });

        app_log('Listing approved', ['admin' => $adminId, 'product' => $product['public_id']]);

        json_ok([
            'product' => admin_products_one($product['public_id']),
            'status'  => 'active',
            'message' => $product['title'] . ' is live on the storefront.',
        ]);
    }

    /* ---------- reject / take down ---------- */
    if ($reason === '') {
        fail_validation(
            ['reason' => 'Say what needs to change — the seller is shown this'],
            $action === 'takedown'
                ? 'A take-down needs a reason.'
                : 'A rejection needs a reason.'
        );
    }
    if (mb_strlen($reason) > 500) {
        fail_validation(['reason' => 'That reason is too long (500 characters max)']);
    }

    if ($wasStatus === 'rejected') {
        json_fail(
            $product['title'] . ' has already been rejected. Its reason is on the seller\'s dashboard.',
            'VALIDATION',
            409
        );
    }
    if ($action === 'takedown' && $wasStatus !== 'active') {
        json_fail(
            sprintf('%s is not live (it is %s), so there is nothing to take down. Reject it instead.', $product['title'], $wasStatus),
            'VALIDATION',
            409
        );
    }

    db_transaction(function (PDO $pdo) use ($productId, $adminId, $product, $reason, $action, $wasStatus): void {
        $pdo->prepare(
            "UPDATE products
                SET status = 'rejected',
                    rejection_reason = ?,
                    reviewed_at = NOW(),
                    reviewed_by = ?
              WHERE id = ?"
        )->execute([$reason, $adminId, $productId]);

        $pdo->prepare('DELETE FROM product_sales WHERE product_id = ? AND end_date >= CURDATE()')
            ->execute([$productId]);

        admin_log(
            $pdo,
            $adminId,
            $action === 'takedown' ? 'takedown_product' : 'reject_product',
            'product',
            $productId,
            sprintf('%s (was %s): %s', $product['title'], $wasStatus, $reason)
        );
    });

    app_log($action === 'takedown' ? 'Listing taken down' : 'Listing rejected', [
        'admin'   => $adminId,
        'product' => $product['public_id'],
    ]);

    json_ok([
        'product' => admin_products_one($product['public_id']),
        'status'  => 'rejected',
        'message' => $action === 'takedown'
            ? $product['title'] . ' has been taken down. The seller can see your reason.'
            : $product['title'] . ' was rejected. The seller can correct it and resubmit.',
    ]);
} catch (Throwable $e) {
    app_log('admin/product_moderate.php failed: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    json_fail('Could not moderate that listing right now. Please try again.', 'SERVER', 500);
}
