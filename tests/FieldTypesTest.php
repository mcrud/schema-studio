<?php

namespace Mcrud\Crud\Tests;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Mcrud\Crud\CrudServiceProvider;
use Mcrud\Crud\Generators\CrudGenerator;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

class FieldTypesTest extends TestCase
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
        $this->root = sys_get_temp_dir().'/schema-studio-fields-'.bin2hex(random_bytes(4));
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
        $f = fn (string $n, string $t, array $x = []) => ['name' => $n, 'type' => $t] + $x;

        return $this->app->make(CrudGenerator::class)->generate($overrides + [
            'project' => ['name' => 'People', 'locale' => 'en'],
            'tables' => [['name' => 'Profile', 'fields' => [
                $f('name', 'string'), $f('password', 'password'), $f('avatar', 'image', ['nullable' => 1]), $f('resume', 'file', ['nullable' => 1]),
                $f('color', 'color'), $f('slug', 'slug', ['unique' => 1]),
            ]]],
            'relations' => [], 'locales' => ['en'], 'permissions' => [], 'dashboard' => 0, 'api' => 1,
        ]);
    }

    private function read(string $path): string
    {
        return (string) file_get_contents("{$this->root}/{$path}");
    }

    public function test_the_new_types_are_registered_and_map_to_string_columns(): void
    {
        foreach (['password', 'color', 'slug', 'image', 'file'] as $type) self::assertContains($type, \Mcrud\Crud\Support\ColumnTypes::names());

        $this->generate();

        $migration = (string) file_get_contents(glob("{$this->root}/database/migrations/*_create_profiles_table.php")[0]);
        foreach (["\$table->string('password')", "\$table->string('avatar')->nullable()", "\$table->string('color')", "\$table->string('slug')->unique()"] as $needle) self::assertStringContainsString($needle, $migration);
        $model = $this->read('app/Models/Profile.php');
        self::assertStringContainsString("'password' => 'hashed'", $model);
        self::assertStringContainsString("protected \$hidden = ['password'];", $model);
    }

    public function test_generated_code_hides_passwords_and_exposes_uploads_as_urls(): void
    {
        $this->generate();

        $resource = $this->read('app/Http/Resources/ProfileResource.php');
        self::assertStringNotContainsString("'password'", $resource);
        self::assertStringContainsString("asset('storage/'.\$this->avatar)", $resource);
        $controller = $this->read('app/Http/Controllers/ProfilesController.php');
        self::assertStringNotContainsString("'password', 'avatar'", $controller);
        self::assertStringContainsString("'id', 'name', 'avatar', 'resume', 'color', 'slug'", str_replace("'created_at', 'updated_at'", '', $controller));
        self::assertStringContainsString('multipart/form-data', $this->read('resources/views/profiles/form.blade.php'));
        self::assertStringNotContainsString('password', $this->read('resources/views/profiles/index.blade.php'));
        self::assertStringContainsString("'remove_avatar'", $this->read('app/Http/Controllers/ProfilesController.php') ?: '') ;
        foreach (['app/Http/Controllers/ProfilesController.php', 'app/Http/Controllers/Api/ProfilesController.php', 'tests/Feature/ProfileCrudTest.php', 'tests/Feature/ProfileApiTest.php'] as $path) {
            exec('php -l '.escapeshellarg("{$this->root}/{$path}").' 2>&1', $output, $code);
            self::assertSame(0, $code, $path.': '.implode("\n", $output));
        }
    }

    // The generated classes are loaded into the process, so this test runs on its own.
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_uploads_passwords_and_slugs_work_end_to_end(): void
    {
        if (!extension_loaded('gd')) $this->markTestSkipped('GD is needed to fake image uploads.');
        $this->generate(['api' => 0]);

        if (!class_exists('App\\Http\\Controllers\\Controller')) eval('namespace App\\Http\\Controllers; abstract class Controller {}');
        foreach (['app/Models/*.php', 'app/Http/Requests/*.php', 'app/Http/Controllers/*Controller.php', 'database/factories/*.php'] as $glob) {
            foreach (glob("{$this->root}/{$glob}") as $file) if (!str_ends_with($file, '/Controller.php')) require_once $file;
        }
        $this->app['view']->addLocation($this->root.'/resources/views');
        $this->app['router']->middleware('web')->group($this->root.'/routes/web.php');
        $this->loadMigrationsFrom($this->root.'/database/migrations');
        $this->artisan('migrate')->assertExitCode(0);
        Storage::fake('public');

        $row = ['name' => 'Ann Lee', 'password' => 'secret-pass-1', 'color' => '#112233', 'slug' => ''];
        $this->post('/profiles', $row + ['avatar' => UploadedFile::fake()->image('me.jpg'), 'resume' => UploadedFile::fake()->create('cv.pdf', 20)])->assertRedirect('/profiles');

        $profile = \App\Models\Profile::first();
        self::assertSame('ann-lee', $profile->slug);
        self::assertTrue(Hash::check('secret-pass-1', $profile->password));
        self::assertNotSame('secret-pass-1', $profile->getRawOriginal('password'));
        Storage::disk('public')->assertExists($profile->avatar);
        Storage::disk('public')->assertExists($profile->resume);
        self::assertArrayNotHasKey('password', $profile->toArray());

        $this->get('/profiles')->assertOk()->assertSee('#112233')->assertSee('storage/'.$profile->avatar, false)->assertDontSee($profile->getRawOriginal('password'));
        $this->get("/profiles/{$profile->id}/edit")->assertOk()->assertSee('type="file"', false)->assertSee('type="color"', false);

        // A second record with the same name gets a unique slug.
        $this->post('/profiles', $row)->assertRedirect('/profiles');
        self::assertSame('ann-lee-2', \App\Models\Profile::orderBy('id')->get()[1]->slug);

        // Blank password and no upload keep the stored values; a blank slug is regenerated from the new name, ignoring the record itself.
        $oldHash = $profile->getRawOriginal('password');
        $oldAvatar = $profile->avatar;
        $this->put("/profiles/{$profile->id}", ['name' => 'Ann L.', 'password' => '', 'color' => '#445566', 'slug' => ''])->assertRedirect('/profiles');
        $profile->refresh();
        self::assertSame($oldHash, $profile->getRawOriginal('password'));
        self::assertSame($oldAvatar, $profile->avatar);
        self::assertSame('ann-l', $profile->slug);
        self::assertSame('#445566', $profile->color);

        // Replacing an upload deletes the previous file, removing it clears the column.
        $this->put("/profiles/{$profile->id}", ['name' => 'Ann L.', 'color' => '#445566', 'slug' => 'ann-lee', 'avatar' => UploadedFile::fake()->image('new.jpg')])->assertRedirect('/profiles');
        $profile->refresh();
        Storage::disk('public')->assertMissing($oldAvatar);
        Storage::disk('public')->assertExists($profile->avatar);
        $current = $profile->avatar;
        $this->put("/profiles/{$profile->id}", ['name' => 'Ann L.', 'color' => '#445566', 'slug' => 'ann-lee', 'remove_avatar' => 1])->assertRedirect('/profiles');
        self::assertNull($profile->refresh()->avatar);
        Storage::disk('public')->assertMissing($current);

        // Validation: short password, bad colour and a non-image upload.
        $this->post('/profiles', ['name' => 'X', 'password' => 'short', 'color' => 'red', 'slug' => 'x', 'avatar' => UploadedFile::fake()->create('a.pdf', 5)])
            ->assertSessionHasErrors(['password', 'color', 'avatar']);
    }
}
