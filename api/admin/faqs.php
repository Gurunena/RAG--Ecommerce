<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/_admin.php';

try {
    require_method('POST');
    csrf_check();

    $me      = require_admin();
    $adminId = (int) $me['id'];

    $action = body_str('action', 'save');

    if ($action === 'delete') {
        $id = body_int('id', 0, 1);
        if (!$id) {
            json_fail('No FAQ was specified.', 'VALIDATION', 400);
        }

        $stmt = db()->prepare('SELECT id, question FROM faqs WHERE id = ?');
        $stmt->execute([$id]);
        $faq = $stmt->fetch();

        if (!$faq) {
            json_fail('That FAQ could not be found.', 'NOT_FOUND', 404);
        }

        db_transaction(function (PDO $pdo) use ($id, $adminId, $faq): void {
            admin_log($pdo, $adminId, 'delete_faq', 'faq', $id, 'deleted: ' . $faq['question']);

            $pdo->prepare('DELETE FROM faqs WHERE id = ?')->execute([$id]);
        });

        app_log('FAQ deleted', ['admin' => $adminId, 'faq' => $id]);

        json_ok([
            'id'      => $id,
            'message' => 'FAQ deleted.',
        ]);
    }

    if ($action !== 'save') {
        json_fail('Unknown action.', 'VALIDATION', 400);
    }

    $id       = body_int('id', 0, 1);
    $question = body_str('question');
    $answer   = body_str('answer');
    $category = mb_strtolower(body_str('category', 'general'));
    $sort     = body_int('sort_order', null, 0, 9999);

    $errors = [];

    if ($question === '') {
        $errors['question'] = 'The question is required';
    } elseif (mb_strlen($question) < 8) {
        $errors['question'] = 'Write the question out in full';
    } elseif (mb_strlen($question) > 255) {
        $errors['question'] = 'That question is too long (255 characters max)';
    }

    if ($answer === '') {
        $errors['answer'] = 'The answer is required';
    } elseif (mb_strlen($answer) < 10) {
        $errors['answer'] = 'Give a fuller answer';
    } elseif (mb_strlen($answer) > 5000) {
        $errors['answer'] = 'That answer is too long (5000 characters max)';
    }

    if ($category === '') {
        $category = 'general';
    }
    if (!preg_match('/^[a-z0-9 _-]{2,60}$/', $category)) {
        $errors['category'] = 'Categories are lowercase letters, digits, spaces and dashes';
    }

    if ($errors) {
        fail_validation($errors);
    }

    /* An existing row must exist before we claim to be editing it. */
    $existing = null;
    if ($id) {
        $stmt = db()->prepare('SELECT id, question, category, sort_order FROM faqs WHERE id = ?');
        $stmt->execute([$id]);
        $existing = $stmt->fetch() ?: null;

        if ($existing === null) {
            json_fail('That FAQ could not be found.', 'NOT_FOUND', 404);
        }
    }

    if ($sort === null) {
        if ($existing !== null) {
            $sort = (int) $existing['sort_order'];
        } else {
            $next = db()->prepare('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM faqs WHERE category = ?');
            $next->execute([$category]);
            $sort = (int) $next->fetchColumn();
        }
    }

    $faqId = db_transaction(function (PDO $pdo) use ($existing, $id, $question, $answer, $category, $sort, $adminId): int {
        if ($existing !== null) {
            $pdo->prepare(
                'UPDATE faqs SET question = ?, answer = ?, category = ?, sort_order = ? WHERE id = ?'
            )->execute([$question, $answer, $category, $sort, $id]);

            admin_log($pdo, $adminId, 'update_faq', 'faq', $id, 'edited: ' . $question);
            return $id;
        }

        $pdo->prepare(
            'INSERT INTO faqs (question, answer, category, sort_order) VALUES (?, ?, ?, ?)'
        )->execute([$question, $answer, $category, $sort]);

        $newId = (int) $pdo->lastInsertId();
        admin_log($pdo, $adminId, 'create_faq', 'faq', $newId, 'added: ' . $question);

        return $newId;
    });

    // Columns named, for the same reason as everywhere else in this API:
    // SELECT * returns whatever the table grows next.
    $stmt = db()->prepare(
        'SELECT id, question, answer, category, sort_order, created_at, updated_at
           FROM faqs
          WHERE id = ?'
    );
    $stmt->execute([$faqId]);
    $row = $stmt->fetch();

    app_log($existing === null ? 'FAQ created' : 'FAQ updated', ['admin' => $adminId, 'faq' => $faqId]);

    json_ok([
        'faq' => [
            'id'         => (int) $row['id'],
            'question'   => $row['question'],
            'answer'     => $row['answer'],
            'category'   => $row['category'],
            'sort_order' => (int) $row['sort_order'],
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
        ],
        'created' => $existing === null,
        'message' => $existing === null ? 'FAQ added.' : 'FAQ updated.',
    ], $existing === null ? 201 : 200);
} catch (Throwable $e) {
    app_log('admin/faqs.php failed: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    json_fail('Could not save that FAQ right now. Please try again.', 'SERVER', 500);
}
