<?php

use Core\Http\HealthController;
use Core\Http\LocaleController;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class)->name('api.health');
Route::get('/locales', LocaleController::class)->name('api.locales');
