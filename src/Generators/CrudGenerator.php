<?php

namespace Mcrud\Crud\Generators;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Mcrud\Crud\Support\ColumnTypes;
use Mcrud\Crud\Support\StubRenderer;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;
use Throwable;

class CrudGenerator
{
    private readonly StubRenderer $stubs;

    public function __construct(private readonly Filesystem $files, ?StubRenderer $stubs = null)
    {
        $this->stubs = $stubs ?? new StubRenderer();
    }

    /** @return array{seeders: list<string>, dashboard: bool, skipped: list<array{model: string, newForeignKeys: list<string>}>, demo: ?string, altered: list<string>} */
    public function generate(array $data): array
    {
        $plan = $this->plan($data);
        $permissions = collect($data['permissions'] ?? [])->unique()->values()->all();

        // Columns/foreign keys added in the designer to tables imported from the database become ALTER migrations.
        $alters = [];
        foreach ($plan['tables'] as $t) {
            $new = array_values(array_filter($t['fields'], fn ($f) => !$f['existing']));
            if ($t['existing'] && $new) $alters[] = ['table' => $t['table'], 'model' => $t['model'], 'fields' => $new];
        }

        // Tables that already exist in the app are skipped instead of aborting the whole run.
        $skipped = [];
        $plan['tables'] = array_values(array_filter($plan['tables'], function ($t) use (&$skipped): bool {
            $exists = $this->files->exists(app_path("Models/{$t['model']}.php"))
                || $this->files->exists(app_path("Http/Controllers/{$t['controller']}.php"))
                || $this->files->exists(resource_path("views/{$t['table']}/index.blade.php"))
                || (!$t['existing'] && $this->migrationExists("create_{$t['table']}_table"));
            if ($exists) $skipped[] = ['model' => $t['model'], 'newForeignKeys' => $t['existing'] ? [] : collect($t['fields'])->whereNotNull('foreign')->pluck('name')->all()];
            return !$exists;
        }));
        $plan['pivots'] = array_values(array_filter($plan['pivots'], fn ($pivot) => !$pivot['existing'] && !$this->migrationExists("create_{$pivot['table']}_table")));

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
            $this->write(database_path('migrations/'.$base->copy()->addSeconds($step++)->format('Y_m_d_His')."_add_columns_to_{$alter['table']}_table.php"), $this->alterMigration($alter['table'], $alter['fields']));
        }

        $seeders = [];
        $this->writeOnce(resource_path('views/layouts/app.blade.php'), $this->stub('layout', ['app' => e($data['project']['name'])]));
        foreach ($plan['tables'] as $t) {
            ['model' => $model, 'table' => $table, 'controller' => $controller, 'fields' => $fields] = $t;
            $this->write(app_path("Models/{$model}.php"), $this->model($model, $fields, $t['relations'], $t));
            $this->write(app_path("Http/Controllers/{$controller}.php"), $this->controller($t, $permissions));
            $this->write(app_path("Http/Requests/Store{$model}Request.php"), $this->formRequest("Store{$model}Request", $model, $table, $t, false, $permissions));
            $this->write(app_path("Http/Requests/Update{$model}Request.php"), $this->formRequest("Update{$model}Request", $model, $table, $t, true, $permissions));
            if ($permissions) $this->write(app_path("Policies/{$model}Policy.php"), $this->policy($model, $table, $permissions));
            $this->write(database_path("factories/{$model}Factory.php"), $this->factory($t));
            $this->write(resource_path("views/{$table}/index.blade.php"), $this->indexView($t));
            $this->write(resource_path("views/{$table}/form.blade.php"), $this->formView($t));
            $this->addNavLink("{$table}.index", Str::headline($table));
            $middleware = $permissions ? "->middleware('auth')" : '';
            $this->appendRoute("Route::resource('{$table}', \\App\\Http\\Controllers\\{$controller}::class){$middleware};");
            foreach ($data['locales'] as $locale) $this->translations($locale, $model, $table, $t);
            if ($permissions) $seeders[] = $this->permissionSeeder($table, $permissions, $data['roles'] ?? '');
        }

        $demo = null;
        if (!empty($data['sample_data']) && $plan['tables'] !== []) {
            $demo = $this->demoSeeder($plan['tables'], max(1, min(100, (int) ($data['sample_rows'] ?? 10))));
            $seeders[] = $demo;
        }

