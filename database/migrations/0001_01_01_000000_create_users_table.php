<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            // هویت کاربر شماره‌ی موبایل (به شکل نرمال 09xxxxxxxxx) هست، نه ایمیل
            $table->string('phone', 11)->unique();
            // null یعنی هنوز رمز نذاشته (برای ورود با کد پیامکی در آینده)
            $table->string('password')->nullable();

            // پروفایل (ایمیل فقط اطلاعات تماسه و یکتا نیست)
            $table->string('display_name', 120)->default('');
            $table->string('email', 100)->nullable();
            $table->mediumText('avatar')->nullable(); // data URL ‏JPEG ‏۲۵۶×۲۵۶؛ بعداً می‌شه به فایل منتقل کرد
            $table->text('national_id')->nullable(); // رمزنگاری‌شده (cast: encrypted)
            $table->string('country', 60)->nullable();
            $table->string('address', 300)->nullable();
            $table->unsignedTinyInteger('years_playing')->nullable();

            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('sessions');
    }
};
