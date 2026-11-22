<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PracticeDay;
use App\Models\PracticeProfile;
use App\Support\ApiError;
use App\Support\AuthRules;
use App\Support\PracticeRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PracticeController extends Controller
{
    /** سقف تعداد روز توی یک درخواست ادغام (فرانت حداکثر ۸۰۰ روز نگه می‌داره) */
    private const MAX_MERGE_DAYS = 1000;

    /** هر upsert حداکثر این‌قدر ردیف می‌گیره، تا از سقف تعداد پارامتر دیتابیس رد نشیم */
    private const UPSERT_CHUNK = 200;

    /**
     * همه‌ی داده‌ی تمرین کاربر، به شکل PracticeData فرانت:
     * {"v":1, "days":{"YYYY-MM-DD":{ms, xp}}, "xp", "goalMin", "badges":{id: زمان}, "counters":{...}, "reminder":{on, time}, "reminded"}
     * فقط ۸۰۰ روز آخر برمی‌گرده (همون سقف فرانت).
     */
    public function index(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        $days = [];
        $rows = PracticeDay::where('user_id', $userId)->orderByDesc('day')->limit(PracticeRules::MAX_DAYS)->get();
        foreach ($rows as $row) {
            $days[(string) $row->day] = $row->toMergeArray();
        }
        ksort($days);

        $meta = PracticeProfile::where('user_id', $userId)->first()?->toMergeArray() ?? PracticeRules::emptyMeta();

        // (object): وقتی خالیه {} برگرده نه []
        return response()->json(['v' => 1, 'days' => (object) $days] + PracticeRules::presentMeta($meta));
    }

    /** ثبت یک روز؛ سرور برای هر روز بیشترین ms و xp رو نگه می‌داره (ثبت مقدار کمتر چیزی رو پایین نمیاره) */
    public function storeDay(Request $request, string $day): JsonResponse
    {
        $record = PracticeRules::parseDay($request->json()->all());
        if (! PracticeRules::isValidDay($day) || $record === null) {
            return ApiError::response(AuthRules::ref('api.invalid'), 422);
        }

        $merged = $this->mergeDays($request->user()->id, [$day => $record]);

        return response()->json(['day' => $merged[$day]]);
    }

    /**
     * ثبت متادیتا (همه‌ی فیلدهای PracticeMeta): xp و شمارنده‌ها بیشینه، نشان‌ها اجتماع، تنظیمات (هدف/یادآور) آخرین مقدار.
     */
    public function storeMeta(Request $request): JsonResponse
    {
        $meta = PracticeRules::parseMeta($this->withEmptyReminded($request->json()->all()));
        if ($meta === null) {
            return ApiError::response(AuthRules::ref('api.invalid'), 422);
        }

        $merged = $this->mergeMeta($request->user()->id, $meta, takeSettings: true);

        return response()->json(['meta' => PracticeRules::presentMeta($merged)]);
    }

    /**
     * انتقال تمرین مهمان به حساب: {"days":{"YYYY-MM-DD":{ms, xp}}, "meta":{...PracticeMeta}}.
     * همه‌ی قوانین «بیشینه / اجتماع» هستن، پس تکرار درخواست چیزی رو دوبرابر نمی‌کنه. روزهای نامعتبر رد می‌شن و شمرده
     * می‌شن؛ ولی متادیتای نامعتبر کل درخواست رو رد می‌کنه (422، قبل از هر نوشتن)، تا فرانت داده‌ی مهمان رو پاک نکنه.
     * تنظیمات مهمان (هدف/یادآور) فقط وقتی استفاده می‌شن که حساب هنوز چیزی ذخیره نکرده باشه.
     */
    public function merge(Request $request): JsonResponse
    {
        $input = $request->json()->all();
        $days = $input['days'] ?? [];
        if (! is_array($days) || count($days) > self::MAX_MERGE_DAYS) {
            return ApiError::response(AuthRules::ref('api.invalid'), 422);
        }

        $meta = null;
        if (($input['meta'] ?? null) !== null) {
            $meta = PracticeRules::parseMeta($this->withEmptyReminded($input['meta']));
            if ($meta === null) {
                return ApiError::response(AuthRules::ref('api.invalid'), 422);
            }
        }

        $valid = [];
        $skipped = 0;
        foreach ($days as $day => $record) {
            $day = (string) $day;
            $parsed = PracticeRules::isValidDay($day) ? PracticeRules::parseDay($record) : null;
            if ($parsed === null) {
                $skipped++;

                continue;
            }
            $valid[$day] = $parsed;
        }

        $userId = $request->user()->id;
        if ($valid !== []) {
            $this->mergeDays($userId, $valid);
        }
        if ($meta !== null) {
            $this->mergeMeta($userId, $meta, takeSettings: false);
        }

        return response()->json(['merged' => count($valid) + ($meta !== null ? 1 : 0), 'skipped' => $skipped]);
    }

    /**
     * ConvertEmptyStringsToNull لاراول «reminded: ''» رو توی بدنه‌ی JSON هم null می‌کنه، ولی برای این فیلد
     * خالی ('') یعنی «هیچ‌وقت یادآور نشون داده نشده» و فرانت برای کاربر تازه همیشه همین رو می‌فرسته.
     */
    private function withEmptyReminded(mixed $meta): mixed
    {
        if (is_array($meta) && array_key_exists('reminded', $meta) && $meta['reminded'] === null) {
            $meta['reminded'] = '';
        }

        return $meta;
    }

    /**
     * @param  array<string, array{ms: int, xp: float}>  $entries  روز ← رکورد
     * @return array<string, array{ms: int, xp: float}> نتیجه‌ی نهایی هر روز
     */
    private function mergeDays(int $userId, array $entries): array
    {
        return DB::transaction(function () use ($userId, $entries) {
            $existing = PracticeDay::where('user_id', $userId)
                ->whereIn('day', array_map('strval', array_keys($entries)))
                ->lockForUpdate()
                ->get()
                ->keyBy(fn (PracticeDay $row) => (string) $row->day);

            $now = now();
            $rows = [];
            $result = [];
            foreach ($entries as $day => $incoming) {
                $day = (string) $day;
                $merged = PracticeRules::mergeDay($existing->get($day)?->toMergeArray(), $incoming);
                $result[$day] = $merged;
                $rows[] = [
                    'user_id' => $userId,
                    'day' => $day,
                    'ms' => $merged['ms'],
                    'xp' => $merged['xp'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            foreach (array_chunk($rows, self::UPSERT_CHUNK) as $chunk) {
                PracticeDay::upsert($chunk, ['user_id', 'day'], ['ms', 'xp', 'updated_at']);
            }

            return $result;
        });
    }

    /**
     * @param  array<string, mixed>  $incoming  خروجی PracticeRules::parseMeta
     * @return array<string, mixed> متادیتای نهایی
     */
    private function mergeMeta(int $userId, array $incoming, bool $takeSettings): array
    {
        return DB::transaction(function () use ($userId, $incoming, $takeSettings) {
            $row = PracticeProfile::where('user_id', $userId)->lockForUpdate()->first();
            $merged = PracticeRules::mergeMeta($row?->toMergeArray(), $incoming, $takeSettings);

            PracticeProfile::upsert(
                [PracticeProfile::toRow($userId, $merged, now())],
                ['user_id'],
                PracticeProfile::updatableColumns(),
            );

            return $merged;
        });
    }
}