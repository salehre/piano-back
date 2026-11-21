<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // آهنگ‌هایی که کاربر از فایل MIDI/MusicXML وارد کرده؛ song_id همون id ساخته‌شده توی فرانت (midi-xxxx / xml-xxxx)
        Schema::create('imported_songs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('song_id', 100);
            $table->string('title', 200);
            $table->double('bpm');
            $table->unsignedTinyInteger('beats_per_bar');
            $table->unsignedTinyInteger('level');
            $table->string('source', 10)->nullable();
            // [[شماره‌ی MIDI، شروع (ms)، طول (ms)، دست: ۰ راست / ۱ چپ], ...]
            $table->json('notes');
            $table->timestamps();

            $table->unique(['user_id', 'song_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('imported_songs');
    }
};
