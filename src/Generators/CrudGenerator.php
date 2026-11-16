<?php

namespace Mcrud\Crud\Generators;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use RuntimeException;

class CrudGenerator
{
    public function __construct(private readonly Filesystem $files) {}

    public function generate(array $data): void
    {
        $resource = Str::snake($data['name']);
        $model = Str::studly(Str::singular($resource));
        $table = Str::plural($resource);
        $controller = Str::studly($table).'Controller';
        $fields = collect($data['fields'])->map(fn ($f) => [
            'name' => Str::snake($f['name']), 'type' => $f['type'], 'nullable' => !empty($f['nullable']),
            'unique' => !empty($f['unique']), 'default' => $f['default'] ?? null,
        ])->all();
        $permissions = collect($data['permissions'] ?? [])->unique()->values()->all();

        $this->write(database_path('migrations/'.now()->format('Y_m_d_His')."_create_{$table}_table.php"), $this->migration($table, $fields));
        $this->write(app_path("Models/{$model}.php"), $this->model($model, $fields));
        $this->write(app_path("Http/Controllers/{$controller}.php"), $this->controller($model, $controller, $table, $fields, $permissions));
        $this->write(resource_path("views/{$table}/index.blade.php"), $this->indexView($model, $table, $fields));
        $this->write(resource_path("views/{$table}/form.blade.php"), $this->formView($table, $fields));
        $middleware = $permissions ? "->middleware('auth')" : '';
        $this->appendRoute("Route::resource('{$table}', \\App\\Http\\Controllers\\{$controller}::class){$middleware};");
        foreach ($data['locales'] as $locale) $this->translations($locale, $model, $table, $fields);
        if ($permissions) $this->permissionSeeder($table, $permissions, $data['roles'] ?? '');

        if (!empty($data['dashboard'])) $this->dashboard($model, $table);
        $this->recordProjectProfile($data['project'], $data['name']);
    }

