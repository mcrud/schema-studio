<?php

namespace Mcrud\Crud\Tests;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Mcrud\Crud\CrudServiceProvider;
use Mcrud\Crud\Generators\CrudGenerator;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

class AuthGenerationTest extends TestCase
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
        $app['config']->set('auth.providers.users.model', 'App\\Models\\User');
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/schema-studio-auth-'.bin2hex(random_bytes(4));
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
            'tables' => [['name' => 'Sprocket', 'fields' => [['name' => 'name', 'type' => 'string']]]],
            'relations' => [], 'locales' => ['en'], 'permissions' => [], 'dashboard' => 0, 'auth' => 1,
        ];

        return $this->app->make(CrudGenerator::class)->generate($data);
    }

    private function read(string $path): string
    {
        return (string) file_get_contents("{$this->root}/{$path}");
    }

    private function allPhp(): array
    {
        $found = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if (str_ends_with($file->getPathname(), '.php')) $found[] = $file->getPathname();
        }

        return $found;
    }

    public function test_auth_is_off_unless_requested(): void
    {
        $report = $this->generate(['auth' => 0]);

        self::assertFalse($report['auth']);
        self::assertFileDoesNotExist("{$this->root}/app/Http/Controllers/Auth/AuthController.php");
        self::assertStringNotContainsString("middleware('auth')", $this->read('routes/web.php'));
    }

    public function test_it_generates_login_profile_and_protects_every_generated_route(): void
    {
        $report = $this->generate(['dashboard' => 1]);

        self::assertTrue($report['auth']);
        self::assertSame('AdminUserSeeder', end($report['seeders']));
        foreach ([
            'app/Http/Controllers/Auth/AuthController.php', 'app/Http/Controllers/Auth/PasswordController.php', 'app/Http/Controllers/ProfileController.php',
            'resources/views/layouts/guest.blade.php', 'resources/views/auth/login.blade.php', 'resources/views/profile/edit.blade.php',
            'database/seeders/AdminUserSeeder.php', 'tests/Feature/AuthTest.php',
        ] as $path) self::assertFileExists("{$this->root}/{$path}", $path);

        $routes = $this->read('routes/web.php');
        self::assertStringContainsString("->name('login')", $routes);
        self::assertStringContainsString("->middleware('throttle:5,1')", $routes);
        self::assertStringContainsString("Route::resource('sprockets', \\App\\Http\\Controllers\\SprocketsController::class)->middleware('auth');", $routes);
        self::assertStringContainsString("->name('dashboard')->middleware('auth');", $routes);
        self::assertStringNotContainsString("name('register')", $routes);
        self::assertStringContainsString('test_public_registration_is_disabled', $this->read('tests/Feature/AuthTest.php'));
        self::assertFileDoesNotExist("{$this->root}/app/Http/Controllers/Admin/UserManagementController.php");
        self::assertStringNotContainsString('Spatie', $this->read('database/seeders/AdminUserSeeder.php'));

        foreach ($this->allPhp() as $file) {
            exec('php -l '.escapeshellarg($file).' 2>&1', $output, $code);
            self::assertSame(0, $code, $file.': '.implode("\n", $output));
        }
    }

    public function test_public_registration_is_opt_in(): void
    {
        $this->generate(['auth_register' => 1]);

        self::assertStringContainsString("->name('register')", $this->read('routes/web.php'));
        self::assertStringContainsString('test_user_can_register', $this->read('tests/Feature/AuthTest.php'));
    }

    public function test_permissions_add_user_and_role_management_guarded_by_gates(): void
    {
        $this->generate(['permissions' => ['view', 'create']]);

        $routes = $this->read('routes/web.php');
        self::assertStringContainsString("Route::middleware('can:users.manage')", $routes);
        self::assertStringContainsString("Route::middleware('can:roles.manage')", $routes);
        $seeder = $this->read('database/seeders/AdminUserSeeder.php');
        self::assertStringContainsString("Role::findOrCreate('super-admin')", $seeder);
        self::assertStringContainsString("Permission::findOrCreate('users.manage')", $seeder);
        $nav = $this->read('resources/views/layouts/nav.blade.php');
        self::assertStringContainsString("@can('users.manage')", $nav);
        foreach (['resources/views/admin/users/index.blade.php', 'resources/views/admin/roles/form.blade.php', 'app/Http/Controllers/Admin/RoleManagementController.php'] as $path) self::assertFileExists("{$this->root}/{$path}");
        foreach ($this->allPhp() as $file) {
            exec('php -l '.escapeshellarg($file).' 2>&1', $output, $code);
            self::assertSame(0, $code, $file.': '.implode("\n", $output));
        }
    }

    public function test_management_is_skipped_when_the_designer_already_has_a_users_table(): void
    {
        $this->generate([
            'permissions' => ['view'],
            'tables' => [['name' => 'User', 'fields' => [['name' => 'name', 'type' => 'string']]]],
        ]);

        self::assertFileDoesNotExist("{$this->root}/app/Http/Controllers/Admin/UserManagementController.php");
        self::assertStringNotContainsString("Route::middleware('can:users.manage')", $this->read('routes/web.php'));
    }

    public function test_an_existing_login_route_prevents_generating_a_second_auth_stack(): void
    {
        file_put_contents($this->root.'/routes/web.php', "<?php\n\nuse Illuminate\\Support\\Facades\\Route;\n\nRoute::get('/login', fn () => 'mine')->name('login');\n");

        $report = $this->generate();

        self::assertFalse($report['auth']);
        self::assertFileDoesNotExist("{$this->root}/app/Http/Controllers/Auth/AuthController.php");
        self::assertSame(1, substr_count($this->read('routes/web.php'), "->name('login')"));
    }

    public function test_preview_lists_the_auth_files_without_writing_anything(): void
    {
        $preview = $this->app->make(CrudGenerator::class)->preview([
            'project' => ['name' => 'Workshop', 'locale' => 'en'], 'tables' => [['name' => 'Sprocket', 'fields' => [['name' => 'name', 'type' => 'string']]]],
            'relations' => [], 'locales' => ['en'], 'permissions' => [], 'auth' => 1,
        ]);

        self::assertContains('app/Http/Controllers/Auth/AuthController.php', $preview['files']);
        self::assertFileDoesNotExist("{$this->root}/app/Http/Controllers/Auth/AuthController.php");
    }

    public function test_email_verification_is_opt_in_and_guards_generated_routes(): void
    {
        $report = $this->generate(['dashboard' => 1, 'auth_verify' => 1, 'auth_register' => 1]);

        self::assertTrue($report['auth']);
        $routes = $this->read('routes/web.php');
        foreach (['verification.notice', 'verification.verify', 'verification.send', "->middleware(['signed', 'throttle:6,1'])"] as $needle) self::assertStringContainsString($needle, $routes);
        self::assertStringContainsString("Route::resource('sprockets', \\App\\Http\\Controllers\\SprocketsController::class)->middleware(['auth', 'verified']);", $routes);
        self::assertStringContainsString("->name('dashboard')->middleware(['auth', 'verified']);", $routes);
        self::assertStringContainsString("'email_verified_at' => now()", $this->read('database/seeders/AdminUserSeeder.php'));
        self::assertStringContainsString('test_unverified_users_can_reach_the_verification_notice', $this->read('tests/Feature/AuthTest.php'));
        self::assertStringContainsString('event(new Registered($user))', $this->read('app/Http/Controllers/Auth/AuthController.php'));
        foreach ($this->allPhp() as $file) {
            exec('php -l '.escapeshellarg($file).' 2>&1', $output, $code);
            self::assertSame(0, $code, $file.': '.implode("\n", $output));
        }
    }

    public function test_without_the_option_there_is_no_verification(): void
    {
        $this->generate();

        self::assertStringNotContainsString('verification.', $this->read('routes/web.php'));
        self::assertStringNotContainsString("'verified'", $this->read('routes/web.php'));
        self::assertFileDoesNotExist("{$this->root}/app/Http/Controllers/Auth/VerifyEmailController.php");
        self::assertStringNotContainsString('email_verified_at', $this->read('database/seeders/AdminUserSeeder.php'));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_email_verification_works_end_to_end(): void
    {
        $this->generate(['auth_verify' => 1]);

        if (!class_exists('App\\Http\\Controllers\\Controller')) eval('namespace App\\Http\\Controllers; abstract class Controller {}');
        eval('namespace App\\Models; class User extends \\Illuminate\\Foundation\\Auth\\User implements \\Illuminate\\Contracts\\Auth\\MustVerifyEmail { use \\Illuminate\\Notifications\\Notifiable; protected $guarded = []; }');
        foreach (['app/Http/Controllers/Auth/AuthController.php', 'app/Http/Controllers/Auth/VerifyEmailController.php', 'app/Http/Controllers/ProfileController.php', 'app/Http/Controllers/SprocketsController.php'] as $file) require_once "{$this->root}/{$file}";
        $this->app['view']->addLocation($this->root.'/resources/views');
        $this->app['router']->middleware('web')->group($this->root.'/routes/web.php');

        Schema::create('users', function ($table): void {
            $table->id(); $table->string('name'); $table->string('email')->unique(); $table->timestamp('email_verified_at')->nullable(); $table->string('password'); $table->rememberToken(); $table->timestamps();
        });
        $user = \App\Models\User::create(['name' => 'Pat', 'email' => 'pat@example.com', 'password' => Hash::make('secret-pass-123')]);
        $this->actingAs($user);

        // Unverified users are held at the notice page, but can still reach their profile.
        $this->get('/sprockets')->assertRedirect(route('verification.notice'));
        $this->get('/email/verify')->assertOk()->assertSee('Verify your email address');
        $this->get('/profile')->assertOk();

        Notification::fake();
        $this->post('/email/verification-notification')->assertSessionHas('status');
        Notification::assertSentTo($user, \Illuminate\Auth\Notifications\VerifyEmail::class);

        // Only a correctly signed link verifies the address.
        $this->get("/email/verify/{$user->getKey()}/".sha1($user->getEmailForVerification()))->assertForbidden();
        self::assertFalse($user->fresh()->hasVerifiedEmail());
        $link = \Illuminate\Support\Facades\URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), ['id' => $user->getKey(), 'hash' => sha1($user->getEmailForVerification())]);
        $this->get($link)->assertRedirect('/');
        self::assertTrue($user->fresh()->hasVerifiedEmail());

        $this->get('/email/verify')->assertRedirect('/');
    }

    // Boots the generated routes, so the generated class names must not leak into other tests.
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_generated_auth_works_end_to_end(): void
    {
        $this->generate();

        if (!class_exists('App\\Http\\Controllers\\Controller')) eval('namespace App\\Http\\Controllers; abstract class Controller {}');
        eval('namespace App\\Models; class User extends \\Illuminate\\Foundation\\Auth\\User { use \\Illuminate\\Notifications\\Notifiable; protected $guarded = []; }');
        foreach (['app/Http/Controllers/Auth/AuthController.php', 'app/Http/Controllers/Auth/PasswordController.php', 'app/Http/Controllers/ProfileController.php', 'app/Http/Controllers/SprocketsController.php'] as $file) require_once "{$this->root}/{$file}";
        $this->app['view']->addLocation($this->root.'/resources/views');
        $this->app['router']->middleware('web')->group($this->root.'/routes/web.php');

        Schema::create('users', function ($table): void {
            $table->id(); $table->string('name'); $table->string('email')->unique(); $table->string('password'); $table->rememberToken(); $table->timestamps();
        });
        Schema::create('password_reset_tokens', function ($table): void {
            $table->string('email')->primary(); $table->string('token'); $table->timestamp('created_at')->nullable();
        });
        $user = \App\Models\User::create(['name' => 'Pat', 'email' => 'pat@example.com', 'password' => Hash::make('secret-pass-123')]);

        // Guests are sent to the login page; registration was not requested.
        $this->get('/sprockets')->assertRedirect(route('login'));
        $this->get('/login')->assertOk()->assertSee('Sign in')->assertSee('Forgot your password?');
        $this->get('/register')->assertNotFound();
        $this->get('/profile')->assertRedirect(route('login'));

        $this->post('/login', ['email' => 'pat@example.com', 'password' => 'wrong'])->assertSessionHasErrors('email');
        $this->assertGuest();
        // The guest visit to /profile above is remembered, so login returns there instead of the home page.
        $this->post('/login', ['email' => 'pat@example.com', 'password' => 'secret-pass-123'])->assertRedirect(url('/profile'));
        $this->assertAuthenticated();

        $this->get('/profile')->assertOk()->assertSee('Change password')->assertSee('pat@example.com');
        $this->put('/profile', ['name' => 'Patricia', 'email' => 'pat@example.com'])->assertSessionHasNoErrors();
        self::assertSame('Patricia', $user->fresh()->name);
        $this->put('/profile/password', ['current_password' => 'nope', 'password' => 'another-pass-456', 'password_confirmation' => 'another-pass-456'])->assertSessionHasErrors('current_password');

        $this->post('/logout')->assertRedirect(route('login'));
        $this->assertGuest();

        // Password reset: the same response whether or not the address exists, and the emailed token works once.
        Notification::fake();
        $this->post('/forgot-password', ['email' => 'nobody@example.com'])->assertSessionHas('status');
        Notification::assertNothingSent();
        $this->post('/forgot-password', ['email' => 'pat@example.com'])->assertSessionHas('status');
        $token = null;
        Notification::assertSentTo($user, ResetPassword::class, function ($notification) use (&$token): bool { $token = $notification->token; return true; });
        $this->post('/reset-password', ['token' => $token, 'email' => 'pat@example.com', 'password' => 'brand-new-pass-1', 'password_confirmation' => 'brand-new-pass-1'])->assertRedirect(route('login'));
        self::assertTrue(Hash::check('brand-new-pass-1', $user->fresh()->password));
        $this->post('/reset-password', ['token' => $token, 'email' => 'pat@example.com', 'password' => 'yet-another-pass-2', 'password_confirmation' => 'yet-another-pass-2'])->assertSessionHasErrors('email');
    }
}
