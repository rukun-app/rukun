<?php

use Illuminate\Support\Facades\Route;
use Modules\Community\Http\AccountController;
use Modules\Community\Http\AreaController;
use Modules\Community\Http\RoleAssignmentController;

Route::prefix('api/community')->middleware(['api', 'auth:sanctum', 'active', 'locale'])->group(function (): void {
    Route::get('areas', [AreaController::class, 'index']);
    Route::get('areas/{area}', [AreaController::class, 'show']);
    Route::post('areas', [AreaController::class, 'store'])->middleware('throttle:admin-sensitive');
    Route::patch('areas/{area}', [AreaController::class, 'update'])->middleware('throttle:admin-sensitive');
    Route::delete('areas/{area}', [AreaController::class, 'destroy'])->middleware('throttle:admin-sensitive');
    Route::post('accounts', [AccountController::class, 'store'])->middleware('throttle:admin-sensitive');
    Route::post('accounts/{user:public_id}/recover', [AccountController::class, 'recover'])->middleware('throttle:admin-sensitive');
    Route::get('credentials/{operation}', [AccountController::class, 'download'])->middleware('throttle:admin-sensitive')->name('community.credentials.download');
    Route::middleware(['permission:users.assign-roles', 'throttle:admin-sensitive'])->group(function (): void {
        Route::get('role-assignments', [RoleAssignmentController::class, 'index']);
        Route::post('role-assignments', [RoleAssignmentController::class, 'store'])->middleware('idempotent');
        Route::delete('role-assignments/{assignment}', [RoleAssignmentController::class, 'destroy']);
    });
});
