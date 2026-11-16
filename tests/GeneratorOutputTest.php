<?php

namespace Mcrud\Crud\Tests;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Validation\ValidationException;
use Mcrud\Crud\CrudServiceProvider;
use Mcrud\Crud\Generators\CrudGenerator;
use Orchestra\Testbench\TestCase;

class GeneratorOutputTest extends TestCase
{
    private string $root;

    protected function getPackageProviders($app): array
    {
        return [CrudServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/schema-studio-'.bin2hex(random_bytes(4));
        (new Filesystem)->makeDirectory($this->root.'/routes', 0777, true);
        $this->app->setBasePath($this->root);
        $this->app->useAppPath($this->root.'/app');
        $this->app->useDatabasePath($this->root.'/database');
        $this->app->useLangPath($this->root.'/lang');
        $this->app->useStoragePath($this->root.'/storage');
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->root);
        parent::tearDown();
    }

    private function payload(array $overrides = []): array
    {
        $f = fn (string $n, string $t = 'string', array $x = []) => ['name' => $n, 'type' => $t] + $x;

        return $overrides + [
            'project' => ['name' => 'Shop', 'locale' => 'en'],
            'tables' => [
                ['name' => 'Category', 'fields' => [$f('name'), $f('description', 'text')]],
                ['name' => 'Product', 'fields' => [$f('name'), $f('sku', 'string', ['unique' => 1]), $f('price', 'decimal'), $f('available', 'boolean'), $f('released', 'date')]],
                ['name' => 'Tag', 'fields' => [$f('label')]],
            ],
            'relations' => [
                ['from' => 'Category', 'to' => 'Product', 'type' => 'hasMany'],
                ['from' => 'Product', 'to' => 'Tag', 'type' => 'belongsToMany'],
            ],
            'locales' => ['en', 'ar'], 'permissions' => ['view', 'create', 'update', 'delete'], 'roles' => 'admin',
            'dashboard' => 1, 'sample_data' => 1, 'sample_rows' => 3,
        ];
    }

    private function generate(array $overrides = []): array
    {
        return $this->app->make(CrudGenerator::class)->generate($this->payload($overrides));
    }

    private function files(string $pattern): array
    {
        return glob($this->root.'/'.$pattern) ?: [];
    }

    public function test_it_generates_every_artifact_for_every_table_and_all_php_is_valid(): void
    {
        $report = $this->generate();

        foreach (['Category' => 'categories', 'Product' => 'products', 'Tag' => 'tags'] as $model => $table) {
            foreach (["app/Models/{$model}.php", "app/Http/Requests/Store{$model}Request.php", "app/Http/Requests/Update{$model}Request.php", "app/Policies/{$model}Policy.php", "database/factories/{$model}Factory.php", "resources/views/{$table}/index.blade.php", "resources/views/{$table}/form.blade.php", "lang/en/{$table}.php", "lang/ar/{$table}.php"] as $path) {
                self::assertFileExists("{$this->root}/{$path}");
            }
        }
        self::assertSame(['CategoryPermissionSeeder', 'ProductPermissionSeeder', 'TagPermissionSeeder', 'StudioDemoSeeder'], $report['seeders']);
        self::assertTrue($report['dashboard']);

        $php = array_merge($this->files('app/*/*.php'), $this->files('app/*.php'), $this->files('database/*/*.php'), $this->files('lang/*/*.php'));
        self::assertNotEmpty($php);
        foreach ($php as $file) self::assertNotFalse(token_get_all((string) file_get_contents($file), TOKEN_PARSE), $file);

        $blade = $this->app->make('blade.compiler');
        foreach ((new Filesystem)->allFiles($this->root.'/resources/views') as $view) {
            self::assertNotFalse(token_get_all($blade->compileString($view->getContents()), TOKEN_PARSE), $view->getRelativePathname());
        }
    }

    public function test_relations_foreign_keys_and_pivots_are_generated_in_dependency_order(): void
    {
        $this->generate();

        $migrations = array_map('basename', $this->files('database/migrations/*.php'));
        sort($migrations);
        $order = array_map(fn ($m) => preg_replace('/^\d{4}_\d{2}_\d{2}_\d{6}_create_|_table\.php$/', '', $m), $migrations);
        self::assertLessThan(array_search('products', $order, true), array_search('categories', $order, true));
        self::assertContains('product_tag', $order);

        $products = (string) file_get_contents($this->files('database/migrations/*_create_products_table.php')[0]);
        self::assertStringContainsString("foreignId('category_id')->constrained('categories')->cascadeOnDelete()", $products);

        $model = (string) file_get_contents($this->root.'/app/Models/Product.php');
        self::assertStringContainsString("belongsTo(Category::class, 'category_id')", $model);
        self::assertStringContainsString("belongsToMany(Tag::class, 'product_tag')", $model);
        self::assertStringContainsString("'available' => 'boolean'", $model);
    }

