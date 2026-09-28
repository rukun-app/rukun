<?php

use Illuminate\Support\Facades\Route;
use Modules\DataTransfer\Http\DataTransferController;

Route::prefix('api/data-transfers')->middleware(['api', 'auth:sanctum', 'active', 'locale'])->group(function (): void {
    Route::get('/types', [DataTransferController::class, 'types'])->name('api.data-transfers.types');
    Route::get('/', [DataTransferController::class, 'index'])->name('api.data-transfers.index');
    Route::post('/exports', [DataTransferController::class, 'export'])->middleware(['permission:data-transfers.create', 'throttle:admin-sensitive', 'idempotent'])->name('api.data-transfers.export');
    Route::post('/imports', [DataTransferController::class, 'import'])->middleware(['permission:data-transfers.create', 'throttle:admin-sensitive', 'idempotent'])->name('api.data-transfers.import');
    Route::get('/{dataTransfer}', [DataTransferController::class, 'show'])->name('api.data-transfers.show');
    Route::post('/{dataTransfer}/cancel', [DataTransferController::class, 'cancel'])->name('api.data-transfers.cancel');
});
