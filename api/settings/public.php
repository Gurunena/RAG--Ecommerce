<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../seller/_seller.php';

try {
    require_method('GET');

    $labels = [
        'laptop'    => 'Laptops & Computers',
        'phone'     => 'Phones & Tablets',
        'audio'     => 'Audio & Headphones',
        'camera'    => 'Cameras',
        'appliance' => 'Home Appliances',
    ];

    $categories = array_map(
        static fn(string $key): array => [
            'key'   => $key,
            'label' => $labels[$key] ?? ucfirst(str_replace('_', ' ', $key)),
        ],
        platform_categories()
    );

    json_ok([
        'categories'            => $categories,
        // setting() from api/helpers.php — one reader for the settings table,
        // shared with the registration endpoints that enforce these toggles.
        'seller_signups_open'   => setting('seller_signups_open', true) === true,
        'customer_signups_open' => setting('customer_signups_open', true) === true,
    ]);
} catch (Throwable $e) {
    app_log('settings/public.php failed: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    json_fail('Could not load site settings right now. Please try again.', 'SERVER', 500);
}
