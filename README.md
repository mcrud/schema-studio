# mcrud/schema-studio

Browser-based schema designer and CRUD generator for Laravel 10, 11 and 12 (PHP 8.2+). Design several related tables on a visual canvas, then generate migrations, Eloquent models (with relationships), resource controllers, Blade views, routes, translations, permissions and a dashboard.

Documentation: https://mcrud.github.io/schema-studio/

## Install

```bash
composer require mcrud/schema-studio
php artisan vendor:publish --tag=crud-config
php artisan vendor:publish --provider="Spatie\Permission\PermissionServiceProvider" --tag="permission-migrations"
php artisan migrate
```

Package discovery registers the package. Open `/crud-generator` to start. The Spatie migration is only needed if you use permissions.

## The wizard

1. **Project brief** – project name, description, primary language and default roles, or pick a starter template (Online Store, SaaS Workspace, Clinic, Restaurant). Saved to `storage/app/crud-schema-studio/project.json` and restored on the next visit.
2. **Schema canvas** – the visual designer:
   - **Add table** and drag tables by their header; **Auto layout** arranges them by dependency.
   - Edit columns inline (name, type), drag `⋮⋮` to reorder, press `Enter` to add a column, duplicate or delete from the row.
   - Column inspector: type (`string`, `text`, `integer`, `bigInteger`, `boolean`, `date`, `dateTime`, `decimal`), nullable, unique, default.
   - **Relations**: drag the ● handle of one table onto another.

     | Type | Result |
     | --- | --- |
     | One to many (`hasMany`) | `<parent>_id` foreign key on the child table, `hasMany` / `belongsTo` methods |
     | One to one (`hasOne`) | Same, with a unique foreign key |
     | Many to many (`belongsToMany`) | Pivot table (e.g. `category_product`) with a composite primary key, `belongsToMany` on both models |

     Mark a one-to-many or one-to-one relation as *Optional* for a nullable foreign key (`nullOnDelete`).
   - The **Migration** tab previews the generated migration, including foreign keys and pivots.
3. **Access & generate** – translations (English / Arabic), permissions (`view`, `create`, `update`, `delete`), roles, **Run migrations & seed permissions** and **Create dashboard** (both on by default).

## What is generated

For every table: a migration, model, resource controller, `index`/`form` views, a `Route::resource` entry, language files and, when permissions are selected, a `<Model>PermissionSeeder`. Pivot tables get a migration only. Migrations are timestamped in dependency order so parents are created before children. Circular `hasMany`/`hasOne` relations are rejected.

With **Run migrations & seed permissions** enabled, the generator runs `php artisan migrate --force` (this applies *all* pending migrations) and then each generated permission seeder. If a step fails, the generated files are kept and the message tells you what to run manually. Turn the option off to run `php artisan migrate` and `php artisan db:seed --class=ProductPermissionSeeder` yourself.

**Create dashboard** adds `DashboardController`, `resources/views/dashboard.blade.php` and a `/dashboard` route with a count card linking to every generated table. It is skipped if a dashboard already exists.

The generator never overwrites existing application files. A table is skipped when its model, controller, index view or create-migration already exists, so you can re-run it to add new tables; the result message lists what was skipped. Foreign keys that a new relation would add to an already-existing table are not applied, so create a migration for those yourself.

## Permissions

Permissions use `spatie/laravel-permission`. Enter comma-separated roles (for example `admin, editor`); the seeder creates the roles and `<table>.<action>` permissions and assigns them.

## Configuration

`config/crud.php`:

```php
return [
    'route_prefix' => 'crud-generator',
    'middleware' => ['web'],
];
```

The generator writes files and can run migrations, so in production protect it, for example `'middleware' => ['web', 'auth', 'can:admin']`, or only enable it in local environments.

## Testing

```bash
composer install
composer test
```
