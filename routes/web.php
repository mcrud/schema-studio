<?php

use Illuminate\Support\Facades\Route;
use Mcrud\Crud\Http\Controllers\CrudGeneratorController;

Route::middleware(config('crud.middleware', ['web']))->prefix(config('crud.route_prefix', 'crud-generator'))->group(function (): void {
    Route::get('/', [CrudGeneratorController::class, 'index'])->name('crud-generator.index');
    Route::post('/', [CrudGeneratorController::class, 'store'])->name('crud-generator.store');
});