        $dashboard = !empty($data['dashboard']) && $plan['tables'] !== [] && $this->dashboard($plan['tables']);
        $this->recordProjectProfile($data['project'], collect($plan['tables'])->pluck('model')->all());

        return ['seeders' => $seeders, 'dashboard' => $dashboard, 'skipped' => $skipped, 'demo' => $demo, 'altered' => array_column($alters, 'model')];
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
                return ['name' => $name, 'type' => $f['type'], 'nullable' => !empty($f['nullable']), 'unique' => !empty($f['unique']), 'default' => $f['default'] ?? null, 'existing' => !empty($f['existing']), 'foreign' => null, 'values' => $f['type'] === 'enum' ? ColumnTypes::enumValues($f['values'] ?? '') : []];
                
            })->all();
            foreach ($fields as $f) {
                if ($f['type'] === 'enum' && !$f['values']) throw ValidationException::withMessages(['tables' => "Column {$f['name']} in {$input['name']} is an enum and needs at least one value."]);
            }
            $tables[$key] = ['model' => Str::studly(Str::singular($resource)), 'table' => Str::plural($resource), 'controller' => Str::studly(Str::plural($resource)).'Controller', 'fields' => $fields, 'display' => $this->displayColumn($fields), 'existing' => !empty($input['existing']), 'soft_deletes' => !empty($input['soft_deletes']), 'timestamps' => !isset($input['timestamps']) || filter_var($input['timestamps'], FILTER_VALIDATE_BOOLEAN), 'relations' => [], 'depends' => []];
        }

        $pivots = [];
        foreach ($data['relations'] ?? [] as $r) {
            $from = Str::lower($r['from']);
            $to = Str::lower($r['to']);
            if (!isset($tables[$from], $tables[$to])) throw ValidationException::withMessages(['relations' => 'A relation points to a table that does not exist.']);
            if ($from === $to) throw ValidationException::withMessages(['relations' => 'A table cannot be related to itself.']);
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
            $tables[$to]['depends'][] = $from;
            $childName = $r['type'] === 'hasOne' ? Str::camel($child['model']) : Str::camel($child['table']);
            if (collect($tables[$from]['relations'])->contains('name', $childName)) $childName .= 'By'.Str::studly(Str::replaceLast('_id', '', $foreignKey));
            $tables[$from]['relations'][] = ['type' => $r['type'], 'name' => $childName, 'model' => $child['model'], 'key' => $foreignKey];
            $tables[$to]['relations'][] = ['type' => 'belongsTo', 'name' => $parentRelation, 'model' => $parent['model'], 'key' => $foreignKey];
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

    private function recordProjectProfile(array $project, array $models): void
    {
        $path = storage_path('app/crud-schema-studio/project.json');
        $existing = $this->files->exists($path) ? json_decode($this->files->get($path), true) : [];
        $resources = collect($existing['resources'] ?? [])->merge($models)->unique()->values()->all();
        $profile = ['name' => $project['name'], 'description' => $project['description'] ?? '', 'locale' => $project['locale'], 'roles' => $project['roles'] ?? '', 'resources' => $resources, 'updated_at' => now()->toIso8601String()];
        $this->files->ensureDirectoryExists(dirname($path));
        $this->files->put($path, json_encode($profile, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n");
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
        $column = "            \$table->{$f['type']}('{$f['name']}'{$args})";
        if ($f['nullable']) $column .= '->nullable()';
        if ($f['unique']) $column .= '->unique()';
        if ($f['default'] !== null && $f['default'] !== '') $column .= "->default('".str_replace("'", "\\'", $f['default'])."')";

        return $column.';';
    }

    private function migration(string $table, array $fields, array $opts = []): string
    {
        $columns = collect($fields)->map(fn ($f) => $this->columnLine($f))->implode("\n");
        $extra = (($opts['timestamps'] ?? true) ? "            \$table->timestamps();\n" : '').(!empty($opts['soft_deletes']) ? "            \$table->softDeletes();\n" : '');

        return "<?php\n\nuse Illuminate\\Database\\Migrations\\Migration;\nuse Illuminate\\Database\\Schema\\Blueprint;\nuse Illuminate\\Support\\Facades\\Schema;\n\nreturn new class extends Migration { public function up(): void { Schema::create('{$table}', function (Blueprint \$table): void {\n            \$table->id();\n{$columns}\n{$extra}        }); } public function down(): void { Schema::dropIfExists('{$table}'); } };\n";
    }

    private function alterMigration(string $table, array $fields): string
    {
        $up = collect($fields)->map(fn ($f) => $this->columnLine($f))->implode("\n");
        $down = collect($fields)->map(fn ($f) => $f['type'] === 'foreignId' ? "            \$table->dropConstrainedForeignId('{$f['name']}');" : "            \$table->dropColumn('{$f['name']}');")->implode("\n");

        return "<?php\n\nuse Illuminate\\Database\\Migrations\\Migration;\nuse Illuminate\\Database\\Schema\\Blueprint;\nuse Illuminate\\Support\\Facades\\Schema;\n\nreturn new class extends Migration {\n    public function up(): void\n    {\n        Schema::table('{$table}', function (Blueprint \$table): void {\n{$up}\n        });\n    }\n\n    public function down(): void\n    {\n        Schema::table('{$table}', function (Blueprint \$table): void {\n{$down}\n        });\n    }\n};\n";
    }

    private function pivotMigration(array $pivot): string
    {
        [$a, $aTable] = $pivot['a'];
        [$b, $bTable] = $pivot['b'];
        return "<?php\n\nuse Illuminate\\Database\\Migrations\\Migration;\nuse Illuminate\\Database\\Schema\\Blueprint;\nuse Illuminate\\Support\\Facades\\Schema;\n\nreturn new class extends Migration { public function up(): void { Schema::create('{$pivot['table']}', function (Blueprint \$table): void {\n            \$table->foreignId('{$a}')->constrained('{$aTable}')->cascadeOnDelete();\n            \$table->foreignId('{$b}')->constrained('{$bTable}')->cascadeOnDelete();\n            \$table->primary(['{$a}', '{$b}']);\n        }); } public function down(): void { Schema::dropIfExists('{$pivot['table']}'); } };\n";
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
            'casts' => (($opts['timestamps'] ?? true) ? '' : "\n    public \$timestamps = false;\n").($casts ? "\n    protected \$casts = [\n{$casts}\n    ];\n" : ''), 'methods' => $methods,
        ]);
    }

    private function formRequest(string $class, string $model, string $table, array $t, bool $update, array $permissions): string
    {
        $param = Str::singular($table);
        $lines = [];
        foreach ($t['fields'] as $f) {
            $rules = [$f['nullable'] ? "'nullable'" : "'required'"];
            if (!empty($f['foreign'])) {
                array_push($rules, "'integer'", "'exists:{$f['foreign']},id'");
            } elseif ($f['type'] === 'enum') {
                $rules[] = "Rule::in(['".implode("', '", array_map(fn ($v) => str_replace("'", "\\'", $v), $f['values']))."'])";
            } else {
                foreach (explode('|', ColumnTypes::rule($f['type'])) as $rule) $rules[] = "'{$rule}'";
            }
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
            $related[] = $this->relatedLine($f['name'], $f['related'], $f['display']);
        }
        $syncKeys = [];
        foreach ($t['relations'] as $r) {
            if ($r['type'] !== 'belongsToMany') continue;
            $uses[$r['model']] = "use App\\Models\\{$r['model']};\n";
            $related[] = $this->relatedLine($r['name'], $r['model'], $r['display']);
            $syncKeys[] = $r['name'];
        }
        unset($uses[$model]);

        $search = collect($fields)->filter(fn ($f) => empty($f['foreign']) && in_array($f['type'], ['string', 'text'], true))->pluck('name')->values();
        $clauses = $search->isEmpty()
            ? ['                    $query->where(\'id\', $term);']
            : $search->map(fn ($n, $i) => '                    $query->'.($i === 0 ? 'where' : 'orWhere')."('{$n}', 'like', \"%{\$term}%\");")->all();
        $belongsTo = collect($t['relations'])->where('type', 'belongsTo')->pluck('name')->map(fn ($n) => "'{$n}'")->implode(', ');
        $sortable = collect($fields)->pluck('name')->prepend('id')->when($t['timestamps'], fn ($c) => $c->push('created_at'))->map(fn ($n) => "'{$n}'")->implode(', ');
        $keys = $syncKeys ? "['".implode("', '", $syncKeys)."']" : '[]';
        $sync = fn (string $var) => collect($syncKeys)->map(fn ($k) => "        \${$var}->{$k}()->sync(\$request->input('{$k}', []));\n")->implode('');

        return $this->stub('controller', [
            'model' => $model, 'controller' => $controller, 'table' => $table, 'param' => $param,
            'uses' => implode('', $uses), 'gateUse' => $permissions ? "use Illuminate\\Support\\Facades\\Gate;\n" : '',
            'authViewAny' => $guard('view', 'viewAny', "{$model}::class"), 'authCreate' => $guard('create', 'create', "{$model}::class"),
            'authUpdate' => $guard('update', 'update', "\${$param}"), 'authDelete' => $guard('delete', 'delete', "\${$param}"),
            'sortable' => "[{$sortable}]", 'with' => $belongsTo ? "\n            ->with([{$belongsTo}])" : '',
            'search' => implode("\n", $clauses), 'syncKeys' => $keys,
            'syncStore' => rtrim($sync('item'), "\n"), 'syncUpdate' => rtrim($sync($param), "\n"), 'related' => implode("\n", $related),
        ]);
    }

    private function relatedLine(string $key, string $model, string $display): string
    {
        $order = $display === 'id' ? 'id' : $display;

        return "            '{$key}' => {$model}::query()->orderBy('{$order}')->pluck('{$display}', 'id'),";
    }

    private function indexView(array $t): string
    {
        $table = $t['table'];
        $belongsTo = collect($t['relations'])->where('type', 'belongsTo')->keyBy('key');
        $headers = [];
        $cells = [];
        foreach ($t['fields'] as $f) {
            $n = $f['name'];
            $headers[] = "                <th class=\"px-4 py-3\"><a href=\"{{ request()->fullUrlWithQuery(['sort' => '{$n}', 'dir' => \$sort === '{$n}' && \$dir === 'asc' ? 'desc' : 'asc']) }}\" class=\"hover:text-slate-900\">{{ __('{$table}.{$n}') }}@if (\$sort === '{$n}') {{ \$dir === 'asc' ? '↑' : '↓' }}@endif</a></th>";
            $value = match (true) {
                !empty($f['foreign']) => "{{ \$item->{$f['relation']}?->{$f['display']} ?? '—' }}",
                $f['type'] === 'boolean' => "@if (\$item->{$n})<span class=\"rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-medium text-emerald-700\">✓</span>@else<span class=\"rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-500\">—</span>@endif",
                $f['type'] === 'date' => "{{ \$item->{$n}?->format('Y-m-d') }}",
                $f['type'] === 'dateTime' => "{{ \$item->{$n}?->format('Y-m-d H:i') }}",
                $f['type'] === 'decimal' => "{{ \$item->{$n} !== null ? number_format((float) \$item->{$n}, 2) : '' }}",
                $f['type'] === 'text' => "{{ \\Illuminate\\Support\\Str::limit(\$item->{$n}, 60) }}",
                default => "{{ \$item->{$n} }}",
            };
            $cells[] = "                    <td class=\"px-4 py-3\">{$value}</td>";
        }

        return $this->stub('index', ['table' => $table, 'headers' => implode("\n", $headers), 'cells' => implode("\n", $cells), 'colspan' => count($t['fields']) + 1]);
    }

    private function formView(array $t): string
    {
        $table = $t['table'];
        $inputs = [];
        foreach ($t['fields'] as $f) {
            $n = $f['name'];
            $input = match (true) {
                !empty($f['foreign']) => $this->stub('fields/select', ['name' => $n]),
                $f['type'] === 'enum' => $this->stub('fields/enum', ['name' => $n, 'values' => "['".implode("', '", array_map(fn ($v) => str_replace("'", "\\'", $v), $f['values']))."']"]),
                $f['type'] === 'time' => $this->stub('fields/text', ['name' => $n, 'type' => 'time']),
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

        return $this->stub('form', ['table' => $table, 'inputs' => implode("\n", $inputs)]);
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

    private function addNavLink(string $route, string $label): void
    {
        $path = resource_path('views/layouts/nav.blade.php');
        $current = $this->files->exists($path) ? $this->files->get($path) : '';
        if (str_contains($current, "route('{$route}')") ) return;
        $link = "<a href=\"{{ route('{$route}') }}\" class=\"rounded-md px-3 py-1.5 text-slate-600 hover:bg-slate-100 {{ request()->routeIs('{$route}') ? 'bg-slate-100 font-medium text-slate-900' : '' }}\">{$label}</a>\n";
        $this->files->ensureDirectoryExists(dirname($path));
        $this->files->put($path, $current.$link);
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
            ? ['title' => $model, 'create' => 'إنشاء', 'edit' => 'تعديل', 'delete' => 'حذف', 'save' => 'حفظ', 'cancel' => 'إلغاء', 'search' => 'بحث', 'actions' => 'الإجراءات', 'empty' => 'لا توجد سجلات بعد.', 'confirm' => 'هل أنت متأكد؟', 'saved' => 'تم الحفظ بنجاح.', 'deleted' => 'تم الحذف بنجاح.']
            : ['title' => $model, 'create' => 'Create', 'edit' => 'Edit', 'delete' => 'Delete', 'save' => 'Save', 'cancel' => 'Cancel', 'search' => 'Search', 'actions' => 'Actions', 'empty' => 'No records yet.', 'confirm' => 'Are you sure?', 'saved' => 'Saved successfully.', 'deleted' => 'Deleted successfully.'];
        $body = collect($words + $labels)->map(fn ($value, $key) => "    '".$key."' => '".str_replace("'", "\\'", $value)."',")->implode("\n");
        $this->write(lang_path("{$locale}/{$table}.php"), "<?php\n\nreturn [\n{$body}\n];\n");
    }

    private function permissionSeeder(string $table, array $permissions, string $roles): string
    {
        $class = Str::studly(Str::singular($table)).'PermissionSeeder';
        $permissionsCode = collect($permissions)->map(fn ($permission) => "        Permission::findOrCreate('{$table}.{$permission}');")->implode("\n");
        $roleNames = collect(explode(',', $roles))->map(fn ($role) => trim($role))->filter()->unique();
        $rolesCode = $roleNames->map(fn ($role) => "        Role::findOrCreate('{$role}')->givePermissionTo([".collect($permissions)->map(fn ($p) => "'{$table}.{$p}'")->implode(', ').']);')->implode("\n");
        $this->write(database_path("seeders/{$class}.php"), "<?php\n\nnamespace Database\\Seeders;\n\nuse Illuminate\\Database\\Seeder;\nuse Spatie\\Permission\\Models\\Permission;\nuse Spatie\\Permission\\Models\\Role;\n\nclass {$class} extends Seeder\n{\n    public function run(): void\n    {\n{$permissionsCode}\n{$rolesCode}\n    }\n}\n");
        return $class;
    }

    private function dashboard(array $tables): bool
    {
        $controller = app_path('Http/Controllers/DashboardController.php');
        if ($this->files->exists($controller) || $this->files->exists(resource_path('views/dashboard.blade.php'))) return false;

        $uses = collect($tables)->map(fn ($t) => "use App\\Models\\{$t['model']};")->implode("\n");
        $stats = collect($tables)->map(fn ($t) => "            ['label' => '".Str::headline($t['table'])."', 'count' => {$t['model']}::count(), 'route' => '{$t['table']}.index'],")->implode("\n");
        $this->write($controller, "<?php\n\nnamespace App\\Http\\Controllers;\n\n{$uses}\n\nclass DashboardController extends Controller\n{\n    public function index()\n    {\n        return view('dashboard', ['stats' => [\n{$stats}\n        ]]);\n    }\n}\n");
        $this->write(resource_path('views/dashboard.blade.php'), "@extends('layouts.app')\n@section('title', 'Dashboard')\n@section('content')\n<h1 class=\"mb-6 text-2xl font-semibold text-slate-900\">Dashboard</h1>\n<div class=\"grid gap-4 sm:grid-cols-2 lg:grid-cols-4\">\n@foreach (\$stats as \$stat)\n    <a href=\"{{ route(\$stat['route']) }}\" class=\"rounded-xl border border-slate-200 bg-white p-6 shadow-sm transition hover:border-indigo-300 hover:shadow\"><div class=\"text-3xl font-bold text-indigo-600\">{{ \$stat['count'] }}</div><div class=\"mt-1 text-sm text-slate-500\">{{ \$stat['label'] }}</div></a>\n@endforeach\n</div>\n@endsection\n");
        $this->addNavLink('dashboard', 'Dashboard');
        $this->appendRoute("Route::get('/dashboard', [\\App\\Http\\Controllers\\DashboardController::class, 'index'])->name('dashboard');");
        return true;
    }
}
