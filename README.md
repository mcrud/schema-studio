# mcrud/crud

Browser-based CRUD generator for Laravel 10, 11 and 12 (PHP 8.2+). It creates a migration, Eloquent model, resource controller, Blade CRUD views and routes. It can also create a starter dashboard.

## Install

```bash
composer require mcrud/crud
php artisan vendor:publish --tag=crud-config
```

Package discovery registers it automatically. Visit `/crud-generator`, enter a resource and its fields, and check **Also create Dashboard** when wanted. The generator never overwrites existing files. Run `php artisan migrate` after generation.

The form is also a schema builder: select nullable/unique/default values, choose Arabic and/or English translations, and select resource permissions. Permissions use `spatie/laravel-permission`; publish its migration once after installation with `php artisan vendor:publish --provider="Spatie\Permission\PermissionServiceProvider" --tag="permission-migrations"`, then migrate. Enter comma-separated role names (for example `admin, editor`) and the generated seeder creates those roles and permissions.

Change the URL or protect the generator in `config/crud.php`, for example by adding `auth` to the middleware list. Generated dashboard route: `/dashboard`.
