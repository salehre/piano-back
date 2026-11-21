<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // بهترین نتیجه‌ی هر کاربر برای هر «آهنگ + حالت + دست + سرعت» (کلید = scoreKey فرانت، مثل twinkle:play:both:1)
        Schema::create('song_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('score_key', 160);
            $table->unsignedTinyInteger('stars');
            $table->double('accuracy');
            $table->unsignedInteger('plays');
            $table->dateTime('played_at', 3);
            $table->timestamps();

            $table->unique(['user_id', 'score_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('song_scores');
    }
};
