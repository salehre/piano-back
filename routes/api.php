<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\ProfileController;
use Illuminate\Support\Facades\Route;

// همه‌ی مسیرها زیر /api هستن. ترتیب فراخوانی فرانت: GET /sanctum/csrf-cookie (یک بار) و بعد بقیه با هدر X-XSRF-TOKEN

Route::prefix('auth')->group(function () {
    Route::get('me', [AuthController::class, 'me']);
    Route::post('lookup', [AuthController::class, 'lookup'])->middleware('throttle:auth-lookup');
    Route::post('register', [AuthController::class, 'register'])->middleware('throttle:auth-register');
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:auth-login');
    Route::post('logout', [AuthController::class, 'logout']);

    Route::put('profile', [ProfileController::class, 'update'])->middleware('auth:sanctum');
});
