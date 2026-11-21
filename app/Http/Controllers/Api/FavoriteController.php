<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FavoriteSong;
use App\Support\ApiError;
use App\Support\AuthRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FavoriteController extends Controller
{
    private const MAX_FAVORITES = 1000;

    private const MAX_IDS_PER_REQUEST = 500;

    /** شناسه‌ی آهنگ‌های موردعلاقه به ترتیب اضافه‌شدن: {"ids": [...]} */
    public function index(Request $request): JsonResponse
    {
        return response()->json(['ids' => $this->idsOf($request->user()->id)]);
    }

    /**
     * تغییر تدریجی لیست: {"add": [...], "remove": [...]}. (به‌جای جایگزینی کل لیست، تا دو دستگاه
     * همدیگه رو پاک نکنن.) نتیجه لیست کامل بعد از تغییره.
     */
    public function update(Request $request): JsonResponse
    {
        $input = $request->json()->all();
        $add = $this->parseIds($input['add'] ?? []);
        $remove = $this->parseIds($input['remove'] ?? []);
        if ($add === null || $remove === null) {
            return ApiError::response(AuthRules::ref('api.invalid'), 422);
        }

        $userId = $request->user()->id;

        $error = DB::transaction(function () use ($userId, $add, $remove): ?JsonResponse {
            $have = FavoriteSong::where('user_id', $userId)->lockForUpdate()->pluck('song_id')->all();
            $after = array_diff($have, $remove);
            $new = array_values(array_diff($add, $after));

            if (count($after) + count($new) > self::MAX_FAVORITES) {
                return ApiError::response(AuthRules::ref('api.limitReached', ['max' => self::MAX_FAVORITES]), 422);
            }

            if ($remove !== []) {
                FavoriteSong::where('user_id', $userId)->whereIn('song_id', $remove)->delete();
            }
            if ($new !== []) {
                $now = now();
                FavoriteSong::insertOrIgnore(array_map(
                    static fn (string $id): array => ['user_id' => $userId, 'song_id' => $id, 'created_at' => $now, 'updated_at' => $now],
                    $new,
                ));
            }

            return null;
        });

        return $error ?? response()->json(['ids' => $this->idsOf($userId)]);
    }

    /** @return list<string> */
    private function idsOf(int $userId): array
    {
        return FavoriteSong::where('user_id', $userId)->orderBy('id')->pluck('song_id')->values()->all();
    }

    /** @return list<string>|null  null یعنی نامعتبر */
    private function parseIds(mixed $ids): ?array
    {
        if (! is_array($ids) || count($ids) > self::MAX_IDS_PER_REQUEST) {
            return null;
        }
        foreach ($ids as $id) {
            if (! is_string($id) || ! preg_match('/^[A-Za-z0-9_.:\-]{1,100}$/D', $id)) {
                return null;
            }
        }

        return array_values(array_unique($ids));
    }
}
