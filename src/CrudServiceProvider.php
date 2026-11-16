<?php

namespace Mcrud\Crud;

use Illuminate\Support\ServiceProvider;

class CrudServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/crud.php', 'crud');
        $this->app->singleton(Generators\CrudGenerator::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'crud');
        $this->publishes([__DIR__.'/../config/crud.php' => config_path('crud.php')], 'crud-config');
    }
}
