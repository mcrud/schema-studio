# Changelog

All notable changes to `mcrud/schema-studio` are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses [Semantic Versioning](https://semver.org/).

## [2.0.0] - 2026-11-17

This release contains changes that need action when upgrading. Read **Upgrading** first.

### Upgrading from 1.x

- **`spatie/laravel-permission` is no longer installed with the package.** If you generate roles and permissions, run `composer require spatie/laravel-permission`. Without it, `crud.permissions = 'auto'` (the default) generates code that only requires login.
- **The studio is locked (403) outside the `local` and `testing` environments** until you set `crud.ability` to a Gate ability. If your middleware already limits it to trusted administrators, set `crud.require_ability` to `false`.
- **Generated routes can require login.** Nothing changes unless you tick *Generate authentication* (or pass `--auth`), but a generated dashboard that is gated by permissions now also sits behind the `auth` middleware.
- **A new default layout.** Newly generated projects get the sidebar layout (`crud.layout = 'admin'`). Already generated `layouts/app.blade.php` files are never overwritten; delete the file and regenerate, or set `crud.layout` to `simple`, to change it.
- `password` columns use the `hashed` cast, which needs Laravel 10.14 or newer.

### Added

- **Admin dashboard template**: a sidebar and topbar layout with dark mode, RTL support and a mobile drawer. The generated dashboard shows count cards, a 30-day growth chart per table (Chart.js), the latest records of every table and hides tables the user may not view. Choose `admin` or `simple` with `crud.layout`, the studio's *Layout* select or `"layout"` in the schema JSON.
- **Authentication scaffold** (`Generate authentication`, `"auth": true`, `crud:generate --auth`): login, logout, forgot and reset password, profile and password change, optional public registration (off by default) and optional email verification, plus a generated `AuthTest`. Login, registration and forgot-password requests are rate limited. With permissions it also generates users and roles management (a permission matrix) guarded by `users.manage` and `roles.manage`, and an `AdminUserSeeder` that creates the first administrator from `ADMIN_EMAIL` and `ADMIN_PASSWORD` (it refuses to run in production without a password). It is skipped when the application already has a login route.
- **Permission drivers**: `crud.permissions` is `auto`, `spatie` or `none`. The studio disables its permission choices when roles and permissions are unavailable.
- **Column types**: `password` (hashed, hidden, kept when left blank on update), `color`, `slug` (generated from `name`/`title`, kept unique), `image` and `file` (stored on the public disk, replaced and deleted with the record, optional removal).
- **Soft-delete screens**: trash filter, restore, delete forever and bulk restore for tables that use soft deletes.
- **Index tools**: date-range filters for date columns, a column visibility menu that is remembered in the browser, and CSV import that validates every row before inserting anything.
- **Searchable foreign keys**: when a related table has more rows than `crud.lookup_threshold` (200), the dropdown becomes a search box backed by a `<table>/lookup/{field}` endpoint.
- **`php artisan crud:sync`**: updates already generated files from the schema. Missing files are created and differing files are merged with conflict markers, with a `.bak` copy of every touched file. `--only` limits it to chosen files and `--force` replaces instead of merging.
- **Studio interface in Arabic** with a language switch (`crud.studio_locale`). A test fails when a new interface string has no translation.
- **Publishable studio assets**: `php artisan vendor:publish --tag=crud-assets`. A published copy is only used while it matches the packaged one.
- The studio preview lists ALTER migrations and the files that already exist and will be kept.
- Continuous integration for PHP 8.2 and 8.3 on Laravel 10, 11 and 12 (`.github/workflows/tests.yml`).

### Changed

- Migrations, factories, tests, seeders, the dashboard and every new screen are now stubs in `resources/stubs`, so they can be customised with `vendor:publish --tag=crud-stubs`.
- `crud:diff` points to `crud:sync`; the Artisan commands share one schema reader.
- Generated index tables mark every column with `data-col`, and the bulk form submits its method from the pressed button.

### Security

- The studio's preview, generate and database-introspection endpoints are rate limited (`crud.rate_limit`, 30 per minute by default).
- Password reset answers identically whether or not the address exists.
- Uploaded files are validated (`image`, `file`, size limits) and CSV imports are limited to 2 MB and 1000 rows.

### Known limitations

- Only Laravel 12 with PHP 8.3 has been exercised locally; the 10 and 11 jobs run in CI.
- `crud:sync` covers application files (models, controllers, requests, policies, views, tests); migrations, routes and language files are not synced.
- Merging has no common ancestor, so every difference is reported as a conflict.

## [1.0.0]

Initial release: multi-table schema canvas with relations, database import, ALTER migrations, CRUD and REST API generation with OpenAPI and Postman documents, permissions, translations and Artisan commands. See the git history for details.
