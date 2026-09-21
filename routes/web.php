<?php

use Core\Http\ApiDocsController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::prefix('docs/api')->group(function (): void {
    Route::get('/', [ApiDocsController::class, 'index'])->name('api-docs.index');
    Route::get('/openapi.json', [ApiDocsController::class, 'specification'])->name('api-docs.specification');
    Route::get('/assets/{asset}', [ApiDocsController::class, 'asset'])->name('api-docs.asset');
});
