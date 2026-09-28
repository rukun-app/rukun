<?php

use Illuminate\Support\Facades\Route;
use Modules\Payments\Http\MidtransNotificationController;
use Modules\Payments\Http\PaymentController;

Route::prefix('api')->middleware('api')->group(function (): void {
    Route::post('/payments/webhooks/midtrans', MidtransNotificationController::class)
        ->middleware('throttle:payment-webhooks')->name('api.payments.webhooks.midtrans');

    Route::post('/payments', [PaymentController::class, 'store'])
        ->middleware(['auth:sanctum', 'active', 'locale', 'permission:payments.create', 'throttle:admin-sensitive', 'idempotent'])
        ->name('api.payments.store');

    Route::get('/payments/{payment}', [PaymentController::class, 'show'])
        ->middleware(['auth:sanctum', 'active', 'locale'])->name('api.payments.show');
});
