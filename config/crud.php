<?php

return [
    'route_prefix' => 'crud-generator',
    // Secure by default. Add a dedicated admin middleware or Gate ability below.
    'middleware' => ['web', 'auth'],
    // Optional Gate ability required for every Schema Studio endpoint.
    'ability' => null,
    // Outside the local and testing environments the studio stays locked (403) until `ability` is set to a Gate ability.
    // Set to false only when the middleware above already restricts the studio to trusted administrators.
    'require_ability' => true,
    // Requests per minute and user (or IP) for the studio's preview, generate and database-introspection endpoints.
    'rate_limit' => 30,
    // Interface language of the studio: 'en', 'ar' or 'auto' (follows the app locale). Visitors can switch it in the header.
    'studio_locale' => 'auto',
    'allow_generation_in_production' => false,
    'allow_migrate_in_production' => false,
    'require_migration_confirmation' => true,
    'generate_tests' => true,

    // Roles and permissions in generated code: 'spatie' (needs spatie/laravel-permission), 'none' (every authenticated user can do everything)
    // or 'auto' (spatie when it is installed, otherwise none).
    'permissions' => 'auto',

    // Foreign keys to tables with more rows than this render as a search box instead of a dropdown in generated forms.
    'lookup_threshold' => 200,

    // Layout written to resources/views/layouts/app.blade.php: 'admin' (sidebar, topbar, dark mode, RTL) or 'simple' (top navbar).
    'layout' => 'admin',

    // Directory with published stubs (php artisan vendor:publish --tag=crud-stubs). Falls back to base_path('stubs/crud').
    'stubs_path' => null,

    // API generation: guard for generated API routes ('sanctum' adds auth:sanctum, null leaves them open) and page size.
    'api_auth' => 'sanctum',
    'api_per_page' => 15,

    // Extra table names that the "Import from database" dialog should hide.
    'import_exclude' => [],
];
