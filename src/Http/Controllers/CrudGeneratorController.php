<?php

namespace Mcrud\Crud\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Mcrud\Crud\Generators\CrudGenerator;
use Mcrud\Crud\Schema\SchemaIntrospector;
use Mcrud\Crud\Support\ColumnTypes;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Throwable;

class CrudGeneratorController
{
    public function index(Request $request, CrudGenerator $generator): View
    {
        $this->authorizeStudio();
        $directory = storage_path('app/crud-schema-studio/projects');
        $profiles = collect(glob($directory.'/*.json') ?: [])->map(function (string $file): array {
            $data = json_decode((string) file_get_contents($file), true) ?: [];
            return ['slug' => pathinfo($file, PATHINFO_FILENAME), 'name' => $data['name'] ?? pathinfo($file, PATHINFO_FILENAME)];
        })->sortBy('name')->values()->all();
        $slug = Str::slug((string) $request->query('profile'));
        $path = $slug !== '' ? $directory.'/'.$slug.'.json' : storage_path('app/crud-schema-studio/project.json');
        $project = is_file($path) ? json_decode((string) file_get_contents($path), true) : [];

        $locale = config('crud.studio_locale', 'auto');
        $locale = $locale === 'auto' ? (app()->getLocale() === 'ar' ? 'ar' : 'en') : ($locale === 'ar' ? 'ar' : 'en');
        $i18n = json_decode((string) file_get_contents(__DIR__.'/../../../resources/studio/ar.json'), true) ?: [];

        return view('crud::generator', ['studioLocale' => $locale, 'i18n' => $i18n, 'assets' => $this->assets(), 'permissionsDriver' => $generator->permissionsDriver(), 'project' => is_array($project) ? $project : [], 'profiles' => $profiles, 'selectedProfile' => $slug]);
    }

