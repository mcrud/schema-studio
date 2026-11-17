<?php

namespace Mcrud\Crud;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class CrudServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/crud.php', 'crud');
        $this->app->singleton(Schema\SchemaValidator::class, fn ($app) => new Schema\SchemaValidator($app->make(Generators\CrudGenerator::class)));
        $this->app->singleton(Generators\CrudGenerator::class, fn ($app) => new Generators\CrudGenerator($app['files'], new Support\StubRenderer(override: config('crud.stubs_path') ?? base_path('stubs/crud'))));
    }

    public function boot(): void
    {
        RateLimiter::for('crud-studio', fn (Request $request) => Limit::perMinute((int) config('crud.rate_limit', 30))->by($request->user()?->getAuthIdentifier() ?: $request->ip()));
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'crud');
        $this->publishes([__DIR__.'/../config/crud.php' => config_path('crud.php')], 'crud-config');
        $this->publishes([__DIR__.'/../resources/stubs' => base_path('stubs/crud')], 'crud-stubs');
        $this->publishes([__DIR__.'/../resources/js/studio.js' => public_path('vendor/crud/studio.js'), __DIR__.'/../resources/js/studio-i18n.js' => public_path('vendor/crud/studio-i18n.js')], 'crud-assets');
        if ($this->app->runningInConsole()) $this->commands([Console\GenerateCrudCommand::class, Console\DiffCrudCommand::class, Console\SyncCrudCommand::class, Console\ValidateCrudCommand::class, Console\TypesCrudCommand::class]);
    }
}
