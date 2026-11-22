<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recordings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('rec_id', 64); // شناسه‌ای که فرانت می‌سازه (UUID)
            $table->string('name', 60);
            $table->unsignedInteger('duration_ms');
            $table->unsignedInteger('note_count');
            $table->longText('events'); // JSON: [{t, note, on, velocity}]
            $table->string('audio_path')->nullable(); // مسیر فایل WAV روی دیسک private؛ null یعنی ضبط صدا نداره
            $table->timestamps();

            $table->unique(['user_id', 'rec_id']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recordings');
    }
};
