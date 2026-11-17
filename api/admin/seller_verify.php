<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/_admin.php';

try {
    require_method('POST');
    csrf_check();

    $me      = require_admin();
    $adminId = (int) $me['id'];

    $sellerId = body_int('seller_id', 0, 1);
    $action   = body_str('action');
    $reason   = body_str('reason');

    if (!$sellerId) {
        json_fail('No seller was specified.', 'VALIDATION', 400);
    }
    if (!in_array($action, ['approve', 'reject', 'commission'], true)) {
        json_fail('Unknown action.', 'VALIDATION', 400);
    }

    /* The row must exist and must actually be a seller. */
    $stmt = db()->prepare(
        'SELECT s.id, s.business_name, s.kyc_status, s.commission_rate, u.name, u.role
           FROM sellers s
           JOIN users u ON u.id = s.id
          WHERE s.id = ?'
    );
    $stmt->execute([$sellerId]);
    $seller = $stmt->fetch();

    if (!$seller || $seller['role'] !== 'seller') {
        json_fail('That seller could not be found.', 'NOT_FOUND', 404);
    }

    if ($action === 'commission') {
        $body = json_body();
        $raw  = $body['rate'] ?? null;

        // null / '' means "use the platform default", which is what a NULL
        // in sellers.commission_rate means. Those have to stay distinct from
        // a genuine 0%.
        $rate = ($raw === null || $raw === '') ? null : (is_numeric($raw) ? (float) $raw : false);

        if ($rate === false) {
            fail_validation(['rate' => 'Enter a number, or leave it empty for the platform default']);
        }
        // CHECK (commission_rate IS NULL OR BETWEEN 0 AND 50) in the schema.
        if ($rate !== null && ($rate < 0 || $rate > 50)) {
            fail_validation(['rate' => 'Enter a rate between 0 and 50']);
        }

        db_transaction(function (PDO $pdo) use ($sellerId, $rate, $adminId, $seller): void {
            $pdo->prepare('UPDATE sellers SET commission_rate = ? WHERE id = ?')
                ->execute([$rate, $sellerId]);

            admin_log(
                $pdo,
                $adminId,
                'set_commission',
                'seller',
                $sellerId,
                $rate === null
                    ? 'reset ' . $seller['business_name'] . ' to the platform default'
                    : sprintf('%s set to %s%%', $seller['business_name'], rtrim(rtrim(number_format($rate, 2, '.', ''), '0'), '.'))
            );
        });

        json_ok([
            'seller'  => admin_find_user($sellerId),
            'message' => $rate === null
                ? 'Reset to the platform default.'
                : sprintf('Rate set to %s%%.', rtrim(rtrim(number_format($rate, 2, '.', ''), '0'), '.')),
        ]);
    }

    $target = $action === 'approve' ? 'verified' : 'rejected';

    if ($seller['kyc_status'] === $target) {
        json_fail(
            sprintf('%s is already %s.', $seller['business_name'], $target),
            'VALIDATION',
            409
        );
    }

    if ($action === 'reject') {
        if ($reason === '') {
            fail_validation(
                ['reason' => 'Give a reason — the seller is shown it so they can fix it and reapply'],
                'A rejection needs a reason.'
            );
        }
        if (mb_strlen($reason) > 500) {
            fail_validation(['reason' => 'That reason is too long (500 characters max)']);
        }
    }

    db_transaction(function (PDO $pdo) use ($sellerId, $target, $reason, $adminId, $seller, $action): void {
        $pdo->prepare(
            'UPDATE sellers
                SET kyc_status = ?,
                    kyc_rejection_reason = ?,
                    kyc_reviewed_at = NOW(),
                    kyc_reviewed_by = ?
              WHERE id = ?'
        )->execute([
            $target,
            // An approval clears any previous rejection reason, so a seller
            // who fixed their details is not still shown the old complaint.
            $target === 'rejected' ? $reason : null,
            $adminId,
            $sellerId,
        ]);

        admin_log(
            $pdo,
            $adminId,
            $action === 'approve' ? 'approve_seller' : 'reject_seller',
            'seller',
            $sellerId,
            $action === 'approve'
                ? 'verified ' . $seller['business_name']
                : $seller['business_name'] . ': ' . $reason
        );
    });

    app_log('Seller verification decided', [
        'admin'  => $adminId,
        'seller' => $sellerId,
        'to'     => $target,
    ]);

    json_ok([
        'seller'  => admin_find_user($sellerId),
        'message' => $action === 'approve'
            ? $seller['business_name'] . ' is verified — their dashboard is now open.'
            : $seller['business_name'] . ' was rejected. They can see the reason and reapply.',
    ]);
} catch (Throwable $e) {
    app_log('admin/seller_verify.php failed: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    json_fail('Could not update that seller right now. Please try again.', 'SERVER', 500);
}
