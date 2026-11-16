<?php

namespace Mcrud\Crud\Tests;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Mcrud\Crud\CrudServiceProvider;
use Mcrud\Crud\Generators\CrudGenerator;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

/** Soft-delete screens, date-range filters, column toggles and CSV import on the generated index pages. */
class ListToolsTest extends TestCase
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
        $this->root = sys_get_temp_dir().'/schema-studio-list-'.bin2hex(random_bytes(4));
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
        return $this->app->make(CrudGenerator::class)->generate($overrides + [
            'project' => ['name' => 'Newsroom', 'locale' => 'en'],
            'tables' => [
                ['name' => 'Article', 'soft_deletes' => 1, 'fields' => [['name' => 'name', 'type' => 'string'], ['name' => 'published', 'type' => 'date'], ['name' => 'views', 'type' => 'integer']]],
                ['name' => 'Note', 'fields' => [['name' => 'body', 'type' => 'string']]],
            ],
            'relations' => [], 'locales' => ['en', 'ar'], 'permissions' => [], 'dashboard' => 0,
        ]);
    }

    private function read(string $path): string
    {
        return (string) file_get_contents("{$this->root}/{$path}");
    }

    public function test_soft_delete_screens_are_only_generated_for_tables_that_use_them(): void
    {
        $this->generate();

        $routes = $this->read('routes/web.php');
        foreach (["articles.restore", "articles.force-delete", "articles.bulk-restore", "articles.import"] as $name) self::assertStringContainsString($name, $routes);
        self::assertStringNotContainsString('notes.restore', $routes);
        self::assertStringContainsString('notes.import', $routes);

        $articles = $this->read('resources/views/articles/index.blade.php');
        foreach (['trashed()', "name=\"trashed\"", 'articles.restore', 'articles.force-delete', 'articles.bulk-restore', 'published_from', 'published_to', 'data-col-toggle="views"', 'data-col="published"', 'articles.import'] as $needle) self::assertStringContainsString($needle, $articles, $needle);
        $notes = $this->read('resources/views/notes/index.blade.php');
        self::assertStringNotContainsString('trashed', $notes);
        self::assertStringNotContainsString('_from', $notes);
        self::assertStringContainsString('data-col-toggle="body"', $notes);

        $controller = $this->read('app/Http/Controllers/ArticlesController.php');
        foreach (['function restore(', 'function forceDelete(', 'function bulkRestore(', 'function import(', "onlyTrashed()", "whereDate('published', '>='"] as $needle) self::assertStringContainsString($needle, $controller, $needle);
        self::assertStringNotContainsString('function restore(', $this->read('app/Http/Controllers/NotesController.php'));
        self::assertStringContainsString("'restore' => 'استعادة'", $this->read('lang/ar/articles.php'));
        foreach (['app/Http/Controllers/ArticlesController.php', 'app/Http/Controllers/NotesController.php', 'tests/Feature/ArticleCrudTest.php'] as $path) {
            exec('php -l '.escapeshellarg("{$this->root}/{$path}").' 2>&1', $output, $code);
            self::assertSame(0, $code, $path.': '.implode("\n", $output));
        }
    }

    public function test_import_is_skipped_for_tables_with_uploads_passwords_or_slugs(): void
    {
        $this->generate(['tables' => [['name' => 'Account', 'fields' => [['name' => 'login', 'type' => 'string'], ['name' => 'secret', 'type' => 'password']]]]]);

        self::assertStringNotContainsString('accounts.import', $this->read('routes/web.php'));
        self::assertStringNotContainsString('function import(', $this->read('app/Http/Controllers/AccountsController.php'));
        self::assertStringNotContainsString('accounts.import', $this->read('resources/views/accounts/index.blade.php'));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_trash_filters_restore_range_filters_and_csv_import_work_end_to_end(): void
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

        $jan = \App\Models\Article::create(['name' => 'January piece', 'published' => '2024-01-10', 'views' => 1]);
        $feb = \App\Models\Article::create(['name' => 'February piece', 'published' => '2024-02-10', 'views' => 2]);
        $mar = \App\Models\Article::create(['name' => 'March piece', 'published' => '2024-03-10', 'views' => 3]);

        // Date-range filter; a malformed date is ignored instead of breaking the page.
        $this->get('/articles?published_from=2024-02-01&published_to=2024-02-28')->assertOk()->assertSee('February piece')->assertDontSee('January piece')->assertDontSee('March piece');
        $this->get('/articles?published_from=2024-02-01')->assertSee('March piece')->assertDontSee('January piece');
        $this->get('/articles?published_from=garbage')->assertOk()->assertSee('January piece');

        // Deleting hides the row; the trash filters bring it back into view with restore actions.
        $this->delete("/articles/{$jan->id}")->assertRedirect('/articles');
        $this->get('/articles')->assertDontSee('January piece');
        $this->get('/articles?trashed=only')->assertSee('January piece')->assertDontSee('February piece')->assertSee('Restore')->assertSee('Delete forever');
        $this->get('/articles?trashed=with')->assertSee('January piece')->assertSee('February piece');

        $this->patch("/articles/{$jan->id}/restore")->assertRedirect();
        $this->get('/articles')->assertSee('January piece');

        $this->delete("/articles/{$jan->id}")->assertRedirect();
        $this->delete("/articles/{$jan->id}/force")->assertRedirect();
        $this->assertDatabaseMissing('articles', ['id' => $jan->id]);

        $feb->delete();
        $mar->delete();
        $this->patch('/articles/bulk-restore', ['ids' => [$feb->id, $mar->id]])->assertRedirect();
        self::assertSame(2, \App\Models\Article::count());
        $this->patch('/articles/bulk-restore', ['ids' => 'x'])->assertSessionHasErrors('ids');

        // CSV import validates every row first, so one bad row imports nothing.
        $bad = "name,published,views\nGood one,2024-04-01,5\nBad one,2024-04-02,many\n";
        $this->post('/articles/import', ['file' => UploadedFile::fake()->createWithContent('a.csv', $bad)])->assertSessionHasErrors('file');
        self::assertSame(2, \App\Models\Article::count());
        self::assertStringContainsString('Row 3', session('errors')->get('file')[0]);

        $good = "\xEF\xBB\xBFid,name,published,views\n9,Imported A,2024-04-01,5\n,Imported B,2024-04-02,6\n";
        $this->post('/articles/import', ['file' => UploadedFile::fake()->createWithContent('a.csv', $good)])->assertRedirect('/articles')->assertSessionHas('status', '2 records imported.');
        self::assertSame(4, \App\Models\Article::count());
        self::assertSame(1, \App\Models\Article::where('name', 'Imported A')->count());

        $this->post('/articles/import', ['file' => UploadedFile::fake()->createWithContent('a.csv', "nothing,here\n1,2\n")])->assertSessionHasErrors('file');
        $this->post('/articles/import', [])->assertSessionHasErrors('file');

        // The index page carries the column toggles.
        $this->get('/articles')->assertOk()->assertSee('data-col-toggle="views"', false)->assertSee('type="date"', false);
    }
}
