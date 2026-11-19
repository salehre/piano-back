<?php

/*
 * CORS برای فرانت Nuxt که روی یه origin جدا اجرا می‌شه (لوکال: http://localhost:8087).
 * supports_credentials باید true باشه چون نشست با کوکی انجام می‌شه؛ پس allowed_origins نباید '*' باشه.
 * چند origin رو با کاما جدا کن: CORS_ALLOWED_ORIGINS=http://localhost:8087,https://example.com
 */
return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('CORS_ALLOWED_ORIGINS', env('FRONTEND_URL', 'http://localhost:8087'))),
    ))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,
];
