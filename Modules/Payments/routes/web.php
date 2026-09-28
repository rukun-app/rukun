<?php

use Illuminate\Support\Facades\Route;
use Modules\Payments\Http\PaymentRedirectController;

Route::middleware('web')->prefix('payments')->group(function (): void {
    Route::get('/finish', [PaymentRedirectController::class, 'finish'])->name('payments.redirect.finish');
    Route::get('/unfinish', [PaymentRedirectController::class, 'unfinish'])->name('payments.redirect.unfinish');
    Route::get('/error', [PaymentRedirectController::class, 'error'])->name('payments.redirect.error');
});
