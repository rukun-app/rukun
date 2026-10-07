<?php

use Illuminate\Support\Facades\Route;
use Modules\Civic\Http\CivicController;

Route::prefix('api/civic')->middleware(['api', 'auth:sanctum', 'active', 'locale'])->group(function (): void {
    Route::get('announcements', [CivicController::class, 'announcements']);
    Route::get('announcements/{id}', [CivicController::class, 'announcement'])->whereUuid('id');
    Route::post('announcements', [CivicController::class, 'createAnnouncement'])->middleware('throttle:admin-sensitive');
    Route::post('announcements/{id}/{action}', [CivicController::class, 'announcementAction'])->whereUuid('id')->whereIn('action', ['publish', 'archive', 'read', 'unread'])->middleware('throttle:admin-sensitive');
    foreach (['reports' => 'report', 'letter-requests' => 'letter'] as $path => $kind) {
        Route::get($path, [CivicController::class, 'cases'])->defaults('kind', $kind);
        Route::get($path.'/{id}', [CivicController::class, 'showCase'])->whereUuid('id')->defaults('kind', $kind);
        Route::get($path.'/{id}/timeline', [CivicController::class, 'timeline'])->whereUuid('id')->defaults('kind', $kind);
        Route::post($path, [CivicController::class, 'createCase'])->defaults('kind', $kind)->middleware('throttle:admin-sensitive');
        Route::post($path.'/{id}/actions', [CivicController::class, 'caseAction'])->whereUuid('id')->defaults('kind', $kind)->middleware('throttle:admin-sensitive');
    }
});
