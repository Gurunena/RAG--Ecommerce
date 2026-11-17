<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

/** Messages per IP per hour before we stop accepting them. */
const CONTACT_MAX_PER_HOUR = 5;

/** A real person takes longer than this to fill the form in. */
const CONTACT_MIN_SECONDS = 2;

try {
    require_method('POST');
    csrf_check();

    $me     = current_user();
    $userId = $me ? (int) $me['id'] : null;

    $name    = body_str('name');
    $email    = mb_strtolower(body_str('email'));
    $subject = body_str('subject');
    $message = body_str('message');
    $trap    = body_str('website');          // the honeypot

    if ($trap !== '') {
        app_log('Contact form honeypot tripped', [
            'ip'    => client_ip(),
            'value' => mb_substr($trap, 0, 80),
        ]);

        // Deliberately the same shape and wording a real submission gets.
        json_ok([
            'stored'  => false,
            'message' => 'Thanks — your message has been sent. We reply within one business day.',
        ]);
    }

    $renderedAt = $_SESSION['contact_form_at'] ?? null;
    if (is_int($renderedAt) && (time() - $renderedAt) < CONTACT_MIN_SECONDS) {
        app_log('Contact form submitted too fast', [
            'ip'      => client_ip(),
            'seconds' => time() - $renderedAt,
        ]);
        json_fail(
            'That was quick — please take a moment and try again.',
            'VALIDATION',
            429
        );
    }

    $errors = [];

    if ($e = invalid_name($name, 'Your name')) {
        $errors['name'] = $e;
    }
    if ($e = invalid_email($email)) {
        $errors['email'] = $e;
    }

    if ($subject === '') {
        $errors['subject'] = 'Add a short subject';
    } elseif (mb_strlen($subject) < 3) {
        $errors['subject'] = 'That subject is too short';
    } elseif (mb_strlen($subject) > 150) {
        $errors['subject'] = 'That subject is too long (150 characters max)';
    }

    if ($message === '') {
        $errors['message'] = 'Your message can\'t be empty';
    } elseif (mb_strlen($message) < 10) {
        $errors['message'] = 'Please say a little more so we can help';
    } elseif (mb_strlen($message) > 5000) {
        $errors['message'] = 'That message is too long (5000 characters max)';
    }

    if ($errors) {
        fail_validation($errors, 'Please fix the highlighted fields.');
    }

    $ip = client_ip();

    $recent = db()->prepare(
        'SELECT COUNT(*) FROM contact_messages
          WHERE ip_address = ? AND created_at > NOW() - INTERVAL 1 HOUR'
    );
    $recent->execute([$ip]);

    if ((int) $recent->fetchColumn() >= CONTACT_MAX_PER_HOUR) {
        app_log('Contact form rate limit hit', ['ip' => $ip]);
        json_fail(
            'You have sent several messages already. Please give us a little time to reply before sending more.',
            'VALIDATION',
            429
        );
    }

    db()->prepare(
        'INSERT INTO contact_messages (user_id, name, email, subject, message, ip_address)
         VALUES (?, ?, ?, ?, ?, ?)'
    )->execute([$userId, $name, $email, $subject, $message, $ip]);

    $id = (int) db()->lastInsertId();

    // The timer is one-shot: the next submission from this session has to
    // load the form again, so a script cannot reuse an old stamp.
    unset($_SESSION['contact_form_at']);

    app_log('Contact message stored', [
        'id'   => $id,
        'user' => $userId,
        'ip'   => $ip,
    ]);

    json_ok([
        'stored'  => true,
        'id'      => $id,
        'message' => 'Thanks — your message has been sent. We reply within one business day.',
    ], 201);
} catch (Throwable $e) {
    app_log('contact_submit.php failed: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    json_fail('Could not send your message right now. Please try again.', 'SERVER', 500);
}
