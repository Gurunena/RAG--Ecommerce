<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/_admin.php';
require_once __DIR__ . '/../lib/risk_engine.php';

try {
    require_method('GET');
    require_admin();

    $db = db();

    $status = query_str('status', 'open');
    $level  = query_str('level', 'all');
    $type   = query_str('type', 'all');
    $from   = query_str('from');
    $to     = query_str('to');

    $validStatus = ['open', 'under_review', 'confirmed_fraud', 'dismissed', 'all', 'unresolved'];
    if (!in_array($status, $validStatus, true)) {
        fail_validation(['status' => 'Unknown status filter']);
    }
    if (!in_array($level, ['medium', 'high', 'all'], true)) {
        fail_validation(['level' => 'Unknown level filter']);
    }
    if (!in_array($type, ['order', 'buyer', 'seller', 'all'], true)) {
        fail_validation(['type' => 'Unknown type filter']);
    }

    $dateIsValid = static function (string $value): bool {
        if ($value === '') {
            return true;
        }
        $parsed = DateTimeImmutable::createFromFormat('Y-m-d', $value);
        return $parsed !== false && $parsed->format('Y-m-d') === $value;
    };
    if (!$dateIsValid($from)) {
        fail_validation(['from' => 'Use YYYY-MM-DD']);
    }
    if (!$dateIsValid($to)) {
        fail_validation(['to' => 'Use YYYY-MM-DD']);
    }

    $page    = query_int('page', 1, 1, 10000) ?? 1;
    $perPage = query_int('per_page', 25, 1, 100) ?? 25;

    $where  = [];
    $params = [];

    if ($status === 'unresolved') {
        // The default working view: everything still needing a decision.
        $where[] = "a.status IN ('open', 'under_review')";
    } elseif ($status !== 'all') {
        $where[]  = 'a.status = ?';
        $params[] = $status;
    }
    if ($level !== 'all') {
        $where[]  = 'a.level = ?';
        $params[] = $level;
    }
    if ($type !== 'all') {
        $where[]  = 'a.alert_type = ?';
        $params[] = $type;
    }
    if ($from !== '') {
        $where[]  = 'a.created_at >= ?';
        $params[] = $from . ' 00:00:00';
    }
    if ($to !== '') {
        $where[]  = 'a.created_at <= ?';
        $params[] = $to . ' 23:59:59';
    }

    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $countStmt = $db->prepare("SELECT COUNT(*) FROM risk_alerts a $whereSql");
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    $pages = $total > 0 ? (int) ceil($total / $perPage) : 1;
    if ($page > $pages) {
        $page = $pages;
    }
    $offset = ($page - 1) * $perPage;

    $sql = "SELECT
                a.id, a.alert_type, a.order_id, a.sub_order_id, a.seller_id,
                a.user_id, a.level, a.score, a.status, a.review_note,
                a.created_at, a.reviewed_at,
                u.name  AS buyer_name,
                s.business_name AS seller_name,
                rev.name AS reviewed_by_name,
                o.total_amount, o.currency, o.created_at AS order_placed_at,
                (SELECT COUNT(*) FROM sub_orders h
                  WHERE h.order_id = a.order_id AND h.payout_hold = 1) AS held_sub_orders,
                (SELECT COALESCE(SUM(h2.subtotal), 0) FROM sub_orders h2
                  WHERE h2.order_id = a.order_id AND h2.payout_hold = 1) AS held_amount,
                rs.explanation, rs.explanation_source, rs.rule_score, rs.ml_score
              FROM risk_alerts a
              LEFT JOIN users   u   ON u.id   = a.user_id
              LEFT JOIN sellers s   ON s.id   = a.seller_id
              LEFT JOIN users   rev ON rev.id = a.reviewed_by
              LEFT JOIN orders  o   ON o.id   = a.order_id
              LEFT JOIN order_risk_scores rs ON rs.id = a.risk_score_id
              $whereSql
          ORDER BY (a.status IN ('open','under_review')) DESC,
                   FIELD(a.level, 'high', 'medium'),
                   a.created_at DESC,
                   a.id DESC
             LIMIT " . (int) $perPage . ' OFFSET ' . (int) $offset;

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $alerts = [];
    foreach ($rows as $row) {
        $alerts[] = [
            'id'          => (int) $row['id'],
            'type'        => (string) $row['alert_type'],
            'level'       => (string) $row['level'],
            'score'       => (int) $row['score'],
            'status'      => (string) $row['status'],
            'created_at'  => (string) $row['created_at'],
            'reviewed_at' => $row['reviewed_at'],
            'reviewed_by' => $row['reviewed_by_name'],
            'note'        => $row['review_note'],

            'order' => $row['order_id'] === null ? null : [
                'id'        => (int) $row['order_id'],
                'total'     => round((float) $row['total_amount'], 2),
                'currency'  => $row['currency'],
                'placed_at' => $row['order_placed_at'],
            ],
            'buyer'  => $row['user_id'] === null ? null : [
                'id'   => (int) $row['user_id'],
                'name' => $row['buyer_name'],
            ],
            'seller' => $row['seller_id'] === null ? null : [
                'id'   => (int) $row['seller_id'],
                'name' => $row['seller_name'],
            ],

            'held_sub_orders' => (int) $row['held_sub_orders'],
            'held_amount'     => round((float) $row['held_amount'], 2),

            'explanation'        => $row['explanation'],
            'explanation_source' => $row['explanation_source'],
            'rule_score'         => $row['rule_score'] === null ? null : (int) $row['rule_score'],
            'ml_score'           => $row['ml_score'] === null ? null : (int) $row['ml_score'],
        ];
    }

    $counts = ['open' => 0, 'under_review' => 0, 'confirmed_fraud' => 0, 'dismissed' => 0];
    foreach ($db->query('SELECT status, COUNT(*) n FROM risk_alerts GROUP BY status') as $row) {
        $counts[(string) $row['status']] = (int) $row['n'];
    }

    json_ok([
        'alerts'   => $alerts,
        'page'     => $page,
        'per_page' => $perPage,
        'total'    => $total,
        'pages'    => $pages,
        'filters'  => compact('status', 'level', 'type', 'from', 'to'),
        'counts'   => $counts,
    ]);
} catch (Throwable $e) {
    app_log('admin/risk_alerts.php failed: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    json_fail('Could not load the alert list right now.', 'SERVER', 500);
}
