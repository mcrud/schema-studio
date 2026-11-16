<?php

use Illuminate\Support\Facades\Route;
use Mcrud\Crud\Http\Controllers\CrudGeneratorController;

Route::middleware(config('crud.middleware', ['web']))->prefix(config('crud.route_prefix', 'crud-generator'))->group(function (): void {
    Route::get('/', [CrudGeneratorController::class, 'index'])->name('crud-generator.index');
    Route::post('/', [CrudGeneratorController::class, 'store'])->name('crud-generator.store');
    Route::get('introspect', [CrudGeneratorController::class, 'introspect'])->name('crud-generator.introspect');
});

Route::middleware(config('crud.middleware', ['web']))->prefix(config('crud.route_prefix', 'crud-generator'))->group(function (): void {
    Route::get('assets/{file}', function (string $file) {
        abort_unless($file === 'studio.js', 404);

        return response()->file(__DIR__.'/../resources/js/studio.js', ['Content-Type' => 'application/javascript; charset=utf-8', 'Cache-Control' => 'no-cache']);
    })->name('crud-generator.asset');
});
