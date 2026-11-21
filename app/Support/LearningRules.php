<?php

namespace App\Support;

use DateTimeImmutable;

/**
 * اعتبارسنجی و ادغام پیشرفت یادگیری (درس‌ها و تمرین گوش)؛ هم‌قانون با applyLessonComplete / applyEarRound
 * توی repositories/learning.ts فرانت. وابستگی به لاراول نداره، تا مستقیم تست‌پذیر باشه.
 *
 * شکل داخلی درس: ['stars' => int|null, 'at' => 'Y-m-d H:i:s.v' (UTC)]
 * شکل داخلی آمار گوش: ['rounds' => int, 'answered' => int, 'correct' => int, 'bestRound' => int]
 */
final class LearningRules
{
    /** همون EAR_MODES فرانت (utils/earTraining.ts) */
    public const EAR_MODES = ['interval', 'chord', 'scale', 'note', 'progression'];

    public const MAX_LESSONS = 500;

    /** سقف تعداد سؤال توی یک دور (فرانت ۱۰ تا می‌فرسته؛ کمی جا برای تغییر آینده) */
    public const MAX_ROUND_TOTAL = 50;

    public const MAX_ROUNDS = 1_000_000;

    /** سقف هر شمارنده بعد از جمع‌شدن، تا از سرریز ستون unsigned int جلوگیری بشه */
    public const COUNTER_CAP = 2_000_000_000;

    public static function isValidLessonId(mixed $id): bool
    {
        return is_string($id) && preg_match('/^[A-Za-z0-9_\-]{1,60}$/D', $id) === 1;
    }

    public static function isValidEarMode(mixed $mode): bool
    {
        return is_string($mode) && in_array($mode, self::EAR_MODES, true);
    }

    /** @return int|null  ستاره‌ی معتبر ۱ تا ۳؛ null یعنی نامعتبر */
    public static function parseStars(mixed $stars): ?int
    {
        return self::isWhole($stars) && $stars >= 1 && $stars <= 3 ? (int) $stars : null;
    }

    /**
     * @param  mixed  $stars  ستاره‌ی درس (اختیاری؛ null یعنی ستاره‌ای نداره). مقدار غیرخالی و نامعتبر کل ورودی رو رد می‌کنه
     * @return array{stars: int|null, at: string}|null  null یعنی نامعتبر
     */
    public static function parseLesson(mixed $stars, mixed $at, ?DateTimeImmutable $now = null): ?array
    {
        if ($stars !== null && self::parseStars($stars) === null) {
            return null;
        }

        return [
            'stars' => $stars === null ? null : self::parseStars($stars),
            'at' => ScoreRules::normalizeAt($at, $now),
        ];
    }

    /**
     * ادغام مثل applyLessonComplete فرانت: زمان اولین تکمیل می‌مونه (زودتر برنده است) و بیشترین ستاره.
     *
     * @param  array{stars: int|null, at: string}|null  $current
     * @param  array{stars: int|null, at: string}  $incoming
     * @return array{stars: int|null, at: string}
     */
    public static function mergeLesson(?array $current, array $incoming): array
    {
        if ($current === null) {
            return $incoming;
        }

        return [
            'stars' => ($current['stars'] === null && $incoming['stars'] === null)
                ? null
                : max($current['stars'] ?? 0, $incoming['stars'] ?? 0),
            // قالب زمان ثابته (Y-m-d H:i:s.v، UTC)، پس مقایسه‌ی رشته‌ای درسته
            'at' => min($current['at'], $incoming['at']),
        ];
    }

    /** @return array{stars?: int, at: string} خروجی API؛ ستاره‌ی خالی توی خروجی نمیاد */
    public static function presentLesson(array $lesson): array
    {
        $out = ['at' => str_replace(' ', 'T', $lesson['at']).'Z'];
        if ($lesson['stars'] !== null) {
            $out['stars'] = $lesson['stars'];
        }

        return $out;
    }

    /**
     * یک دور تمرین گوش: total بین ۱ تا MAX_ROUND_TOTAL و correct بین ۰ تا total.
     *
     * @return array{rounds: int, answered: int, correct: int, bestRound: int}|null  تغییر آمار (دلتا) یا null اگه نامعتبره
     */
    public static function parseEarRound(mixed $correct, mixed $total): ?array
    {
        if (! self::isWhole($correct) || ! self::isWhole($total)) {
            return null;
        }
        if ($total < 1 || $total > self::MAX_ROUND_TOTAL || $correct < 0 || $correct > $total) {
            return null;
        }

        return ['rounds' => 1, 'answered' => (int) $total, 'correct' => (int) $correct, 'bestRound' => (int) $correct];
    }

    /**
     * آمار تجمعی مهمان (برای انتقال به حساب). علاوه بر بازه‌ها، سازگاری داخلی هم چک می‌شه:
     * answered حداکثر rounds×MAX_ROUND_TOTAL، correct ≤ answered، و bestRound ≤ min(correct, MAX_ROUND_TOTAL).
     *
     * @return array{rounds: int, answered: int, correct: int, bestRound: int}|null
     */
    public static function parseEarStat(mixed $stat): ?array
    {
        if (! is_array($stat)) {
            return null;
        }
        $rounds = $stat['rounds'] ?? null;
        $answered = $stat['answered'] ?? null;
        $correct = $stat['correct'] ?? null;
        $best = $stat['bestRound'] ?? null;

        foreach ([$rounds, $answered, $correct, $best] as $n) {
            if (! self::isWhole($n) || $n < 0) {
                return null;
            }
        }
        if ($rounds > self::MAX_ROUNDS
            || $answered > $rounds * self::MAX_ROUND_TOTAL
            || $correct > $answered
            || $best > min($correct, self::MAX_ROUND_TOTAL)) {
            return null;
        }

        return ['rounds' => (int) $rounds, 'answered' => (int) $answered, 'correct' => (int) $correct, 'bestRound' => (int) $best];
    }

    /**
     * جمع دلتا با آمار فعلی (مثل applyEarRound فرانت): شمارنده‌ها جمع می‌شن و bestRound بیشینه می‌شه.
     *
     * @param  array{rounds: int, answered: int, correct: int, bestRound: int}|null  $current
     * @param  array{rounds: int, answered: int, correct: int, bestRound: int}  $delta
     * @return array{rounds: int, answered: int, correct: int, bestRound: int}
     */
    public static function mergeEar(?array $current, array $delta): array
    {
        $current ??= ['rounds' => 0, 'answered' => 0, 'correct' => 0, 'bestRound' => 0];

        return [
            'rounds' => min(self::COUNTER_CAP, $current['rounds'] + $delta['rounds']),
            'answered' => min(self::COUNTER_CAP, $current['answered'] + $delta['answered']),
            'correct' => min(self::COUNTER_CAP, $current['correct'] + $delta['correct']),
            'bestRound' => max($current['bestRound'], $delta['bestRound']),
        ];
    }

    private static function isWhole(mixed $value): bool
    {
        return is_int($value) || (is_float($value) && is_finite($value) && floor($value) === $value);
    }
}