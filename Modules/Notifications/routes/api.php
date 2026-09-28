<?php

use Illuminate\Support\Facades\Route;
use Modules\Notifications\Http\NotificationController;
use Modules\Notifications\Http\PreferenceController;

Route::prefix('api')->middleware(['api', 'auth:sanctum', 'active', 'locale'])->group(function (): void {
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::get('/notifications/unread-count', [NotificationController::class, 'count']);
    Route::post('/notifications/read-all', [NotificationController::class, 'readAll']);
    Route::get('/notifications/{notification}', [NotificationController::class, 'show']);
    Route::patch('/notifications/{notification}/read', [NotificationController::class, 'read']);
    Route::delete('/notifications/{notification}/read', [NotificationController::class, 'unread']);
    Route::delete('/notifications/{notification}', [NotificationController::class, 'destroy']);
    Route::get('/notification-preferences', [PreferenceController::class, 'index']);
    Route::put('/notification-preferences', [PreferenceController::class, 'update']);
});
