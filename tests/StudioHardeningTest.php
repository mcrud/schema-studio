<?php

namespace Mcrud\Crud\Tests;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Gate;
use Mcrud\Crud\CrudServiceProvider;
use Orchestra\Testbench\TestCase;

class StudioHardeningTest extends TestCase
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
        $this->root = sys_get_temp_dir().'/schema-studio-hard-'.bin2hex(random_bytes(4));
        (new Filesystem)->makeDirectory($this->root.'/routes', 0777, true);
        $this->app->setBasePath($this->root);
        $this->app->useStoragePath($this->root.'/storage');
        $this->app->usePublicPath($this->root.'/public');
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->root);
        parent::tearDown();
    }

    public function test_the_studio_is_open_in_local_and_testing_without_an_ability(): void
    {
        $this->get('/crud-generator')->assertOk();
        $this->app['env'] = 'local';
        $this->get('/crud-generator')->assertOk();
    }

    public function test_outside_local_environments_the_studio_is_locked_until_an_ability_is_configured(): void
    {
        $this->app['env'] = 'staging';
        // CSRF checks are only skipped in the testing environment; this test is about the studio lock.
        foreach (['ValidateCsrfToken', 'VerifyCsrfToken', 'PreventRequestForgery'] as $csrf) {
            if (class_exists("Illuminate\\Foundation\\Http\\Middleware\\{$csrf}")) $this->withoutMiddleware("Illuminate\\Foundation\\Http\\Middleware\\{$csrf}");
        }

        $this->get('/crud-generator')->assertForbidden();
        $this->getJson('/crud-generator/introspect')->assertForbidden();
        $this->postJson('/crud-generator/preview', [])->assertForbidden();
        $this->post('/crud-generator', [])->assertForbidden();

        config(['crud.ability' => 'use-studio']);
        Gate::define('use-studio', fn ($user = null) => false);
        $this->get('/crud-generator')->assertForbidden();

        Gate::define('use-studio', fn ($user = null) => true);
        $this->get('/crud-generator')->assertOk();
    }

    public function test_the_lock_can_be_lifted_explicitly(): void
    {
        $this->app['env'] = 'staging';
        config(['crud.require_ability' => false]);

        $this->get('/crud-generator')->assertOk();
    }

    public function test_preview_generate_and_introspect_are_rate_limited(): void
    {
        config(['crud.rate_limit' => 2]);

        $this->postJson('/crud-generator/preview', [])->assertStatus(422);
        $this->postJson('/crud-generator/preview', [])->assertStatus(422);
        $this->postJson('/crud-generator/preview', [])->assertStatus(429);
        // The three endpoints share one budget, but the page itself and its assets are not counted.
        $this->getJson('/crud-generator/introspect')->assertStatus(429);
        $this->get('/crud-generator')->assertOk();
        $this->get('/crud-generator/assets/studio.js')->assertOk();
    }

    public function test_assets_are_served_by_the_package_until_matching_copies_are_published(): void
    {
        $this->get('/crud-generator')->assertOk()
            ->assertSee('/crud-generator/assets/studio.js', false)->assertSee('/crud-generator/assets/studio-i18n.js', false)->assertDontSee('vendor/crud', false);

        (new Filesystem)->ensureDirectoryExists($this->root.'/public/vendor/crud');
        copy(__DIR__.'/../resources/js/studio.js', $this->root.'/public/vendor/crud/studio.js');
        copy(__DIR__.'/../resources/js/studio-i18n.js', $this->root.'/public/vendor/crud/studio-i18n.js');
        $this->get('/crud-generator')->assertOk()->assertSee('/vendor/crud/studio.js?v=', false)->assertSee('/vendor/crud/studio-i18n.js?v=', false)->assertDontSee('/crud-generator/assets/studio.js', false);

        // A copy left behind by an older release is ignored instead of being served stale.
        file_put_contents($this->root.'/public/vendor/crud/studio.js', '/* old release */');
        $this->get('/crud-generator')->assertOk()->assertSee('/crud-generator/assets/studio.js', false)->assertSee('/vendor/crud/studio-i18n.js?v=', false);
    }

    public function test_the_asset_route_only_serves_known_files_and_the_tag_is_publishable(): void
    {
        $this->get('/crud-generator/assets/studio-i18n.js')->assertOk()->assertHeader('Content-Type', 'application/javascript; charset=utf-8');
        $this->get('/crud-generator/assets/ar.json')->assertNotFound();
        $this->get('/crud-generator/assets/..%2Fcomposer.json')->assertNotFound();

        $paths = \Illuminate\Support\ServiceProvider::pathsToPublish(CrudServiceProvider::class, 'crud-assets');
        self::assertEqualsCanonicalizing(['studio.js', 'studio-i18n.js'], array_map('basename', $paths));
        self::assertEqualsCanonicalizing(['studio.js', 'studio-i18n.js'], array_map('basename', array_keys($paths)));
    }

    public function test_the_interface_language_follows_the_config_and_the_app_locale(): void
    {
        $page = $this->get('/crud-generator')->assertOk();
        $page->assertSee('id="lang-toggle"', false)->assertSee('locale:"en"', false)->assertSee('"Add table"', false);

        config(['crud.studio_locale' => 'ar']);
        $this->get('/crud-generator')->assertSee('locale:"ar"', false);

        config(['crud.studio_locale' => 'auto']);
        app()->setLocale('ar');
        $this->get('/crud-generator')->assertSee('locale:"ar"', false);
    }
}
