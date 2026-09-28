<?php

use Illuminate\Support\Facades\Route;
use Modules\Realtime\Http\EventController;

Route::prefix('api')->middleware(['api', 'auth:sanctum', 'active', 'locale'])->group(function (): void {
    Route::get('/events', [EventController::class, 'index']);
    Route::get('/events/cursor', [EventController::class, 'cursor']);
});
