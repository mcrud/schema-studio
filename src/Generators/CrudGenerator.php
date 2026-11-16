<?php

namespace Mcrud\Crud\Generators;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Mcrud\Crud\Support\ColumnTypes;
use Mcrud\Crud\Support\ApiDocs;
use Mcrud\Crud\Support\Differ;
use Mcrud\Crud\Support\StubRenderer;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Throwable;

class CrudGenerator
{
    private readonly StubRenderer $stubs;

    /** 'admin' (sidebar + topbar) or 'simple' (top navbar) for the generated layout and nav links. */
    private string $layout = 'admin';

    public function __construct(private readonly Filesystem $files, ?StubRenderer $stubs = null)
    {
        $this->stubs = $stubs ?? new StubRenderer();
    }

    /** @return array{seeders: list<string>, dashboard: bool, auth: bool, skipped: list<array{model: string, newForeignKeys: list<string>}>, demo: ?string, altered: list<string>, api: bool, docs: list<string>} */
    public function generate(array $data): array
    {
        $plan = $this->plan($data);
        $permissions = $this->permissionsFor($data);
        $web = $this->webEnabled($data);
        $api = !empty($data['api']);
        $layout = $data['layout'] ?? config('crud.layout', 'admin');
        $this->layout = $layout === 'simple' ? 'simple' : 'admin';
        $tableNames = array_column($plan['tables'], 'table');

        // Columns, renames, drops, indexes and foreign keys added in the designer to tables imported from the database become ALTER migrations.
        $alters = [];
        foreach ($plan['tables'] as $t) {
            if (!$t['existing']) continue;
            $ops = $this->alterOps($t);
            if (array_filter($ops)) $alters[] = ['table' => $t['table'], 'model' => $t['model'], 'ops' => $ops];
        }

        // Tables that already exist in the app are skipped instead of aborting the whole run.
        $skipped = [];
        $plan['tables'] = array_values(array_filter($plan['tables'], function ($t) use (&$skipped, &$alters, $web, $api): bool {
            $exists = $this->files->exists(app_path("Models/{$t['model']}.php"))
                || ($web && $this->files->exists(app_path("Http/Controllers/{$t['controller']}.php")))
                || ($web && $this->files->exists(resource_path("views/{$t['table']}/index.blade.php")))
                || ($api && $this->files->exists(app_path("Http/Controllers/Api/{$t['controller']}.php")))
                || (!$t['existing'] && $this->migrationExists("create_{$t['table']}_table"));
            if ($exists) {
                // Foreign keys a new relation would add to a table that is already generated get their own ALTER migration.
                $added = [];
                if (!$t['existing']) {
                    $missing = array_values(array_filter($t['fields'], fn ($f) => !empty($f['foreign']) && !$f['existing'] && !$this->migrationDefinesColumn($t['table'], $f['name'])));
                    if ($missing) {
                        $alters[] = ['table' => $t['table'], 'model' => $t['model'], 'ops' => ['add' => $missing, 'rename' => [], 'change' => [], 'drop' => [], 'indexes' => []]];
                        $added = array_column($missing, 'name');
                    }
                }
                $skipped[] = ['model' => $t['model'], 'newForeignKeys' => $t['existing'] ? [] : collect($t['fields'])->whereNotNull('foreign')->pluck('name')->all(), 'alteredForeignKeys' => $added];
            }
            return !$exists;
        }));
        $plan['pivots'] = array_values(array_filter($plan['pivots'], fn ($pivot) => !$pivot['existing'] && !$this->migrationExists("create_{$pivot['table']}_table")));

        // Fail before the first write. A late collision (for example a hand-written
        // request or test) must never leave a half-generated CRUD behind.
        $artifacts = [];
        foreach ($plan['tables'] as $t) $artifacts[$t['table']] = $this->artifacts($t, $data);
        $this->assertGenerationIsWritable($plan['tables'], $artifacts, $data);

        $base = now();
        $step = 0;
        foreach ($plan['tables'] as $t) {
            if ($t['existing']) continue;
            $this->write(database_path('migrations/'.$base->copy()->addSeconds($step++)->format('Y_m_d_His')."_create_{$t['table']}_table.php"), $this->migration($t['table'], $t['fields'], $t));
        }
        foreach ($plan['pivots'] as $pivot) {
            $this->write(database_path('migrations/'.$base->copy()->addSeconds($step++)->format('Y_m_d_His')."_create_{$pivot['table']}_table.php"), $this->pivotMigration($pivot));
        }

        foreach ($alters as $alter) {
            $onlyAdds = !$alter['ops']['rename'] && !$alter['ops']['change'] && !$alter['ops']['drop'] && !$alter['ops']['indexes'];
            $name = $onlyAdds ? "add_columns_to_{$alter['table']}_table" : "alter_{$alter['table']}_table";
            $this->write(database_path('migrations/'.$base->copy()->addSeconds($step++)->format('Y_m_d_His')."_{$name}.php"), $this->alterMigration($alter['table'], $alter['ops']));
        }

        $seeders = [];
        if ($web) $this->writeOnce(resource_path('views/layouts/app.blade.php'), $this->stub($this->layout === 'admin' ? 'layout-admin' : 'layout', ['app' => e($data['project']['name'])]));
        // Login, profile and (with permissions) user and role management. Skipped when the app already has a login route.
        $auth = $web && !empty($data['auth']) && !$this->authInstalled();
        $adminSeeder = $auth ? $this->installAuth($data, $tableNames, $permissions) : null;
        // Verified email is enforced by the 'verified' middleware, which only acts on users implementing MustVerifyEmail.
        $guard = $auth && !empty($data['auth_verify']) ? "->middleware(['auth', 'verified'])" : (($permissions || $auth) ? "->middleware('auth')" : '');
        foreach ($plan['tables'] as $t) {
            ['model' => $model, 'table' => $table, 'controller' => $controller] = $t;
            foreach ($artifacts[$table] as $path => $contents) $this->write($path, $contents);
            if ($web) {
                $this->addNavLink("{$table}.index", Str::headline($table));
                $middleware = $guard;
                $this->appendRoute("Route::get('{$table}/export', [\\App\\Http\\Controllers\\{$controller}::class, 'export'])->name('{$table}.export'){$middleware};");
                $this->appendRoute("Route::delete('{$table}/bulk', [\\App\\Http\\Controllers\\{$controller}::class, 'bulkDestroy'])->name('{$table}.bulk-destroy'){$middleware};");
                if (collect($t['fields'])->contains(fn ($f) => !empty($f['foreign']))) $this->appendRoute("Route::get('{$table}/lookup/{field}', [\\App\\Http\\Controllers\\{$controller}::class, 'lookup'])->name('{$table}.lookup'){$middleware};");
                if ($this->importable($t)) $this->appendRoute("Route::post('{$table}/import', [\\App\\Http\\Controllers\\{$controller}::class, 'import'])->name('{$table}.import'){$middleware};");
                if (!empty($t['soft_deletes'])) {
                    $this->appendRoute("Route::patch('{$table}/{id}/restore', [\\App\\Http\\Controllers\\{$controller}::class, 'restore'])->name('{$table}.restore'){$middleware};");
                    $this->appendRoute("Route::delete('{$table}/{id}/force', [\\App\\Http\\Controllers\\{$controller}::class, 'forceDelete'])->name('{$table}.force-delete'){$middleware};");
                    $this->appendRoute("Route::patch('{$table}/bulk-restore', [\\App\\Http\\Controllers\\{$controller}::class, 'bulkRestore'])->name('{$table}.bulk-restore'){$middleware};");
                }
                $this->appendRoute("Route::resource('{$table}', \\App\\Http\\Controllers\\{$controller}::class){$middleware};");
                foreach ($data['locales'] as $locale) $this->translations($locale, $model, $table, $t);
            }
            if ($api) $this->appendApiRoute($table, $controller, $permissions !== []);
            if ($permissions) $seeders[] = $this->permissionSeeder($table, $permissions, $data['roles'] ?? '');
        }

        $demo = null;
        if (!empty($data['sample_data']) && $plan['tables'] !== []) {
            $demo = $this->demoSeeder($plan['tables'], max(1, min(100, (int) ($data['sample_rows'] ?? 10))));
            $seeders[] = $demo;
        }

        if ($adminSeeder) $seeders[] = $adminSeeder;

        $docs = $api && $plan['tables'] !== [] ? $this->apiDocs($plan['tables'], $data) : [];
        $dashboard = $web && !empty($data['dashboard']) && $plan['tables'] !== [] && $this->dashboard($plan['tables'], $permissions, $guard);
        $this->recordProjectProfile($data['project'], collect($plan['tables'])->pluck('model')->all());

        return ['seeders' => $seeders, 'dashboard' => $dashboard, 'auth' => $auth, 'skipped' => $skipped, 'demo' => $demo, 'altered' => array_column($alters, 'model'), 'api' => $api, 'docs' => $docs];
    }

    /** Which permission system generated code uses: 'spatie', 'none', or 'missing' (spatie was required but is not installed). */
    public function permissionsDriver(): string
    {
        $setting = config('crud.permissions', 'auto');
        if ($setting === 'none') return 'none';
        if ($this->spatieInstalled()) return 'spatie';

        return $setting === 'spatie' ? 'missing' : 'none';
    }

    protected function spatieInstalled(): bool
    {
        return class_exists(\Spatie\Permission\PermissionServiceProvider::class);
    }

    /** The requested permissions, or none when the configured driver is "none" (generated code then only requires login). */
    private function permissionsFor(array $data): array
    {
        $requested = collect($data['permissions'] ?? [])->unique()->values()->all();
        if ($requested === []) return [];

        return match ($this->permissionsDriver()) {
            'spatie' => $requested,
            'none' => [],
            default => throw ValidationException::withMessages(['permissions' => "Permissions need spatie/laravel-permission. Run composer require spatie/laravel-permission, or set crud.permissions to 'none'."]),
        };
    }

