<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/_seller.php';
require_once __DIR__ . '/../_uploads.php';

try {
    require_method('POST');
    csrf_check();

    $me       = require_seller();      // an unverified seller may still set their shop up
    $sellerId = (int) $me['seller']['id'];

    $body   = json_body();
    $errors = [];

    /** Present in the request at all? */
    $sent = static fn(string $key): bool => array_key_exists($key, $body);

    $userSets = [];
    $userArgs = [];

    if ($sent('name')) {
        $name = body_str('name');
        if ($e = invalid_name($name, 'Your name')) {
            $errors['name'] = $e;
        } else {
            $userSets[] = 'name = ?';
            $userArgs[] = $name;
        }
    }

    if ($sent('phone')) {
        $phone = body_str('phone');
        if ($e = invalid_phone($phone)) {
            $errors['phone'] = $e;
        } else {
            $userSets[] = 'phone = ?';
            $userArgs[] = $phone;
        }
    }

    $sellerSets = [];
    $sellerArgs = [];

    /** A plain text field: required-when-sent, length-capped. */
    $text = static function (
        string $key,
        string $column,
        string $label,
        int $max,
        bool $required
    ) use ($sent, &$errors, &$sellerSets, &$sellerArgs): void {
        if (!$sent($key)) {
            return;
        }
        $value = body_str($key);

        if ($value === '') {
            if ($required) {
                $errors[$key] = $label . ' is required';
                return;
            }
            $sellerSets[] = "$column = NULL";
            return;
        }
        if (mb_strlen($value) > $max) {
            $errors[$key] = sprintf('%s is too long (%d characters max)', $label, $max);
            return;
        }
        $sellerSets[] = "$column = ?";
        $sellerArgs[] = $value;
    };

    $text('business_name',   'business_name',   'Business name', 150, true);
    $text('registration_no', 'registration_no', 'Registration number', 60, false);
    $text('city',            'city',            'City', 80, true);
    $text('district',        'district',        'District', 60, false);
    $text('bank_name',       'bank_name',       'Bank name', 80, false);
    $text('branch',          'branch',          'Branch', 80, false);

    if ($sent('business_type')) {
        $type = body_str('business_type');
        if (!in_array($type, ['sole_proprietorship', 'partnership', 'plc', 'other'], true)) {
            $errors['business_type'] = 'Choose one of the listed business types';
        } else {
            $sellerSets[] = 'business_type = ?';
            $sellerArgs[] = $type;
        }
    }

    if ($sent('primary_category')) {
        $category = body_str('primary_category');
        if ($category === '') {
            $errors['primary_category'] = 'Choose a primary category';
        } elseif (!in_array($category, platform_categories(), true)) {
            $errors['primary_category'] = 'Choose one of the categories we currently sell';
        } else {
            $sellerSets[] = 'primary_category = ?';
            $sellerArgs[] = $category;
        }
    }

    if ($sent('account_number')) {
        $account = body_str('account_number');
        if ($account === '') {
            $sellerSets[] = 'account_number = NULL';
        } elseif (!preg_match('/^[0-9-]{6,40}$/', $account)) {
            $errors['account_number'] = 'Enter a valid account number (digits only)';
        } else {
            $sellerSets[] = 'account_number = ?';
            $sellerArgs[] = $account;
        }
    }

    $removeAvatar = body_bool('remove_avatar');
    if ($removeAvatar) {
        $userSets[] = 'avatar_url = NULL';
    }

    if ($errors) {
        fail_validation($errors);
    }
    if (!$userSets && !$sellerSets) {
        json_fail('There was nothing to save.', 'VALIDATION', 400);
    }

    // Captured before the write, so the file can be deleted afterwards.
    $oldAvatar = $removeAvatar ? ($me['user']['avatar_url'] ?? null) : null;

    db_transaction(function (PDO $pdo) use ($userSets, $userArgs, $sellerSets, $sellerArgs, $sellerId): void {
        if ($userSets) {
            $args = $userArgs;
            $args[] = $sellerId;
            $pdo->prepare('UPDATE users SET ' . implode(', ', $userSets) . ' WHERE id = ?')
                ->execute($args);
        }
        if ($sellerSets) {
            $args = $sellerArgs;
            $args[] = $sellerId;
            // id = ? is the seller's own id from the session, never from the
            // request — there is no way to aim this at another shop.
            $pdo->prepare('UPDATE sellers SET ' . implode(', ', $sellerSets) . ' WHERE id = ?')
                ->execute($args);
        }
    });

    /* Delete the old image only once the row no longer references it. */
    if ($oldAvatar !== null) {
        delete_stored_upload($oldAvatar);
    }

    app_log('Seller profile updated', ['seller' => $sellerId]);

    $fresh = current_user(true);

    json_ok([
        'user'    => public_user($fresh),
        'message' => 'Your details have been saved.',
    ]);
} catch (Throwable $e) {
    app_log('seller/profile.php failed: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    json_fail('Could not save your details right now. Please try again.', 'SERVER', 500);
}
