<?php

use Illuminate\Support\Facades\Route;
use Mcrud\Crud\Http\Controllers\CrudGeneratorController;

Route::middleware(config('crud.middleware', ['web']))->prefix(config('crud.route_prefix', 'crud-generator'))->group(function (): void {
    Route::get('/', [CrudGeneratorController::class, 'index'])->name('crud-generator.index');
    Route::middleware('throttle:crud-studio')->group(function (): void {
        Route::post('preview', [CrudGeneratorController::class, 'preview'])->name('crud-generator.preview');
        Route::post('/', [CrudGeneratorController::class, 'store'])->name('crud-generator.store');
        Route::get('introspect', [CrudGeneratorController::class, 'introspect'])->name('crud-generator.introspect');
    });
});

Route::middleware(config('crud.middleware', ['web']))->prefix(config('crud.route_prefix', 'crud-generator'))->group(function (): void {
    Route::get('assets/{file}', function (string $file) {
        abort_unless(in_array($file, ['studio.js', 'studio-i18n.js'], true), 404);

        return response()->file(__DIR__."/../resources/js/{$file}", ['Content-Type' => 'application/javascript; charset=utf-8', 'Cache-Control' => 'no-cache']);
    })->name('crud-generator.asset');
});
