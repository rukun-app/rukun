<?php

use Illuminate\Support\Facades\Route;
use Modules\Identity\Http\AuthController;
use Modules\Identity\Http\TokenController;

Route::prefix('api/auth')->middleware('api')->group(function (): void {
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:5,1')->name('api.auth.register');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:20,1')->name('api.auth.login');
    Route::post('/email/resend', [AuthController::class, 'resendVerification'])->middleware('throttle:3,1')->name('api.auth.email.resend');
    Route::get('/email/verify/{id}/{hash}', [AuthController::class, 'verify'])->middleware(['signed', 'throttle:6,1'])->name('verification.verify');
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:3,1')->name('password.email');
    Route::post('/reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:5,1')->name('password.update');
    Route::middleware(['auth:sanctum', 'active'])->group(function (): void {
        Route::get('/me', [AuthController::class, 'me'])->name('api.auth.me');
        Route::patch('/profile', [AuthController::class, 'updateProfile'])->name('api.auth.profile');
        Route::put('/password', [AuthController::class, 'changePassword'])->name('api.auth.password');
        Route::post('/logout', [AuthController::class, 'logout'])->name('api.auth.logout');
        Route::post('/logout-all', [AuthController::class, 'logoutAll'])->name('api.auth.logout-all');
        Route::get('/tokens', [TokenController::class, 'index'])->name('api.auth.tokens.index');
        Route::delete('/tokens/{token}', [TokenController::class, 'destroy'])->name('api.auth.tokens.destroy');
    });
});
