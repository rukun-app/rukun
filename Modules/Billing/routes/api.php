<?php

use Illuminate\Support\Facades\Route;
use Modules\Billing\Http\BillingController;

Route::prefix('api/billing')->middleware(['api', 'auth:sanctum', 'active', 'locale'])->group(function (): void {
    Route::get('reports/monthly', [BillingController::class, 'report']);
    Route::get('payment-types/{type}/tariffs', [BillingController::class, 'tariffs']);
    Route::get('{collection}', [BillingController::class, 'index'])->whereIn('collection', ['payment-types', 'invoices', 'bank-accounts', 'submissions', 'receipts', 'expenses', 'ledger', 'periods']);
    Route::get('{collection}/{id}', [BillingController::class, 'show'])->whereIn('collection', ['invoices', 'submissions', 'receipts', 'expenses', 'ledger', 'periods'])->whereUuid('id');
    Route::middleware('throttle:admin-sensitive')->group(function (): void {
        Route::post('payment-types', [BillingController::class, 'paymentType']);
        Route::post('payment-types/{type}/tariffs', [BillingController::class, 'tariff']);
        Route::post('tariffs/{tariff}/end', [BillingController::class, 'endTariff']);
        Route::post('bank-accounts', [BillingController::class, 'bankAccount']);
        Route::post('invoices/generate', [BillingController::class, 'generate']);
        Route::post('invoices/{invoice}/{action}', [BillingController::class, 'invoiceAction'])->whereIn('action', ['issue', 'cancel']);
        Route::post('receipts/cash', [BillingController::class, 'cash']);
        Route::post('receipts/{receipt}/reverse', [BillingController::class, 'reverseReceipt']);
        Route::post('submissions', [BillingController::class, 'submit']);
        Route::post('submissions/{submission}/{action}', [BillingController::class, 'review'])->whereIn('action', ['approve', 'reject', 'cancel']);
        Route::post('expenses', [BillingController::class, 'expense']);
        Route::post('expenses/{expense}/{action}', [BillingController::class, 'expenseAction'])->whereIn('action', ['approve', 'post']);
        Route::post('ledger', [BillingController::class, 'journal']);
        Route::post('ledger/{entry}/reverse', [BillingController::class, 'reverseJournal']);
        Route::post('periods/close',[BillingController::class, 'close']);
    });
});
