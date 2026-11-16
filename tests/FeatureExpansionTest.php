<?php

namespace Mcrud\Crud\Tests;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Validation\ValidationException;
use Mcrud\Crud\CrudServiceProvider;
use Mcrud\Crud\Generators\CrudGenerator;
use Orchestra\Testbench\TestCase;

class FeatureExpansionTest extends TestCase
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
        $this->root = sys_get_temp_dir().'/schema-studio-x-'.bin2hex(random_bytes(4));
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
                ['name' => 'Category', 'fields' => [$f('name')]],
                ['name' => 'Product', 'fields' => [$f('name', 'string', ['rules' => 'min:3|regex:/^[A-Z]/']), $f('status', 'enum', ['values' => 'draft,live']), $f('available', 'boolean'), $f('code', 'string', ['index' => 1])],
                    'indexes' => [['columns' => 'status, code', 'unique' => true]]],
                ['name' => 'Tag', 'fields' => [$f('label')]],
            ],
            'relations' => [['from' => 'Category', 'to' => 'Product', 'type' => 'hasMany'], ['from' => 'Product', 'to' => 'Tag', 'type' => 'belongsToMany']],
            'locales' => ['en', 'ar'], 'permissions' => ['view', 'create', 'update', 'delete'], 'roles' => 'admin', 'dashboard' => 1, 'api' => 1,
        ];
    }

    private function generator(): CrudGenerator
    {
        return $this->app->make(CrudGenerator::class);
    }

    private function read(string $path): string
    {
        return (string) file_get_contents($this->root.'/'.$path);
    }

    public function test_every_generated_php_file_is_syntactically_valid(): void
    {
        $this->generator()->generate($this->payload());
        $checked = 0;
        foreach ((new Filesystem)->allFiles($this->root) as $file) {
            if ($file->getExtension() !== 'php' || str_ends_with($file->getFilename(), '.blade.php')) continue;
            exec('php -l '.escapeshellarg($file->getPathname()).' 2>&1', $output, $code);
            self::assertSame(0, $code, $file->getPathname().': '.implode("\n", $output));
            $checked++;
        }
        self::assertGreaterThan(20, $checked);
    }

    public function test_web_controller_has_filters_csv_export_and_bulk_delete(): void
    {
        $this->generator()->generate($this->payload());
        $controller = $this->read('app/Http/Controllers/ProductsController.php');
        self::assertStringContainsString("filled('status')", $controller);
        self::assertStringContainsString("boolean('available')", $controller);
        self::assertStringContainsString("filled('category_id')", $controller);
        self::assertStringContainsString('streamDownload', $controller);
        self::assertStringContainsString('bulkDestroy', $controller);
        self::assertStringContainsString("Gate::authorize('delete', \$item)", $controller);
        $routes = $this->read('routes/web.php');
        self::assertLessThan(strpos($routes, "Route::resource('products'"), strpos($routes, "products/export"));
        self::assertStringContainsString("name('products.bulk-destroy')", $routes);
        $view = $this->read('resources/views/products/index.blade.php');
        self::assertStringContainsString('name="status"', $view);
        self::assertStringContainsString("route('products.export'", $view);
        self::assertStringContainsString('form="bulk-form"', $view);
        self::assertStringContainsString("'delete_selected' =>", $this->read('lang/en/products.php'));
    }

    public function test_generated_crud_tests_cover_the_whole_resource(): void
    {
        $this->generator()->generate($this->payload());
        $test = $this->read('tests/Feature/ProductCrudTest.php');
        foreach (['test_store_creates_a_record', 'test_update_changes_the_record', 'test_destroy_removes_the_record', 'test_bulk_delete', 'test_export_streams_a_csv', 'test_index_can_be_searched', 'Gate::before'] as $needle) {
            self::assertStringContainsString($needle, $test);
        }
    }

    public function test_api_resources_controllers_routes_tests_and_docs_are_generated(): void
    {
        $report = $this->generator()->generate($this->payload());
        self::assertTrue($report['api']);
        self::assertSame(['docs/api/openapi.json', 'docs/api/postman_collection.json'], $report['docs']);
        $controller = $this->read('app/Http/Controllers/Api/ProductsController.php');
        self::assertStringContainsString('ProductResource::collection', $controller);
        self::assertStringContainsString('setStatusCode(201)', $controller);
        self::assertStringContainsString("sync(\$request->input('tags', []))", $controller);
        self::assertStringContainsString("'category'", $this->read('app/Http/Resources/ProductResource.php'));
        $routes = $this->read('routes/api.php');
        self::assertStringContainsString("Route::apiResource('products'", $routes);
        self::assertStringContainsString("->names('api.products')->middleware('auth:sanctum')", $routes);
        self::assertStringContainsString('Sanctum::actingAs', $this->read('tests/Feature/ProductApiTest.php'));

        $openapi = json_decode($this->read('docs/api/openapi.json'), true);
        self::assertArrayHasKey('/products/{product}', $openapi['paths']);
        self::assertSame(['draft', 'live'], $openapi['components']['schemas']['Product']['properties']['status']['enum']);
        $postman = json_decode($this->read('docs/api/postman_collection.json'), true);
        self::assertSame(['Category', 'Product', 'Tag'], array_column($postman['item'], 'name'));
    }

    public function test_api_docs_merge_across_runs_and_api_only_skips_web_files(): void
    {
        $this->generator()->generate($this->payload(['web' => 0, 'tables' => [['name' => 'Category', 'fields' => [['name' => 'name', 'type' => 'string']]]], 'relations' => []]));
        self::assertFileDoesNotExist($this->root.'/app/Http/Controllers/CategoriesController.php');
        self::assertFileDoesNotExist($this->root.'/resources/views/categories/index.blade.php');
        self::assertFileDoesNotExist($this->root.'/routes/web.php');
        self::assertFileExists($this->root.'/app/Http/Controllers/Api/CategoriesController.php');

        $this->generator()->generate($this->payload(['web' => 0, 'tables' => [['name' => 'Tag', 'fields' => [['name' => 'label', 'type' => 'string']]]], 'relations' => []]));
        $openapi = json_decode($this->read('docs/api/openapi.json'), true);
        self::assertArrayHasKey('Category', $openapi['components']['schemas']);
        self::assertArrayHasKey('Tag', $openapi['components']['schemas']);
    }

    public function test_api_auth_can_be_disabled(): void
    {
        config(['crud.api_auth' => null]);
        $this->generator()->generate($this->payload(['permissions' => []]));
        self::assertStringNotContainsString('middleware', $this->read('routes/api.php'));
    }

    public function test_custom_rules_and_indexes_reach_requests_and_migrations(): void
    {
        $this->generator()->generate($this->payload());
        $request = $this->read('app/Http/Requests/StoreProductRequest.php');
        self::assertStringContainsString("'min:3'", $request);
        self::assertStringContainsString("'regex:/^[A-Z]/'", $request);
        $migration = (string) file_get_contents(glob($this->root.'/database/migrations/*_create_products_table.php')[0]);
        self::assertStringContainsString("\$table->string('code')->index();", $migration);
        self::assertStringContainsString("\$table->unique(['status', 'code']);", $migration);
    }

    public function test_index_on_an_unknown_column_is_rejected(): void
    {
        $payload = $this->payload();
        $payload['tables'][1]['indexes'] = [['columns' => 'missing']];
        $this->expectException(ValidationException::class);
        $this->generator()->plan($payload);
    }

    public function test_imported_tables_support_rename_change_drop_and_new_indexes(): void
    {
        $this->generator()->generate([
            'project' => ['name' => 'Legacy', 'locale' => 'en'],
            'tables' => [['name' => 'Customer', 'existing' => 1, 'dropped' => ['fax'], 'indexes' => [['columns' => 'email', 'unique' => true]], 'fields' => [
                ['name' => 'full_name', 'type' => 'string', 'existing' => 1, 'rename_from' => 'name'],
                ['name' => 'email', 'type' => 'string', 'existing' => 1, 'changed' => 1, 'unique' => 1],
                ['name' => 'nickname', 'type' => 'string', 'nullable' => 1],
            ]]],
            'relations' => [], 'locales' => ['en'], 'permissions' => [], 'dashboard' => 0,
        ]);
        $files = glob($this->root.'/database/migrations/*_alter_customers_table.php');
        self::assertCount(1, $files);
        $migration = (string) file_get_contents($files[0]);
        self::assertStringContainsString("renameColumn('name', 'full_name')", $migration);
        self::assertStringContainsString("renameColumn('full_name', 'name')", $migration);
        self::assertStringContainsString("->change();", $migration);
        self::assertStringContainsString("dropColumn(['fax'])", $migration);
        self::assertStringContainsString("unique(['email'])", $migration);
        self::assertStringContainsString("string('nickname')->nullable()", $migration);
        exec('php -l '.escapeshellarg($files[0]).' 2>&1', $out, $code);
        self::assertSame(0, $code);
    }

    public function test_new_foreign_keys_on_already_generated_tables_get_an_alter_migration(): void
    {
        $this->generator()->generate($this->payload(['api' => 0]));
        $report = $this->generator()->generate($this->payload(['api' => 0, 'tables' => [
            ['name' => 'Category', 'fields' => [['name' => 'name', 'type' => 'string']]],
            ['name' => 'Product', 'fields' => [['name' => 'name', 'type' => 'string']]],
            ['name' => 'Brand', 'fields' => [['name' => 'name', 'type' => 'string']]],
        ], 'relations' => [['from' => 'Brand', 'to' => 'Product', 'type' => 'hasMany']]]));
        self::assertContains('Product', array_column($report['skipped'], 'model'));
        $alter = glob($this->root.'/database/migrations/*_add_columns_to_products_table.php');
        self::assertCount(1, $alter);
        self::assertStringContainsString("foreignId('brand_id')", (string) file_get_contents($alter[0]));
        self::assertSame(['brand_id'], $report['skipped'][array_search('Product', array_column($report['skipped'], 'model'), true)]['alteredForeignKeys']);
    }

    public function test_diff_reports_missing_identical_and_changed_files(): void
    {
        $generator = $this->generator();
        $payload = $this->payload(['api' => 0]);
        $missing = array_column($generator->diff($payload), 'status', 'path');
        self::assertSame('missing', $missing['app/Models/Product.php']);

        $generator->generate($payload);
        $statuses = array_column($generator->diff($payload), 'status');
        self::assertSame(['identical'], array_values(array_unique($statuses)));

        file_put_contents($this->root.'/app/Models/Product.php', str_replace('class Product', "// edited by hand\nclass Product", $this->read('app/Models/Product.php')));
        $changed = collect($generator->diff($payload))->firstWhere('path', 'app/Models/Product.php');
        self::assertSame('changed', $changed['status']);
        self::assertStringContainsString('-// edited by hand', $changed['diff']);
    }

    public function test_published_stubs_override_packaged_ones(): void
    {
        (new Filesystem)->ensureDirectoryExists($this->root.'/stubs/crud');
        file_put_contents($this->root.'/stubs/crud/model.stub', "<?php\n// custom stub\nclass [[model]] {}\n");
        $this->app->forgetInstance(CrudGenerator::class);
        $this->generator()->generate($this->payload(['api' => 0]));
        self::assertStringContainsString('// custom stub', $this->read('app/Models/Product.php'));
    }
}
