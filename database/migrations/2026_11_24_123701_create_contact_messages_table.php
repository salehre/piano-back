<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // پیام‌های فرم تماس (کاربر وارد‌نشده هم می‌تونه بفرسته، پس user_id نداریم)
        Schema::create('contact_messages', function (Blueprint $table) {
            $table->id();
            $table->string('name', 40);
            $table->string('email', 100);
            $table->string('subject', 100);
            $table->text('message');
            $table->timestamps();

            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_messages');
    }
};