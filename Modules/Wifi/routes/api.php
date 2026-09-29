<?php

use Illuminate\Support\Facades\Route;
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
