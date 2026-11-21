<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EarStat;
use App\Models\LessonProgress;
use App\Support\ApiError;
use App\Support\AuthRules;
use App\Support\LearningRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LearningController extends Controller
{
    /**
     * همه‌ی پیشرفت یادگیری کاربر، به شکل LearningData فرانت:
     * {"v":1, "lessons":{"<id>":"<زمان تکمیل>"}, "stars":{"<id>":1..3}, "ear":{"<mode>":{rounds, answered, correct, bestRound}}}
     */
    public function index(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        $lessons = [];
        $stars = [];
        foreach (LessonProgress::where('user_id', $userId)->get() as $row) {
            $entry = $row->toApiEntry();
            $lessons[$row->lesson_id] = $entry['at'];
            if (isset($entry['stars'])) {
                $stars[$row->lesson_id] = $entry['stars'];
            }
        }

        $ear = array_fill_keys(LearningRules::EAR_MODES, ['rounds' => 0, 'answered' => 0, 'correct' => 0, 'bestRound' => 0]);
        foreach (EarStat::where('user_id', $userId)->get() as $row) {
            if (isset($ear[$row->mode])) {
                $ear[$row->mode] = $row->toStatArray();
            }
        }

        // (object): وقتی خالیه {} برگرده نه []
        return response()->json(['v' => 1, 'lessons' => (object) $lessons, 'stars' => (object) $stars, 'ear' => $ear]);
    }

    /** ثبت تکمیل درس با ستاره‌ی این اجرا؛ سرور زمان اولین تکمیل و بیشترین ستاره رو نگه می‌داره */
    public function completeLesson(Request $request, string $lessonId): JsonResponse
    {
        $lesson = LearningRules::parseLesson($request->json()->get('stars'), null);
        // ستاره توی این endpoint اجباریه (فرانت همیشه ۱ تا ۳ می‌فرسته)
        if (! LearningRules::isValidLessonId($lessonId) || $lesson === null || $lesson['stars'] === null) {
            return ApiError::response(AuthRules::ref('api.invalid'), 422);
        }

        $merged = $this->mergeLessons($request->user()->id, [$lessonId => $lesson]);
        if ($merged === null) {
            return ApiError::response(AuthRules::ref('api.limitReached', ['max' => LearningRules::MAX_LESSONS]), 422);
        }

        return response()->json(['lesson' => LearningRules::presentLesson($merged[$lessonId])]);
    }

    /** ثبت یک دور تمرین گوش؛ سرور آمار رو جمع می‌زنه */
    public function earRound(Request $request): JsonResponse
    {
        $input = $request->json()->all();
        $mode = $input['mode'] ?? null;
        $delta = LearningRules::parseEarRound($input['correct'] ?? null, $input['total'] ?? null);

        if (! LearningRules::isValidEarMode($mode) || $delta === null) {
            return ApiError::response(AuthRules::ref('api.invalid'), 422);
        }

        $merged = $this->mergeEar($request->user()->id, [$mode => $delta]);

        return response()->json(['ear' => $merged[$mode]]);
    }

    /**
     * انتقال پیشرفت مهمان به حساب: {"lessons":{id: زمان}, "stars":{id: n}, "ear":{mode: آمار}}.
     * درس‌ها ادغام می‌شن (اولین زمان + بیشترین ستاره) و آمار گوش به آمار حساب اضافه می‌شه.
     * ورودی‌های نامعتبر رد می‌شن و شمرده می‌شن. فرانت بعد از موفقیت داده‌ی مهمان رو پاک می‌کنه، پس این endpoint
     * رو فقط یک بار برای هر داده‌ی مهمان صدا می‌زنه.
     */
    public function merge(Request $request): JsonResponse
    {
        $input = $request->json()->all();
        $lessons = $input['lessons'] ?? [];
        $stars = $input['stars'] ?? [];
        $ear = $input['ear'] ?? [];

        if (! is_array($lessons) || ! is_array($stars) || ! is_array($ear)
            || count($lessons) > LearningRules::MAX_LESSONS || count($stars) > LearningRules::MAX_LESSONS) {
            return ApiError::response(AuthRules::ref('api.invalid'), 422);
        }

        $skipped = 0;

        $validLessons = [];
        foreach ($lessons as $id => $at) {
            $id = (string) $id;
            // ستاره‌ی درس از «stars» میاد؛ فقط درس‌های تمام‌شده (کلید توی «lessons») شمرده می‌شن
            $parsed = LearningRules::isValidLessonId($id) ? LearningRules::parseLesson($stars[$id] ?? null, $at) : null;
            if ($parsed === null) {
                $skipped++;

                continue;
            }
            $validLessons[$id] = $parsed;
        }

        $validEar = [];
        foreach ($ear as $mode => $stat) {
            $parsed = LearningRules::isValidEarMode($mode) ? LearningRules::parseEarStat($stat) : null;
            if ($parsed === null) {
                $skipped++;

                continue;
            }
            if ($parsed['rounds'] > 0) {
                $validEar[$mode] = $parsed;
            }
        }

        $userId = $request->user()->id;

        if ($validLessons !== [] && $this->mergeLessons($userId, $validLessons) === null) {
            return ApiError::response(AuthRules::ref('api.limitReached', ['max' => LearningRules::MAX_LESSONS]), 422);
        }
        if ($validEar !== []) {
            $this->mergeEar($userId, $validEar);
        }

        return response()->json(['merged' => count($validLessons) + count($validEar), 'skipped' => $skipped]);
    }

    /**
     * @param  array<string, array{stars: int|null, at: string}>  $entries
     * @return array<string, array{stars: int|null, at: string}>|null  نتیجه‌ی نهایی هر درس؛ null اگه از سقف تعداد درس‌ها رد می‌شد (هیچی نوشته نمی‌شه)
     */
    private function mergeLessons(int $userId, array $entries): ?array
    {
        return DB::transaction(function () use ($userId, $entries) {
            $existing = LessonProgress::where('user_id', $userId)
                ->whereIn('lesson_id', array_map('strval', array_keys($entries)))
                ->lockForUpdate()
                ->get()
                ->keyBy('lesson_id');

            $newCount = count($entries) - $existing->count();
            if ($newCount > 0 && LessonProgress::where('user_id', $userId)->count() + $newCount > LearningRules::MAX_LESSONS) {
                return null;
            }

            $now = now();
            $rows = [];
            $result = [];
            foreach ($entries as $id => $incoming) {
                $id = (string) $id;
                $merged = LearningRules::mergeLesson($existing->get($id)?->toMergeArray(), $incoming);
                $result[$id] = $merged;
                $rows[] = [
                    'user_id' => $userId,
                    'lesson_id' => $id,
                    'stars' => $merged['stars'],
                    'completed_at' => $merged['at'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            LessonProgress::upsert($rows, ['user_id', 'lesson_id'], ['stars', 'completed_at', 'updated_at']);

            return $result;
        });
    }

    /**
     * @param  array<string, array{rounds: int, answered: int, correct: int, bestRound: int}>  $deltas  mode ← تغییر آمار
     * @return array<string, array{rounds: int, answered: int, correct: int, bestRound: int}>  آمار نهایی هر حالت
     */
    private function mergeEar(int $userId, array $deltas): array
    {
        return DB::transaction(function () use ($userId, $deltas) {
            $existing = EarStat::where('user_id', $userId)
                ->whereIn('mode', array_map('strval', array_keys($deltas)))
                ->lockForUpdate()
                ->get()
                ->keyBy('mode');

            $now = now();
            $rows = [];
            $result = [];
            foreach ($deltas as $mode => $delta) {
                $mode = (string) $mode;
                $merged = LearningRules::mergeEar($existing->get($mode)?->toStatArray(), $delta);
                $result[$mode] = $merged;
                $rows[] = [
                    'user_id' => $userId,
                    'mode' => $mode,
                    'rounds' => $merged['rounds'],
                    'answered' => $merged['answered'],
                    'correct' => $merged['correct'],
                    'best_round' => $merged['bestRound'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            EarStat::upsert($rows, ['user_id', 'mode'], ['rounds', 'answered', 'correct', 'best_round', 'updated_at']);

            return $result;
        });
    }
}