<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/_admin.php';
require_once __DIR__ . '/../lib/risk_engine.php';
require_once __DIR__ . '/../lib/risk_persist.php';

/** The shortest note that can actually mean something. */
const RISK_NOTE_MIN = 10;
const RISK_NOTE_MAX = 500;

try {
    require_method('POST');
    csrf_check();

    $me      = require_admin();
    $adminId = (int) $me['id'];

    $alertId  = body_int('alert_id', 0, 1);
    $decision = body_str('decision');
    $note     = trim(body_str('note'));

    if (!$alertId) {
        json_fail('No alert was specified.', 'VALIDATION', 400);
    }

    $allowed = ['under_review', 'confirmed_fraud', 'dismissed'];
    if (!in_array($decision, $allowed, true)) {
        fail_validation(['decision' => 'Choose under review, confirmed or dismissed']);
    }

    if (mb_strlen($note) < RISK_NOTE_MIN) {
        fail_validation([
            'note' => $note === ''
                ? 'A note is required — say why you reached this decision'
                : sprintf('Please write at least %d characters', RISK_NOTE_MIN),
        ]);
    }
    if (mb_strlen($note) > RISK_NOTE_MAX) {
        fail_validation(['note' => sprintf('That note is too long (%d characters max)', RISK_NOTE_MAX)]);
    }

    $result = db_transaction(static function (PDO $pdo) use ($alertId, $decision, $note, $adminId): array {
        $stmt = $pdo->prepare(
            'SELECT id, alert_type, order_id, seller_id, user_id, level, score, status
               FROM risk_alerts
              WHERE id = ?
                FOR UPDATE'
        );
        $stmt->execute([$alertId]);
        $alert = $stmt->fetch();

        if (!$alert) {
            throw new AdminOrderProblem('That alert could not be found.');
        }

        $current = (string) $alert['status'];

        if (in_array($current, ['confirmed_fraud', 'dismissed'], true)) {
            throw new AdminOrderProblem(sprintf(
                'That alert was already %s. Re-score the order to raise a fresh alert if the situation has changed.',
                str_replace('_', ' ', $current)
            ));
        }
        if ($current === $decision) {
            throw new AdminOrderProblem('That alert is already under review.');
        }

        $pdo->prepare(
            'UPDATE risk_alerts
                SET status = ?, reviewed_by = ?, review_note = ?, reviewed_at = NOW()
              WHERE id = ?'
        )->execute([$decision, $adminId, $note, $alertId]);

        $released = 0;
        $orderId = $alert['order_id'] === null ? null : (int) $alert['order_id'];

        if ($decision === 'dismissed' && $orderId !== null) {
            $released = risk_release_hold(
                $pdo,
                $orderId,
                'Reviewed and cleared by an admin — payout hold released'
            );
        }

        if ($decision === 'confirmed_fraud' && $orderId !== null) {
            risk_log_status_note($pdo, $orderId, 'Reviewed by an admin — flagged, payout remains held');
        }

        admin_log(
            $pdo,
            $adminId,
            'risk_' . $decision,
            'risk_alert',
            $alertId,
            sprintf(
                '%s alert #%d (%s, score %d)%s: %s',
                ucfirst((string) $alert['alert_type']),
                $alertId,
                (string) $alert['level'],
                (int) $alert['score'],
                $released > 0 ? sprintf(' — released %d payout hold(s)', $released) : '',
                $note
            )
        );

        return [
            'alert_id'   => $alertId,
            'status'     => $decision,
            'order_id'   => $orderId,
            'seller_id'  => $alert['seller_id'] === null ? null : (int) $alert['seller_id'],
            'user_id'    => $alert['user_id'] === null ? null : (int) $alert['user_id'],
            'released'   => $released,
            'alert_type' => (string) $alert['alert_type'],
        ];
    });

    app_log('Risk alert decided', [
        'alert'    => $result['alert_id'],
        'decision' => $result['status'],
        'admin'    => $adminId,
        'released' => $result['released'],
    ]);

    $nextActions = [];
    if ($result['status'] === 'confirmed_fraud') {
        if ($result['order_id'] !== null) {
            $nextActions[] = [
                'label'    => 'Refund or cancel this order',
                'endpoint' => 'admin/order_action.php',
                'note'     => 'Moves money and changes the order status. Confirmed separately.',
            ];
        }
        if ($result['user_id'] !== null) {
            $nextActions[] = [
                'label'    => 'Suspend this account',
                'endpoint' => 'admin/user_status.php',
                'note'     => 'Takes effect on their very next request. Reversible.',
            ];
        }
        if ($result['seller_id'] !== null) {
            $nextActions[] = [
                'label'    => 'Review this seller',
                'endpoint' => 'admin/seller_verify.php',
                'note'     => 'Revoking verification unpublishes their listings.',
            ];
        }
    }

    $message = match ($result['status']) {
        'under_review' => 'Marked as under review.',
        'dismissed'    => $result['released'] > 0
            ? sprintf('Dismissed. %d payout hold(s) released.', $result['released'])
            : 'Dismissed. There was no payout hold to release.',
        default        => 'Flagged as confirmed. The payout stays held — '
            . 'nothing has been cancelled or refunded automatically.',
    };

    json_ok([
        'alert'        => $result,
        'message'      => $message,
        'next_actions' => $nextActions,
    ]);
} catch (AdminOrderProblem $e) {
    json_fail($e->getMessage(), 'VALIDATION', 409);
} catch (Throwable $e) {
    app_log('admin/risk_alert_decide.php failed: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    json_fail('Could not record that decision right now. Please try again.', 'SERVER', 500);
}
