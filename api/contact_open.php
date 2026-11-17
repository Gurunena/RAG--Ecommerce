<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

try {
    require_method('GET');

    $_SESSION['contact_form_at'] = time();

    json_ok([
        'csrf_token' => csrf_token(),
        // So the form can prefill for somebody already signed in, rather
        // than asking them to type what we already know.
        'prefill'    => (static function (): array {
            $me = current_user();
            return $me
                ? ['name' => $me['name'], 'email' => $me['email']]
                : ['name' => '', 'email' => ''];
        })(),
    ]);
} catch (Throwable $e) {
    app_log('contact_open.php failed: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    // Not worth failing the page over — the form still works without the
    // timing defence, so answer with something usable.
    json_ok(['csrf_token' => '', 'prefill' => ['name' => '', 'email' => '']]);
}
