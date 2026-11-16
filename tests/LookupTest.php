<?php

namespace Mcrud\Crud\Tests;

use Illuminate\Filesystem\Filesystem;
use Mcrud\Crud\CrudServiceProvider;
use Mcrud\Crud\Generators\CrudGenerator;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

class LookupTest extends TestCase
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
        $app['config']->set('crud.lookup_threshold', 2);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/schema-studio-lookup-'.bin2hex(random_bytes(4));
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

    private function generate(): void
    {
        $this->app->make(CrudGenerator::class)->generate([
            'project' => ['name' => 'Shelf', 'locale' => 'en'],
            'tables' => [
                ['name' => 'Genre', 'fields' => [['name' => 'name', 'type' => 'string']]],
                ['name' => 'Book', 'fields' => [['name' => 'title', 'type' => 'string']]],
            ],
            'relations' => [['from' => 'Genre', 'to' => 'Book', 'type' => 'hasMany']],
            'locales' => ['en'], 'permissions' => [], 'dashboard' => 0,
        ]);
    }

    private function read(string $path): string
    {
        return (string) file_get_contents("{$this->root}/{$path}");
    }

    public function test_generated_code_switches_to_a_lookup_when_the_related_table_is_big(): void
    {
        $this->generate();

        $controller = $this->read('app/Http/Controllers/BooksController.php');
        self::assertStringContainsString("Genre::query()->count() > 2 ? null :", $controller);
        self::assertStringContainsString('function lookup(', $controller);
        self::assertStringContainsString("'genre_id' => [Genre::class, 'name']", $controller);
        self::assertStringContainsString("books.lookup", $this->read('routes/web.php'));
        $form = $this->read('resources/views/books/form.blade.php');
        self::assertStringContainsString('data-lookup', $form);
        self::assertStringContainsString("document.querySelectorAll('[data-lookup]')", $form);
        self::assertStringContainsString("@if (\$related['genre_id'] !== null)", $this->read('resources/views/books/index.blade.php'));
        self::assertStringNotContainsString('function lookup(', $this->read('app/Http/Controllers/GenresController.php'));
        self::assertStringNotContainsString('data-lookup', $this->read('resources/views/genres/form.blade.php'));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_the_form_uses_a_select_for_small_tables_and_a_search_box_for_big_ones(): void
    {
        $this->generate();

        if (!class_exists('App\\Http\\Controllers\\Controller')) eval('namespace App\\Http\\Controllers; abstract class Controller {}');
        foreach (['app/Models/*.php', 'app/Http/Requests/*.php', 'app/Http/Controllers/*Controller.php', 'database/factories/*.php'] as $glob) {
            foreach (glob("{$this->root}/{$glob}") as $file) if (!str_ends_with($file, '/Controller.php')) require_once $file;
        }
        $this->app['view']->addLocation($this->root.'/resources/views');
        $this->app['router']->middleware('web')->group($this->root.'/routes/web.php');
        $this->loadMigrationsFrom($this->root.'/database/migrations');
        $this->artisan('migrate')->assertExitCode(0);

        $scifi = \App\Models\Genre::create(['name' => 'Science fiction']);
        \App\Models\Genre::create(['name' => 'Cooking']);

        // Two genres is within the threshold, so the classic dropdown is used.
        $this->get('/books/create')->assertOk()->assertSee('<select id="genre_id"', false)->assertDontSee('data-lookup="', false);
        $this->get('/books')->assertOk()->assertSee('name="genre_id"', false);

        \App\Models\Genre::create(['name' => 'Cookery']);
        \App\Models\Genre::create(['name' => 'History']);

        $this->get('/books/create')->assertOk()->assertDontSee('<select id="genre_id"', false)->assertSee('data-lookup="'.url('/books/lookup/genre_id').'"', false);
        $this->get('/books')->assertOk()->assertDontSee('name="genre_id"', false);

        $book = \App\Models\Book::create(['genre_id' => $scifi->id, 'title' => 'Dune']);
        $this->get("/books/{$book->id}/edit")->assertOk()->assertSee('value="Science fiction"', false)->assertSee('name="genre_id"', false);

        // The endpoint searches, caps results and rejects unknown fields.
        $this->getJson('/books/lookup/genre_id?q=Cook')->assertOk()->assertJsonCount(2)->assertJsonFragment(['label' => 'Cooking'])->assertJsonMissing(['label' => 'History']);
        $this->getJson('/books/lookup/genre_id')->assertOk()->assertJsonCount(4);
        $this->getJson('/books/lookup/genre_id?q=%25')->assertOk()->assertJsonCount(0);
        $this->getJson('/books/lookup/title')->assertNotFound();

        // Saving through the hidden field works like the dropdown did.
        $this->post('/books', ['genre_id' => $scifi->id, 'title' => 'Hyperion'])->assertRedirect('/books');
        self::assertSame(2, \App\Models\Book::where('genre_id', $scifi->id)->count());
    }
}
