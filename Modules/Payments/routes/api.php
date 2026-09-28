<?php

use Illuminate\Support\Facades\Route;
use Modules\Payments\Http\MidtransNotificationController;
use Modules\Payments\Http\PaymentController;

Route::prefix('api')->middleware('api')->group(function (): void {
    Route::post('/payments/webhooks/midtrans', MidtransNotificationController::class)
        ->middleware('throttle:payment-webhooks')->name('api.payments.webhooks.midtrans');

    Route::get('/payments/{payment}', [PaymentController::class, 'show'])
        ->middleware(['auth:sanctum', 'active', 'locale'])->name('api.payments.show');
});
