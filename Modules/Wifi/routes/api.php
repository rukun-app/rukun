<?php

use Illuminate\Support\Facades\Route;
use Modules\Wifi\Http\BenefitController;
use Modules\Wifi\Http\WifiController;

Route::prefix('api/wifi')->middleware(['api', 'auth:sanctum', 'active', 'locale'])->group(function (): void {
    Route::get('{collection}', [WifiController::class, 'index'])->whereIn('collection', ['packages', 'customers']);
    Route::get('{collection}/{id}', [WifiController::class, 'show'])->whereIn('collection', ['packages', 'customers'])->whereUuid('id');
    Route::middleware('throttle:admin-sensitive')->group(function (): void {
        Route::post('packages', [WifiController::class, 'package']);
        Route::post('customers', [WifiController::class, 'customer']);
        Route::post('customers/{customer}/end', [WifiController::class, 'end']);
        Route::post('customers/{customer}/bill', [WifiController::class, 'bill']);
    });
});

Route::prefix('api/wifi')->middleware(['api', 'auth:sanctum', 'active', 'locale'])->group(function (): void {
    Route::get('bills/{bill}/settlement', [BenefitController::class, 'settlement']);
    Route::get('{resource}', [BenefitController::class, 'index'])->whereIn('resource', ['bills', 'benefits', 'claims', 'finance', 'gallon-ledger']);
    Route::get('{resource}/{id}', [BenefitController::class, 'show'])->whereIn('resource', ['bills', 'benefits', 'claims', 'finance', 'gallon-ledger'])->whereUuid('id');
    Route::middleware('throttle:admin-sensitive')->group(function (): void {
        Route::post('bills/{bill}/{action}', [BenefitController::class, 'finance'])->whereIn('action', ['remit', 'recover']);
        Route::post('finance/{finance}/reverse', [BenefitController::class, 'reverseFinance']);
        Route::post('bills/{bill}/grant', [BenefitController::class, 'grant']);
        Route::post('benefits/{benefit}/reserve', [BenefitController::class, 'reserve']);
        Route::post('benefits/{benefit}/reverse', [BenefitController::class, 'reverseGrant']);
        Route::post('claims/{claim}/{action}', [BenefitController::class, 'claim'])->whereIn('action', ['deliver', 'confirm', 'reverse']);
    });
});