    public function test_form_requests_policies_and_views_use_the_right_inputs_and_rules(): void
    {
        $this->generate();

        $update = (string) file_get_contents($this->root.'/app/Http/Requests/UpdateProductRequest.php');
        self::assertStringContainsString("Rule::unique('products', 'sku')->ignore(\$this->route('product'))", $update);
        self::assertStringContainsString("'category_id' => ['required', 'integer', 'exists:categories,id']", $update);
        self::assertStringContainsString("'tags.*' => ['integer', 'exists:tags,id']", $update);

        self::assertStringContainsString("\$user->can('products.view')", (string) file_get_contents($this->root.'/app/Policies/ProductPolicy.php'));

        $form = (string) file_get_contents($this->root.'/resources/views/products/form.blade.php');
        self::assertStringContainsString('<select id="category_id"', $form);
        self::assertStringContainsString('name="tags[]" multiple', $form);
        self::assertStringContainsString('type="checkbox"', $form);
        self::assertStringContainsString('type="date"', $form);
        self::assertStringNotContainsString('[[', $form);
    }

    public function test_demo_seeder_and_factories_are_generated_only_when_requested(): void
    {
        $this->generate();
        self::assertFileExists($this->root.'/database/seeders/StudioDemoSeeder.php');
        self::assertStringContainsString('Product::factory()->count(3)->create()', (string) file_get_contents($this->root.'/database/seeders/StudioDemoSeeder.php'));

        (new Filesystem)->deleteDirectory($this->root);
        (new Filesystem)->makeDirectory($this->root.'/routes', 0777, true);
        $report = $this->generate(['sample_data' => 0]);
        self::assertNull($report['demo']);
        self::assertFileDoesNotExist($this->root.'/database/seeders/StudioDemoSeeder.php');
    }

    public function test_rerunning_skips_existing_tables_and_generates_only_new_ones(): void
    {
        $this->generate();
        $payload = $this->payload();
        $payload['tables'][] = ['name' => 'Room', 'fields' => [['name' => 'number', 'type' => 'string']]];
        $report = $this->app->make(CrudGenerator::class)->generate($payload);

        self::assertCount(3, $report['skipped']);
        self::assertFileExists($this->root.'/app/Models/Room.php');
    }

