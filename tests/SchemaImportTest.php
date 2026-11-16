<?php

namespace Mcrud\Crud\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Schema;
use Mcrud\Crud\CrudServiceProvider;
use Mcrud\Crud\Generators\CrudGenerator;
use Mcrud\Crud\Schema\SchemaIntrospector;
use Orchestra\Testbench\TestCase;

class SchemaImportTest extends TestCase
{
    private string $root;

    protected function getPackageProviders($app): array
    {
        return [CrudServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        $app['config']->set('crud.middleware', ['web']);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/schema-import-'.bin2hex(random_bytes(4));
        (new Filesystem)->makeDirectory($this->root.'/routes', 0777, true);
        $this->app->setBasePath($this->root);
        $this->app->useAppPath($this->root.'/app');
        $this->app->useDatabasePath($this->root.'/database');
        $this->app->useLangPath($this->root.'/lang');
        $this->app->useStoragePath($this->root.'/storage');

        Schema::create('categories', function (Blueprint $t) {
            $t->id();
            $t->string('name')->unique();
            $t->timestamps();
        });
        Schema::create('products', function (Blueprint $t) {
            $t->id();
            $t->foreignId('category_id')->nullable()->constrained('categories');
            $t->string('title', 120)->default('Untitled');
            $t->text('body')->nullable();
            $t->boolean('published')->default(false);
            $t->decimal('price', 8, 2);
            $t->dateTime('released_at')->nullable();
            $t->json('meta')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('tags', function (Blueprint $t) {
            $t->id();
            $t->string('label');
        });
        Schema::create('product_tag', function (Blueprint $t) {
            $t->foreignId('product_id')->constrained();
            $t->foreignId('tag_id')->constrained();
            $t->primary(['product_id', 'tag_id']);
        });
        Schema::create('profiles', function (Blueprint $t) {
            $t->id();
            $t->foreignId('owner_id')->unique()->constrained('categories');
            $t->string('bio');
        });
        Schema::create('news', function (Blueprint $t) {
            $t->id();
            $t->string('headline');
        });
        Schema::create('audit', function (Blueprint $t) {
            $t->string('message');
        });
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->root);
        parent::tearDown();
    }

    private function table(array $described, string $name): array
    {
        return collect($described['tables'])->firstWhere('name', $name);
    }

    public function test_it_describes_tables_columns_relations_and_pivots(): void
    {
        $d = (new SchemaIntrospector)->describe();
        $names = collect($d['tables'])->pluck('name')->all();

        self::assertContains('Category', $names);
        self::assertContains('Product', $names);
        self::assertNotContains('ProductTag', $names, 'pivot tables are not importable as tables');
        self::assertNotContains('Migration', $names, 'system tables are hidden');

        $product = $this->table($d, 'Product');
        self::assertTrue($product['timestamps']);
        self::assertTrue($product['softDeletes']);
        $cols = collect($product['columns'])->keyBy('name');
        self::assertSame(['title', 'body', 'published', 'price', 'released_at', 'meta'], $cols->keys()->all(), 'id, timestamps, deleted_at and FK columns are not plain columns');
        self::assertSame('string', $cols['title']['type']);
        self::assertSame('Untitled', $cols['title']['default']);
        self::assertTrue($cols['body']['nullable']);
        self::assertSame('text', $cols['body']['type']);
        self::assertSame('boolean', $cols['published']['type']);
        self::assertSame('decimal', $cols['price']['type']);
        self::assertSame('dateTime', $cols['released_at']['type']);
        self::assertContains($cols['meta']['type'], ['json', 'text'], 'SQLite stores json columns as text');
        self::assertTrue(collect($this->table($d, 'Category')['columns'])->firstWhere('name', 'name')['unique']);

        $relations = collect($d['relations']);
        self::assertNotNull($relations->first(fn ($r) => $r['from'] === 'Category' && $r['to'] === 'Product' && $r['type'] === 'hasMany' && $r['nullable'] && $r['existing'] && !isset($r['key'])));
        self::assertNotNull($relations->first(fn ($r) => $r['type'] === 'belongsToMany' && [$r['from'], $r['to']] === ['Product', 'Tag']));
        $profile = $relations->first(fn ($r) => $r['to'] === 'Profile');
        self::assertSame('hasOne', $profile['type']);
        self::assertSame('owner_id', $profile['key']);
    }

    public function test_tables_that_the_generator_cannot_target_are_flagged(): void
    {
        $d = (new SchemaIntrospector)->describe();

        self::assertTrue($this->table($d, 'Category')['importable']);
        self::assertSame(null, $this->table($d, 'Category')['problem']);
        self::assertFalse($this->table($d, 'Audit')['importable']);
        self::assertStringContainsString('id', $this->table($d, 'Audit')['problem']);
    }

    public function test_the_introspect_endpoint_returns_json(): void
    {
        $this->getJson('/crud-generator/introspect')->assertOk()->assertJsonStructure(['tables', 'relations', 'connection'])->assertJsonFragment(['name' => 'Product']);
    }

    public function test_imported_tables_generate_code_and_only_alter_migrations_for_new_columns(): void
    {
        $d = (new SchemaIntrospector)->describe();
        $tables = collect($d['tables'])->filter(fn ($t) => in_array($t['name'], ['Category', 'Product', 'Tag'], true))->map(fn ($t) => [
            'name' => $t['name'], 'existing' => 1, 'timestamps' => $t['timestamps'], 'soft_deletes' => $t['softDeletes'],
            'fields' => array_map(fn ($c) => $c + ['existing' => 1], $t['columns']),
        ])->values()->all();
        // The designer adds one new column to an imported table and a brand new table with a relation to it.
        $tables[array_search('Product', array_column($tables, 'name'), true)]['fields'][] = ['name' => 'sku', 'type' => 'string', 'unique' => 1];
        $tables[] = ['name' => 'Review', 'fields' => [['name' => 'body', 'type' => 'text']]];
        $relations = array_values(array_filter($d['relations'], fn ($r) => in_array($r['from'], ['Category', 'Product', 'Tag'], true) && in_array($r['to'], ['Category', 'Product', 'Tag'], true)));
        $relations[] = ['from' => 'Product', 'to' => 'Review', 'type' => 'hasMany'];

        $report = $this->app->make(CrudGenerator::class)->generate([
            'project' => ['name' => 'Shop', 'locale' => 'en'], 'tables' => $tables, 'relations' => $relations,
            'locales' => ['en'], 'permissions' => [], 'dashboard' => 0,
        ]);

        $migrations = array_map('basename', glob($this->root.'/database/migrations/*.php'));
        self::assertCount(2, $migrations, 'only the new table and the ALTER migration are written: '.implode(', ', $migrations));
        self::assertNotEmpty(preg_grep('/_create_reviews_table/', $migrations));
        self::assertNotEmpty(preg_grep('/_add_columns_to_products_table/', $migrations));
        self::assertEmpty(preg_grep('/_create_(categories|products|tags|product_tag)_table/', $migrations));
        self::assertSame(['Product'], $report['altered']);

        $alter = (string) file_get_contents($this->root.'/database/migrations/'.array_values(preg_grep('/add_columns_to_products/', $migrations))[0]);
        self::assertStringContainsString("\$table->string('sku')->unique();", $alter);
        self::assertStringContainsString("dropColumn('sku')", $alter);
        self::assertStringNotContainsString('category_id', $alter, 'existing foreign keys are not re-added');

        $model = (string) file_get_contents($this->root.'/app/Models/Product.php');
        self::assertStringContainsString('use HasFactory, SoftDeletes;', $model);
        self::assertStringContainsString("belongsTo(Category::class, 'category_id')", $model);
        self::assertStringContainsString("belongsToMany(Tag::class, 'product_tag')", $model);
        self::assertStringContainsString("hasMany(Review::class, 'product_id')", $model);
        self::assertStringContainsString("'category_id'", $model);

        $review = (string) file_get_contents($this->root.'/database/migrations/'.array_values(preg_grep('/create_reviews/', $migrations))[0]);
        self::assertStringContainsString("foreignId('product_id')->constrained('products')", $review);
    }

    public function test_custom_foreign_key_names_are_respected(): void
    {
        $report = $this->app->make(CrudGenerator::class)->generate([
            'project' => ['name' => 'x', 'locale' => 'en'],
            'tables' => [
                ['name' => 'Category', 'existing' => 1, 'fields' => [['name' => 'name', 'type' => 'string', 'existing' => 1]]],
                ['name' => 'Profile', 'existing' => 1, 'fields' => [['name' => 'bio', 'type' => 'string', 'existing' => 1]]],
            ],
            'relations' => [['from' => 'Category', 'to' => 'Profile', 'type' => 'hasOne', 'existing' => 1, 'key' => 'owner_id']],
            'locales' => ['en'],
        ]);

        self::assertSame([], $report['altered']);
        self::assertEmpty(glob($this->root.'/database/migrations/*.php'));
        $profile = (string) file_get_contents($this->root.'/app/Models/Profile.php');
        self::assertStringContainsString("belongsTo(Category::class, 'owner_id')", $profile);
        self::assertStringContainsString('public function owner()', $profile);
        self::assertStringContainsString("hasOne(Profile::class, 'owner_id')", (string) file_get_contents($this->root.'/app/Models/Category.php'));
    }
}