    public function introspect(SchemaIntrospector $introspector): JsonResponse
    {
        $this->authorizeStudio();
        try {
            return response()->json($introspector->describe());
        } catch (Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function store(Request $request, CrudGenerator $generator): RedirectResponse
    {
        $this->authorizeStudio();
        abort_if(app()->environment('production') && !config('crud.allow_generation_in_production'), 403, 'Generation is disabled in production.');
        $data = $this->validated($request);
        if ($request->boolean('migrate') && config('crud.require_migration_confirmation') && !$request->boolean('confirm_migrate')) {
            return back()->withInput()->withErrors(['migrate' => 'Confirm that you want to run migrations.']);
        }
        abort_if($request->boolean('migrate') && app()->environment('production') && !config('crud.allow_migrate_in_production'), 403, 'Migrations are disabled in production.');
        $report = $generator->generate($data);
        $messages = [];
        foreach ($report['skipped'] as $skip) {
            $messages[] = "{$skip['model']} already exists, so it was skipped".($skip['newForeignKeys'] ? ' (its new foreign keys '.implode(', ', $skip['newForeignKeys']).($skip['alteredForeignKeys'] === [] ? ' were not added; create a migration for them)' : ' were added by an ALTER migration)') : '').'.';
        }
        array_unshift($messages, $report['skipped'] ? 'CRUD created for the new tables.' : 'CRUD created for all tables.');
        if ($report['altered']) $messages[] = 'Added an ALTER migration for new columns on: '.implode(', ', $report['altered']).'.';
        if ($report['docs']) $messages[] = 'API docs written to '.implode(' and ', $report['docs']).'.';
        if ($report['dashboard']) $messages[] = 'Dashboard ready at /dashboard.';
        if ($report['auth']) $messages[] = 'Authentication ready at /login. Set ADMIN_EMAIL and ADMIN_PASSWORD in .env; the AdminUserSeeder creates the first administrator.';
        if ($report['auth'] && $request->boolean('auth_verify')) $messages[] = 'Email verification is routed; add "implements MustVerifyEmail" to App\\Models\\User so it is enforced.';
        if (!$report['auth'] && $request->boolean('auth')) $messages[] = 'Authentication was skipped because the application already has a login route.';
        if ($report['demo'] && !$request->boolean('migrate')) $messages[] = "Run php artisan db:seed --class={$report['demo']} for sample data.";
        if ($request->boolean('migrate')) $messages = array_merge($messages, $generator->migrateAndSeed($report['seeders']));
        else $messages[] = 'Run php artisan migrate to create the tables.';
        return back()->with('status', implode(' ', $messages));
    }

    public function preview(Request $request, CrudGenerator $generator): JsonResponse
    {
        $this->authorizeStudio();
        return response()->json($generator->preview($this->validated($request)));
    }

    private function authorizeStudio(): void
    {
        if ($ability = config('crud.ability')) {
            Gate::authorize($ability);

            return;
        }
        // Without an ability every authenticated user could write files into the application, so only local development is open.
        abort_if(config('crud.require_ability', true) && !app()->environment(['local', 'testing']), 403, 'Schema Studio is locked outside local environments. Define a Gate ability and set crud.ability (or set crud.require_ability to false).');
    }

    /**
     * Published copies (php artisan vendor:publish --tag=crud-assets) are used while they match the packaged files,
     * so a stale copy after an upgrade can never be served; otherwise the assets come from the package route.
     *
     * @return array<string, string>
     */
    private function assets(): array
    {
        $urls = [];
        foreach (['studio.js', 'studio-i18n.js'] as $file) {
            $packaged = __DIR__.'/../../../resources/js/'.$file;
            $published = public_path("vendor/crud/{$file}");
            $urls[$file] = is_file($published) && md5_file($published) === md5_file($packaged)
                ? asset("vendor/crud/{$file}").'?v='.substr(md5_file($packaged), 0, 8)
                : route('crud-generator.asset', $file);
        }

        return $urls;
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'project' => ['required', 'array'],
            'project.name' => ['required', 'string', 'max:120'],
            'project.description' => ['nullable', 'string', 'max:500'],
            'project.locale' => ['required', 'in:en,ar'],
            'project.roles' => ['nullable', 'string', 'max:255', 'regex:/^[A-Za-z0-9_ ,-]+$/'],
            'tables' => ['required', 'array', 'min:1', 'max:12'],
            'tables.*.name' => ['required', 'string', 'max:80', 'regex:/^[A-Za-z][A-Za-z0-9_]*$/'],
            'tables.*.fields' => ['required', 'array', 'min:1', 'max:30'],
            'tables.*.fields.*.name' => ['required', 'string', 'max:80', 'regex:/^[A-Za-z][A-Za-z0-9_]*$/'],
            'tables.*.fields.*.type' => ['required', 'in:'.implode(',', ColumnTypes::names())],
            'tables.*.fields.*.nullable' => ['nullable', 'boolean'],
            'tables.*.fields.*.unique' => ['nullable', 'boolean'],
            'tables.*.existing' => ['nullable', 'boolean'],
            'tables.*.fields.*.existing' => ['nullable', 'boolean'],
            'tables.*.soft_deletes' => ['nullable', 'boolean'],
            'tables.*.timestamps' => ['nullable', 'boolean'],
            'tables.*.fields.*.values' => ['nullable', 'string', 'max:255', 'regex:/^[A-Za-z0-9_ ,.\\-]+$/'],
            'tables.*.fields.*.rules' => ['nullable', 'string', 'max:255', 'regex:/^[^\'"`;{}$]+$/'],
            'tables.*.fields.*.index' => ['nullable', 'boolean'],
            'tables.*.fields.*.rename_from' => ['nullable', 'string', 'max:80', 'regex:/^[A-Za-z][A-Za-z0-9_]*$/'],
            'tables.*.fields.*.changed' => ['nullable', 'boolean'],
            'tables.*.indexes' => ['nullable', 'array', 'max:20'],
            'tables.*.indexes.*.columns' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9_, ]+$/'],
            'tables.*.indexes.*.unique' => ['nullable', 'boolean'],
            'tables.*.indexes.*.existing' => ['nullable', 'boolean'],
            'tables.*.dropped' => ['nullable', 'array', 'max:30'],
            'tables.*.dropped.*' => ['string', 'regex:/^[A-Za-z][A-Za-z0-9_]*$/'],
            'api' => ['nullable', 'boolean'],
            'web' => ['nullable', 'boolean'],
            'tables.*.fields.*.default' => ['nullable', 'string', 'max:100'],
            'relations' => ['nullable', 'array', 'max:40'],
            'relations.*.from' => ['required', 'string', 'max:80'],
            'relations.*.to' => ['required', 'string', 'max:80'],
            'relations.*.type' => ['required', 'in:hasMany,hasOne,belongsTo,belongsToMany'],
            'relations.*.nullable' => ['nullable', 'boolean'],
            'relations.*.existing' => ['nullable', 'boolean'],
            'relations.*.key' => ['nullable', 'string', 'max:80', 'regex:/^[A-Za-z][A-Za-z0-9_]*$/'],
            'locales' => ['required', 'array', 'min:1'],
            'locales.*' => ['in:en,ar'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['in:view,create,update,delete'],
            'roles' => ['nullable', 'string', 'max:255', 'regex:/^[A-Za-z0-9_ ,-]+$/'],
            'dashboard' => ['nullable', 'boolean'],
            'layout' => ['nullable', 'in:admin,simple'],
            'auth' => ['nullable', 'boolean'],
            'auth_register' => ['nullable', 'boolean'],
            'auth_verify' => ['nullable', 'boolean'],
            'migrate' => ['nullable', 'boolean'],
            'sample_data' => ['nullable', 'boolean'],
            'sample_rows' => ['nullable', 'integer', 'between:1,100'],
            'generate_tests' => ['nullable', 'boolean'],
            'confirm_migrate' => ['nullable', 'boolean'],
        ]);
    }
}
