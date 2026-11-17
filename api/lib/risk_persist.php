<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli' && basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit("This is a library, not an endpoint.\n");
}

require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/risk_engine.php';
require_once __DIR__ . '/risk_ml.php';

/** Who asked for the score. Matches order_risk_scores.scored_by. */
const RISK_SCORED_BY = ['checkout', 'sweep', 'admin'];

function risk_score_order(int $orderId, string $scoredBy = 'checkout', bool $force = false): array
{
    $outcome = [
        'ok'                 => false,
        'order_id'           => $orderId,
        'score'              => 0,
        'level'              => 'unscored',
        'rule_score'         => 0,
        'ml_score'           => null,
        'signals'            => [],
        'explanation'        => '',
        'explanation_source' => 'template',
        'score_id'           => null,
        'alert_id'           => null,
        'held'               => 0,
        'reason'             => null,
    ];

    if (!in_array($scoredBy, RISK_SCORED_BY, true)) {
        $scoredBy = 'admin';
    }

    try {
        $db = db();

        /* ---------- the rules ---------- */
        $result = score_order($db, $orderId);

        if (($result['order'] ?? null) === null) {
            $outcome['reason'] = $result['reason'] ?? 'order not found';
            return $outcome;
        }

        $mlWhy = null;
        $ml = risk_ml_score($db, $orderId, $mlWhy);
        $blend = risk_blend((int) $result['rule_score'], $ml['ml_score'] ?? null);

        $result['score'] = $blend['score'];
        $result['level'] = risk_level_for($blend['score']);

        $explained = risk_explain($result);

        $outcome = array_merge($outcome, [
            'ok'                 => true,
            'score'              => $blend['score'],
            'level'              => $result['level'],
            'rule_score'         => $blend['rule_score'],
            'ml_score'           => $blend['ml_score'],
            'signals'            => $result['signals'],
            'explanation'        => $explained['text'],
            'explanation_source' => $explained['source'] === 'llm' ? 'llm' : 'template',
        ]);

        $written = db_transaction(static function (PDO $pdo) use (
            $orderId, $result, $blend, $explained, $ml, $scoredBy, $force
        ): array {
            if (!$force && risk_score_exists($pdo, $orderId)) {
                return ['score_id' => null, 'alert_id' => null, 'held' => 0, 'skipped' => true];
            }

            $scoreId = risk_insert_score($pdo, $orderId, $result, $blend, $explained, $ml, $scoredBy);

            $alertId = null;
            $held    = 0;

            if (in_array($result['level'], ['medium', 'high'], true)) {
                $alertId = risk_raise_alert($pdo, [
                    'alert_type'    => 'order',
                    'order_id'      => $orderId,
                    'user_id'       => $result['order']['user_id'] ?? null,
                    'level'         => $result['level'],
                    'score'         => $blend['score'],
                    'risk_score_id' => $scoreId,
                ]);
            }

            if (risk_should_hold($result['level'])) {
                $held = risk_apply_hold(
                    $pdo,
                    $orderId,
                    $alertId,
                    sprintf('Risk %s (%d) — awaiting review', $result['level'], $blend['score'])
                );
            }

            return ['score_id' => $scoreId, 'alert_id' => $alertId, 'held' => $held, 'skipped' => false];
        });

        $outcome['score_id'] = $written['score_id'];
        $outcome['alert_id'] = $written['alert_id'];
        $outcome['held']     = $written['held'];

        if ($written['skipped']) {
            $outcome['reason'] = 'already scored (pass force to re-score)';
        }

        return $outcome;
    } catch (Throwable $e) {
        app_log('Risk scoring failed for order ' . $orderId . ': ' . $e->getMessage()
            . ' @ ' . $e->getFile() . ':' . $e->getLine());

        $outcome['reason'] = 'scoring failed: ' . $e->getMessage();

        try {
            $stmt = db()->prepare(
                'INSERT INTO order_risk_scores
                    (order_id, score, level, rule_score, signals, explanation, scored_by)
                 VALUES (?, 0, \'unscored\', 0, ?, ?, ?)'
            );
            $stmt->execute([
                $orderId,
                json_encode([]),
                'Scoring did not complete. An admin can re-run it.',
                $scoredBy,
            ]);
            $outcome['score_id'] = (int) db()->lastInsertId();
        } catch (Throwable $inner) {
            // The database itself is unhappy. Log and give up quietly —
            // there is nothing useful left to try, and the caller must
            // still succeed.
            app_log('Risk scoring could not even record the failure: ' . $inner->getMessage());
        }

        return $outcome;
    }
}

/** Has this order been scored already? */
function risk_score_exists(PDO $db, int $orderId): bool
{
    $stmt = $db->prepare(
        'SELECT 1 FROM order_risk_scores WHERE order_id = ? AND level <> \'unscored\' LIMIT 1'
    );
    $stmt->execute([$orderId]);
    return (bool) $stmt->fetchColumn();
}

/** Insert the score row and return its id. */
function risk_insert_score(
    PDO $pdo,
    int $orderId,
    array $result,
    array $blend,
    array $explained,
    ?array $ml,
    string $scoredBy
): int {
    $pdo->prepare(
        'INSERT INTO order_risk_scores
            (order_id, score, level, rule_score, ml_score, signals,
             explanation, explanation_source, model_version, scored_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        $orderId,
        $blend['score'],
        $result['level'],
        $blend['rule_score'],
        $blend['ml_score'],
        json_encode($result['signals'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        mb_substr($explained['text'], 0, 1000),
        $explained['source'] === 'llm' ? 'llm' : 'template',
        $ml['model_version'] ?? null,
        $scoredBy,
    ]);

    return (int) $pdo->lastInsertId();
}

function risk_raise_alert(PDO $pdo, array $alert): ?int
{
    $type = $alert['alert_type'] ?? 'order';

    $existing = match ($type) {
        'order' => (static function () use ($pdo, $alert) {
            $stmt = $pdo->prepare(
                'SELECT id FROM risk_alerts
                  WHERE alert_type = \'order\' AND order_id = ?
                    AND status IN (\'open\', \'under_review\')
                  LIMIT 1'
            );
            $stmt->execute([$alert['order_id'] ?? 0]);
            return $stmt->fetchColumn();
        })(),
        'seller' => (static function () use ($pdo, $alert) {
            $stmt = $pdo->prepare(
                'SELECT id FROM risk_alerts
                  WHERE alert_type = \'seller\' AND seller_id = ?
                    AND status IN (\'open\', \'under_review\')
                  LIMIT 1'
            );
            $stmt->execute([$alert['seller_id'] ?? 0]);
            return $stmt->fetchColumn();
        })(),
        default => (static function () use ($pdo, $alert) {
            $stmt = $pdo->prepare(
                'SELECT id FROM risk_alerts
                  WHERE alert_type = \'buyer\' AND user_id = ?
                    AND status IN (\'open\', \'under_review\')
                  LIMIT 1'
            );
            $stmt->execute([$alert['user_id'] ?? 0]);
            return $stmt->fetchColumn();
        })(),
    };

    if ($existing !== false && $existing !== null) {
        $pdo->prepare(
            'UPDATE risk_alerts
                SET score = GREATEST(score, ?),
                    level = CASE WHEN ? = \'high\' THEN \'high\' ELSE level END
              WHERE id = ?'
        )->execute([
            (int) ($alert['score'] ?? 0),
            (string) ($alert['level'] ?? 'medium'),
            (int) $existing,
        ]);

        return null;
    }

    $pdo->prepare(
        'INSERT INTO risk_alerts
            (alert_type, order_id, sub_order_id, seller_id, user_id,
             level, score, risk_score_id)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        $type,
        $alert['order_id']      ?? null,
        $alert['sub_order_id']  ?? null,
        $alert['seller_id']     ?? null,
        $alert['user_id']       ?? null,
        $alert['level']         ?? 'medium',
        (int) ($alert['score']  ?? 0),
        $alert['risk_score_id'] ?? null,
    ]);

    return (int) $pdo->lastInsertId();
}

/** Should this level hold the payout? Reads the two settings. */
function risk_should_hold(string $level): bool
{
    $config = risk_config();

    if (!$config['holds_enabled']) {
        return false;
    }

    $at = $config['hold_at_level'];

    return match ($at) {
        'medium' => in_array($level, ['medium', 'high'], true),
        'high'   => $level === 'high',
        default  => false,
    };
}

function risk_apply_hold(PDO $pdo, int $orderId, ?int $alertId, string $reason): int
{
    $stmt = $pdo->prepare(
        'UPDATE sub_orders
            SET payout_hold = 1,
                payout_hold_at = NOW(),
                payout_hold_reason = ?,
                payout_hold_alert_id = ?
          WHERE order_id = ?
            AND payout_hold = 0
            AND payout_released_at IS NULL'
    );
    $stmt->execute([mb_substr($reason, 0, 255), $alertId, $orderId]);

    $held = $stmt->rowCount();

    if ($held > 0) {
        risk_log_status_note($pdo, $orderId, 'Flagged for review — payout held');
    }

    return $held;
}

function risk_release_hold(PDO $pdo, int $orderId, string $note = 'Review complete — payout hold released'): int
{
    $stmt = $pdo->prepare(
        'UPDATE sub_orders
            SET payout_hold = 0,
                payout_hold_reason = NULL,
                payout_hold_alert_id = NULL
          WHERE order_id = ? AND payout_hold = 1'
    );
    $stmt->execute([$orderId]);

    $released = $stmt->rowCount();

    if ($released > 0) {
        risk_log_status_note($pdo, $orderId, $note);
    }

    return $released;
}

function risk_log_status_note(PDO $pdo, int $orderId, string $note): int
{
    $stmt = $pdo->prepare(
        'INSERT INTO order_status_log (sub_order_id, status, changed_by, note, internal)
         SELECT so.id, so.status, \'system\', ?, 1
           FROM sub_orders so
          WHERE so.order_id = ?'
    );
    $stmt->execute([mb_substr($note, 0, 255), $orderId]);

    return $stmt->rowCount();
}

function risk_score_subject(string $subject, int $id, ?string $asOf = null): array
{
    $outcome = [
        'ok'       => false,
        'level'    => 'unscored',
        'score'    => 0,
        'alert_id' => null,
        'signals'  => [],
        'reason'   => null,
    ];

    try {
        $db = db();

        $result = $subject === 'seller'
            ? score_seller($db, $id, $asOf)
            : score_user($db, $id, $asOf);

        if (isset($result['reason']) && !isset($result['signals'])) {
            $outcome['reason'] = (string) $result['reason'];
            return $outcome;
        }

        $outcome['ok']      = true;
        $outcome['level']   = (string) $result['level'];
        $outcome['score']   = (int) $result['score'];
        $outcome['signals'] = $result['signals'] ?? [];

        if (!in_array($result['level'], ['medium', 'high'], true)) {
            return $outcome;
        }

        $outcome['alert_id'] = db_transaction(static function (PDO $pdo) use ($subject, $id, $result): ?int {
            return risk_raise_alert($pdo, [
                'alert_type' => $subject === 'seller' ? 'seller' : 'buyer',
                'seller_id'  => $subject === 'seller' ? $id : null,
                'user_id'    => $subject === 'seller' ? null : $id,
                'level'      => $result['level'],
                'score'      => (int) $result['score'],
            ]);
        });

        return $outcome;
    } catch (Throwable $e) {
        app_log("Risk scoring failed for $subject $id: " . $e->getMessage());
        $outcome['reason'] = 'scoring failed: ' . $e->getMessage();
        return $outcome;
    }
}

function risk_latest_score(PDO $db, int $orderId): ?array
{
    $stmt = $db->prepare(
        'SELECT id, order_id, score, level, rule_score, ml_score, signals,
                explanation, explanation_source, model_version, scored_by, created_at
           FROM order_risk_scores
          WHERE order_id = ?
       ORDER BY created_at DESC, id DESC
          LIMIT 1'
    );
    $stmt->execute([$orderId]);
    $row = $stmt->fetch();

    if (!$row) {
        return null;
    }

    $signals = json_decode((string) $row['signals'], true);

    return [
        'id'                 => (int) $row['id'],
        'order_id'           => (int) $row['order_id'],
        'score'              => (int) $row['score'],
        'level'              => (string) $row['level'],
        'rule_score'         => (int) $row['rule_score'],
        'ml_score'           => $row['ml_score'] === null ? null : (int) $row['ml_score'],
        'signals'            => is_array($signals) ? $signals : [],
        'explanation'        => (string) ($row['explanation'] ?? ''),
        'explanation_source' => (string) $row['explanation_source'],
        'model_version'      => $row['model_version'],
        'scored_by'          => (string) $row['scored_by'],
        'scored_at'          => (string) $row['created_at'],
    ];
}
