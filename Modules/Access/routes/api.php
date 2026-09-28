<?php

use Core\Audit\AuditController;
use Illuminate\Support\Facades\Route;
use Modules\Access\Http\RoleController;
use Modules\Access\Http\UserAccessController;

Route::prefix('api')->middleware(['api', 'auth:sanctum', 'active', 'locale'])->group(function (): void {
    Route::get('/permissions', [RoleController::class, 'permissions'])->middleware('permission:roles.view');
    Route::get('/audit-events', AuditController::class)->middleware('permission:audit.view');
    Route::get('/roles', [RoleController::class, 'index'])->middleware('permission:roles.view');
    Route::post('/roles', [RoleController::class, 'store'])->middleware(['permission:roles.create', 'throttle:admin-sensitive']);
    Route::patch('/roles/{role}', [RoleController::class, 'update'])->middleware(['permission:roles.update', 'throttle:admin-sensitive']);
    Route::delete('/roles/{role}', [RoleController::class, 'destroy'])->middleware(['permission:roles.delete', 'throttle:admin-sensitive']);
    Route::get('/users', [UserAccessController::class, 'index'])->middleware('permission:users.view');
    Route::post('/users', [UserAccessController::class, 'store'])->middleware(['permission:users.create', 'throttle:admin-sensitive', 'idempotent']);
    Route::get('/users/{user}', [UserAccessController::class, 'show'])->middleware('permission:users.view');
    Route::patch('/users/{user}/status', [UserAccessController::class, 'status'])->middleware(['permission:users.suspend', 'throttle:admin-sensitive']);
    Route::put('/users/{user}/roles', [UserAccessController::class, 'roles'])->middleware(['permission:users.assign-roles', 'throttle:admin-sensitive']);
});
