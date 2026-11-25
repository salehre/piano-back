<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

return new class extends Migration
{
    private const DATA_URL = '#^data:image/jpeg;base64,([A-Za-z0-9+/]+={0,2})$#D';

    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('avatar_path', 120)->nullable();
        });

        // عکس‌های base64 فعلی رو به فایل (دیسک private) منتقل می‌کنیم؛ نامعتبرها نادیده گرفته می‌شن
        $disk = Storage::disk('local');
        DB::table('users')->whereNotNull('avatar')->where('avatar', '!=', '')->orderBy('id')
            ->chunkById(100, function ($users) use ($disk) {
                foreach ($users as $user) {
                    if (! preg_match(self::DATA_URL, (string) $user->avatar, $m)) {
                        continue;
                    }
                    $bytes = base64_decode($m[1], true);
                    if ($bytes === false || ! str_starts_with($bytes, "\xFF\xD8\xFF")) {
                        continue;
                    }
                    $path = 'avatars/'.$user->id.'-'.Str::random(10).'.jpg';
                    $disk->put($path, $bytes);
                    DB::table('users')->where('id', $user->id)->update(['avatar_path' => $path]);
                }
            });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('avatar');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->mediumText('avatar')->nullable();
        });

        $disk = Storage::disk('local');
        DB::table('users')->whereNotNull('avatar_path')->orderBy('id')->chunkById(100, function ($users) use ($disk) {
            foreach ($users as $user) {
                if ($disk->exists($user->avatar_path)) {
                    DB::table('users')->where('id', $user->id)->update([
                        'avatar' => 'data:image/jpeg;base64,'.base64_encode($disk->get($user->avatar_path)),
                    ]);
                }
            }
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('avatar_path');
        });
    }
};