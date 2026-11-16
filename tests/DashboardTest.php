<?php

namespace Mcrud\Crud\Tests;

use Illuminate\Filesystem\Filesystem;
use Mcrud\Crud\CrudServiceProvider;
use Mcrud\Crud\Generators\CrudGenerator;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

class DashboardTest extends TestCase
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
        $this->root = sys_get_temp_dir().'/schema-studio-dash-'.bin2hex(random_bytes(4));
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

    private function generate(array $overrides = []): array
    {
        $data = $overrides + [
            'project' => ['name' => 'Workshop', 'locale' => 'en'],
            'tables' => [
                ['name' => 'Sprocket', 'fields' => [['name' => 'name', 'type' => 'string']]],
                ['name' => 'Gizmo', 'timestamps' => 0, 'fields' => [['name' => 'title', 'type' => 'string']]],
            ],
            'relations' => [], 'locales' => ['en'], 'permissions' => [], 'dashboard' => 1,
        ];

        return $this->app->make(CrudGenerator::class)->generate($data);
    }

    private function read(string $path): string
    {
        return (string) file_get_contents("{$this->root}/{$path}");
    }

    public function test_admin_layout_is_the_default_and_the_dashboard_is_first_in_the_sidebar(): void
    {
        $report = $this->generate();

        self::assertTrue($report['dashboard']);
        $layout = $this->read('resources/views/layouts/app.blade.php');
        foreach (['id="sidebar"', "darkMode: 'class'", 'id="theme"', "@stack('scripts')", 'rtl'] as $needle) self::assertStringContainsString($needle, $layout);
        $nav = $this->read('resources/views/layouts/nav.blade.php');
        self::assertLessThan(strpos($nav, "route('sprockets.index')"), strpos($nav, "route('dashboard')"));
        self::assertStringContainsString("routeIs('sprockets.*')", $nav);
    }

    public function test_simple_layout_can_be_selected_and_still_supports_dashboard_scripts(): void
    {
        $this->generate(['layout' => 'simple']);

        $layout = $this->read('resources/views/layouts/app.blade.php');
        self::assertStringNotContainsString('id="sidebar"', $layout);
        self::assertStringContainsString("@stack('scripts')", $layout);
    }

    public function test_dashboard_controller_lists_every_table_and_honours_view_permissions(): void
    {
        $this->generate(['permissions' => ['view']]);

        $controller = $this->read('app/Http/Controllers/DashboardController.php');
        self::assertStringContainsString("'model' => Sprocket::class", $controller);
        self::assertStringContainsString("'timestamps' => false", $controller);
        self::assertStringContainsString("Gate::allows('viewAny'", $controller);
        exec('php -l '.escapeshellarg("{$this->root}/app/Http/Controllers/DashboardController.php").' 2>&1', $output, $code);
        self::assertSame(0, $code, implode("\n", $output));
    }

    // The generated DashboardController class name is shared with other end-to-end tests, so it needs its own process.
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_dashboard_renders_stats_chart_data_and_latest_records(): void
    {
        $this->generate();

        if (!class_exists('App\\Http\\Controllers\\Controller')) eval('namespace App\\Http\\Controllers; abstract class Controller {}');
        foreach (['app/Models/Sprocket.php', 'app/Models/Gizmo.php', 'app/Http/Controllers/DashboardController.php'] as $file) require_once "{$this->root}/{$file}";
        $this->app['view']->addLocation($this->root.'/resources/views');
        $this->app['router']->middleware('web')->group($this->root.'/routes/web.php');
        $this->loadMigrationsFrom($this->root.'/database/migrations');
        $this->artisan('migrate')->assertExitCode(0);

        \App\Models\Sprocket::create(['name' => 'Cog']);
        \App\Models\Gizmo::create(['title' => 'Lever']);

        $this->get('/dashboard')->assertOk()
            ->assertSee('id="growth"', false)
            ->assertSee('Latest Sprockets')
            ->assertSee('Cog')
            ->assertSee('Lever')
            ->assertSee('New records');
    }
}
