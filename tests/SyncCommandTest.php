<?php

namespace Mcrud\Crud\Tests;

use Illuminate\Filesystem\Filesystem;
use Mcrud\Crud\CrudServiceProvider;
use Mcrud\Crud\Generators\CrudGenerator;
use Mcrud\Crud\Support\Differ;
use Orchestra\Testbench\TestCase;

class SyncCommandTest extends TestCase
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
        $this->root = sys_get_temp_dir().'/schema-studio-sync-'.bin2hex(random_bytes(4));
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

    private function schema(array $fields): array
    {
        return [
            'project' => ['name' => 'Shop', 'locale' => 'en'], 'locales' => ['en'], 'permissions' => [], 'relations' => [],
            'tables' => [['name' => 'Product', 'fields' => $fields]],
        ];
    }

    private function write(array $schema): string
    {
        $path = $this->root.'/schema.json';
        file_put_contents($path, json_encode($schema));

        return $path;
    }

    private function generate(array $schema): void
    {
        $this->app->make(CrudGenerator::class)->generate($schema + ['dashboard' => 0]);
    }

    private function read(string $path): string
    {
        return (string) file_get_contents("{$this->root}/{$path}");
    }

    public function test_merge_keeps_identical_lines_and_marks_every_difference(): void
    {
        [$merged, $conflicts] = (new Differ)->merge("a\nmine\nc\nd\nmine2\n", "a\ntheirs\nc\nd\ntheirs2\n");

        self::assertSame(2, $conflicts);
        self::assertSame("a\n<<<<<<< current\nmine\n=======\ntheirs\n>>>>>>> generated\nc\nd\n<<<<<<< current\nmine2\n=======\ntheirs2\n>>>>>>> generated\n", $merged);
        self::assertSame([ "same\n", 0], (new Differ)->merge("same\n", "same\n"));
    }

    public function test_merge_handles_pure_insertions_and_deletions(): void
    {
        [$added, $a] = (new Differ)->merge("a\nb", "a\nnew\nb");
        [$removed, $r] = (new Differ)->merge("a\nold\nb", "a\nb");

        self::assertSame("a\n<<<<<<< current\n=======\nnew\n>>>>>>> generated\nb", $added);
        self::assertSame("a\n<<<<<<< current\nold\n=======\n>>>>>>> generated\nb", $removed);
        self::assertSame([1, 1], [$a, $r]);
    }

    public function test_sync_merges_changes_into_edited_files_and_keeps_a_backup(): void
    {
        $this->generate($this->schema([['name' => 'name', 'type' => 'string']]));
        $model = 'app/Models/Product.php';
        $edited = str_replace('use HasFactory', "// my custom note\n    use HasFactory", $this->read($model));
        self::assertNotSame($this->read($model), $edited);
        file_put_contents("{$this->root}/{$model}", $edited);

        $schema = $this->schema([['name' => 'name', 'type' => 'string'], ['name' => 'price', 'type' => 'decimal']]);
        $this->artisan('crud:sync', ['schema' => $this->write($schema)])->assertExitCode(0)->expectsOutputToContain('merged')->expectsOutputToContain('Resolve the');

        $merged = $this->read($model);
        self::assertStringContainsString('<<<<<<< current', $merged);
        self::assertStringContainsString('// my custom note', $merged);
        self::assertStringContainsString("'price',", $merged);
        self::assertSame($edited, $this->read($model.'.bak'));
        // Files that only changed in the generator side are merged too, untouched ones are reported identical.
        self::assertStringContainsString('<<<<<<< current', $this->read('app/Http/Requests/StoreProductRequest.php'));
    }

    public function test_sync_does_not_merge_on_top_of_unresolved_markers_unless_forced(): void
    {
        $this->generate($this->schema([['name' => 'name', 'type' => 'string']]));
        $schema = $this->write($this->schema([['name' => 'name', 'type' => 'string'], ['name' => 'price', 'type' => 'decimal']]));

        $this->artisan('crud:sync', ['schema' => $schema, '--only' => ['app/Models/Product.php']])->assertExitCode(0);
        $first = $this->read('app/Models/Product.php');
        self::assertStringContainsString('<<<<<<< current', $first);

        $this->artisan('crud:sync', ['schema' => $schema, '--only' => ['app/Models/Product.php']])->assertExitCode(0)->expectsOutputToContain('skipped');
        self::assertSame($first, $this->read('app/Models/Product.php'));

        $this->artisan('crud:sync', ['schema' => $schema, '--only' => ['app/Models/Product.php'], '--force' => true])->assertExitCode(0)->expectsOutputToContain('overwritten');
        $forced = $this->read('app/Models/Product.php');
        self::assertStringNotContainsString('<<<<<<<', $forced);
        self::assertStringContainsString("'price',", $forced);
        // Only the requested file was touched.
        self::assertStringNotContainsString('price', $this->read('app/Http/Requests/StoreProductRequest.php'));
    }

    public function test_sync_creates_missing_files_and_leaves_identical_ones_alone(): void
    {
        $schema = $this->schema([['name' => 'name', 'type' => 'string']]);
        $this->generate($schema);
        unlink("{$this->root}/app/Http/Requests/UpdateProductRequest.php");
        $controller = $this->read('app/Http/Controllers/ProductsController.php');

        $this->artisan('crud:sync', ['schema' => $this->write($schema)])->assertExitCode(0)->expectsOutputToContain('created')->expectsOutputToContain('identical');

        self::assertFileExists("{$this->root}/app/Http/Requests/UpdateProductRequest.php");
        self::assertSame($controller, $this->read('app/Http/Controllers/ProductsController.php'));
        self::assertFileDoesNotExist("{$this->root}/app/Http/Controllers/ProductsController.php.bak");
    }

    public function test_the_schema_file_must_be_readable(): void
    {
        $this->artisan('crud:sync', ['schema' => $this->root.'/missing.json'])->assertExitCode(1);
        $this->artisan('crud:diff', ['schema' => $this->root.'/missing.json'])->assertExitCode(1);
        $this->artisan('crud:generate', ['schema' => $this->root.'/missing.json'])->assertExitCode(1);
    }
}
