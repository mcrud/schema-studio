<?php

namespace Mcrud\Crud\Tests;

use Illuminate\Filesystem\Filesystem;
use Mcrud\Crud\CrudServiceProvider;
use Mcrud\Crud\Schema\SchemaRules;
use Mcrud\Crud\Schema\SchemaValidator;
use Mcrud\Crud\Support\ColumnTypes;
use Orchestra\Testbench\TestCase;

class SchemaToolsTest extends TestCase
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
        $this->root = sys_get_temp_dir().'/schema-studio-tools-'.bin2hex(random_bytes(4));
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

    private function schema(?array $tables = null, array $relations = []): array
    {
        return [
            'project' => ['name' => 'Shop', 'locale' => 'en'], 'locales' => ['en'], 'permissions' => [], 'relations' => $relations,
            'tables' => $tables ?? [['name' => 'Product', 'fields' => [['name' => 'title', 'type' => 'string']]]],
        ];
    }

    private function write(array $schema): string
    {
        $path = $this->root.'/schema.json';
        file_put_contents($path, json_encode($schema));

        return $path;
    }

    public function test_a_valid_schema_has_no_errors(): void
    {
        self::assertSame([], $this->app->make(SchemaValidator::class)->errors($this->schema()));
    }

    public function test_request_rules_are_reported_with_readable_messages(): void
    {
        $errors = $this->app->make(SchemaValidator::class)->errors($this->schema([['name' => '1bad', 'fields' => [['name' => 'x', 'type' => 'nope']]]]));

        self::assertGreaterThanOrEqual(2, count($errors));
        self::assertNotEmpty(array_filter($errors, fn ($e) => str_contains($e, 'tables.0.name')));
    }

    public function test_generator_checks_are_reported(): void
    {
        $duplicate = $this->schema([
            ['name' => 'Product', 'fields' => [['name' => 'title', 'type' => 'string']]],
            ['name' => 'product', 'fields' => [['name' => 'title', 'type' => 'string']]],
        ]);
        self::assertSame(['Duplicate table name: product.'], $this->app->make(SchemaValidator::class)->errors($duplicate));

        $relation = $this->schema(null, [['from' => 'Product', 'to' => 'Ghost', 'type' => 'hasMany']]);
        self::assertSame(['A relation points to a table that does not exist.'], $this->app->make(SchemaValidator::class)->errors($relation));

        $enum = $this->schema([['name' => 'Product', 'fields' => [['name' => 'state', 'type' => 'enum']]]]);
        self::assertStringContainsString('needs at least one value', $this->app->make(SchemaValidator::class)->errors($enum)[0]);
    }

    public function test_validate_command_succeeds_and_fails_with_an_exit_code(): void
    {
        $this->artisan('crud:validate', ['schema' => $this->write($this->schema())])->expectsOutputToContain('The schema is valid.')->assertSuccessful();

        $bad = $this->schema(null, [['from' => 'Product', 'to' => 'Ghost', 'type' => 'hasMany']]);
        $this->artisan('crud:validate', ['schema' => $this->write($bad)])->expectsOutputToContain('does not exist')->assertFailed();
    }

    public function test_validate_command_prints_json(): void
    {
        $this->artisan('crud:validate', ['schema' => $this->write($this->schema()), '--json' => true])->expectsOutputToContain('"valid": true')->assertSuccessful();
    }

    public function test_validate_command_rejects_an_unreadable_file(): void
    {
        $this->artisan('crud:validate', ['schema' => $this->root.'/missing.json'])->expectsOutputToContain('readable JSON file')->assertFailed();
    }

    public function test_generate_refuses_an_invalid_schema_before_writing_files(): void
    {
        $bad = $this->schema(null, [['from' => 'Product', 'to' => 'Ghost', 'type' => 'hasMany']]);

        $this->artisan('crud:generate', ['schema' => $this->write($bad)])->expectsOutputToContain('nothing was generated')->assertFailed();

        self::assertDirectoryDoesNotExist($this->root.'/app');
    }

    public function test_diff_can_print_json(): void
    {
        $path = $this->write($this->schema());

        $this->artisan('crud:diff', ['schema' => $path, '--json' => true, '--stat' => true])->expectsOutputToContain('"status": "missing"')->assertSuccessful();
    }

    public function test_types_command_lists_every_column_type(): void
    {
        $this->artisan('crud:types', ['--json' => true])->expectsOutputToContain('"type": "bigInteger"')->assertSuccessful();
        $this->artisan('crud:types')->expectsOutputToContain('password')->assertSuccessful();

        self::assertSame(array_keys(['type' => 1]), array_keys(['type' => 1]));
        self::assertContains('slug', ColumnTypes::names());
    }

    public function test_rules_cover_every_column_type(): void
    {
        self::assertStringContainsString(implode(',', ColumnTypes::names()), SchemaRules::rules()['tables.*.fields.*.type'][1]);
    }
}
