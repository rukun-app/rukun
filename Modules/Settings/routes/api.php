<?php

use Illuminate\Support\Facades\Route;
use Modules\Settings\Http\SettingsController;

Route::middleware('api')->group(function (): void {
    Route::get('/api/settings/public', [SettingsController::class, 'public'])->name('api.settings.public');
    Route::prefix('api/settings')->middleware(['auth:sanctum', 'active'])->group(function (): void {
        Route::get('/', [SettingsController::class, 'index'])->middleware('permission:settings.view')->name('api.settings.index');
        Route::get('/metadata', [SettingsController::class, 'metadata'])->middleware('permission:settings.view')->name('api.settings.metadata');
        Route::patch('/', [SettingsController::class, 'update'])->middleware('permission:settings.update')->name('api.settings.update');
    });
});
