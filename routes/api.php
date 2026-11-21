<?php

use App\Http\Controllers\Api\FavoriteController;
use App\Http\Controllers\Api\LearningController;
use App\Http\Controllers\Api\LibraryController;
use App\Http\Controllers\Api\ProgressController;
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

// داده‌ی کاربر وارد‌شده
Route::middleware(['auth:sanctum', 'throttle:user-data'])->group(function () {
    // امتیاز آهنگ‌ها
    Route::get('progress/scores', [ProgressController::class, 'index']);
    Route::put('progress/scores', [ProgressController::class, 'store']);
    Route::post('progress/scores/merge', [ProgressController::class, 'merge']);

    // علاقه‌مندی‌ها
    Route::get('favorites', [FavoriteController::class, 'index']);
    Route::patch('favorites', [FavoriteController::class, 'update']);

    // مسیر یادگیری و تمرین گوش
    Route::get('learning', [LearningController::class, 'index']);
    Route::put('learning/lessons/{lessonId}', [LearningController::class, 'completeLesson'])->where('lessonId', '[A-Za-z0-9_\-]{1,60}');
    Route::post('learning/ear-rounds', [LearningController::class, 'earRound']);
    Route::post('learning/merge', [LearningController::class, 'merge']);

    // آهنگ‌های واردشده
    Route::get('library/songs', [LibraryController::class, 'index']);
    Route::put('library/songs/{songId}', [LibraryController::class, 'upsert'])->where('songId', '[A-Za-z0-9_\-]{1,100}');
    Route::delete('library/songs/{songId}', [LibraryController::class, 'destroy'])->where('songId', '[A-Za-z0-9_\-]{1,100}');
});