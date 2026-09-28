<?php

use Illuminate\Support\Facades\Route;
use Modules\Community\Http\AccountController;
use Modules\Community\Http\AreaController;
use Modules\Community\Http\PopulationController;
use Modules\Community\Http\PopulationTransferController;
use Modules\Community\Http\RoleAssignmentController;
use Modules\Community\Http\VendorController;

Route::prefix('api/community')->middleware(['api', 'auth:sanctum', 'active', 'locale'])->group(function (): void {
    Route::get('households', [PopulationController::class, 'households']);
    Route::post('households', [PopulationController::class, 'storeHousehold']);
    Route::get('households/{household}', [PopulationController::class, 'household']);
    Route::patch('households/{household}', [PopulationController::class, 'updateHousehold']);
    Route::match(['GET', 'PUT'], 'households/{household}/sensitive', [PopulationController::class, 'householdSensitive'])->middleware('throttle:admin-sensitive');
    Route::get('residents', [PopulationController::class, 'residents']);
    Route::post('residents', [PopulationController::class, 'storeResident']);
    Route::get('residents/{resident}', [PopulationController::class, 'resident']);
    Route::patch('residents/{resident}', [PopulationController::class, 'updateResident']);
    Route::match(['GET', 'PUT'], 'residents/{resident}/sensitive', [PopulationController::class, 'residentSensitive'])->middleware('throttle:admin-sensitive');
    Route::put('residents/{resident}/membership', [PopulationController::class, 'move']);
    Route::get('residents/{resident}/memberships', [PopulationController::class, 'memberships']);
    Route::post('residents/{resident}/account', [PopulationController::class, 'account'])->middleware('throttle:admin-sensitive');
    Route::post('population/{direction}', [PopulationTransferController::class, 'store'])->whereIn('direction', ['imports', 'exports'])->middleware('throttle:admin-sensitive');
    Route::get('population/transfers/{transfer}/results', [PopulationTransferController::class, 'results']);
    Route::get('population/transfers/{transfer}/errors', [PopulationTransferController::class, 'errors']);
    Route::get('vendors', [VendorController::class, 'index']);
    Route::post('vendors', [VendorController::class, 'store']);
    Route::patch('vendors/{vendor}', [VendorController::class, 'update']);
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
