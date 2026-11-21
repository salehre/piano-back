<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ImportedSong;
use App\Support\ApiError;
use App\Support\AuthRules;
use App\Support\SongRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class LibraryController extends Controller
{
    /** آهنگ‌های واردشده‌ی کاربر (با نت‌ها): {"songs": [StoredSong...]} */
    public function index(Request $request): JsonResponse
    {
        $songs = ImportedSong::where('user_id', $request->user()->id)
            ->orderBy('id')
            ->get()
            ->map(fn (ImportedSong $song) => $song->toApiPayload())
            ->all();

        return response()->json(['songs' => $songs]);
    }

    /** ذخیره‌ی آهنگ (upsert بر اساس id توی آدرس) */
    public function upsert(Request $request, string $songId): JsonResponse
    {
        $input = $request->json()->all();
        if (! SongRules::isValidId($songId) || ! SongRules::isValid($input)) {
            return ApiError::response(AuthRules::ref('api.invalid'), 422);
        }

        $userId = $request->user()->id;
        $exists = ImportedSong::where('user_id', $userId)->where('song_id', $songId)->exists();
        if (! $exists && ImportedSong::where('user_id', $userId)->count() >= SongRules::MAX_SONGS_PER_USER) {
            // همون پیام «حداکثر {max} آهنگ» که فرانت داره
            return ApiError::response(AuthRules::ref('songs.import.errors.limit', ['max' => SongRules::MAX_SONGS_PER_USER]), 409);
        }

        $song = ImportedSong::updateOrCreate(
            ['user_id' => $userId, 'song_id' => $songId],
            SongRules::normalize($input),
        );

        return response()->json(['song' => $song->toApiPayload()]);
    }

    public function destroy(Request $request, string $songId): Response
    {
        ImportedSong::where('user_id', $request->user()->id)->where('song_id', $songId)->delete();

        return response()->noContent();
    }
}
