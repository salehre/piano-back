<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // اشتراک هر کاربر (حداکثر یک ردیف). نبودن ردیف یعنی پلن رایگان و دوره‌ی آزمایشی استفاده‌نشده.
        // ردیف بعد از انقضا پاک نمی‌شه، تا trial_used_at بمونه و دوره‌ی آزمایشی دوباره داده نشه.
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('source', 10); // trial | purchase
            $table->dateTime('expires_at', 3);
            $table->dateTime('trial_used_at', 3)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};