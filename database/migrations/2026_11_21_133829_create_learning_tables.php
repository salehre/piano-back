<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // درس‌های تمام‌شده‌ی هر کاربر. زمان اولین تکمیل ثابت می‌مونه و ستاره همیشه بیشترین مقدار ثبت‌شده‌ست
        // (stars خالی = درسی که قبل از اضافه‌شدن ستاره تموم شده)
        Schema::create('lesson_progress', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('lesson_id', 60);
            $table->unsignedTinyInteger('stars')->nullable();
            $table->dateTime('completed_at', 3);
            $table->timestamps();

            $table->unique(['user_id', 'lesson_id']);
        });

        // آمار تجمعی تمرین گوش برای هر حالت (interval, chord, ...)
        Schema::create('ear_stats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('mode', 20);
            $table->unsignedInteger('rounds')->default(0);
            $table->unsignedInteger('answered')->default(0);
            $table->unsignedInteger('correct')->default(0);
            $table->unsignedTinyInteger('best_round')->default(0);
            $table->timestamps();

            $table->unique(['user_id', 'mode']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ear_stats');
        Schema::dropIfExists('lesson_progress');
    }
};