<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/_admin.php';

try {
    require_method('POST');
    csrf_check();

    $me      = require_admin();
    $adminId = (int) $me['id'];

    $body = json_body();
    if (!$body) {
        json_fail('There was nothing to save.', 'VALIDATION', 400);
    }

    $before  = admin_settings();
    $changes = [];      // key => validated value
    $notes   = [];      // one readable line per change, for the audit trail
    $errors  = [];

    if (array_key_exists('commission_rate', $body)) {
        $raw = $body['commission_rate'];

        if (!is_numeric($raw)) {
            $errors['commission_rate'] = 'Enter a number';
        } else {
            $rate = round((float) $raw, 2);
            // Matches CHECK (commission_rate BETWEEN 0 AND 50) on sellers,
            // so a platform default can never be outside what a seller's own
            // negotiated rate is allowed to be.
            if ($rate < 0 || $rate > 50) {
                $errors['commission_rate'] = 'Enter a rate between 0 and 50';
            } elseif (abs($rate - (float) $before['commission_rate']) > 0.001) {
                $changes['commission_rate'] = $rate;
                $notes[] = sprintf('commission rate %s%% → %s%%', $before['commission_rate'], $rate);
            }
        }
    }

    if (array_key_exists('categories', $body)) {
        $raw = $body['categories'];

        if (!is_array($raw)) {
            $errors['categories'] = 'Categories must be a list';
        } else {
            $clean = [];
            foreach ($raw as $c) {
                if (!is_string($c)) {
                    continue;
                }
                $c = mb_strtolower(trim($c));
                if ($c === '' || in_array($c, $clean, true)) {
                    continue;
                }
                if (!preg_match('/^[a-z0-9_-]{2,40}$/', $c)) {
                    $errors['categories'] = 'Category names are lowercase letters, digits, dashes and underscores (2–40 characters)';
                    break;
                }
                $clean[] = $c;
            }

            if (!isset($errors['categories'])) {
                if (!$clean) {
                    $errors['categories'] = 'Keep at least one category — sellers choose from this list';
                } else {
                    $removed = array_values(array_diff($before['categories'], $clean));

                    if ($removed) {
                        $in   = implode(',', array_fill(0, count($removed), '?'));
                        $stmt = db()->prepare(
                            "SELECT category, COUNT(*) AS n
                               FROM products
                              WHERE deleted_at IS NULL AND category IN ($in)
                           GROUP BY category"
                        );
                        $stmt->execute($removed);
                        $inUse = $stmt->fetchAll();

                        if ($inUse) {
                            $errors['categories'] = 'Still in use: ' . implode(', ', array_map(
                                static fn(array $r): string => sprintf('%s (%d listing%s)', $r['category'], $r['n'], $r['n'] == 1 ? '' : 's'),
                                $inUse
                            )) . '. Move or remove those listings first.';
                        }
                    }

                    if (!isset($errors['categories']) && $clean !== $before['categories']) {
                        $changes['categories'] = $clean;

                        $added = array_values(array_diff($clean, $before['categories']));
                        if ($added) {
                            $notes[] = 'added ' . implode(', ', $added);
                        }
                        if ($removed) {
                            $notes[] = 'removed ' . implode(', ', $removed);
                        }
                        if (!$added && !$removed) {
                            $notes[] = 'reordered categories';
                        }
                    }
                }
            }
        }
    }

    foreach (['seller_signups_open', 'customer_signups_open'] as $key) {
        if (!array_key_exists($key, $body)) {
            continue;
        }
        $raw = $body[$key];

        // Accepts true/false, 1/0 and 'true'/'false', because a checkbox
        // reaches here differently depending on how the page sends it.
        $value = in_array($raw, [true, 1, '1', 'true', 'on'], true)
            ? true
            : (in_array($raw, [false, 0, '0', 'false', 'off', ''], true) ? false : null);

        if ($value === null) {
            $errors[$key] = 'This must be on or off';
        } elseif ($value !== (bool) $before[$key]) {
            $changes[$key] = $value;
            $notes[] = str_replace('_', ' ', $key) . ' → ' . ($value ? 'on' : 'off');
        }
    }

    $riskInts = [
        'risk_medium_at' => 'Medium threshold',
        'risk_high_at'   => 'High threshold',
    ];
    foreach ($riskInts as $key => $label) {
        if (!array_key_exists($key, $body)) {
            continue;
        }
        $raw = $body[$key];
        if (!is_numeric($raw)) {
            $errors[$key] = 'Enter a number';
            continue;
        }
        $value = (int) $raw;
        if ($value < 1 || $value > 100) {
            $errors[$key] = $label . ' must be between 1 and 100';
        } elseif ($value !== (int) $before[$key]) {
            $changes[$key] = $value;
            $notes[] = sprintf('%s %d → %d', $key, (int) $before[$key], $value);
        }
    }

    $finalMedium = (int) ($changes['risk_medium_at'] ?? $before['risk_medium_at']);
    $finalHigh   = (int) ($changes['risk_high_at'] ?? $before['risk_high_at']);
    if (!isset($errors['risk_medium_at'], $errors['risk_high_at']) && $finalHigh <= $finalMedium) {
        $errors['risk_high_at'] = sprintf(
            'The high threshold (%d) must be above the medium one (%d)',
            $finalHigh,
            $finalMedium
        );
    }

    foreach (['risk_rule_weight' => 'Rule weight', 'risk_ml_weight' => 'Model weight'] as $key => $label) {
        if (!array_key_exists($key, $body)) {
            continue;
        }
        $raw = $body[$key];
        if (!is_numeric($raw)) {
            $errors[$key] = 'Enter a number';
            continue;
        }
        $value = round((float) $raw, 2);
        if ($value < 0 || $value > 1) {
            $errors[$key] = $label . ' must be between 0 and 1';
        } elseif (abs($value - (float) $before[$key]) > 0.0001) {
            $changes[$key] = $value;
            $notes[] = sprintf('%s %s → %s', $key, $before[$key], $value);
        }
    }

    $finalRule = (float) ($changes['risk_rule_weight'] ?? $before['risk_rule_weight']);
    $finalMl   = (float) ($changes['risk_ml_weight'] ?? $before['risk_ml_weight']);
    if (!isset($errors['risk_rule_weight'], $errors['risk_ml_weight']) && $finalRule + $finalMl <= 0) {
        $errors['risk_rule_weight'] = 'The two weights cannot both be zero';
    }

    if (array_key_exists('risk_hold_at_level', $body)) {
        $raw = (string) $body['risk_hold_at_level'];
        if (!in_array($raw, ['medium', 'high'], true)) {
            $errors['risk_hold_at_level'] = 'Choose medium or high';
        } elseif ($raw !== (string) $before['risk_hold_at_level']) {
            $changes['risk_hold_at_level'] = $raw;
            $notes[] = 'hold payouts at ' . $raw . ' risk';
        }
    }

    foreach (['risk_holds_enabled', 'risk_llm_explain'] as $key) {
        if (!array_key_exists($key, $body)) {
            continue;
        }
        $raw = $body[$key];
        $value = in_array($raw, [true, 1, '1', 'true', 'on'], true)
            ? true
            : (in_array($raw, [false, 0, '0', 'false', 'off', ''], true) ? false : null);

        if ($value === null) {
            $errors[$key] = 'This must be on or off';
        } elseif ($value !== (bool) $before[$key]) {
            $changes[$key] = $value;
            $notes[] = str_replace('_', ' ', $key) . ' → ' . ($value ? 'on' : 'off');
        }
    }

    $unknown = array_diff(
        array_keys($body),
        [
            'commission_rate', 'categories', 'seller_signups_open',
            'customer_signups_open', 'csrf_token',
            'risk_medium_at', 'risk_high_at', 'risk_rule_weight',
            'risk_ml_weight', 'risk_hold_at_level', 'risk_holds_enabled',
            'risk_llm_explain',
        ]
    );
    if ($unknown) {
        json_fail(
            'Unknown setting: ' . implode(', ', array_map('strval', $unknown)),
            'VALIDATION',
            400
        );
    }

    if ($errors) {
        fail_validation($errors);
    }

    if (!$changes) {
        // Not an error — the form submits everything, so "no change" is
        // normal and should not look like a failure.
        json_ok([
            'settings' => $before,
            'changed'  => [],
            'message'  => 'Nothing had changed.',
        ]);
    }

    db_transaction(function (PDO $pdo) use ($changes, $notes, $adminId): void {
        foreach ($changes as $key => $value) {
            admin_set_setting($pdo, $key, $value, $adminId);
        }

        admin_log($pdo, $adminId, 'update_settings', 'settings', null, implode('; ', $notes));
    });

    app_log('Platform settings changed', [
        'admin'   => $adminId,
        'changed' => array_keys($changes),
    ]);

    json_ok([
        'settings' => admin_settings(),
        'changed'  => array_keys($changes),
        'message'  => count($changes) === 1
            ? 'Setting saved: ' . $notes[0]
            : sprintf('%d settings saved.', count($changes)),
        // Said out loud whenever the rate moves, because it is the one
        // setting people assume is retroactive.
        'notice'   => match (true) {
            array_key_exists('commission_rate', $changes) =>
                'This applies to new orders only — existing orders keep the rate they were placed at.',
            (bool) array_intersect(
                array_keys($changes),
                ['risk_medium_at', 'risk_high_at', 'risk_rule_weight', 'risk_ml_weight']
            ) =>
                'This applies to orders scored from now on. Existing scores and alerts are '
                . 'unchanged — use "Re-score" on an order, or run a sweep, to apply the new values.',
            array_key_exists('risk_holds_enabled', $changes) && $changes['risk_holds_enabled'] === false =>
                'New high-risk orders will no longer have their payouts held. Holds already '
                . 'in place stay until an admin releases them.',
            default => null,
        },
    ]);
} catch (Throwable $e) {
    app_log('admin/settings.php failed: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    json_fail('Could not save those settings right now. Please try again.', 'SERVER', 500);
}
