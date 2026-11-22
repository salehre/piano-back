<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // null یعنی مالکیت شماره با پیامک ثابت نشده (حساب‌های قبلی و ثبت‌نام‌های بدون تأیید)
            $table->timestamp('phone_verified_at')->nullable()->after('phone');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('phone_verified_at');
        });
    }
};
