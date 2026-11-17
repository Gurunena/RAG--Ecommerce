<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

const FAQ_CATEGORY_LABELS = [
    'general'  => 'General',
    'ordering' => 'Ordering & delivery',
    'payments' => 'Payments & refunds',
    'sellers'  => 'Selling on Consult',
    'selling'  => 'Selling on Consult',
    'account'  => 'Your account',
];

try {
    require_method('GET');

    $category = mb_strtolower(query_str('category'));

    $where = '';
    $args  = [];

    if ($category !== '' && $category !== 'all') {
        // Matched as a parameter, never interpolated — an unknown category
        // simply returns nothing rather than erroring.
        $where  = 'WHERE category = ?';
        $args[] = $category;
    }

    $stmt = db()->prepare(
        "SELECT id, question, answer, category, sort_order, updated_at
           FROM faqs
           $where
       ORDER BY category, sort_order, id"
    );
    $stmt->execute($args);
    $rows = $stmt->fetchAll();

    $items  = [];
    $groups = [];

    foreach ($rows as $row) {
        $cat = $row['category'];

        $item = [
            'id'       => (int) $row['id'],
            'question' => $row['question'],
            'answer'   => $row['answer'],
            'category' => $cat,
        ];

        $items[] = $item;

        if (!isset($groups[$cat])) {
            $groups[$cat] = [
                'category' => $cat,
                'label'    => FAQ_CATEGORY_LABELS[$cat] ?? ucfirst(str_replace(['_', '-'], ' ', $cat)),
                'items'    => [],
            ];
        }
        $groups[$cat]['items'][] = $item;
    }

    json_ok([
        'items' => $items,
        // array_values so this is a JSON array, not an object keyed by
        // category — the page maps over it in the order given.
        'groups' => array_values($groups),
        'total'  => count($items),
    ]);
} catch (Throwable $e) {
    app_log('faqs.php failed: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    json_fail('Could not load the FAQ right now. Please try again.', 'SERVER', 500);
}
