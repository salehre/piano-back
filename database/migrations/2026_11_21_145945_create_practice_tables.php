<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // زمان و XP هر روز تمرین هر کاربر. day تاریخ «محلی» کاربره (YYYY-MM-DD)، نه یک لحظه‌ی UTC.
        // سرور برای هر روز بیشترین ms و xp رو نگه می‌داره، نه آخرین مقدار رو
        Schema::create('practice_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('day');
            $table->unsignedInteger('ms')->default(0);
            $table->double('xp')->default(0);
            $table->timestamps();

            $table->unique(['user_id', 'day']);
        });

        // بقیه‌ی داده‌ی تمرین هر کاربر (حداکثر یک ردیف): XP کل، هدف روزانه، یادآور، شمارنده‌ها و نشان‌ها.
        // نبودن ردیف یعنی کاربر هنوز چیزی ذخیره نکرده (مقادیر پیش‌فرض).
        Schema::create('practice_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->double('xp')->default(0);
            $table->unsignedTinyInteger('goal_min')->default(10);
            $table->boolean('reminder_on')->default(false);
            $table->string('reminder_time', 5)->default('19:00');
            // آخرین روزی که یادآور نشون داده شد؛ null یعنی هیچ‌وقت
            $table->date('reminded')->nullable();
            // {id نشان: زمان کسب (UTC)}
            $table->json('badges')->nullable();
            // شمارنده‌های نشان‌ها (همون Counters فرانت، با snake_case)
            $table->unsignedInteger('lessons')->default(0);
            $table->unsignedInteger('ear_rounds')->default(0);
            $table->unsignedInteger('perfect_ear')->default(0);
            $table->unsignedInteger('song_runs')->default(0);
            $table->unsignedInteger('three_stars')->default(0);
            $table->unsignedInteger('perfect_runs')->default(0);
            $table->unsignedInteger('goal_days')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('practice_profiles');
        Schema::dropIfExists('practice_days');
    }
};