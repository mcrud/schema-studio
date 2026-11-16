<?php

namespace Mcrud\Crud\Tests;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Validation\ValidationException;
use Mcrud\Crud\CrudServiceProvider;
use Mcrud\Crud\Generators\CrudGenerator;
use Orchestra\Testbench\TestCase;

class PermissionDriverTest extends TestCase
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
        $this->root = sys_get_temp_dir().'/schema-studio-perm-'.bin2hex(random_bytes(4));
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
        return $overrides + [
            'project' => ['name' => 'Workshop', 'locale' => 'en'],
            'tables' => [['name' => 'Sprocket', 'fields' => [['name' => 'name', 'type' => 'string']]]],
            'relations' => [], 'locales' => ['en'], 'permissions' => ['view', 'create', 'update', 'delete'], 'roles' => 'admin', 'dashboard' => 0,
        ];
    }

    /** A generator that behaves as if spatie/laravel-permission were not installed. */
    private function withoutSpatie(): CrudGenerator
    {
        return new class(new Filesystem) extends CrudGenerator {
            protected function spatieInstalled(): bool { return false; }
        };
    }

    public function test_the_driver_is_spatie_when_installed_and_auto(): void
    {
        self::assertSame('spatie', $this->app->make(CrudGenerator::class)->permissionsDriver());
    }

    public function test_none_generates_no_policies_gates_or_seeders_and_only_requires_login(): void
    {
        config(['crud.permissions' => 'none']);

        $report = $this->app->make(CrudGenerator::class)->generate($this->payload(['auth' => 1]));

        self::assertSame([], $report['seeders'] === [] ? [] : array_diff($report['seeders'], ['AdminUserSeeder']));
        self::assertFileDoesNotExist("{$this->root}/app/Policies/SprocketPolicy.php");
        self::assertStringNotContainsString('Gate::authorize', (string) file_get_contents("{$this->root}/app/Http/Controllers/SprocketsController.php"));
        self::assertFileDoesNotExist("{$this->root}/database/seeders/SprocketPermissionSeeder.php");
        self::assertFileDoesNotExist("{$this->root}/app/Http/Controllers/Admin/UserManagementController.php");
        self::assertStringNotContainsString('Spatie', (string) file_get_contents("{$this->root}/database/seeders/AdminUserSeeder.php"));
        self::assertStringContainsString("Route::resource('sprockets', \\App\\Http\\Controllers\\SprocketsController::class)->middleware('auth');", (string) file_get_contents("{$this->root}/routes/web.php"));
    }

    public function test_auto_falls_back_to_none_when_spatie_is_not_installed(): void
    {
        $generator = $this->withoutSpatie();

        self::assertSame('none', $generator->permissionsDriver());
        $generator->generate($this->payload());
        self::assertFileExists("{$this->root}/app/Http/Controllers/SprocketsController.php");
        self::assertFileDoesNotExist("{$this->root}/app/Policies/SprocketPolicy.php");
    }

    public function test_requiring_spatie_explicitly_fails_clearly_when_it_is_missing(): void
    {
        config(['crud.permissions' => 'spatie']);
        $generator = $this->withoutSpatie();

        self::assertSame('missing', $generator->permissionsDriver());
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('spatie/laravel-permission');
        $generator->generate($this->payload());
    }

    public function test_the_studio_disables_permission_choices_when_the_driver_is_none(): void
    {
        config(['crud.permissions' => 'none']);

        $this->get('/crud-generator')->assertOk()
            ->assertSee('Disabled: install spatie/laravel-permission')
            ->assertDontSee('value="view" checked', false);
    }

    public function test_the_studio_keeps_permission_choices_with_spatie(): void
    {
        $page = $this->get('/crud-generator')->assertOk();
        $page->assertSee('Assigned to each role below.')->assertSee('name="permissions[]" value="view" checked', false);
    }
}
