<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/_admin.php';
require_once __DIR__ . '/../lib/risk_engine.php';
require_once __DIR__ . '/../lib/risk_persist.php';

require_once __DIR__ . '/../lib/risk_sweep_lib.php';

/** The longest sweep window an admin can ask for in one click. */
const RESCORE_MAX_HOURS = 24 * 14;

try {
    require_method('POST');
    csrf_check();

    $me      = require_admin();
    $adminId = (int) $me['id'];

    $sweep   = body_bool('sweep');
    $orderId = body_int('order_id', 0, 1);

    if (!$sweep && !$orderId) {
        fail_validation(['order_id' => 'Give an order to re-score, or ask for a sweep']);
    }

    if (!$sweep) {
        $stmt = db()->prepare('SELECT id FROM orders WHERE id = ?');
        $stmt->execute([$orderId]);
        if (!$stmt->fetchColumn()) {
            json_fail('That order could not be found.', 'NOT_FOUND', 404);
        }

        $result = risk_score_order($orderId, 'admin', true);

        db_transaction(static function (PDO $pdo) use ($adminId, $orderId, $result): void {
            admin_log(
                $pdo,
                $adminId,
                'risk_rescore_order',
                'order',
                $orderId,
                $result['ok']
                    ? sprintf(
                        'Re-scored: %s (%d)%s%s',
                        $result['level'],
                        $result['score'],
                        $result['alert_id'] !== null ? ', alert raised' : '',
                        $result['held'] > 0 ? sprintf(', %d payout hold(s) applied', $result['held']) : ''
                    )
                    : 'Re-score did not complete: ' . (string) $result['reason']
            );
        });

        app_log('Admin re-scored an order', [
            'admin' => $adminId,
            'order' => $orderId,
            'level' => $result['level'],
        ]);

        json_ok([
            'order_id'    => $orderId,
            'ok'          => $result['ok'],
            'score'       => $result['score'],
            'level'       => $result['level'],
            'rule_score'  => $result['rule_score'],
            'ml_score'    => $result['ml_score'],
            'blended'     => $result['ml_score'] !== null,
            'signals'     => $result['signals'],
            'explanation' => $result['explanation'],
            'explanation_source' => $result['explanation_source'],
            'alert_id'    => $result['alert_id'],
            'held'        => $result['held'],
            'reason'      => $result['reason'],
            'message'     => $result['ok']
                ? sprintf(
                    'Re-scored: %s risk (%d).%s',
                    $result['level'],
                    $result['score'],
                    $result['held'] > 0
                        ? sprintf(' %d payout hold(s) applied.', $result['held'])
                        : ''
                )
                : 'Scoring did not complete. The order is recorded as unscored — '
                  . 'check the application log and try again.',
        ]);
    }

    $hours = body_int('hours', SWEEP_DEFAULT_HOURS, 1, RESCORE_MAX_HOURS) ?? SWEEP_DEFAULT_HOURS;

    @set_time_limit(300);

    $stats = risk_sweep_run([
        'hours'   => $hours,
        'orders'  => true,
        'sellers' => true,
        'buyers'  => true,
        // Not forced. An admin-triggered sweep is "look for anything new",
        // not "redo everything" — that is what the per-order button is for.
        'force'   => false,
    ]);

    db_transaction(static function (PDO $pdo) use ($adminId, $stats, $hours): void {
        admin_log(
            $pdo,
            $adminId,
            'risk_sweep',
            'settings',
            null,
            sprintf(
                'Ran sweep over %dh: %d scored, %d alerts, %d hold(s), %d seller(s), %d buyer(s)',
                $hours,
                $stats['orders_scored'],
                $stats['alerts_raised'],
                $stats['payouts_held'],
                $stats['sellers_checked'],
                $stats['buyers_checked']
            )
        );
    });

    app_log('Admin ran the risk sweep', ['admin' => $adminId, 'hours' => $hours]);

    json_ok([
        'sweep'   => $stats,
        'message' => sprintf(
            'Swept the last %d hours: %d order(s) scored, %d alert(s) raised, %d payout hold(s) applied.%s',
            $hours,
            $stats['orders_scored'],
            $stats['alerts_raised'],
            $stats['payouts_held'],
            $stats['ml_used'] === 0 && $stats['ml_missing'] > 0
                ? ' The AI service was not reachable, so these are rule scores only.'
                : ''
        ),
    ]);
} catch (Throwable $e) {
    app_log('admin/risk_rescore.php failed: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    json_fail('Could not re-score right now. Please try again.', 'SERVER', 500);
}