    private function webEnabled(array $data): bool
    {
        return !array_key_exists('web', $data) || filter_var($data['web'], FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Every application file generated for one table, keyed by absolute path.
     * Migrations, translations and seeders are written separately because they are stateful or timestamped.
     *
     * @return array<string, string>
     */
    public function artifacts(array $t, array $data): array
    {
        ['model' => $model, 'table' => $table, 'controller' => $controller] = $t;
        $permissions = $this->permissionsFor($data);
        $web = $this->webEnabled($data);
        $api = !empty($data['api']);
        $tests = $data['generate_tests'] ?? config('crud.generate_tests', true);

        $files = [
            app_path("Models/{$model}.php") => $this->model($model, $t['fields'], $t['relations'], $t),
            app_path("Http/Requests/Store{$model}Request.php") => $this->formRequest("Store{$model}Request", $model, $table, $t, false, $permissions),
            app_path("Http/Requests/Update{$model}Request.php") => $this->formRequest("Update{$model}Request", $model, $table, $t, true, $permissions),
            database_path("factories/{$model}Factory.php") => $this->factory($t),
        ];
        if ($permissions) $files[app_path("Policies/{$model}Policy.php")] = $this->policy($model, $table, $permissions);
        if ($web) {
            $files[app_path("Http/Controllers/{$controller}.php")] = $this->controller($t, $permissions);
            $files[resource_path("views/{$table}/index.blade.php")] = $this->indexView($t);
            $files[resource_path("views/{$table}/form.blade.php")] = $this->formView($t);
            if ($tests) $files[base_path("tests/Feature/{$model}CrudTest.php")] = $this->featureTest($t, $permissions);
        }
        if ($api) {
            $files[app_path("Http/Controllers/Api/{$controller}.php")] = $this->apiController($t, $permissions);
            $files[app_path("Http/Resources/{$model}Resource.php")] = $this->apiResource($t);
            if ($tests) $files[base_path("tests/Feature/{$model}ApiTest.php")] = $this->apiTest($t, $permissions);
        }

        return $files;
    }

    /** A side-effect-free manifest used by the browser and CLI before generation. */
    public function preview(array $data): array
    {
        $plan = $this->plan($data);
        $files = [];
        $conflicts = [];
        foreach ($plan['tables'] as $t) {
            foreach (array_keys($this->artifacts($t, $data)) as $path) {
                $files[] = $this->relative($path);
                if ($this->files->exists($path)) $conflicts[] = $this->relative($path);
            }
        }
        if ($this->webEnabled($data) && !empty($data['auth']) && !$this->authInstalled()) {
            foreach (array_keys($this->authFiles($data, array_column($plan['tables'], 'table'), $this->permissionsFor($data))) as $path) {
                if (!$this->files->exists($path)) $files[] = $this->relative($path);
            }
        }
        $creates = collect($plan['tables'])->reject('existing')->pluck('table')->values()->all();
        foreach ($creates as $table) if ($this->migrationExists("create_{$table}_table")) $conflicts[] = "database/migrations/*_create_{$table}_table.php";
        foreach (collect($plan['pivots'])->reject('existing')->pluck('table') as $table) if ($this->migrationExists("create_{$table}_table")) $conflicts[] = "database/migrations/*_create_{$table}_table.php";
        $alters = collect($plan['tables'])->filter(fn ($t) => $t['existing'] && array_filter($this->alterOps($t)))->pluck('table')->values()->all();
        return ['tables' => collect($plan['tables'])->pluck('table')->values()->all(), 'create_migrations' => $creates, 'pivot_migrations' => collect($plan['pivots'])->reject('existing')->pluck('table')->values()->all(), 'alter_migrations' => $alters, 'files' => $files, 'conflicts' => array_values(array_unique($conflicts)), 'will_migrate' => !empty($data['migrate']), 'api' => !empty($data['api'])];
    }

    /**
     * Compares freshly generated files with what is on disk.
     *
     * @return list<array{path: string, status: 'missing'|'identical'|'changed', diff: string}>
     */
    public function diff(array $data): array
    {
        $differ = new Differ();
        $report = [];
        foreach ($this->plan($data)['tables'] as $t) {
            foreach ($this->artifacts($t, $data) as $path => $generated) {
                if (!$this->files->exists($path)) { $report[] = ['path' => $this->relative($path), 'status' => 'missing', 'diff' => '']; continue; }
                $current = $this->files->get($path);
                $report[] = $current === $generated
                    ? ['path' => $this->relative($path), 'status' => 'identical', 'diff' => '']
                    : ['path' => $this->relative($path), 'status' => 'changed', 'diff' => $differ->unified($current, $generated, $this->relative($path))];
            }
        }
        return $report;
    }

    /**
     * Brings generated application files in line with the schema. Missing files are created, differing ones are
     * merged with conflict markers (or replaced with $force) and every touched file is backed up as *.bak first.
     *
     * @param list<string> $only Project-relative paths to limit the run to; empty means every file.
     * @return list<array{path: string, status: 'created'|'identical'|'merged'|'overwritten'|'skipped', conflicts: int}>
     */
    public function sync(array $data, bool $force = false, array $only = []): array
    {
        $differ = new Differ();
        $report = [];
        foreach ($this->plan($data)['tables'] as $t) {
            foreach ($this->artifacts($t, $data) as $path => $generated) {
                $relative = $this->relative($path);
                if ($only !== [] && !in_array($relative, $only, true)) continue;
                if (!$this->files->exists($path)) {
                    $this->files->ensureDirectoryExists(dirname($path));
                    $this->files->put($path, $generated);
                    $report[] = ['path' => $relative, 'status' => 'created', 'conflicts' => 0];
                    continue;
                }
                $current = $this->files->get($path);
                if ($current === $generated) {
                    $report[] = ['path' => $relative, 'status' => 'identical', 'conflicts' => 0];
                    continue;
                }
                if (!$force && str_contains($current, '<<<<<<< current')) {
                    // Merging on top of unresolved markers would bury the first merge.
                    $report[] = ['path' => $relative, 'status' => 'skipped', 'conflicts' => 0];
                    continue;
                }
                $this->files->put($path.'.bak', $current);
                if ($force) {
                    $this->files->put($path, $generated);
                    $report[] = ['path' => $relative, 'status' => 'overwritten', 'conflicts' => 0];
                    continue;
                }
                [$merged, $conflicts] = $differ->merge($current, $generated);
                $this->files->put($path, $merged);
                $report[] = ['path' => $relative, 'status' => 'merged', 'conflicts' => $conflicts];
            }
        }

        return $report;
    }

    private function relative(string $path): string
    {
        return ltrim(str_replace(base_path(), '', $path), '/');
    }

    /** Runs pending migrations and the generated permission seeders; returns human-readable result lines. */
    public function migrateAndSeed(array $seeders): array
    {
        $messages = [];
        try {
            Artisan::call('migrate', ['--force' => true]);
            $messages[] = 'Database migrated.';
        } catch (Throwable $e) {
            return ["Files were generated, but migration failed: {$e->getMessage()}. Run php artisan migrate manually."];
        }
        foreach ($seeders as $class) {
            try {
                Artisan::call('db:seed', ['--class' => "Database\\Seeders\\{$class}", '--force' => true]);
            } catch (Throwable $e) {
                $messages[] = "Seeder {$class} failed: {$e->getMessage()}. Is spatie/laravel-permission migrated?";
                return $messages;
            }
        }
        if ($seeders) $messages[] = 'Permissions and roles seeded.';
        return $messages;
    }

    /** Normalises the submitted tables and relations into an ordered, dependency-safe generation plan. */
    public function plan(array $data): array
    {
        $tables = [];
        foreach ($data['tables'] as $input) {
            $resource = Str::snake($input['name']);
            $key = Str::lower($input['name']);
            if (isset($tables[$key])) throw ValidationException::withMessages(['tables' => "Duplicate table name: {$input['name']}."]);
            $names = [];
            $fields = collect($input['fields'])->map(function ($f) use (&$names, $input) {
                $name = Str::snake($f['name']);
                if (in_array($name, $names, true)) throw ValidationException::withMessages(['tables' => "Duplicate column {$name} in {$input['name']}."]);
                $names[] = $name;
                return ['name' => $name, 'type' => $f['type'], 'nullable' => !empty($f['nullable']), 'unique' => !empty($f['unique']), 'default' => $f['default'] ?? null, 'existing' => !empty($f['existing']), 'foreign' => null, 'rules' => $this->customRules($f['rules'] ?? ''), 'index' => !empty($f['index']), 'rename_from' => !empty($f['rename_from']) ? Str::snake($f['rename_from']) : null, 'changed' => !empty($f['changed']), 'values' => $f['type'] === 'enum' ? ColumnTypes::enumValues($f['values'] ?? '') : []];
                
            })->all();
            foreach ($fields as $f) {
                if ($f['type'] === 'enum' && !$f['values']) throw ValidationException::withMessages(['tables' => "Column {$f['name']} in {$input['name']} is an enum and needs at least one value."]);
            }
            $indexes = [];
            foreach ($input['indexes'] ?? [] as $index) {
                $columns = collect(is_array($index['columns']) ? $index['columns'] : explode(',', (string) $index['columns']))->map(fn ($c) => Str::snake(trim((string) $c)))->filter()->values()->all();
                if ($columns) $indexes[] = ['columns' => $columns, 'unique' => !empty($index['unique']), 'existing' => !empty($index['existing'])];
            }
            $tables[$key] = ['indexes' => $indexes, 'dropped' => array_values(array_unique(array_map(fn ($c) => Str::snake((string) $c), $input['dropped'] ?? []))), 'model' => Str::studly(Str::singular($resource)), 'table' => Str::plural($resource), 'controller' => Str::studly(Str::plural($resource)).'Controller', 'fields' => $fields, 'display' => $this->displayColumn($fields), 'existing' => !empty($input['existing']), 'soft_deletes' => !empty($input['soft_deletes']), 'timestamps' => !isset($input['timestamps']) || filter_var($input['timestamps'], FILTER_VALIDATE_BOOLEAN), 'relations' => [], 'depends' => []];
        }

        $pivots = [];
        foreach ($data['relations'] ?? [] as $r) {
            $from = Str::lower($r['from']);
            $to = Str::lower($r['to']);
            if (!isset($tables[$from], $tables[$to])) throw ValidationException::withMessages(['relations' => 'A relation points to a table that does not exist.']);
            if ($from === $to && $r['type'] === 'belongsToMany') throw ValidationException::withMessages(['relations' => 'A self many-to-many relation needs an explicit pivot model.']);
            // In the designer, "Order belongsTo User" reads naturally child -> parent.
            if ($r['type'] === 'belongsTo') { [$from, $to] = [$to, $from]; $r['type'] = 'hasMany'; }
            $parent = $tables[$from];
            $child = $tables[$to];

            if ($r['type'] === 'belongsToMany') {
                $pair = collect([Str::singular($parent['table']), Str::singular($child['table'])])->sort()->values();
                $pivotTable = $pair->implode('_');
                if (collect($pivots)->contains('table', $pivotTable)) continue;
                $pivots[] = ['existing' => !empty($r['existing']), 'table' => $pivotTable, 'a' => [Str::singular($parent['table']).'_id', $parent['table']], 'b' => [Str::singular($child['table']).'_id', $child['table']]];
                $tables[$from]['relations'][] = ['type' => 'belongsToMany', 'name' => Str::camel($child['table']), 'model' => $child['model'], 'pivot' => $pivotTable, 'table' => $child['table'], 'display' => $child['display'], 'owner' => true];
                $tables[$to]['relations'][] = ['type' => 'belongsToMany', 'name' => Str::camel($parent['table']), 'model' => $parent['model'], 'pivot' => $pivotTable, 'table' => $parent['table'], 'display' => $parent['display'], 'owner' => false];
                continue;
            }

            $foreignKey = !empty($r['key']) ? Str::snake($r['key']) : Str::singular($parent['table']).'_id';
            $parentRelation = !empty($r['key']) ? Str::camel(Str::replaceLast('_id', '', $foreignKey)) : Str::camel($parent['model']);
            if (!collect($tables[$to]['fields'])->contains('name', $foreignKey)) {
                array_unshift($tables[$to]['fields'], ['name' => $foreignKey, 'type' => 'foreignId', 'nullable' => !empty($r['nullable']), 'unique' => $r['type'] === 'hasOne', 'default' => null, 'foreign' => $parent['table'], 'existing' => !empty($r['existing']), 'relation' => $parentRelation, 'related' => $parent['model'], 'display' => $parent['display']]);
            }
            if ($from !== $to) $tables[$to]['depends'][] = $from;
            $childName = $r['type'] === 'hasOne' ? Str::camel($child['model']) : Str::camel($child['table']);
            if (collect($tables[$from]['relations'])->contains('name', $childName)) $childName .= 'By'.Str::studly(Str::replaceLast('_id', '', $foreignKey));
            $tables[$from]['relations'][] = ['type' => $r['type'], 'name' => $childName, 'model' => $child['model'], 'key' => $foreignKey];
            $tables[$to]['relations'][] = ['type' => 'belongsTo', 'name' => $parentRelation, 'model' => $parent['model'], 'key' => $foreignKey];
        }

        foreach ($tables as $key => $table) {
            $columns = array_column($table['fields'], 'name');
            foreach ($table['indexes'] as $i => $index) {
                $missing = array_diff($index['columns'], $columns);
                if ($missing) throw ValidationException::withMessages(['tables' => "Index on {$table['table']} uses unknown column ".implode(', ', $missing).'.']);
            }
        }

        $ordered = [];
        $visiting = [];
        $visit = function (string $key) use (&$visit, &$ordered, &$visiting, $tables): void {
            if (isset($ordered[$key])) return;
            if (isset($visiting[$key])) throw ValidationException::withMessages(['relations' => 'Relations form a cycle, so the migrations cannot be ordered.']);
            $visiting[$key] = true;
            foreach (array_unique($tables[$key]['depends']) as $dependency) $visit($dependency);
            unset($visiting[$key]);
            $ordered[$key] = $tables[$key];
        };
        foreach (array_keys($tables) as $key) $visit($key);

        return ['tables' => array_values($ordered), 'pivots' => $pivots];
    }

    private function migrationExists(string $name): bool
    {
        return $this->files->glob(database_path("migrations/*_{$name}.php")) !== [];
    }

    private function assertGenerationIsWritable(array $tables, array $artifacts, array $data): void
    {
        $paths = [];
        foreach ($tables as $t) {
            $paths = array_merge($paths, array_keys($artifacts[$t['table']] ?? []));
            if ($this->webEnabled($data)) foreach ($data['locales'] as $locale) $paths[] = lang_path("{$locale}/{$t['table']}.php");
        }
        $collisions = array_values(array_filter($paths, fn (string $path) => $this->files->exists($path)));
        if ($collisions) throw new RuntimeException('Refusing to overwrite existing generated artifacts: '.implode(', ', $collisions));
    }

    private function recordProjectProfile(array $project, array $models): void
    {
        $path = storage_path('app/crud-schema-studio/project.json');
        $existing = $this->files->exists($path) ? json_decode($this->files->get($path), true) : [];
        $resources = collect($existing['resources'] ?? [])->merge($models)->unique()->values()->all();
        $profile = ['name' => $project['name'], 'description' => $project['description'] ?? '', 'locale' => $project['locale'], 'roles' => $project['roles'] ?? '', 'resources' => $resources, 'updated_at' => now()->toIso8601String()];
        $this->files->ensureDirectoryExists(dirname($path));
        $this->files->put($path, json_encode($profile, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n");
        $projectPath = storage_path('app/crud-schema-studio/projects/'.(Str::slug($project['name']) ?: 'project').'.json');
        $this->files->ensureDirectoryExists(dirname($projectPath));
        $this->files->put($projectPath, json_encode($profile, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n");
    }

    private function write(string $path, string $contents): void
    {
        if ($this->files->exists($path)) throw new RuntimeException("Refusing to overwrite existing file: {$path}");
        $this->files->ensureDirectoryExists(dirname($path));
        $this->files->put($path, $contents);
    }

    private function appendRoute(string $route): void
    {
        $path = base_path('routes/web.php');
        $contents = $this->files->exists($path) ? $this->files->get($path) : "<?php\n\nuse Illuminate\\Support\\Facades\\Route;\n";
        if (!str_contains($contents, $route)) $this->files->put($path, rtrim($contents)."\n\n{$route}\n");
    }

    private function columnLine(array $f): string
    {
        if ($f['type'] === 'foreignId') {
            $column = "            \$table->foreignId('{$f['name']}')".($f['nullable'] ? '->nullable()' : '')."->constrained('{$f['foreign']}')".($f['nullable'] ? '->nullOnDelete()' : '->cascadeOnDelete()');

            return $column.($f['unique'] ? '->unique()' : '').';';
        }
        $args = $f['type'] === 'enum' ? ", ['".implode("', '", array_map(fn ($v) => str_replace("'", "\\'", $v), $f['values']))."']" : '';
        // Browser-oriented semantic types map to portable Blueprint columns.
        $blueprintType = ColumnTypes::storedAsString($f['type']) ? 'string' : $f['type'];
        $column = "            \$table->{$blueprintType}('{$f['name']}'{$args})";
        if ($f['nullable']) $column .= '->nullable()';
        if ($f['unique']) $column .= '->unique()';
        elseif (!empty($f['index'])) $column .= '->index()';
        if ($f['default'] !== null && $f['default'] !== '') $column .= "->default('".str_replace("'", "\\'", $f['default'])."')";

        return $column.';';
    }

    private function migration(string $table, array $fields, array $opts = []): string
    {
        $columns = collect($fields)->map(fn ($f) => $this->columnLine($f))->implode("\n");
        $composite = collect($opts['indexes'] ?? [])->map(fn ($i) => $this->indexLine($i))->implode('');
        $extra = $composite.(($opts['timestamps'] ?? true) ? "            \$table->timestamps();\n" : '').(!empty($opts['soft_deletes']) ? "            \$table->softDeletes();\n" : '');

        return $this->stub('migration', ['table' => $table, 'columns' => $columns, 'extra' => $extra]);
    }

    private function indexLine(array $index): string
    {
        $columns = "['".implode("', '", $index['columns'])."']";

        return '            $table->'.($index['unique'] ? 'unique' : 'index')."({$columns});\n";
    }

    /** Operations for an imported table: columns added, renamed, changed or dropped, and new indexes. */
    private function alterOps(array $t): array
    {
        $existing = array_filter($t['fields'], fn ($f) => $f['existing']);

        return [
            'add' => array_values(array_filter($t['fields'], fn ($f) => !$f['existing'])),
            'rename' => array_values(array_filter($existing, fn ($f) => !empty($f['rename_from']) && $f['rename_from'] !== $f['name'])),
            'change' => array_values(array_filter($existing, fn ($f) => !empty($f['changed']) && empty($f['foreign']))),
            'drop' => $t['dropped'],
            'indexes' => array_values(array_filter($t['indexes'], fn ($i) => !$i['existing'])),
        ];
    }

    private function alterMigration(string $table, array $ops): string
    {
        $up = [];
        $down = [];
        foreach ($ops['rename'] as $f) {
            $up[] = "            \$table->renameColumn('{$f['rename_from']}', '{$f['name']}');";
            array_unshift($down, "            \$table->renameColumn('{$f['name']}', '{$f['rename_from']}');");
        }
        foreach ($ops['add'] as $f) {
            $up[] = $this->columnLine($f);
            array_unshift($down, $f['type'] === 'foreignId' ? "            \$table->dropConstrainedForeignId('{$f['name']}');" : "            \$table->dropColumn('{$f['name']}');");
        }
        foreach ($ops['change'] as $f) {
            $up[] = rtrim($this->columnLine($f), ';').'->change();';
            array_unshift($down, "            // The previous definition of '{$f['name']}' is unknown, so this change is not reversed automatically.");
        }
        foreach ($ops['indexes'] as $index) {
            $up[] = rtrim($this->indexLine($index));
            array_unshift($down, '            $table->drop'.($index['unique'] ? 'Unique' : 'Index')."(['".implode("', '", $index['columns'])."']);");
        }
        if ($ops['drop']) {
            $up[] = "            \$table->dropColumn(['".implode("', '", $ops['drop'])."']);";
            array_unshift($down, '            // Dropped columns ('.implode(', ', $ops['drop']).') cannot be restored automatically.');
        }
        $up = implode("\n", $up);
        $down = implode("\n", $down);

        return $this->stub('migration-alter', ['table' => $table, 'up' => $up, 'down' => $down]);
    }

    /** True when an existing migration already creates or adds this column on the table. */
    private function migrationDefinesColumn(string $table, string $column): bool
    {
        foreach ($this->files->glob(database_path('migrations/*.php')) ?: [] as $file) {
            $contents = $this->files->get($file);
            if (str_contains($contents, "'{$table}'") && str_contains($contents, "'{$column}'")) return true;
        }

        return false;
    }

    /** @return list<string> Laravel rule fragments entered in the column inspector. */
    private function customRules(string $raw): array
    {
        return array_values(array_filter(array_map('trim', explode('|', $raw)), fn ($r) => $r !== ''));
    }

    private function pivotMigration(array $pivot): string
    {
        [$a, $aTable] = $pivot['a'];
        [$b, $bTable] = $pivot['b'];
        return $this->stub('migration-pivot', ['table' => $pivot['table'], 'a' => $a, 'b' => $b, 'aTable' => $aTable, 'bTable' => $bTable]);
    }

    private function stub(string $name, array $vars = []): string
    {
        return $this->stubs->render($name, $vars);
    }

    /** Pick the column that best describes a record (used for select labels and relation display). */
    private function displayColumn(array $fields): string
    {
        $names = collect($fields)->pluck('name');
        foreach (['name', 'title', 'label', 'full_name', 'email', 'number', 'sku'] as $candidate) {
            if ($names->contains($candidate)) return $candidate;
        }

        return collect($fields)->first(fn ($f) => $f['type'] === 'string' && empty($f['foreign']))['name'] ?? 'id';
    }

    private function model(string $model, array $fields, array $relations = [], array $opts = []): string
    {
        $fillable = collect($fields)->pluck('name')->map(fn ($n) => "        '{$n}',")->implode("\n");
        $casts = collect($fields)->filter(fn ($f) => empty($f['foreign']) && ColumnTypes::cast($f['type']))
            ->map(fn ($f) => "        '{$f['name']}' => '".ColumnTypes::cast($f['type'])."',")->implode("\n");
        $methods = collect($relations)->map(function ($r): string {
            $call = match ($r['type']) {
                'belongsToMany' => "belongsToMany({$r['model']}::class, '{$r['pivot']}')",
                default => "{$r['type']}({$r['model']}::class, '{$r['key']}')",
            };
            $return = match ($r['type']) { 'belongsTo' => 'BelongsTo', 'hasOne' => 'HasOne', 'hasMany' => 'HasMany', default => 'BelongsToMany' };

            return "\n    public function {$r['name']}(): \\Illuminate\\Database\\Eloquent\\Relations\\{$return}\n    {\n        return \$this->{$call};\n    }\n";
        })->implode('');

        return $this->stub('model', [
            'model' => $model, 'fillable' => $fillable,
            'softUse' => !empty($opts['soft_deletes']) ? "use Illuminate\\Database\\Eloquent\\SoftDeletes;\n" : '', 'softTrait' => !empty($opts['soft_deletes']) ? ', SoftDeletes' : '',
            'casts' => (($opts['timestamps'] ?? true) ? '' : "\n    public \$timestamps = false;\n").($casts ? "\n    protected \$casts = [\n{$casts}\n    ];\n" : '').$this->hiddenLine($fields), 'methods' => $methods,
        ]);
    }

    /** Passwords never leave the model through toArray() or JSON. */
    private function hiddenLine(array $fields): string
    {
        $hidden = collect($fields)->where('type', 'password')->pluck('name')->map(fn ($n) => "'{$n}'")->implode(', ');

        return $hidden ? "\n    protected \$hidden = [{$hidden}];\n" : '';
    }

    /** The private data() method shared by the web and API controllers: uploads, blank passwords and generated slugs. */
    private function dataMethod(array $t): array
    {
        $uses = [];
        $lines = [];
        $fields = collect($t['fields']);
        $source = $fields->first(fn ($f) => in_array($f['name'], ['name', 'title', 'label'], true))['name']
            ?? $fields->first(fn ($f) => $f['type'] === 'string' && empty($f['foreign']))['name'] ?? null;
        foreach ($t['fields'] as $f) {
            $n = $f['name'];
            if ($f['type'] === 'password') {
                $lines[] = "        if (\$item !== null && empty(\$data['{$n}'])) unset(\$data['{$n}']);";
            } elseif (ColumnTypes::isUpload($f['type'])) {
                $uses['Storage'] = "use Illuminate\\Support\\Facades\\Storage;\n";
                $remove = $f['nullable'] ? "            if (\$request->boolean('remove_{$n}') && \$item?->{$n}) {\n                Storage::disk('public')->delete(\$item->{$n});\n                \$data['{$n}'] = null;\n            }\n" : '';
                $lines[] = "        if (\$request->hasFile('{$n}')) {\n            if (\$item?->{$n}) Storage::disk('public')->delete(\$item->{$n});\n            \$data['{$n}'] = \$request->file('{$n}')->store('{$t['table']}', 'public');\n        } else {\n            unset(\$data['{$n}']);\n{$remove}        }";
            } elseif ($f['type'] === 'slug') {
                $uses['Str'] = "use Illuminate\\Support\\Str;\n";
                $from = $source && $source !== $n ? "(string) (\$data['{$source}'] ?? '')" : "''";
                $lines[] = "        if (empty(\$data['{$n}'])) {\n            \$base = Str::slug({$from}) ?: 'item';\n            \$slug = \$base;\n            \$i = 2;\n            while ({$t['model']}::query()->withoutGlobalScopes()->where('{$n}', \$slug)->when(\$item, fn (\$query) => \$query->whereKeyNot(\$item->getKey()))->exists()) \$slug = \$base.'-'.\$i++;\n            \$data['{$n}'] = \$slug;\n        }";
            }
        }

        return ['uses' => $uses, 'prepare' => implode("\n", $lines)];
    }

    private function formRequest(string $class, string $model, string $table, array $t, bool $update, array $permissions): string
    {
        $param = Str::singular($table);
        $lines = [];
        foreach ($t['fields'] as $f) {
            // A blank password or upload on update keeps the current value, and an empty slug is generated.
            $optional = $f['nullable'] || $f['type'] === 'slug' || ($update && ColumnTypes::optionalOnUpdate($f['type']));
            $rules = [$optional ? "'nullable'" : "'required'"];
            if (!empty($f['foreign'])) {
                array_push($rules, "'integer'", "'exists:{$f['foreign']},id'");
            } elseif ($f['type'] === 'enum') {
                $rules[] = "Rule::in(['".implode("', '", array_map(fn ($v) => str_replace("'", "\\'", $v), $f['values']))."'])";
            } else {
                foreach (explode('|', ColumnTypes::rule($f['type'])) as $rule) $rules[] = "'{$rule}'";
            }
            foreach ($f['rules'] ?? [] as $custom) $rules[] = var_export($custom, true);
            if ($f['unique']) {
                $rules[] = "Rule::unique('{$table}', '{$f['name']}')".($update ? "->ignore(\$this->route('{$param}'))" : '');
            }
            $lines[] = "            '{$f['name']}' => [".implode(', ', $rules).'],';
        }
        foreach ($t['relations'] as $r) {
            if ($r['type'] !== 'belongsToMany') continue;
            $lines[] = "            '{$r['name']}' => ['nullable', 'array'],";
            $lines[] = "            '{$r['name']}.*' => ['integer', 'exists:{$r['table']},id'],";
        }

        $gate = in_array($update ? 'update' : 'create', $permissions, true);
        $authorize = !$gate ? 'true' : ($update ? "\$this->user()?->can('update', \$this->route('{$param}')) ?? false" : "\$this->user()?->can('create', {$model}::class) ?? false");

        return $this->stub('request', [
            'class' => $class, 'authorize' => $authorize, 'rules' => implode("\n", $lines),
            'uses' => $gate && !$update ? "use App\\Models\\{$model};\n" : '',
        ]);
    }

    private function policy(string $model, string $table, array $permissions): string
    {
        $check = fn (string $p) => in_array($p, $permissions, true) ? "\$user->can('{$table}.{$p}')" : 'true';

        return $this->stub('policy', ['model' => $model, 'view' => $check('view'), 'create' => $check('create'), 'update' => $check('update'), 'delete' => $check('delete')]);
    }

    private function factory(array $t): string
    {
        $uses = [];
        $lines = collect($t['fields'])->map(function ($f) use (&$uses): string {
            if (!empty($f['foreign'])) {
                $uses[$f['related']] = "use App\\Models\\{$f['related']};";
                return "            '{$f['name']}' => fn () => {$f['related']}::query()->inRandomOrder()->value('id') ?? {$f['related']}::factory()->create()->id,";
            }

            if ($f['type'] === 'enum') return "            '{$f['name']}' => fake()->randomElement(['".implode("', '", array_map(fn ($v) => str_replace("'", "\\'", $v), $f['values']))."']),";

            return "            '{$f['name']}' => ".ColumnTypes::faker($f['name'], $f['type'], $f['unique']).',';
        })->implode("\n");
        $uses["{$t['model']}"] = "use App\\Models\\{$t['model']};";

        return $this->stub('factory', ['model' => $t['model'], 'attributes' => $lines, 'uses' => implode("\n", $uses)."\n"]);
    }

    private function featureTest(array $t, array $permissions): string
    {
        $model = $t['model']; $table = $t['table']; $param = Str::singular($table);
        $login = $this->actingAsLines($permissions, false).$this->fakeDisk($t);
        $payload = $this->testPayload($t);
        $gone = !empty($t['soft_deletes']) ? 'assertSoftDeleted' : 'assertModelMissing';
        $firstText = collect($t['fields'])->first(fn ($f) => empty($f['foreign']) && in_array($f['type'], ['string'], true));
        $searchTest = $firstText ? "\n    public function test_index_can_be_searched(): void\n    {\n        \$match = {$model}::factory()->create();\n        {$model}::factory()->create();\n\n        \$this->get(route('{$table}.index', ['q' => (string) \$match->{$firstText['name']}]))->assertOk()->assertSee(e(\$match->{$firstText['name']}), false);\n    }\n" : '';

        $extra = '';
        if (!empty($t['soft_deletes'])) {
            $extra .= "\n    public function test_deleted_records_can_be_restored_or_deleted_forever(): void\n    {\n        \$item = {$model}::factory()->create();\n        \$item->delete();\n\n        \$this->get(route('{$table}.index', ['trashed' => 'only']))->assertOk();\n        \$this->patch(route('{$table}.restore', \$item->id))->assertRedirect();\n        \$this->assertNotSoftDeleted(\$item);\n\n        \$item->delete();\n        \$this->delete(route('{$table}.force-delete', \$item->id))->assertRedirect();\n        \$this->assertModelMissing(\$item);\n    }\n";
        }
        if ($this->importable($t)) {
            $columns = "['".implode("', '", array_column($t['fields'], 'name'))."']";
            $extra .= "\n    public function test_records_can_be_imported_from_csv(): void\n    {\n        \$columns = {$columns};\n        \$csv = fopen('php://temp', 'r+');\n        fputcsv(\$csv, \$columns);\n        foreach ({$model}::factory()->count(2)->make() as \$row) {\n            fputcsv(\$csv, array_map(fn (\$value) => is_bool(\$value) ? (int) \$value : \$value, \$row->only(\$columns)));\n        }\n        rewind(\$csv);\n\n        \$this->post(route('{$table}.import'), ['file' => \\Illuminate\\Http\\UploadedFile::fake()->createWithContent('import.csv', stream_get_contents(\$csv))])->assertRedirect(route('{$table}.index'));\n\n        \$this->assertSame(2, {$model}::count());\n    }\n";
        }

        return $this->stub('test-feature', ['model' => $model, 'table' => $table, 'param' => $param, 'login' => $login, 'searchTest' => $searchTest, 'payload' => $payload, 'gone' => $gone, 'extraTests' => $extra]);
    }

    /** Shared query pieces for the web and API index endpoints. */
    private function queryParts(array $t): array
    {
        $fields = $t['fields'];
        $search = collect($fields)->filter(fn ($f) => empty($f['foreign']) && in_array($f['type'], ['string', 'text', 'email', 'url', 'slug'], true))->pluck('name')->values();
        $clauses = $search->isEmpty()
            ? ['                    $query->where(\'id\', $term);']
            : $search->map(fn ($n, $i) => '                    $query->'.($i === 0 ? 'where' : 'orWhere')."('{$n}', 'like', \"%{\$term}%\");")->all();
        $filters = collect($this->filterFields($t))->map(fn ($f) => $f['type'] === 'boolean'
            ? "\n            ->when(\$request->filled('{$f['name']}'), fn (\$query) => \$query->where('{$f['name']}', \$request->boolean('{$f['name']}')))"
            : "\n            ->when(\$request->filled('{$f['name']}'), fn (\$query) => \$query->where('{$f['name']}', \$request->query('{$f['name']}')))")->implode('');
        foreach ($this->dateFields($t) as $f) {
            foreach (['from' => '>=', 'to' => '<='] as $edge => $operator) {
                $filters .= "\n            ->when(\$request->filled('{$f['name']}_{$edge}') && strtotime((string) \$request->query('{$f['name']}_{$edge}')), fn (\$query) => \$query->whereDate('{$f['name']}', '{$operator}', \$request->query('{$f['name']}_{$edge}')))";
            }
        }
        if (!empty($t['soft_deletes'])) {
            $filters .= "\n            ->when(\$request->query('trashed') === 'only', fn (\$query) => \$query->onlyTrashed())\n            ->when(\$request->query('trashed') === 'with', fn (\$query) => \$query->withTrashed())";
        }
        $belongsTo = collect($t['relations'])->whereIn('type', ['belongsTo'])->pluck('name')->map(fn ($n) => "'{$n}'")->implode(', ');
        $sortable = collect($fields)->where('type', '!=', 'password')->pluck('name')->prepend('id')->when($t['timestamps'], fn ($c) => $c->push('created_at'))->map(fn ($n) => "'{$n}'")->implode(', ');

        return ['search' => implode("\n", $clauses), 'filters' => $filters, 'with' => $belongsTo ? "\n            ->with([{$belongsTo}])" : '', 'sortable' => "[{$sortable}]"];
    }

    /** Date columns get a from/to range filter (at most two, to keep the toolbar tidy). */
    private function dateFields(array $t): array
    {
        return array_slice(array_values(array_filter($t['fields'], fn ($f) => empty($f['foreign']) && in_array($f['type'], ['date', 'dateTime', 'dateTimeTz'], true))), 0, 2);
    }

    /** CSV import re-creates rows through the Store request, so it only supports plain columns. */
    private function importable(array $t): bool
    {
        return !collect($t['fields'])->contains(fn ($f) => in_array($f['type'], ['password', 'image', 'file', 'slug'], true));
    }

    /** Boolean, enum and foreign-key columns get a filter control in the index view and a query filter. */
    private function filterFields(array $t): array
    {
        return array_values(array_filter($t['fields'], fn ($f) => !empty($f['foreign']) || in_array($f['type'], ['boolean', 'enum'], true)));
    }

    private function controller(array $t, array $permissions): string
    {
        ['model' => $model, 'table' => $table, 'controller' => $controller, 'fields' => $fields] = $t;
        $param = Str::singular($table);
        $guard = fn (string $permission, string $ability, string $target) => in_array($permission, $permissions, true) ? "        Gate::authorize('{$ability}', {$target});\n" : '';

        $uses = [];
        $related = [];
        foreach ($fields as $f) {
            if (empty($f['foreign'])) continue;
            $uses[$f['related']] = "use App\\Models\\{$f['related']};\n";
            $related[] = $this->relatedLine($f['name'], $f['related'], $f['display'], true);
        }
        $syncKeys = [];
        foreach ($t['relations'] as $r) {
            if ($r['type'] !== 'belongsToMany') continue;
            $uses[$r['model']] = "use App\\Models\\{$r['model']};\n";
            $related[] = $this->relatedLine($r['name'], $r['model'], $r['display']);
            $syncKeys[] = $r['name'];
        }
        unset($uses[$model]);

        $data = $this->dataMethod($t);
        $extra = '';
        if (!empty($t['soft_deletes'])) {
            $authItem = in_array('delete', $permissions, true) ? "        Gate::authorize('delete', \$item);\n" : '';
            $files = collect($fields)->filter(fn ($f) => ColumnTypes::isUpload($f['type']))->map(fn ($f) => "        if (\$item->{$f['name']}) Storage::disk('public')->delete(\$item->{$f['name']});\n")->implode('');
            $extra .= $this->stub('controller-soft', [
                'model' => $model, 'table' => $table, 'authItem' => $authItem, 'deleteFiles' => $files,
                'authBulk' => in_array('delete', $permissions, true) ? "        \$items->each(fn ({$model} \$item) => Gate::authorize('delete', \$item));\n" : '',
            ]);
        }
        $lookups = collect($fields)->filter(fn ($f) => !empty($f['foreign']));
        if ($lookups->isNotEmpty()) {
            $extra .= $this->stub('controller-lookup', [
                'authViewAny' => $guard('view', 'viewAny', "{$model}::class"),
                'sources' => $lookups->map(fn ($f) => "            '{$f['name']}' => [{$f['related']}::class, '{$f['display']}'],")->implode("\n"),
            ]);
        }
        if ($this->importable($t)) {
            $uses['DB'] = "use Illuminate\\Support\\Facades\\DB;\n";
            $uses['Validator'] = "use Illuminate\\Support\\Facades\\Validator;\n";
            $extra .= $this->stub('controller-import', ['model' => $model, 'table' => $table, 'authCreate' => $guard('create', 'create', "{$model}::class"), 'importColumns' => "['".implode("', '", array_column($fields, 'name'))."']"]);
        }
        $parts = $this->queryParts($t);
        $keys = $syncKeys ? "['".implode("', '", $syncKeys)."']" : '[]';
        $sync = fn (string $var) => collect($syncKeys)->map(fn ($k) => "        \${$var}->{$k}()->sync(\$request->input('{$k}', []));\n")->implode('');
        $exportColumns = "['".implode("', '", array_merge(['id'], array_column(array_filter($fields, fn ($f) => $f['type'] !== 'password'), 'name'), $t['timestamps'] ? ['created_at', 'updated_at'] : []))."']";

        return $this->stub('controller', [
            'model' => $model, 'controller' => $controller, 'table' => $table, 'param' => $param,
            'uses' => implode('', $uses + $data['uses']), 'prepare' => $data['prepare'], 'extraMethods' => $extra, 'gateUse' => $permissions ? "use Illuminate\\Support\\Facades\\Gate;\n" : '',
            'authViewAny' => $guard('view', 'viewAny', "{$model}::class"), 'authCreate' => $guard('create', 'create', "{$model}::class"),
            'authUpdate' => $guard('update', 'update', "\${$param}"), 'authDelete' => $guard('delete', 'delete', "\${$param}"),
            'authBulk' => in_array('delete', $permissions, true) ? "        \$items->each(fn ({$model} \$item) => Gate::authorize('delete', \$item));\n" : '',
            'sortable' => $parts['sortable'], 'with' => $parts['with'], 'filters' => $parts['filters'], 'exportColumns' => $exportColumns,
            'search' => $parts['search'], 'syncKeys' => $keys,
            'syncStore' => rtrim($sync('item'), "\n"), 'syncUpdate' => rtrim($sync($param), "\n"), 'related' => implode("\n", $related),
        ]);
    }

    private function apiController(array $t, array $permissions): string
    {
        ['model' => $model, 'table' => $table, 'controller' => $controller] = $t;
        $param = Str::singular($table);
        $guard = fn (string $permission, string $ability, string $target) => in_array($permission, $permissions, true) ? "        Gate::authorize('{$ability}', {$target});\n" : '';
        $syncKeys = collect($t['relations'])->where('type', 'belongsToMany')->pluck('name')->all();
        $sync = fn (string $var) => collect($syncKeys)->map(fn ($k) => "        \${$var}->{$k}()->sync(\$request->input('{$k}', []));\n")->implode('');
        $parts = $this->queryParts($t);
        $loads = collect($t['relations'])->whereIn('type', ['belongsTo', 'belongsToMany'])->pluck('name')->map(fn ($n) => "'{$n}'")->implode(', ');
        $data = $this->dataMethod($t);

        return $this->stub('api-controller', [
            'model' => $model, 'controller' => $controller, 'param' => $param, 'gateUse' => $permissions ? "use Illuminate\\Support\\Facades\\Gate;\n" : '', 'dataUses' => implode('', $data['uses']), 'prepare' => $data['prepare'],
            'authViewAny' => $guard('view', 'viewAny', "{$model}::class"), 'authCreate' => $guard('create', 'create', "{$model}::class"), 'authView' => $guard('view', 'view', "\${$param}"),
            'authUpdate' => $guard('update', 'update', "\${$param}"), 'authDelete' => $guard('delete', 'delete', "\${$param}"),
            'sortable' => $parts['sortable'], 'with' => $parts['with'], 'filters' => $parts['filters'], 'search' => $parts['search'],
            'syncKeys' => $syncKeys ? "['".implode("', '", $syncKeys)."']" : '[]', 'syncStore' => rtrim($sync('item'), "\n"), 'syncUpdate' => rtrim($sync($param), "\n"), 'loads' => $loads,
        ]);
    }

    private function apiResource(array $t): string
    {
        $lines = [];
        foreach ($t['fields'] as $f) {
            if ($f['type'] === 'password') continue;
            $lines[] = match (true) {
                ColumnTypes::isUpload($f['type']) => "            '{$f['name']}' => \$this->{$f['name']} ? asset('storage/'.\$this->{$f['name']}) : null,",
                !empty($f['foreign']) => "            '{$f['name']}' => \$this->{$f['name']},",
                in_array($f['type'], ['decimal', 'float'], true) => "            '{$f['name']}' => \$this->{$f['name']} !== null ? (float) \$this->{$f['name']} : null,",
                $f['type'] === 'json' => "            '{$f['name']}' => is_string(\$this->{$f['name']}) ? json_decode(\$this->{$f['name']}, true) : \$this->{$f['name']},",
                default => "            '{$f['name']}' => \$this->{$f['name']},",
            };
        }
        foreach ($t['relations'] as $r) {
            if (in_array($r['type'], ['belongsTo', 'belongsToMany'], true)) $lines[] = "            '{$r['name']}' => \$this->whenLoaded('{$r['name']}'),";
        }
        if ($t['timestamps']) array_push($lines, "            'created_at' => \$this->created_at,", "            'updated_at' => \$this->updated_at,");

        return $this->stub('resource', ['model' => $t['model'], 'attributes' => implode("\n", $lines)]);
    }

    private function actingAsLines(array $permissions, bool $api): string
    {
        $user = $api && config('crud.api_auth') === 'sanctum' ? '\\Laravel\\Sanctum\\Sanctum::actingAs(\\App\\Models\\User::factory()->create());' : '$this->actingAs(\\App\\Models\\User::factory()->create());';
        if (!$permissions && !($api && config('crud.api_auth') === 'sanctum')) return '';

        return "        {$user}\n".($permissions ? "        \\Illuminate\\Support\\Facades\\Gate::before(fn () => true);\n" : '');
    }

    private function apiTest(array $t, array $permissions): string
    {
        $model = $t['model']; $table = $t['table']; $param = Str::singular($table);
        $login = $this->actingAsLines($permissions, true).$this->fakeDisk($t);
        $soft = !empty($t['soft_deletes']) ? 'assertSoftDeleted' : 'assertModelMissing';
        $payload = $this->testPayload($t);

        return $this->stub('test-api', ['model' => $model, 'table' => $table, 'param' => $param, 'login' => $login, 'payload' => $payload, 'gone' => $soft]);
    }

    private function fakeDisk(array $t): string
    {
        return collect($t['fields'])->contains(fn ($f) => ColumnTypes::isUpload($f['type'])) ? "        \\Illuminate\\Support\\Facades\\Storage::fake('public');\n" : '';
    }

    /** A valid request payload built from the factory; unique columns get fresh values via the factory itself. */
    private function testPayload(array $t): string
    {
        $model = $t['model'];
        $hidden = collect($t['fields'])->where('type', 'password')->pluck('name')->map(fn ($n) => "'{$n}'")->implode(', ');
        $lines = "        \$payload = {$model}::factory()->make()".($hidden ? "->makeVisible([{$hidden}])" : '').'->toArray();';
        foreach ($t['fields'] as $f) {
            if (!ColumnTypes::isUpload($f['type'])) continue;
            $lines .= "\n        \$payload['{$f['name']}'] = \\Illuminate\\Http\\UploadedFile::fake()->".($f['type'] === 'image' ? "image('{$f['name']}.jpg')" : "create('{$f['name']}.pdf', 10)").';';
        }

        return $lines;
    }

    /** Foreign keys to big tables fall back to a search box: null tells the view to use the lookup endpoint. */
    private function relatedLine(string $key, string $model, string $display, bool $lazy = false): string
    {
        $order = $display === 'id' ? 'id' : $display;
        $load = "{$model}::query()->orderBy('{$order}')->pluck('{$display}', 'id')";
        $limit = (int) config('crud.lookup_threshold', 200);

        return $lazy ? "            '{$key}' => {$model}::query()->count() > {$limit} ? null : {$load}," : "            '{$key}' => {$load},";
    }

    private function indexView(array $t): string
    {
        $table = $t['table'];
        $belongsTo = collect($t['relations'])->where('type', 'belongsTo')->keyBy('key');
        $headers = [];
        $cells = [];
        $columns = [];
        $shown = 0;
        foreach ($t['fields'] as $f) {
            if ($f['type'] === 'password') continue;
            $shown++;
            $n = $f['name'];
            $headers[] = "                <th data-col=\"{$n}\" class=\"px-4 py-3\"><a href=\"{{ request()->fullUrlWithQuery(['sort' => '{$n}', 'dir' => \$sort === '{$n}' && \$dir === 'asc' ? 'desc' : 'asc']) }}\" class=\"hover:text-slate-900\">{{ __('{$table}.{$n}') }}@if (\$sort === '{$n}') {{ \$dir === 'asc' ? '↑' : '↓' }}@endif</a></th>";
            $value = match (true) {
                !empty($f['foreign']) => "{{ \$item->{$f['relation']}?->{$f['display']} ?? '—' }}",
                $f['type'] === 'boolean' => "@if (\$item->{$n})<span class=\"rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-medium text-emerald-700\">✓</span>@else<span class=\"rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-500\">—</span>@endif",
                $f['type'] === 'image' => "@if (\$item->{$n})<img src=\"{{ asset('storage/'.\$item->{$n}) }}\" alt=\"\" class=\"h-10 w-10 rounded-lg object-cover\">@endif",
                $f['type'] === 'file' => "@if (\$item->{$n})<a href=\"{{ asset('storage/'.\$item->{$n}) }}\" target=\"_blank\" class=\"text-indigo-600 hover:underline\">{{ basename(\$item->{$n}) }}</a>@endif",
                $f['type'] === 'color' => "<span class=\"inline-flex items-center gap-2\"><span class=\"h-4 w-4 rounded border border-slate-300\" style=\"background: {{ \$item->{$n} }}\"></span>{{ \$item->{$n} }}</span>",
                $f['type'] === 'date' => "{{ \$item->{$n}?->format('Y-m-d') }}",
                $f['type'] === 'dateTime' => "{{ \$item->{$n}?->format('Y-m-d H:i') }}",
                $f['type'] === 'decimal' => "{{ \$item->{$n} !== null ? number_format((float) \$item->{$n}, 2) : '' }}",
                $f['type'] === 'text' => "{{ \\Illuminate\\Support\\Str::limit(\$item->{$n}, 60) }}",
                default => "{{ \$item->{$n} }}",
            };
            $cells[] = "                    <td data-col=\"{$n}\" class=\"px-4 py-3\">{$value}</td>";
            $columns[] = "            <label class=\"flex items-center gap-2 text-sm text-slate-700\"><input type=\"checkbox\" data-col-toggle=\"{$n}\" checked> {{ __('{$table}.{$n}') }}</label>";
        }

        $filters = [];
        $select = 'class="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm shadow-sm" onchange="this.form.submit()"';
        foreach ($this->filterFields($t) as $f) {
            $n = $f['name'];
            $label = "{{ __('{$table}.{$n}') }}";
            $options = match (true) {
                !empty($f['foreign']) => "@foreach (\$related['{$n}'] as \$value => \$text)<option value=\"{{ \$value }}\" @selected(request('{$n}') === (string) \$value)>{{ \$text }}</option>@endforeach",
                $f['type'] === 'boolean' => "<option value=\"1\" @selected(request('{$n}') === '1')>✓</option><option value=\"0\" @selected(request('{$n}') === '0')>—</option>",
                default => "@foreach (['".implode("', '", array_map(fn ($v) => str_replace("'", "\\'", $v), $f['values']))."'] as \$value)<option value=\"{{ \$value }}\" @selected(request('{$n}') === \$value)>{{ \$value }}</option>@endforeach",
            };
            $control = "    <select name=\"{$n}\" {$select}><option value=\"\">{$label} · {{ __('{$table}.all') }}</option>{$options}</select>";
            $filters[] = !empty($f['foreign']) ? "    @if (\$related['{$n}'] !== null){$control}@endif" : $control;
        }

        $inputClass = 'rounded-lg border border-slate-300 bg-white px-2 py-1.5 text-sm shadow-sm';
        foreach ($this->dateFields($t) as $f) {
            $n = $f['name'];
            $filters[] = "    <label class=\"flex items-center gap-1 text-sm text-slate-600\">{{ __('{$table}.{$n}') }} <input type=\"date\" name=\"{$n}_from\" value=\"{{ request('{$n}_from') }}\" title=\"{{ __('{$table}.from') }}\" class=\"{$inputClass}\" onchange=\"this.form.submit()\"> – <input type=\"date\" name=\"{$n}_to\" value=\"{{ request('{$n}_to') }}\" title=\"{{ __('{$table}.to') }}\" class=\"{$inputClass}\" onchange=\"this.form.submit()\"></label>";
        }
        $soft = !empty($t['soft_deletes']);
        if ($soft) {
            $filters[] = "    <select name=\"trashed\" {$select}><option value=\"\">{{ __('{$table}.trashed') }}</option><option value=\"with\" @selected(request('trashed') === 'with')>{{ __('{$table}.trashed_with') }}</option><option value=\"only\" @selected(request('trashed') === 'only')>{{ __('{$table}.trashed_only') }}</option></select>";
        }

        return $this->stub('index', [
            'table' => $table, 'headers' => implode("\n", $headers), 'cells' => implode("\n", $cells), 'colspan' => $shown + 2, 'filters' => implode("\n", $filters), 'columns' => implode("\n", $columns),
            'actions' => rtrim($this->stub($soft ? 'index-actions-soft' : 'index-actions', ['table' => $table]), "\n"),
            'rowClass' => $soft ? " {{ \$item->trashed() ? 'opacity-60' : '' }}" : '',
            'bulkRestore' => $soft ? "    <button name=\"_method\" value=\"PATCH\" formaction=\"{{ route('{$table}.bulk-restore') }}\" class=\"rounded-lg border border-emerald-200 bg-white px-3 py-1.5 text-sm font-medium text-emerald-600 hover:bg-emerald-50\">{{ __('{$table}.restore_selected') }}</button>\n" : '',
            'import' => $this->importable($t) ? $this->stub('index-import', ['table' => $table]) : '',
        ]);
    }

    private function formView(array $t): string
    {
        $table = $t['table'];
        $inputs = [];
        $hasUpload = false;
        $hasLookup = false;
        foreach ($t['fields'] as $f) {
            $n = $f['name'];
            $hasUpload = $hasUpload || ColumnTypes::isUpload($f['type']);
            $hasLookup = $hasLookup || !empty($f['foreign']);
            $input = match (true) {
                !empty($f['foreign']) => $this->stub('fields/select', ['name' => $n, 'table' => $table, 'relation' => $f['relation'], 'display' => $f['display']]),
                $f['type'] === 'enum' => $this->stub('fields/enum', ['name' => $n, 'values' => "['".implode("', '", array_map(fn ($v) => str_replace("'", "\\'", $v), $f['values']))."']"]),
                $f['type'] === 'time' => $this->stub('fields/text', ['name' => $n, 'type' => 'time']),
                $f['type'] === 'password' => $this->stub('fields/password', ['name' => $n]),
                $f['type'] === 'color' => $this->stub('fields/color', ['name' => $n]),
                $f['type'] === 'slug' => $this->stub('fields/slug', ['name' => $n, 'table' => $table]),
                ColumnTypes::isUpload($f['type']) => $this->uploadInput($f, $table),
                ColumnTypes::input($f['type']) === 'textarea' => $this->stub('fields/textarea', ['name' => $n]),
                ColumnTypes::input($f['type']) === 'checkbox' => $this->stub('fields/checkbox', ['name' => $n]),
                ColumnTypes::input($f['type']) === 'date' => $this->stub('fields/date', ['name' => $n]),
                ColumnTypes::input($f['type']) === 'datetime' => $this->stub('fields/datetime', ['name' => $n]),
                ColumnTypes::input($f['type']) === 'number' => $this->stub('fields/number', ['name' => $n, 'step' => in_array($f['type'], ['decimal', 'float'], true) ? 'any' : '1']),
                default => $this->stub('fields/text', ['name' => $n, 'type' => match (true) { str_contains($n, 'email') => 'email', str_contains($n, 'phone') => 'tel', str_contains($n, 'url') => 'url', default => 'text' }]),
            };
            $inputs[] = rtrim($this->stub('field', ['table' => $table, 'name' => $n, 'label' => $n, 'input' => rtrim($input, "\n")]), "\n");
        }
        foreach ($t['relations'] as $r) {
            if ($r['type'] !== 'belongsToMany') continue;
            $inputs[] = rtrim($this->stub('field', ['table' => $table, 'name' => $r['name'], 'label' => $r['name'], 'input' => rtrim($this->stub('fields/multiselect', ['name' => $r['name']]), "\n")]), "\n");
        }

        return $this->stub('form', ['table' => $table, 'inputs' => implode("\n", $inputs), 'enctype' => $hasUpload ? ' enctype="multipart/form-data"' : '', 'lookupScript' => $hasLookup ? $this->stub('form-lookup') : '']);
    }

    private function uploadInput(array $f, string $table): string
    {
        $n = $f['name'];
        $image = $f['type'] === 'image';
        $show = $image
            ? "<img src=\"{{ asset('storage/'.\$item->{$n}) }}\" alt=\"\" class=\"mb-2 h-20 rounded-lg object-cover\">"
            : "<a href=\"{{ asset('storage/'.\$item->{$n}) }}\" target=\"_blank\" class=\"mb-2 block text-sm text-indigo-600 hover:underline\">{{ basename(\$item->{$n}) }}</a>";

        return $this->stub('fields/upload', [
            'name' => $n, 'accept' => $image ? ' accept="image/*"' : '',
            'current' => "            @if (isset(\$item) && \$item->{$n}){$show}@endif\n",
            'remove' => $f['nullable'] ? "            <label class=\"mt-2 flex items-center gap-2 text-sm text-slate-600\"><input type=\"checkbox\" name=\"remove_{$n}\" value=\"1\"> {{ __('{$table}.remove') }}</label>" : '',
        ]);
    }

    private function demoSeeder(array $tables, int $rows): string
    {
        $generated = collect($tables)->pluck('model')->all();
        $uses = [];
        $body = [];
        foreach ($tables as $t) {
            $uses[] = "use App\\Models\\{$t['model']};";
            $body[] = "        {$t['model']}::factory()->count({$rows})->create();";
        }
        foreach ($tables as $t) {
            foreach ($t['relations'] as $r) {
                if ($r['type'] !== 'belongsToMany' || empty($r['owner']) || !in_array($r['model'], $generated, true)) continue;
                $ids = '$'.Str::camel($r['name']).'Ids';
                $body[] = "\n        {$ids} = {$r['model']}::query()->pluck('id');\n        if ({$ids}->isNotEmpty()) {\n            {$t['model']}::all()->each(fn ({$t['model']} \$item) => \$item->{$r['name']}()->syncWithoutDetaching({$ids}->random(min(2, {$ids}->count()))->all()));\n        }";
            }
        }
        $class = 'StudioDemoSeeder';
        for ($i = 2; $this->files->exists(database_path("seeders/{$class}.php")); $i++) $class = "StudioDemo{$i}Seeder";
        $this->write(database_path("seeders/{$class}.php"), $this->stub('demo-seeder', ['class' => $class, 'uses' => implode("\n", $uses)."\n", 'body' => implode("\n", $body)]));

        return $class;
    }

    private function writeOnce(string $path, string $contents): void
    {
        if ($this->files->exists($path)) return;
        $this->files->ensureDirectoryExists(dirname($path));
        $this->files->put($path, $contents);
    }

    private function addNavLink(string $route, string $label, bool $prepend = false, ?string $can = null): void
    {
        $path = resource_path('views/layouts/nav.blade.php');
        $current = $this->files->exists($path) ? $this->files->get($path) : '';
        if (str_contains($current, "route('{$route}')") ) return;
        $active = str_ends_with($route, '.index') ? substr($route, 0, -5).'*' : $route;
        $base = $this->layout === 'admin' ? 'flex items-center rounded-lg px-3 py-2' : 'rounded-md px-3 py-1.5';
        $link = "<a href=\"{{ route('{$route}') }}\" class=\"{$base} text-slate-600 hover:bg-slate-100 {{ request()->routeIs('{$active}') ? 'bg-slate-100 font-medium text-slate-900' : '' }}\">{$label}</a>\n";
        if ($can !== null) $link = "@can('{$can}')\n{$link}@endcan\n";
        $this->files->ensureDirectoryExists(dirname($path));
        $this->files->put($path, $prepend ? $link.$current : $current.$link);
    }

    private function translations(string $locale, string $model, string $table, array $t): void
    {
        $arabic = $locale === 'ar';
        $labels = [];
        foreach ($t['fields'] as $f) {
            $labels[$f['name']] = !empty($f['foreign']) ? Str::headline($f['relation']) : ($arabic ? $f['name'] : Str::headline($f['name']));
        }
        foreach ($t['relations'] as $r) {
            if ($r['type'] === 'belongsToMany') $labels[$r['name']] = Str::headline($r['name']);
        }
        $words = $arabic
            ? ['title' => $model, 'create' => 'إنشاء', 'edit' => 'تعديل', 'delete' => 'حذف', 'save' => 'حفظ', 'cancel' => 'إلغاء', 'search' => 'بحث', 'actions' => 'الإجراءات', 'empty' => 'لا توجد سجلات بعد.', 'confirm' => 'هل أنت متأكد؟', 'saved' => 'تم الحفظ بنجاح.', 'deleted' => 'تم الحذف بنجاح.', 'all' => 'الكل', 'export' => 'تصدير CSV', 'delete_selected' => 'حذف المحدد', 'remove' => 'إزالة الملف الحالي', 'auto' => 'تلقائي', 'restore' => 'استعادة', 'delete_forever' => 'حذف نهائي', 'trashed' => 'السجلات النشطة', 'trashed_with' => 'مع المحذوفة', 'trashed_only' => 'المحذوفة فقط', 'restore_selected' => 'استعادة المحدد', 'import' => 'استيراد CSV', 'imported' => 'تم استيراد :count سجل.', 'import_columns' => 'رأس ملف CSV لا يحتوي على أي عمود معروف.', 'import_row' => 'الصف :row: :message', 'import_limit' => 'يمكن استيراد أول 1000 صف فقط في المرة الواحدة.', 'columns' => 'الأعمدة', 'from' => 'من', 'to' => 'إلى']
            : ['title' => $model, 'create' => 'Create', 'edit' => 'Edit', 'delete' => 'Delete', 'save' => 'Save', 'cancel' => 'Cancel', 'search' => 'Search', 'actions' => 'Actions', 'empty' => 'No records yet.', 'confirm' => 'Are you sure?', 'saved' => 'Saved successfully.', 'deleted' => 'Deleted successfully.', 'all' => 'All', 'export' => 'Export CSV', 'delete_selected' => 'Delete selected', 'remove' => 'Remove current file', 'auto' => 'Auto-generated', 'restore' => 'Restore', 'delete_forever' => 'Delete forever', 'trashed' => 'Active records', 'trashed_with' => 'With deleted', 'trashed_only' => 'Only deleted', 'restore_selected' => 'Restore selected', 'import' => 'Import CSV', 'imported' => ':count records imported.', 'import_columns' => 'The CSV header does not contain any known column.', 'import_row' => 'Row :row: :message', 'import_limit' => 'Only the first 1000 rows can be imported at once.', 'columns' => 'Columns', 'from' => 'From', 'to' => 'To'];
        $body = collect($words + $labels)->map(fn ($value, $key) => "    '".$key."' => '".str_replace("'", "\\'", $value)."',")->implode("\n");
        $this->write(lang_path("{$locale}/{$table}.php"), "<?php\n\nreturn [\n{$body}\n];\n");
    }

    private function permissionSeeder(string $table, array $permissions, string $roles): string
    {
        $class = Str::studly(Str::singular($table)).'PermissionSeeder';
        $permissionsCode = collect($permissions)->map(fn ($permission) => "        Permission::findOrCreate('{$table}.{$permission}');")->implode("\n");
        $roleNames = collect(explode(',', $roles))->map(fn ($role) => trim($role))->filter()->unique();
        $rolesCode = $roleNames->map(fn ($role) => "        Role::findOrCreate('{$role}')->givePermissionTo([".collect($permissions)->map(fn ($p) => "'{$table}.{$p}'")->implode(', ').']);')->implode("\n");
        $this->write(database_path("seeders/{$class}.php"), $this->stub('seeder-permission', ['class' => $class, 'permissions' => $permissionsCode, 'roles' => $rolesCode]));
        return $class;
    }

    private function appendApiRoute(string $table, string $controller, bool $hasPermissions): void
    {
        $path = base_path('routes/api.php');
        $contents = $this->files->exists($path) ? $this->files->get($path) : "<?php\n\nuse Illuminate\\Support\\Facades\\Route;\n";
        $guard = config('crud.api_auth') === 'sanctum' ? "->middleware('auth:sanctum')" : ($hasPermissions ? "->middleware('auth')" : '');
        $route = "Route::apiResource('{$table}', \\App\\Http\\Controllers\\Api\\{$controller}::class)->names('api.{$table}'){$guard};";
        if (!str_contains($contents, "Route::apiResource('{$table}'")) {
            $this->files->ensureDirectoryExists(dirname($path));
            $this->files->put($path, rtrim($contents)."\n\n{$route}\n");
        }
    }

    /** Writes OpenAPI 3 and Postman collection files, merging with documents from earlier runs. */
    private function apiDocs(array $tables, array $data): array
    {
        $openapi = (new ApiDocs())->openApi($tables, $data['project']['name']);
        $postman = (new ApiDocs())->postman($tables, $data['project']['name']);
        $dir = base_path('docs/api');
        $this->files->ensureDirectoryExists($dir);
        $written = [];
        foreach ([['openapi.json', $openapi, 'openapi'], ['postman_collection.json', $postman, 'postman']] as [$name, $doc, $kind]) {
            $path = "{$dir}/{$name}";
            if ($this->files->exists($path)) {
                $old = json_decode($this->files->get($path), true);
                if (is_array($old)) $doc = $kind === 'openapi' ? ApiDocs::mergeOpenApi($old, $doc) : ApiDocs::mergePostman($old, $doc);
            }
            $this->files->put($path, json_encode($doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");
            $written[] = "docs/api/{$name}";
        }

        return $written;
    }

    /** True when the app already has a login route or an auth scaffold, so generating another would collide. */
    private function authInstalled(): bool
    {
        $routes = base_path('routes/web.php');

        return Route::has('login')
            || is_file(app_path('Http/Controllers/Auth/AuthController.php'))
            || is_file(app_path('Http/Controllers/Auth/AuthenticatedSessionController.php'))
            || ($this->files->exists($routes) && str_contains($this->files->get($routes), "->name('login')"));
    }

    /** User and role management needs permissions (Spatie) and must not collide with tables the user designed. */
    private function authManages(array $tableNames, array $permissions): bool
    {
        return $permissions !== [] && array_intersect(['users', 'roles'], $tableNames) === [];
    }

    /** Writes the auth scaffold (existing files are kept), registers its routes and returns the admin seeder class. */
    private function installAuth(array $data, array $tableNames, array $permissions): string
    {
        foreach ($this->authFiles($data, $tableNames, $permissions) as $path => $contents) $this->writeOnce($path, $contents);

        $manage = $this->authManages($tableNames, $permissions);
        $verify = !empty($data['auth_verify']) ? "    Route::get('email/verify', [\\App\\Http\\Controllers\\Auth\\VerifyEmailController::class, 'notice'])->name('verification.notice');\n    Route::get('email/verify/{id}/{hash}', [\\App\\Http\\Controllers\\Auth\\VerifyEmailController::class, 'verify'])->middleware(['signed', 'throttle:6,1'])->name('verification.verify');\n    Route::post('email/verification-notification', [\\App\\Http\\Controllers\\Auth\\VerifyEmailController::class, 'send'])->middleware('throttle:6,1')->name('verification.send');\n" : '';
        $register = !empty($data['auth_register']) ? "    Route::get('register', [\\App\\Http\\Controllers\\Auth\\AuthController::class, 'showRegister'])->name('register');\n    Route::post('register', [\\App\\Http\\Controllers\\Auth\\AuthController::class, 'register'])->middleware('throttle:5,1');\n" : '';
        $admin = $manage ? "\n    Route::middleware('can:users.manage')->group(function () {\n        Route::resource('users', \\App\\Http\\Controllers\\Admin\\UserManagementController::class)->except('show');\n    });\n    Route::middleware('can:roles.manage')->group(function () {\n        Route::resource('roles', \\App\\Http\\Controllers\\Admin\\RoleManagementController::class)->except('show');\n    });\n" : '';
        $this->appendRoute(rtrim($this->stub('auth/routes', ['register' => $register, 'verify' => $verify, 'manage' => $admin])));
        if ($manage) {
            $this->addNavLink('users.index', 'Users', false, 'users.manage');
            $this->addNavLink('roles.index', 'Roles', false, 'roles.manage');
        }

        return 'AdminUserSeeder';
    }

    /** @return array<string, string> Project-level auth files keyed by absolute path. */
    private function authFiles(array $data, array $tableNames, array $permissions): array
    {
        $app = e($data['project']['name']);
        $manage = $this->authManages($tableNames, $permissions);
        $tests = $data['generate_tests'] ?? config('crud.generate_tests', true);
        $register = !empty($data['auth_register']);

        $files = [
            app_path('Http/Controllers/Auth/AuthController.php') => $this->stub('auth/auth-controller'),
            app_path('Http/Controllers/Auth/PasswordController.php') => $this->stub('auth/password-controller'),
            app_path('Http/Controllers/ProfileController.php') => $this->stub('auth/profile-controller'),
            resource_path('views/layouts/guest.blade.php') => $this->stub('auth/guest-layout', ['app' => $app]),
            resource_path('views/auth/login.blade.php') => $this->stub('auth/login'),
            resource_path('views/auth/register.blade.php') => $this->stub('auth/register'),
            resource_path('views/auth/forgot-password.blade.php') => $this->stub('auth/forgot-password'),
            resource_path('views/auth/reset-password.blade.php') => $this->stub('auth/reset-password'),
            resource_path('views/profile/edit.blade.php') => $this->stub('auth/profile'),
        ];
        if (!empty($data['auth_verify'])) {
            $files[app_path('Http/Controllers/Auth/VerifyEmailController.php')] = $this->stub('auth/verify-controller');
            $files[resource_path('views/auth/verify-email.blade.php')] = $this->stub('auth/verify-email');
        }
        $files += [
            database_path('seeders/AdminUserSeeder.php') => $this->stub('auth/seeder-admin', ['verified' => !empty($data['auth_verify']) ? ", 'email_verified_at' => now()" : ''] + ($permissions
                ? ['uses' => "use Spatie\\Permission\\Models\\Permission;\nuse Spatie\\Permission\\Models\\Role;\n", 'roles' => "\n        Permission::findOrCreate('users.manage');\n        Permission::findOrCreate('roles.manage');\n        \$role = Role::findOrCreate('super-admin');\n        \$role->syncPermissions(Permission::all());\n        if (method_exists(\$user, 'assignRole')) \$user->assignRole(\$role);\n"]
                : ['uses' => '', 'roles' => ''])),
        ];
        if ($manage) {
            $files += [
                app_path('Http/Controllers/Admin/UserManagementController.php') => $this->stub('auth/user-controller'),
                app_path('Http/Controllers/Admin/RoleManagementController.php') => $this->stub('auth/role-controller'),
                resource_path('views/admin/users/index.blade.php') => $this->stub('auth/users-index'),
                resource_path('views/admin/users/form.blade.php') => $this->stub('auth/users-form'),
                resource_path('views/admin/roles/index.blade.php') => $this->stub('auth/roles-index'),
                resource_path('views/admin/roles/form.blade.php') => $this->stub('auth/roles-form'),
            ];
        }
        if ($tests) {
            $registration = $register
                ? "\n    public function test_user_can_register(): void\n    {\n        \$this->get(route('register'))->assertOk();\n\n        \$this->post(route('register'), ['name' => 'New Person', 'email' => 'new@example.com', 'password' => 'secret-pass-123', 'password_confirmation' => 'secret-pass-123'])->assertRedirect();\n\n        \$this->assertDatabaseHas('users', ['email' => 'new@example.com']);\n        \$this->assertAuthenticated();\n    }\n"
                : "\n    public function test_public_registration_is_disabled(): void\n    {\n        \$this->get('/register')->assertNotFound();\n    }\n";
            if (!empty($data['auth_verify'])) $registration .= "\n    public function test_unverified_users_can_reach_the_verification_notice(): void\n    {\n        if (!is_subclass_of(User::class, \\Illuminate\\Contracts\\Auth\\MustVerifyEmail::class)) {\n            \$this->markTestSkipped('Add \"implements MustVerifyEmail\" to App\\\\Models\\\\User to enforce email verification.');\n        }\n\n        \$user = User::factory()->unverified()->create();\n\n        \$this->actingAs(\$user)->get(route('verification.notice'))->assertOk();\n        \$this->actingAs(\$user)->get(route('profile.edit'))->assertOk();\n    }\n";
            $files[base_path('tests/Feature/AuthTest.php')] = $this->stub('auth/test', ['registration' => $registration]);
        }

        return $files;
    }

    private function dashboard(array $tables, array $permissions = [], string $guard = ''): bool
    {
        $controller = app_path('Http/Controllers/DashboardController.php');
        if ($this->files->exists($controller) || $this->files->exists(resource_path('views/dashboard.blade.php'))) return false;

        $uses = collect($tables)->map(fn ($t) => "use App\\Models\\{$t['model']};")->implode("\n");
        $resources = collect($tables)->map(fn ($t) => "            ['label' => '".Str::headline($t['table'])."', 'model' => {$t['model']}::class, 'route' => '{$t['table']}.index', 'edit' => '{$t['table']}.edit', 'display' => '{$t['display']}', 'timestamps' => ".($t['timestamps'] ? 'true' : 'false').'],')->implode("\n");
        $gated = in_array('view', $permissions, true);
        $this->write($controller, $this->stub('dashboard-controller', [
            'uses' => $uses, 'resources' => $resources,
            'gateUse' => $gated ? "use Illuminate\\Support\\Facades\\Gate;\n" : '',
            'authorize' => $gated ? "            if (!Gate::allows('viewAny', \$resource['model'])) continue;\n" : '',
        ]));
        $this->write(resource_path('views/dashboard.blade.php'), $this->stub('dashboard-view'));
        $this->addNavLink('dashboard', 'Dashboard', true);
        $this->appendRoute("Route::get('/dashboard', [\\App\\Http\\Controllers\\DashboardController::class, 'index'])->name('dashboard')".$guard.';');
        return true;
    }
}
