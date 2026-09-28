<?php

use Illuminate\Support\Facades\Route;
use Modules\Files\Http\FileController;

Route::prefix('api/files')->middleware(['api', 'auth:sanctum', 'active', 'locale'])->group(function (): void {
    Route::get('/', [FileController::class, 'index'])->name('api.files.index');
    Route::post('/', [FileController::class, 'store'])->name('api.files.store');
    Route::get('/{file}', [FileController::class, 'show'])->name('api.files.show');
    Route::patch('/{file}', [FileController::class, 'update'])->name('api.files.update');
    Route::get('/{file}/download', [FileController::class, 'download'])->name('api.files.download');
    Route::delete('/{file}', [FileController::class, 'destroy'])->name('api.files.destroy');
});