    private function recordProjectProfile(array $project, string $resourceName): void
    {
        $path = storage_path('app/crud-schema-studio/project.json');
        $existing = $this->files->exists($path) ? json_decode($this->files->get($path), true) : [];
        $resources = collect($existing['resources'] ?? [])->push(Str::studly(Str::singular($resourceName)))->unique()->values()->all();
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
            $column = "            \\$table->{$f['type']}('{$f['name']}')";
            if ($f['nullable']) $column .= '->nullable()';
            if ($f['unique']) $column .= '->unique()';
            if ($f['default'] !== null && $f['default'] !== '') $column .= "->default('".str_replace("'", "\\'", $f['default'])."')";
            return $column.';';
        })->implode("\n");
        return "<?php\n\nuse Illuminate\\Database\\Migrations\\Migration;\nuse Illuminate\\Database\\Schema\\Blueprint;\nuse Illuminate\\Support\\Facades\\Schema;\n\nreturn new class extends Migration { public function up(): void { Schema::create('{$table}', function (Blueprint \\$table): void {\n            \\$table->id();\n{$columns}\n            \\$table->timestamps();\n        }); } public function down(): void { Schema::dropIfExists('{$table}'); } };\n";
    }

    private function model(string $model, array $fields): string
    {
        $fillable = collect($fields)->pluck('name')->map(fn ($n) => "        '{$n}',")->implode("\n");
        return "<?php\n\nnamespace App\\Models;\n\nuse Illuminate\\Database\\Eloquent\\Model;\n\nclass {$model} extends Model\n{\n    protected \\$fillable = [\n{$fillable}\n    ];\n}\n";
    }

    private function controller(string $model, string $controller, string $table, array $fields, array $permissions): string
    {
        $singular = Str::singular($table);
        $parameter = '$'.$singular;
        $rules = collect($fields)->map(fn ($f) => "            '{$f['name']}' => '".($f['nullable'] ? 'nullable' : 'required').'|'.$this->rule($f['type'])."',")->implode("\n");
        $checks = collect($permissions)->map(function ($permission) use ($table): string {
            $methods = match ($permission) { 'view' => "['index']", 'create' => "['create', 'store']", 'update' => "['edit', 'update']", default => "['destroy']" };
            return "        \\$this->middleware('permission:{$table}.{$permission}')->only({$methods});";
        })->implode("\n");
        $constructor = $checks ? "    public function __construct()\n    {\n{$checks}\n    }\n\n" : '';
        return "<?php\n\nnamespace App\\Http\\Controllers;\n\nuse App\\Models\\{$model};\nuse Illuminate\\Http\\Request;\n\nclass {$controller} extends Controller\n{\n{$constructor}    public function index() { return view('{$table}.index', ['items' => {$model}::latest()->paginate(15)]); }\n    public function create() { return view('{$table}.form'); }\n    public function store(Request \\$request) { {$model}::create(\$request->validate([\n{$rules}\n        ])); return redirect()->route('{$table}.index'); }\n    public function edit({$model} {$parameter}) { return view('{$table}.form', ['item' => {$parameter}]); }\n    public function update(Request \\$request, {$model} {$parameter}) { {$parameter}->update(\$request->validate([\n{$rules}\n        ])); return redirect()->route('{$table}.index'); }\n    public function destroy({$model} {$parameter}) { {$parameter}->delete(); return redirect()->route('{$table}.index'); }\n}\n";
    }

    private function rule(string $type): string { return match ($type) { 'integer', 'bigInteger' => 'integer', 'boolean' => 'boolean', 'date', 'dateTime' => 'date', 'decimal' => 'numeric', default => 'string|max:255' }; }

    private function indexView(string $model, string $table, array $fields): string
    {
        $headers = collect($fields)->pluck('name')->map(fn ($n) => "<th>{{ __('{$table}.{$n}') }}</th>")->implode('');
        $cells = collect($fields)->pluck('name')->map(fn ($n) => '<td>{{ $item->'.e($n).' }}</td>')->implode('');
        return "<h1>{{ __('{$table}.title') }}</h1><p><a href=\"{{ route('{$table}.create') }}\">{{ __('{$table}.create') }}</a></p><table border=\"1\"><tr><th>ID</th>{$headers}<th>Actions</th></tr>@foreach(\$items as \$item)<tr><td>{{ \$item->id }}</td>{$cells}<td><a href=\"{{ route('{$table}.edit', \$item) }}\">{{ __('{$table}.edit') }}</a><form method=\"POST\" action=\"{{ route('{$table}.destroy', \$item) }}\" style=\"display:inline\">@csrf @method('DELETE')<button>{{ __('{$table}.delete') }}</button></form></td></tr>@endforeach</table>{{ \$items->links() }}\n";
    }

    private function formView(string $table, array $fields): string
    {
        $inputs = collect($fields)->map(fn ($f) => "<p><label>{{ __('{$table}.{$f['name']}') }} <input name=\"{$f['name']}\" value=\"{{ old('{$f['name']}', \\$item->{$f['name']} ?? '') }}\"></label>@error('{$f['name']}) <small>{{ \\$message }}</small> @enderror</p>")->implode("\n");
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

    private function permissionSeeder(string $table, array $permissions, string $roles): void
    {
        $class = Str::studly(Str::singular($table)).'PermissionSeeder';
        $permissionsCode = collect($permissions)->map(fn ($permission) => "        Permission::findOrCreate('{$table}.{$permission}');")->implode("\n");
        $roleNames = collect(explode(',', $roles))->map(fn ($role) => trim($role))->filter()->unique();
        $rolesCode = $roleNames->map(fn ($role) => "        Role::findOrCreate('{$role}')->givePermissionTo([".collect($permissions)->map(fn ($p) => "'{$table}.{$p}'")->implode(', ').']);')->implode("\n");
        $this->write(database_path("seeders/{$class}.php"), "<?php\n\nnamespace Database\\Seeders;\n\nuse Illuminate\\Database\\Seeder;\nuse Spatie\\Permission\\Models\\Permission;\nuse Spatie\\Permission\\Models\\Role;\n\nclass {$class} extends Seeder\n{\n    public function run(): void\n    {\n{$permissionsCode}\n{$rolesCode}\n    }\n}\n");
    }

    private function dashboard(string $model, string $table): void
    {
        $this->write(app_path('Http/Controllers/DashboardController.php'), "<?php\n\nnamespace App\\Http\\Controllers;\n\nuse App\\Models\\{$model};\n\nclass DashboardController extends Controller { public function index() { return view('dashboard', ['count' => {$model}::count()]); } }\n");
        $this->write(resource_path('views/dashboard.blade.php'), "<h1>Dashboard</h1><p>Total {$table}: {{ \\$count }}</p><a href=\"{{ route('{$table}.index') }}\">Manage {$table}</a>\n");
        $this->appendRoute("Route::get('/dashboard', [\\App\\Http\\Controllers\\DashboardController::class, 'index'])->name('dashboard');");
    }
}
