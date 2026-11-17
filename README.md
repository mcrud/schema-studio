# mcrud/schema-studio

Browser-based schema designer and CRUD generator for Laravel 10, 11 and 12 (PHP 8.2+). Design several related tables on a visual canvas, then generate migrations, Eloquent models (with relationships), resource controllers, Blade views, routes, translations, an admin dashboard, authentication and, optionally, roles and permissions.

Documentation: https://mcrud.github.io/schema-studio/ · [Changelog](CHANGELOG.md)

## Install

```bash
composer require mcrud/schema-studio
php artisan vendor:publish --tag=crud-config
```

Package discovery registers the package. Open `/crud-generator` to start.

Roles and permissions are optional. To generate them, also install Spatie's package and publish its migration:

```bash
composer require spatie/laravel-permission
php artisan vendor:publish --provider="Spatie\Permission\PermissionServiceProvider" --tag="permission-migrations"
php artisan migrate
```

Without it (`crud.permissions` is `auto` by default) the generated code simply requires a logged-in user, and the studio disables its permission choices.

## Safe generation

The studio writes files and can run migrations, so it is locked down by default:

- It requires authentication (`crud.middleware` is `['web', 'auth']`).
- **Outside the `local` and `testing` environments it answers 403 until `crud.ability` names a Gate ability**, for example `manage-crud-generator`. If your middleware already restricts it to trusted administrators, set `crud.require_ability` to `false`.
- File generation and migrations are blocked in production (`allow_generation_in_production`, `allow_migrate_in_production`), migrations need a separate confirmation, and the browser preview has no side effects.
- Preview, generate and database introspection are rate limited (`crud.rate_limit`, 30 requests per minute per user or IP).
- Generation never overwrites an application file; see [Updating generated code](#updating-generated-code).

```php
// app/Providers/AppServiceProvider.php
Gate::define('manage-crud-generator', fn ($user) => $user->is_admin);
// config/crud.php
'ability' => 'manage-crud-generator',
```

Automation without the browser:

```bash
php artisan crud:generate schema.json --dry-run
php artisan crud:generate schema.json --migrate
php artisan crud:generate schema.json --auth      # also generate login, profile and user management
php artisan crud:generate schema.json --skip-validation  # skip the schema check that runs first
```

## The wizard

1. **Project brief** – project name, description, primary language and default roles, or pick a starter template (Online Store, SaaS Workspace, Clinic, Restaurant). The most recent profile is restored from `storage/app/crud-schema-studio/project.json`; each generated profile is also kept under `storage/app/crud-schema-studio/projects/`.
2. **Schema canvas** – the visual designer:
   - **Add table** and drag tables by their header; **Auto layout** arranges them by dependency.
   - Edit columns inline (name, type), drag `⋮⋮` to reorder, press `Enter` to add a column, duplicate or delete from the row.
   - Column inspector: type, nullable, unique, default, validation rules and index (see [Column types](#column-types)).
   - Per table: **Timestamps**, **Soft deletes**, a header **color**, and collapse/expand.
   - **Relations**: drag the ● handle of one table onto another.

     | Type | Result |
     | --- | --- |
     | One to many (`hasMany`) | `<parent>_id` foreign key on the child table, `hasMany` / `belongsTo` methods |
     | One to one (`hasOne`) | Same, with a unique foreign key |
     | Many to many (`belongsToMany`) | Pivot table (e.g. `category_product`) with a composite primary key, `belongsToMany` on both models |

     Mark a one-to-many or one-to-one relation as *Optional* for a nullable foreign key (`nullOnDelete`).
   - The **Migration** tab previews the generated migration, including foreign keys and pivots.
   - **Undo / redo** (`Ctrl/⌘+Z`, `Ctrl/⌘+Shift+Z`), **zoom** (`Ctrl/⌘+scroll`, `+`/`-`, **Fit**), drag the background to **pan**, a **minimap**, and **find** table/column. Press `?` for all shortcuts.
   - **Import from DB** reads the connected database and drops its tables, columns, foreign keys (as relations) and many-to-many pivots onto the canvas. Imported tables are marked **DB**; any column or relation you add generates an ALTER migration instead of a create migration. Importing needs Laravel 11+, tables must follow Laravel naming (`id` primary key, plural snake_case) and system tables (`migrations`, `jobs`, `sessions`, Spatie permission tables, ...) are hidden; add more in `import_exclude`.
   - Your draft is **autosaved in the browser** and restored on the next visit (**New** starts over).
   - **Export** the schema as JSON, the diagram as SVG or PNG, or copy a **Mermaid ERD**; **Import JSON** loads a saved schema.
3. **Access & generate** – translations (English / Arabic), permissions (`view`, `create`, `update`, `delete`), roles, the dashboard and its layout, authentication, REST API, migrations and optional sample data. **Preview changes** lists the migrations, every file, ALTER migrations and the files that already exist and will be kept.

The studio itself is available in English and Arabic. Switch language with the button in the header (the choice is remembered), or set the default with `crud.studio_locale` (`en`, `ar` or `auto`, which follows the app locale). Table and column names you type are never translated.

## What is generated

For every table:

- a **migration** (dependency-ordered, with foreign keys) and a **model** with casts, `HasFactory` and relationship methods;
- a resource **controller** with search (`?q=`), sortable columns, filters, pagination, eager loading, pivot syncing, CSV export and bulk delete;
- **Store/Update FormRequests** (unique rules ignore the current record, foreign keys use `exists:`);
- a **Policy** wired to the selected permissions, when permissions are available;
- a **factory** with column-aware fake data (`email`, `price`, `*_name`, ...);
- **Tailwind views** (`index` and `form`) inside a generated `layouts/app.blade.php`: the right input per type, a `<select>` for foreign keys and a multi-select for many-to-many relations;
- a `Route::resource` entry, language files and a `tests/Feature/<Model>CrudTest.php`;
- with permissions, a `<Model>PermissionSeeder`.

Pivot tables get a migration only. Tables imported from the database get everything except a create migration (plus an ALTER migration for anything new). Circular `hasMany`/`hasOne` relations are rejected. Tick **Add sample data** to also generate a `StudioDemoSeeder`.

With **Run migrations & seed permissions** enabled, the generator runs `php artisan migrate --force` (this applies *all* pending migrations) and then the generated seeders. If a step fails, the generated files are kept and the message tells you what to run manually.

### Dashboard and layout

**Create dashboard** adds `DashboardController`, `resources/views/dashboard.blade.php` and a `/dashboard` route with count cards, a 30-day growth chart per table (Chart.js from a CDN), the latest records of each table and a link back to every list. With permissions it hides tables the user cannot `viewAny`. It is skipped if a dashboard already exists.

`crud.layout` (or the studio's *Layout* select, or `"layout"` in the JSON) picks the generated `layouts/app.blade.php`:

| Layout | Look |
| --- | --- |
| `admin` (default) | Sidebar (collapsible, drawer on mobile), topbar with profile link and logout, dark mode toggle, automatic RTL for `ar`, `he`, `fa`, `ur` |
| `simple` | Top navigation bar |

The layout is written once and never overwritten; delete `resources/views/layouts/app.blade.php` to regenerate it.

### Authentication

Tick **Generate authentication** (or `"auth": true`, or `--auth`) to add:

- login, logout, **forgot and reset password** (the reset form answers the same whether or not the address exists), a **profile** page and password change, and a `tests/Feature/AuthTest.php`;
- optional **public registration** (off by default) and optional **email verification** (add `implements MustVerifyEmail` to `App\Models\User` so it is enforced; without it the `verified` middleware lets everyone through);
- `auth` middleware on every generated route, and `throttle` on login, registration and forgot-password requests;
- with permissions: **users and roles management** (roles with a permission matrix) guarded by `users.manage` and `roles.manage`. Add Spatie's `HasRoles` trait to `App\Models\User` so roles can be assigned;
- an **`AdminUserSeeder`** that creates the first administrator from `ADMIN_EMAIL`, `ADMIN_PASSWORD` and `ADMIN_NAME` (default `admin@example.com`; it refuses to run in production without `ADMIN_PASSWORD`) and gives the `super-admin` role every current permission. Run it again after adding tables.

Authentication is skipped, and the studio says so, when the application already has a login route or a Breeze/Fortify scaffold. Users and roles management is skipped when your schema has a `users` or `roles` table.

### Permissions

`crud.permissions` decides how generated code authorises:

| Value | Behaviour |
| --- | --- |
| `auto` (default) | `spatie` when `spatie/laravel-permission` is installed, otherwise `none` |
| `spatie` | Policies and `<table>.<action>` permissions; fails with a clear message when the package is missing |
| `none` | No policies, gates or seeders; every logged-in user can do everything |

Enter comma-separated roles (for example `admin, editor`); the seeder creates the roles and `<table>.<action>` permissions and assigns them.

### Column types

`string`, `text`, `longText`, `integer`, `bigInteger`, `float`, `decimal`, `boolean`, `date`, `dateTime`, `dateTimeTz`, `time`, `uuid`, `json`, `enum` (comma-separated values), `email`, `url`, `ipAddress` and:

| Type | Behaviour |
| --- | --- |
| `password` | Hashed (`hashed` cast, Laravel 10.14+), hidden from arrays and JSON, left out of the index, CSV and API; a blank value on update keeps the stored one |
| `color` | `<input type="color">`, validated as `#rrggbb`, shown as a swatch |
| `slug` | Generated from `name`/`title` when blank, kept unique, searchable |
| `image`, `file` | Uploaded to the `public` disk (run `php artisan storage:link`), validated (`image`/`file`, size limits), the previous file is deleted when replaced or when a soft-delete record is deleted forever (files of records removed with a plain `delete()` are not cleaned up), optional removal for nullable columns, `<form enctype>` added automatically |

Each column also has a **Validation rules** field (Laravel rules separated by `|`, for example `min:3|regex:/^[A-Z]/`) and an **Index** checkbox. A table can declare composite **indexes** in the inspector or as `"indexes": [{"columns": "status, code", "unique": true}]` in the JSON.

### Index pages

Generated index pages include:

- search, sortable columns, and dropdown filters for boolean, enum and foreign-key columns;
- **date-range filters** (from / to) for the first two date columns;
- a **Columns** menu to hide or show columns, remembered in the browser;
- **CSV export** that honours the search and filters (formula-injection safe) and **CSV import** (`<table>/import`, up to 2 MB and 1000 rows; every row is validated first, so one bad row imports nothing; it accepts the export format). Import is skipped for tables with `password`, `image`, `file` or `slug` columns;
- **bulk delete**, and for soft-delete tables a trash filter (active, with deleted, only deleted), **restore**, **delete forever** and **bulk restore**;
- for foreign keys to tables with more than `crud.lookup_threshold` rows (200), a **search box** instead of a dropdown, backed by `<table>/lookup/{field}`.

All of these respect the generated policies.

### REST API, OpenAPI and Postman

Tick **REST API** (or set `"api": true`). For every table you also get:

- `App\Http\Controllers\Api\<Table>Controller` with search, sorting, filters, `per_page` pagination and many-to-many syncing, reusing the generated FormRequests and policies;
- an `App\Http\Resources\<Model>Resource` (uploads are returned as URLs, passwords are omitted) and `Route::apiResource` entries in `routes/api.php`;
- `tests/Feature/<Model>ApiTest.php`;
- `docs/api/openapi.json` and `docs/api/postman_collection.json`, merged with earlier runs.

`crud.api_auth` (`'sanctum'` by default) decides the guard; set it to `null` for open routes. Laravel 11+ needs `php artisan install:api` for `routes/api.php` to be loaded. Untick **Web CRUD** (`"web": false`) for an API-only run. PHP does not parse multipart bodies for `PUT`, so clients that upload files to the API should send `POST` with `_method=PUT`.

## Updating generated code

Re-running the generator never overwrites anything: a table is skipped when its model, controller, index view or create migration already exists, and the result lists what was skipped. A relation added to an already generated table gets its own ALTER migration.

Imported tables (marked **DB**) can be edited in the column inspector: **Edit this column**, **Rename to** or **Drop column**. Together with new columns, foreign keys and composite indexes these produce one `alter_<table>_table` migration. Changed and dropped columns cannot be reversed automatically; the generated `down()` says so.

To bring files you already generated in line with a changed schema:

```bash
php artisan crud:validate schema.json      # check the schema (exit code 1 on problems); --json for CI
php artisan crud:types                     # list the column types with their rule, cast and input
php artisan crud:diff schema.json          # unified diff of every generated file vs. what is on disk
php artisan crud:diff schema.json --stat   # only missing / changed / identical (add --json for scripts)
php artisan crud:sync schema.json          # create missing files, merge the rest with conflict markers
php artisan crud:sync schema.json --only=app/Models/Product.php
php artisan crud:sync schema.json --force  # replace differing files instead of merging
```

`crud:sync` saves a `*.bak` copy of every file it touches. Without a common ancestor it cannot tell your edits from the generator's, so every difference is kept between `<<<<<<< current`, `=======` and `>>>>>>> generated`; resolve them by hand. A file that still has markers is skipped until you resolve it (or pass `--force`). It covers application files: models, controllers, requests, policies, views and tests. Migrations, routes and language files are not touched.

## Customising

```bash
php artisan vendor:publish --tag=crud-stubs     # stubs/crud: every generated file is a stub
php artisan vendor:publish --tag=crud-config
php artisan vendor:publish --tag=crud-assets    # public/vendor/crud: studio.js and studio-i18n.js
```

Any stub you keep in `stubs/crud` is used instead of the packaged one (change the directory with `crud.stubs_path`). Published assets are only served while they match the packaged files, so a copy left behind by an older release is ignored.

## Configuration

`config/crud.php`:

| Key | Default | Purpose |
| --- | --- | --- |
| `route_prefix` | `crud-generator` | URL prefix of the studio |
| `middleware` | `['web', 'auth']` | Middleware of the studio routes |
| `ability` | `null` | Gate ability required for every studio endpoint |
| `require_ability` | `true` | Keep the studio locked (403) outside local and testing until `ability` is set |
| `rate_limit` | `30` | Studio requests per minute for preview, generate and introspection |
| `studio_locale` | `auto` | Studio interface language: `en`, `ar`, `auto` |
| `allow_generation_in_production` | `false` | Allow writing files in production |
| `allow_migrate_in_production` | `false` | Allow running migrations in production |
| `require_migration_confirmation` | `true` | Require the migration checkbox |
| `generate_tests` | `true` | Generate feature tests |
| `permissions` | `auto` | `auto`, `spatie` or `none` |
| `lookup_threshold` | `200` | Rows above which a foreign key becomes a search box |
| `layout` | `admin` | `admin` or `simple` |
| `stubs_path` | `null` | Directory of published stubs (defaults to `base_path('stubs/crud')`) |
| `api_auth` | `sanctum` | Guard of generated API routes, or `null` |
| `api_per_page` | `15` | Default API page size |
| `import_exclude` | `[]` | Extra tables the "Import from DB" dialog hides |

## Roadmap

Not built yet, in no particular order:

- Polymorphic (`morph`) relations and a multi-select search for many-to-many fields.
- A role-column permission driver that does not need Spatie.
- File uploads and restore endpoints in the generated REST API.
- `crud:sync` for migrations, routes and language files, and a three-way merge against a stored baseline.
- Translated generated pages (authentication, users, roles) and more studio languages.
- Livewire and Inertia output, and theme colours for the generated layouts.

## Testing

```bash
composer install
composer test
```

The suite uses Orchestra Testbench. It generates CRUD (web and API), authentication, dashboards and every field type into a temporary app, lints the generated PHP, migrates it on SQLite and drives the generated controllers over HTTP. It needs the `gd` extension for image uploads; the translation-engine checks run only when `node` is on the path. GitHub Actions runs it on PHP 8.2 and 8.3 against Laravel 10, 11 and 12.