    public function test_circular_relations_are_rejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->generate(['relations' => [['from' => 'Category', 'to' => 'Product', 'type' => 'hasMany'], ['from' => 'Product', 'to' => 'Category', 'type' => 'hasMany']]]);
    }

    /** Generates a real CRUD, migrates it on sqlite and drives the generated controllers over HTTP. */
    public function test_generated_crud_actually_works_end_to_end(): void
    {
        $this->generate(['permissions' => [], 'sample_data' => 0]);

        if (!class_exists('App\\Http\\Controllers\\Controller')) eval('namespace App\\Http\\Controllers; abstract class Controller {}');
        foreach (['app/Models/*.php', 'app/Http/Requests/*.php', 'app/Http/Controllers/*Controller.php', 'database/factories/*.php'] as $glob) {
            foreach ($this->files($glob) as $file) {
                if (!str_ends_with($file, '/Controller.php')) require_once $file;
            }
        }
        $this->app['view']->addLocation($this->root.'/resources/views');
        $this->app['router']->middleware('web')->group($this->root.'/routes/web.php');
        $this->loadMigrationsFrom($this->root.'/database/migrations');
        $this->artisan('migrate')->assertExitCode(0);

        $category = \App\Models\Category::create(['name' => 'Books', 'description' => 'All books']);
        $tag = \App\Models\Tag::create(['label' => 'Sale']);
        $row = ['category_id' => $category->id, 'name' => 'Novel', 'sku' => 'N-1', 'price' => '9.50', 'available' => 1, 'released' => '2024-01-02', 'tags' => [$tag->id]];

        $this->get('/products/create')->assertOk()->assertSee('<select id="category_id"', false)->assertSee($category->name);
        $this->post('/products', $row)->assertRedirect('/products');
        $product = \App\Models\Product::firstOrFail();
        self::assertSame($category->id, $product->category->id);
        self::assertSame([$tag->id], $product->tags()->pluck('tags.id')->all());

        $this->post('/products', $row)->assertSessionHasErrors('sku');
        $this->post('/products', ['name' => 'x'])->assertSessionHasErrors(['category_id', 'sku', 'price']);

        $this->get('/products')->assertOk()->assertSee('Novel')->assertSee('Books');
        $this->get('/products?q=Novel')->assertSee('N-1');
        $this->get('/products?q=zzz')->assertDontSee('N-1');
        $this->get('/products?sort=price&dir=asc')->assertOk();

        $this->get("/products/{$product->id}/edit")->assertOk()->assertSee('value="Novel"', false);
        $this->put("/products/{$product->id}", ['name' => 'Novel 2'] + $row)->assertRedirect('/products');
        self::assertSame('Novel 2', $product->fresh()->name);

        $this->delete("/products/{$product->id}")->assertRedirect('/products');
        self::assertSame(0, \App\Models\Product::count());

        \App\Models\Product::factory()->count(2)->create();
        self::assertSame(2, \App\Models\Product::count());
    }

    public function test_new_column_types_and_table_options_work_end_to_end(): void
    {
        $this->generate([
            'permissions' => [], 'dashboard' => 0, 'sample_data' => 1, 'sample_rows' => 2, 'relations' => [],
            'tables' => [['name' => 'Ticket', 'soft_deletes' => 1, 'timestamps' => 0, 'fields' => [
                ['name' => 'title', 'type' => 'string'],
                ['name' => 'status', 'type' => 'enum', 'values' => 'open, closed, on hold'],
                ['name' => 'ref', 'type' => 'uuid'],
                ['name' => 'meta', 'type' => 'json', 'nullable' => 1],
                ['name' => 'due', 'type' => 'time'],
                ['name' => 'score', 'type' => 'float'],
            ]]],
        ]);

        $migration = (string) file_get_contents($this->files('database/migrations/*_create_tickets_table.php')[0]);
        self::assertStringContainsString("enum('status', ['open', 'closed', 'on hold'])", $migration);
        self::assertStringContainsString('softDeletes()', $migration);
        self::assertStringNotContainsString('timestamps()', $migration);

        $model = (string) file_get_contents($this->root.'/app/Models/Ticket.php');
        self::assertStringContainsString('use HasFactory, SoftDeletes;', $model);
        self::assertStringContainsString('public $timestamps = false;', $model);
        self::assertStringContainsString("Rule::in(['open', 'closed', 'on hold'])", (string) file_get_contents($this->root.'/app/Http/Requests/StoreTicketRequest.php'));
        self::assertStringContainsString('<select id="status"', (string) file_get_contents($this->root.'/resources/views/tickets/form.blade.php'));

        if (!class_exists('App\\Http\\Controllers\\Controller')) eval('namespace App\\Http\\Controllers; abstract class Controller {}');
        foreach (['app/Models/*.php', 'app/Http/Requests/*.php', 'app/Http/Controllers/*Controller.php', 'database/factories/*.php'] as $glob) {
            foreach ($this->files($glob) as $file) if (!str_ends_with($file, '/Controller.php')) require_once $file;
        }
        $this->app['view']->addLocation($this->root.'/resources/views');
        $this->app['router']->middleware('web')->group($this->root.'/routes/web.php');
        $this->loadMigrationsFrom($this->root.'/database/migrations');
        $this->artisan('migrate')->assertExitCode(0);

        \App\Models\Ticket::factory()->count(2)->create();
        self::assertSame(2, \App\Models\Ticket::count());
        $row = ['title' => 'Bug', 'status' => 'open', 'ref' => '3f2504e0-4f89-41d3-9a0c-0305e82c3301', 'meta' => '{"a":1}', 'due' => '10:30', 'score' => '4.5'];
        $this->post('/tickets', $row)->assertRedirect('/tickets');
        $this->post('/tickets', ['status' => 'nope'] + $row)->assertSessionHasErrors('status');
        $this->post('/tickets', ['due' => 'late'] + $row)->assertSessionHasErrors('due');
        $this->get('/tickets/create')->assertOk()->assertSee('On Hold');

        $ticket = \App\Models\Ticket::first();
        $this->delete("/tickets/{$ticket->id}");
        self::assertSame(2, \App\Models\Ticket::count());
        self::assertSame(1, \App\Models\Ticket::onlyTrashed()->count());
        self::assertSame(3, \App\Models\Ticket::withTrashed()->count());
    }

    public function test_generator_page_and_designer_script_are_served(): void
    {
        $this->get('/crud-generator/assets/studio.js')->assertOk()->assertHeader('Content-Type', 'application/javascript; charset=utf-8');
        $this->get('/crud-generator/assets/other.js')->assertNotFound();
        $this->get('/crud-generator')->assertOk()->assertSee('Schema canvas')->assertSee('"enum"', false)->assertSee('/crud-generator/assets/studio.js', false);
    }
}
