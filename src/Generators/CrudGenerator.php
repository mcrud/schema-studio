<?php

namespace Mcrud\Crud\Generators;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;
use Throwable;

class CrudGenerator
{
    public function __construct(private readonly Filesystem $files) {}

    /** @return array{seeders: list<string>, dashboard: bool} */
    public function generate(array $data): array
    {
        $plan = $this->plan($data);
        $permissions = collect($data['permissions'] ?? [])->unique()->values()->all();

        foreach ($plan['tables'] as $t) {
            foreach ([app_path("Models/{$t['model']}.php"), app_path("Http/Controllers/{$t['controller']}.php"), resource_path("views/{$t['table']}/index.blade.php")] as $path) {
                if ($this->files->exists($path)) throw new RuntimeException("Refusing to overwrite existing file: {$path}");
            }
        }

        $base = now();
        $step = 0;
        foreach ($plan['tables'] as $t) {
            $this->write(database_path('migrations/'.$base->copy()->addSeconds($step++)->format('Y_m_d_His')."_create_{$t['table']}_table.php"), $this->migration($t['table'], $t['fields']));
        }
        foreach ($plan['pivots'] as $pivot) {
            $this->write(database_path('migrations/'.$base->copy()->addSeconds($step++)->format('Y_m_d_His')."_create_{$pivot['table']}_table.php"), $this->pivotMigration($pivot));
        }

        $seeders = [];
        foreach ($plan['tables'] as $t) {
            ['model' => $model, 'table' => $table, 'controller' => $controller, 'fields' => $fields] = $t;
            $this->write(app_path("Models/{$model}.php"), $this->model($model, $fields, $t['relations']));
            $this->write(app_path("Http/Controllers/{$controller}.php"), $this->controller($model, $controller, $table, $fields, $permissions));
            $this->write(resource_path("views/{$table}/index.blade.php"), $this->indexView($model, $table, $fields));
            $this->write(resource_path("views/{$table}/form.blade.php"), $this->formView($table, $fields));
            $middleware = $permissions ? "->middleware('auth')" : '';
            $this->appendRoute("Route::resource('{$table}', \\App\\Http\\Controllers\\{$controller}::class){$middleware};");
            foreach ($data['locales'] as $locale) $this->translations($locale, $model, $table, $fields);
            if ($permissions) $seeders[] = $this->permissionSeeder($table, $permissions, $data['roles'] ?? '');
        }

        $dashboard = !empty($data['dashboard']) && $this->dashboard($plan['tables']);
        $this->recordProjectProfile($data['project'], collect($plan['tables'])->pluck('model')->all());

        return ['seeders' => $seeders, 'dashboard' => $dashboard];
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
                return ['name' => $name, 'type' => $f['type'], 'nullable' => !empty($f['nullable']), 'unique' => !empty($f['unique']), 'default' => $f['default'] ?? null, 'foreign' => null];
            })->all();
            $tables[$key] = ['model' => Str::studly(Str::singular($resource)), 'table' => Str::plural($resource), 'controller' => Str::studly(Str::plural($resource)).'Controller', 'fields' => $fields, 'relations' => [], 'depends' => []];
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
                $pivots[] = ['table' => $pivotTable, 'a' => [Str::singular($parent['table']).'_id', $parent['table']], 'b' => [Str::singular($child['table']).'_id', $child['table']]];
                $tables[$from]['relations'][] = ['type' => 'belongsToMany', 'name' => Str::camel($child['table']), 'model' => $child['model'], 'pivot' => $pivotTable];
                $tables[$to]['relations'][] = ['type' => 'belongsToMany', 'name' => Str::camel($parent['table']), 'model' => $parent['model'], 'pivot' => $pivotTable];
                continue;
            }

            $foreignKey = Str::singular($parent['table']).'_id';
            if (!collect($tables[$to]['fields'])->contains('name', $foreignKey)) {
                array_unshift($tables[$to]['fields'], ['name' => $foreignKey, 'type' => 'foreignId', 'nullable' => !empty($r['nullable']), 'unique' => $r['type'] === 'hasOne', 'default' => null, 'foreign' => $parent['table']]);
            }
            $tables[$to]['depends'][] = $from;
            $tables[$from]['relations'][] = ['type' => $r['type'], 'name' => $r['type'] === 'hasOne' ? Str::camel($child['model']) : Str::camel($child['table']), 'model' => $child['model'], 'key' => $foreignKey];
            $tables[$to]['relations'][] = ['type' => 'belongsTo', 'name' => Str::camel($parent['model']), 'model' => $parent['model'], 'key' => $foreignKey];
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

    private function migration(string $table, array $fields): string
    {
        $columns = collect($fields)->map(function ($f): string {
            if ($f['type'] === 'foreignId') {
                $column = "            \$table->foreignId('{$f['name']}')".($f['nullable'] ? '->nullable()' : '')."->constrained('{$f['foreign']}')".($f['nullable'] ? '->nullOnDelete()' : '->cascadeOnDelete()');
                return $column.($f['unique'] ? '->unique()' : '').';';
            }
            $column = "            \$table->{$f['type']}('{$f['name']}')";
            if ($f['nullable']) $column .= '->nullable()';
            if ($f['unique']) $column .= '->unique()';
            if ($f['default'] !== null && $f['default'] !== '') $column .= "->default('".str_replace("'", "\\'", $f['default'])."')";
            return $column.';';
        })->implode("\n");
        return "<?php\n\nuse Illuminate\\Database\\Migrations\\Migration;\nuse Illuminate\\Database\\Schema\\Blueprint;\nuse Illuminate\\Support\\Facades\\Schema;\n\nreturn new class extends Migration { public function up(): void { Schema::create('{$table}', function (Blueprint \$table): void {\n            \$table->id();\n{$columns}\n            \$table->timestamps();\n        }); } public function down(): void { Schema::dropIfExists('{$table}'); } };\n";
    }

    private function pivotMigration(array $pivot): string
    {
        [$a, $aTable] = $pivot['a'];
        [$b, $bTable] = $pivot['b'];
        return "<?php\n\nuse Illuminate\\Database\\Migrations\\Migration;\nuse Illuminate\\Database\\Schema\\Blueprint;\nuse Illuminate\\Support\\Facades\\Schema;\n\nreturn new class extends Migration { public function up(): void { Schema::create('{$pivot['table']}', function (Blueprint \$table): void {\n            \$table->foreignId('{$a}')->constrained('{$aTable}')->cascadeOnDelete();\n            \$table->foreignId('{$b}')->constrained('{$bTable}')->cascadeOnDelete();\n            \$table->primary(['{$a}', '{$b}']);\n        }); } public function down(): void { Schema::dropIfExists('{$pivot['table']}'); } };\n";
    }

    private function model(string $model, array $fields, array $relations = []): string
    {
        $fillable = collect($fields)->pluck('name')->map(fn ($n) => "        '{$n}',")->implode("\n");
        $methods = collect($relations)->map(function ($r): string {
            $call = match ($r['type']) {
                'belongsToMany' => "belongsToMany({$r['model']}::class, '{$r['pivot']}')",
                default => "{$r['type']}({$r['model']}::class, '{$r['key']}')",
            };
            $return = match ($r['type']) { 'belongsTo' => 'BelongsTo', 'hasOne' => 'HasOne', 'hasMany' => 'HasMany', default => 'BelongsToMany' };
            return "\n    public function {$r['name']}(): \\Illuminate\\Database\\Eloquent\\Relations\\{$return}\n    {\n        return \$this->{$call};\n    }\n";
        })->implode('');
        return "<?php\n\nnamespace App\\Models;\n\nuse Illuminate\\Database\\Eloquent\\Model;\n\nclass {$model} extends Model\n{\n    protected \$fillable = [\n{$fillable}\n    ];\n{$methods}}\n";
    }

    private function controller(string $model, string $controller, string $table, array $fields, array $permissions): string
    {
        $singular = Str::singular($table);
        $parameter = '$'.$singular;
        $rules = collect($fields)->map(fn ($f) => "            '{$f['name']}' => '".($f['nullable'] ? 'nullable' : 'required').'|'.$this->rule($f)."',")->implode("\n");
        $checks = collect($permissions)->map(function ($permission) use ($table): string {
            $methods = match ($permission) { 'view' => "['index']", 'create' => "['create', 'store']", 'update' => "['edit', 'update']", default => "['destroy']" };
            return "        \$this->middleware('permission:{$table}.{$permission}')->only({$methods});";
        })->implode("\n");
        $constructor = $checks ? "    public function __construct()\n    {\n{$checks}\n    }\n\n" : '';
        return "<?php\n\nnamespace App\\Http\\Controllers;\n\nuse App\\Models\\{$model};\nuse Illuminate\\Http\\Request;\n\nclass {$controller} extends Controller\n{\n{$constructor}    public function index() { return view('{$table}.index', ['items' => {$model}::latest()->paginate(15)]); }\n    public function create() { return view('{$table}.form'); }\n    public function store(Request \$request) { {$model}::create(\$request->validate([\n{$rules}\n        ])); return redirect()->route('{$table}.index'); }\n    public function edit({$model} {$parameter}) { return view('{$table}.form', ['item' => {$parameter}]); }\n    public function update(Request \$request, {$model} {$parameter}) { {$parameter}->update(\$request->validate([\n{$rules}\n        ])); return redirect()->route('{$table}.index'); }\n    public function destroy({$model} {$parameter}) { {$parameter}->delete(); return redirect()->route('{$table}.index'); }\n}\n";
    }

    private function rule(array $f): string { if ($f['type'] === 'foreignId') return "integer|exists:{$f['foreign']},id"; return match ($f['type']) { 'integer', 'bigInteger' => 'integer', 'boolean' => 'boolean', 'date', 'dateTime' => 'date', 'decimal' => 'numeric', default => 'string|max:255' }; }

    private function indexView(string $model, string $table, array $fields): string
    {
        $headers = collect($fields)->pluck('name')->map(fn ($n) => "<th>{{ __('{$table}.{$n}') }}</th>")->implode('');
        $cells = collect($fields)->pluck('name')->map(fn ($n) => '<td>{{ $item->'.e($n).' }}</td>')->implode('');
        return "<h1>{{ __('{$table}.title') }}</h1><p><a href=\"{{ route('{$table}.create') }}\">{{ __('{$table}.create') }}</a></p><table border=\"1\"><tr><th>ID</th>{$headers}<th>Actions</th></tr>@foreach(\$items as \$item)<tr><td>{{ \$item->id }}</td>{$cells}<td><a href=\"{{ route('{$table}.edit', \$item) }}\">{{ __('{$table}.edit') }}</a><form method=\"POST\" action=\"{{ route('{$table}.destroy', \$item) }}\" style=\"display:inline\">@csrf @method('DELETE')<button>{{ __('{$table}.delete') }}</button></form></td></tr>@endforeach</table>{{ \$items->links() }}\n";
    }

    private function formView(string $table, array $fields): string
    {
        $inputs = collect($fields)->map(fn ($f) => "<p><label>{{ __('{$table}.{$f['name']}') }} <input name=\"{$f['name']}\" value=\"{{ old('{$f['name']}', \$item->{$f['name']} ?? '') }}\"></label>@error('{$f['name']}) <small>{{ \$message }}</small> @enderror</p>")->implode("\n");
        return "<h1>{{ isset(\$item) ? __('{$table}.edit') : __('{$table}.create') }}</h1><form method=\"POST\" action=\"{{ isset(\$item) ? route('{$table}.update', \$item) : route('{$table}.store') }}\">@csrf @isset(\$item) @method('PUT') @endisset\n{$inputs}\n<button>Save</button></form>\n";
    }

    private function translations(string $locale, string $model, string $table, array $fields): void
    {
        $arabic = $locale === 'ar';
        $labels = collect($fields)->mapWithKeys(fn ($f) => [$f['name'] => $arabic ? $f['name'] : Str::headline($f['name'])])->all();
        $lines = ['title' => $arabic ? $model : $model, 'create' => $arabic ? 'إنشاء' : 'Create', 'edit' => $arabic ? 'تعديل' : 'Edit', 'delete' => $arabic ? 'حذف' : 'Delete'] + $labels;
        $body = collect($lines)->map(fn ($value, $key) => "    '".$key."' => '".str_replace("'", "\\'", $value)."',")->implode("\n");
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
        $this->write(resource_path('views/dashboard.blade.php'), "<h1>Dashboard</h1>\n<div style=\"display:flex;gap:16px;flex-wrap:wrap\">\n@foreach(\$stats as \$stat)\n    <a href=\"{{ route(\$stat['route']) }}\" style=\"padding:20px;border:1px solid #ddd;border-radius:12px;min-width:160px;text-decoration:none;color:inherit\"><div style=\"font-size:32px;font-weight:700\">{{ \$stat['count'] }}</div><div>{{ \$stat['label'] }}</div></a>\n@endforeach\n</div>\n");
        $this->appendRoute("Route::get('/dashboard', [\\App\\Http\\Controllers\\DashboardController::class, 'index'])->name('dashboard');");
        return true;
    }
}
