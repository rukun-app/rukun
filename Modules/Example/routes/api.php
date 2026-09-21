<?php

use Core\Http\ApiResponse;
use Illuminate\Support\Facades\Route;

Route::get('/api/example', fn () => ApiResponse::success(['module' => 'Example']))->name('api.example');
