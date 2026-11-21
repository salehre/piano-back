<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SongScore;
use App\Support\ApiError;
use App\Support\AuthRules;
use App\Support\ScoreRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProgressController extends Controller
{
    /** سقف تعداد کلید توی یک درخواست ادغام (فرانت تکه‌تکه می‌فرسته) */
    private const MAX_MERGE_KEYS = 500;

    /** همه‌ی بهترین نتیجه‌های کاربر: {"scores": {"<key>": {stars, accuracy, plays, at}}} */
    public function index(Request $request): JsonResponse
    {
        $scores = [];
        foreach (SongScore::where('user_id', $request->user()->id)->get() as $row) {
            $scores[$row->score_key] = $row->toApiEntry();
        }

        // (object): وقتی خالیه {} برگرده نه []
        return response()->json(['scores' => (object) $scores]);
    }

    /** ثبت نتیجه‌ی یک کلید؛ سرور خودش بهترین رو نگه می‌داره (ثبت نتیجه‌ی ضعیف‌تر رکورد رو پایین نمیاره) */
    public function store(Request $request): JsonResponse
    {
        $input = $request->json()->all();
        $key = $input['key'] ?? null;
        $entry = ScoreRules::parseEntry($input);

        if (! ScoreRules::isValidKey($key) || $entry === null) {
            return ApiError::response(AuthRules::ref('api.invalid'), 422);
        }

        $merged = $this->mergeInto($request->user()->id, [$key => $entry]);

        return response()->json(['score' => ScoreRules::present($merged[$key])]);
    }

    /** ادغام گروهی (انتقال نتیجه‌های مهمان به حساب). ورودی‌های نامعتبر رد می‌شن و شمرده می‌شن */
    public function merge(Request $request): JsonResponse
    {
        $scores = $request->json()->all()['scores'] ?? null;
        if (! is_array($scores) || count($scores) > self::MAX_MERGE_KEYS) {
            return ApiError::response(AuthRules::ref('api.invalid'), 422);
        }

        $valid = [];
        $skipped = 0;
        foreach ($scores as $key => $entry) {
            $key = (string) $key;
            $parsed = ScoreRules::isValidKey($key) ? ScoreRules::parseEntry($entry) : null;
            if ($parsed === null) {
                $skipped++;

                continue;
            }
            $valid[$key] = $parsed;
        }

        if ($valid !== []) {
            $this->mergeInto($request->user()->id, $valid);
        }

        return response()->json(['merged' => count($valid), 'skipped' => $skipped]);
    }

    /**
     * @param  array<string, array{stars: int, accuracy: float, plays: int, at: string}>  $entries
     * @return array<string, array{stars: int, accuracy: float, plays: int, at: string}> نتیجه‌ی نهایی هر کلید
     */
    private function mergeInto(int $userId, array $entries): array
    {
        return DB::transaction(function () use ($userId, $entries) {
            $existing = SongScore::where('user_id', $userId)
                ->whereIn('score_key', array_map('strval', array_keys($entries)))
                ->lockForUpdate()
                ->get()
                ->keyBy('score_key');

            $now = now();
            $rows = [];
            $result = [];
            foreach ($entries as $key => $incoming) {
                $key = (string) $key;
                $current = $existing->get($key);
                $merged = ScoreRules::merge($current?->toMergeArray(), $incoming);
                $result[$key] = $merged;
                $rows[] = [
                    'user_id' => $userId,
                    'score_key' => $key,
                    'stars' => $merged['stars'],
                    'accuracy' => $merged['accuracy'],
                    'plays' => $merged['plays'],
                    'played_at' => $merged['at'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            SongScore::upsert($rows, ['user_id', 'score_key'], ['stars', 'accuracy', 'plays', 'played_at', 'updated_at']);

            return $result;
        });
    }
}
