<?php

use Illuminate\Support\Facades\Route;
use Modules\Example\Http\ExampleController;

Route::get('/api/example', ExampleController::class)->name('api.example');
