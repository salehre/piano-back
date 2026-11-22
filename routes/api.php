<?php

use App\Http\Controllers\Api\ClubController;
use App\Http\Controllers\Api\ContactController;
use App\Http\Controllers\Api\FavoriteController;
use App\Http\Controllers\Api\LearningController;
use App\Http\Controllers\Api\LibraryController;
use App\Http\Controllers\Api\PlanController;
use App\Http\Controllers\Api\PracticeController;
use App\Http\Controllers\Api\ProgressController;
use App\Http\Controllers\Api\RecordingController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\ProfileController;
use Illuminate\Support\Facades\Route;

// همه‌ی مسیرها زیر /api هستن. ترتیب فراخوانی فرانت: GET /sanctum/csrf-cookie (یک بار) و بعد بقیه با هدر X-XSRF-TOKEN

Route::prefix('auth')->group(function () {
    Route::get('me', [AuthController::class, 'me']);
    Route::post('lookup', [AuthController::class, 'lookup'])->middleware('throttle:auth-lookup');
    Route::post('register', [AuthController::class, 'register'])->middleware('throttle:auth-register');
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:auth-login');
    Route::post('logout', [AuthController::class, 'logout']);

    // بازیابی رمز با کد پیامکی (با موفقیت، مالکیت شماره هم تأیید می‌شه)
    Route::post('password/forgot', [PasswordResetController::class, 'forgot'])->middleware('throttle:auth-forgot');
    Route::post('password/reset', [PasswordResetController::class, 'reset'])->middleware('throttle:auth-reset');

    Route::put('profile', [ProfileController::class, 'update'])->middleware('auth:sanctum');
});

// عمومی (بدون ورود): عضویت در باشگاه و فرم تماس
Route::post('club/join', [ClubController::class, 'join'])->middleware('throttle:club-join');
Route::post('contact', [ContactController::class, 'store'])->middleware('throttle:contact');

// داده‌ی کاربر وارد‌شده
Route::middleware(['auth:sanctum', 'throttle:user-data'])->group(function () {
    // امتیاز آهنگ‌ها
    Route::get('progress/scores', [ProgressController::class, 'index']);
    Route::put('progress/scores', [ProgressController::class, 'store']);
    Route::post('progress/scores/merge', [ProgressController::class, 'merge']);

    // علاقه‌مندی‌ها
    Route::get('favorites', [FavoriteController::class, 'index']);
    Route::patch('favorites', [FavoriteController::class, 'update']);

    // ضبط‌ها (رویدادها + صدای WAV)
    Route::get('recordings', [RecordingController::class, 'index']);
    Route::post('recordings', [RecordingController::class, 'store'])->middleware('throttle:recordings-upload');
    Route::get('recordings/{id}', [RecordingController::class, 'show'])->where('id', '[A-Za-z0-9-]{1,64}');
    Route::get('recordings/{id}/audio', [RecordingController::class, 'audio'])->where('id', '[A-Za-z0-9-]{1,64}');
    Route::patch('recordings/{id}', [RecordingController::class, 'rename'])->where('id', '[A-Za-z0-9-]{1,64}');
    Route::delete('recordings/{id}', [RecordingController::class, 'destroy'])->where('id', '[A-Za-z0-9-]{1,64}');

    // پلن (رایگان / پریمیوم) و دوره‌ی آزمایشی
    Route::get('plan', [PlanController::class, 'show']);
    Route::post('plan/trial', [PlanController::class, 'startTrial']);

    // مسیر یادگیری و تمرین گوش
    Route::get('learning', [LearningController::class, 'index']);
    Route::put('learning/lessons/{lessonId}', [LearningController::class, 'completeLesson'])->where('lessonId', '[A-Za-z0-9_\-]{1,60}');
    Route::post('learning/ear-rounds', [LearningController::class, 'earRound']);
    Route::post('learning/merge', [LearningController::class, 'merge']);

    // تمرین روزانه (زمان هر روز، XP، هدف، نشان‌ها، شمارنده‌ها، یادآور)
    Route::get('practice', [PracticeController::class, 'index']);
    Route::put('practice/days/{day}', [PracticeController::class, 'storeDay'])->where('day', '\d{4}-\d{2}-\d{2}');
    Route::put('practice/meta', [PracticeController::class, 'storeMeta']);
    Route::post('practice/merge', [PracticeController::class, 'merge']);

    // آهنگ‌های واردشده
    Route::get('library/songs', [LibraryController::class, 'index']);
    Route::put('library/songs/{songId}', [LibraryController::class, 'upsert'])->where('songId', '[A-Za-z0-9_\-]{1,100}');
    Route::delete('library/songs/{songId}', [LibraryController::class, 'destroy'])->where('songId', '[A-Za-z0-9_\-]{1,100}');
});