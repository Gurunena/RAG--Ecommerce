<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/_admin.php';

try {
    require_method('POST');
    csrf_check();

    $me      = require_admin();
    $adminId = (int) $me['id'];

    $userId = body_int('user_id', 0, 1);
    $action = body_str('action');
    $reason = body_str('reason');

    if (!$userId) {
        json_fail('No user was specified.', 'VALIDATION', 400);
    }
    if (!in_array($action, ['suspend', 'reactivate'], true)) {
        json_fail('Unknown action.', 'VALIDATION', 400);
    }
    if (mb_strlen($reason) > 500) {
        fail_validation(['reason' => 'That note is too long (500 characters max)']);
    }

    $stmt = db()->prepare('SELECT id, name, email, role, status FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $user = $stmt->fetch();

    if (!$user) {
        json_fail('That user could not be found.', 'NOT_FOUND', 404);
    }

    $target = $action === 'suspend' ? 'suspended' : 'active';

    if ($user['status'] === $target) {
        json_fail(
            sprintf('%s is already %s.', $user['name'], $target === 'active' ? 'active' : 'suspended'),
            'VALIDATION',
            409
        );
    }

    /* ---------- the two locks ---------- */
    if ($action === 'suspend') {
        if ($userId === $adminId) {
            json_fail(
                'You cannot suspend your own account — you would be locked out of this console.',
                'VALIDATION',
                409
            );
        }

        if ($user['role'] === 'admin') {
            // Counted in SQL, excluding this one, so the check is about what
            // would be LEFT rather than what exists now.
            $others = db()->prepare(
                "SELECT COUNT(*) FROM users WHERE role = 'admin' AND status = 'active' AND id <> ?"
            );
            $others->execute([$userId]);

            if ((int) $others->fetchColumn() === 0) {
                json_fail(
                    'That is the last active admin. Promote another admin before suspending this one.',
                    'VALIDATION',
                    409
                );
            }
        }
    }

    db_transaction(function (PDO $pdo) use ($userId, $target, $adminId, $reason, $user, $action): void {
        $pdo->prepare('UPDATE users SET status = ? WHERE id = ?')->execute([$target, $userId]);

        admin_log(
            $pdo,
            $adminId,
            $action === 'suspend' ? 'suspend_user' : 'reactivate_user',
            'user',
            $userId,
            sprintf(
                '%s (%s)%s',
                $user['name'],
                $user['role'],
                $reason !== '' ? ': ' . $reason : ''
            )
        );
    });

    app_log('User status changed', [
        'admin' => $adminId,
        'user'  => $userId,
        'to'    => $target,
    ]);

    json_ok([
        'user'    => admin_find_user($userId),
        'status'  => $target,
        'message' => $action === 'suspend'
            ? $user['name'] . ' has been suspended — they are signed out on their next request.'
            : $user['name'] . ' has been reactivated.',
    ]);
} catch (Throwable $e) {
    app_log('admin/user_status.php failed: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    json_fail('Could not update that account right now. Please try again.', 'SERVER', 500);
}
