<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/_seller.php';

try {
    require_method('POST');
    csrf_check();

    if (current_user()) {
        json_fail('You are already signed in. Sign out first to apply as a seller.', 'FORBIDDEN', 403);
    }

    /* ---------- is the door open? ---------- */
    // Through setting(), the same reader auth/register.php uses for the
    // customer toggle — so "closed" means the same thing on both forms.
    if (setting('seller_signups_open', true) !== true) {
        json_fail('Seller applications are closed at the moment. Please check back soon.', 'FORBIDDEN', 403);
    }

    $first    = body_str('first_name');
    $last     = body_str('last_name');
    $email    = strtolower(body_str('email'));
    $phone    = body_str('phone');
    $password = body_raw('password');
    $nic      = strtoupper(body_str('nic_number'));

    $bizName  = body_str('business_name');
    $bizType  = body_str('business_type', 'sole_proprietorship');
    $regNo    = body_str('registration_no');
    $city     = body_str('city');
    $district = body_str('district');
    $category = body_str('primary_category');

    $bank     = body_str('bank_name');
    $account  = body_str('account_number');
    $branch   = body_str('branch');

    $errors = [];

    /* ---- the person ---- */
    if ($e = invalid_name($first, 'First name')) {
        $errors['first_name'] = $e;
    }
    if ($e = invalid_name($last, 'Last name')) {
        $errors['last_name'] = $e;
    }
    if ($e = invalid_email($email)) {
        $errors['email'] = $e;
    }
    if ($e = invalid_phone($phone)) {
        $errors['phone'] = $e;
    }
    if ($e = invalid_password($password)) {
        $errors['password'] = $e;
    }
    // Sri Lankan NIC: the old 9-digit + V/X form, or the 12-digit form.
    if ($nic === '') {
        $errors['nic_number'] = 'NIC number is required';
    } elseif (!preg_match('/^(\d{9}[VX]|\d{12})$/', $nic)) {
        $errors['nic_number'] = 'Enter a valid NIC (XXXXXXXXXV or 200XXXXXXXXX)';
    }

    /* ---- the business ---- */
    if ($bizName === '') {
        $errors['business_name'] = 'Business name is required';
    } elseif (mb_strlen($bizName) > 150) {
        $errors['business_name'] = 'Business name is too long (150 characters max)';
    }
    if (!in_array($bizType, ['sole_proprietorship', 'partnership', 'plc', 'other'], true)) {
        $bizType = 'other';
    }
    if ($city === '') {
        $errors['city'] = 'City is required';
    } elseif (mb_strlen($city) > 80) {
        $errors['city'] = 'City is too long (80 characters max)';
    }
    if ($category === '') {
        $errors['primary_category'] = 'Choose a primary category';
    } elseif (!in_array($category, platform_categories(), true)) {
        $errors['primary_category'] = 'Choose one of the categories we currently sell';
    }
    if (mb_strlen($regNo) > 60) {
        $errors['registration_no'] = 'Registration number is too long (60 characters max)';
    }
    if (mb_strlen($district) > 60) {
        $errors['district'] = 'District is too long (60 characters max)';
    }

    /* ---- the payout account ---- */
    if ($bank === '') {
        $errors['bank_name'] = 'Select your bank';
    }
    if ($account === '') {
        $errors['account_number'] = 'Account number is required';
    } elseif (!preg_match('/^[0-9-]{6,40}$/', $account)) {
        $errors['account_number'] = 'Enter a valid account number (digits only)';
    }
    if (mb_strlen($branch) > 80) {
        $errors['branch'] = 'Branch is too long (80 characters max)';
    }

    if (!body_bool('accept_terms')) {
        $errors['accept_terms'] = 'You must accept the seller agreement';
    }

    if ($errors) {
        fail_validation($errors, 'Please fix the highlighted fields.');
    }

    $taken = db()->prepare('SELECT 1 FROM users WHERE email = ?');
    $taken->execute([$email]);
    if ($taken->fetchColumn()) {
        fail_validation(
            ['email' => 'An account with this email already exists — sign in instead'],
            'That email is already registered.'
        );
    }

    $name = trim($first . ' ' . $last);
    // PASSWORD_DEFAULT is bcrypt here; the hash is one-way, so not even a
    // database dump reveals the password.
    $hash = password_hash($password, PASSWORD_DEFAULT);

    try {
        $sellerId = db_transaction(function (PDO $pdo) use (
            $name, $email, $phone, $hash, $bizName, $bizType, $regNo,
            $city, $district, $category, $nic, $bank, $account, $branch
        ): int {
            $pdo->prepare(
                "INSERT INTO users (name, email, phone, password_hash, role, status)
                 VALUES (?, ?, ?, ?, 'seller', 'active')"
            )->execute([$name, $email, $phone, $hash]);

            $userId = (int) $pdo->lastInsertId();

            $maxSeller = (int) $pdo->query(
                "SELECT COALESCE(MAX(CAST(SUBSTRING(public_id, 2) AS UNSIGNED)), 0)
                   FROM sellers
                  WHERE public_id REGEXP '^s[0-9]+$'"
            )->fetchColumn();

            $pdo->prepare(
                "INSERT INTO sellers
                    (id, public_id, business_name, business_type, registration_no,
                     city, district, primary_category, nic_number,
                     bank_name, account_number, branch, kyc_status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')"
            )->execute([
                $userId,
                's' . ($maxSeller + 1),
                $bizName,
                $bizType,
                $regNo === '' ? null : $regNo,
                $city,
                $district === '' ? null : $district,
                $category,
                $nic,
                $bank,
                $account,
                $branch === '' ? null : $branch,
                // kyc_status is hard-coded 'pending'. It is never read from
                // the request, so an application cannot approve itself.
            ]);

            return $userId;
        });
    } catch (PDOException $e) {
        // 23000 = integrity constraint: two applications for the same email
        // landed at once and the UNIQUE KEY caught the second.
        if ($e->getCode() === '23000') {
            fail_validation(
                ['email' => 'An account with this email already exists — sign in instead'],
                'That email is already registered.'
            );
        }
        throw $e;
    }

    app_log('Seller application received', ['user' => $sellerId, 'business' => $bizName]);

    json_ok([
        'status'  => 'pending',
        'message' => 'Application received. An admin will review your details, and you can sign in '
                   . 'to set your shop up in the meantime — listings go live once you are approved.',
        'email'   => $email,
    ], 201);
} catch (Throwable $e) {
    app_log('seller/register.php failed: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    json_fail('Could not submit your application right now. Please try again.', 'SERVER', 500);
}
