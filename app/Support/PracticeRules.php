<?php

namespace App\Support;

use DateTimeImmutable;
use DateTimeZone;

/**
 * اعتبارسنجی و ادغام داده‌ی تمرین روزانه (زمان هر روز، XP، هدف، نشان‌ها، شمارنده‌ها، یادآور)؛
 * هم‌قانون با repositories/practice.ts و utils/dailyPractice.ts فرانت. وابستگی به لاراول نداره، تا مستقیم تست‌پذیر باشه.
 *
 * شکل داخلی روز: ['ms' => int, 'xp' => float]
 * شکل داخلی متادیتا: ['xp' => float, 'goalMin' => int, 'badges' => [id => 'Y-m-d H:i:s.v' (UTC)],
 *   'counters' => [کلید => int], 'reminder' => ['on' => bool, 'time' => 'HH:MM'], 'reminded' => 'YYYY-MM-DD' یا '']
 *
 * قانون ادغام (همون که توی repositories/practice.ts نوشته شده):
 *  - هر روز: بیشترین ms و بیشترین xp (نه آخرین مقدار)
 *  - xp و شمارنده‌ها: بیشینه
 *  - نشان‌ها: اجتماع (زمان زودتر می‌مونه)
 *  - تنظیمات (هدف، یادآور): آخرین مقدار
 * XP و نشان‌ها فعلاً سمت کلاینت حساب می‌شن؛ این‌جا فقط بازه‌ها چک می‌شن، نه درستیِ محاسبه.
 */
final class PracticeRules
{
    /** همون GOAL_OPTIONS و DEFAULT_GOAL فرانت (utils/dailyPractice.ts) */
    public const GOAL_OPTIONS = [5, 10, 15, 20];

    public const DEFAULT_GOAL = 10;

    public const DEFAULT_REMINDER_TIME = '19:00';

    /** همون کلیدهای Counters فرانت */
    public const COUNTER_KEYS = ['lessons', 'earRounds', 'perfectEar', 'songRuns', 'threeStars', 'perfectRuns', 'goalDays'];

    /** همون idهای BADGES فرانت؛ نشان ناشناخته نادیده گرفته می‌شه (سازگار با نسخه‌ی جدیدتر فرانت) */
    public const BADGE_IDS = [
        'firstPractice', 'streak3', 'streak7', 'streak30', 'hour1', 'hour10', 'goal1', 'goal7',
        'lesson1', 'lessonsAll', 'ear1', 'earPerfect', 'song1', 'threeStars', 'perfect', 'level5',
    ];

    /** سقف زمان تمرین یک روز (۲۴ ساعت) */
    public const MAX_DAY_MS = 86_400_000;

    /** سقف XP یک روز (۲۴ ساعت تمرین ≈ ۷۲۰۰ XP، به‌اضافه‌ی درس و آهنگ؛ این سقف فقط جلوی عدد بی‌معنی رو می‌گیره) */
    public const MAX_DAY_XP = 20_000.0;

    public const MAX_XP = 10_000_000.0;

    /** همون سقف شمارنده‌های یادگیری، تا از سرریز ستون unsigned int جلوگیری بشه */
    public const COUNTER_CAP = LearningRules::COUNTER_CAP;

    /** فرانت فقط ۸۰۰ روز آخر رو نگه می‌داره؛ API هم همین‌قدر برمی‌گردونه */
    public const MAX_DAYS = 800;

    /** قدیمی‌تر از این تاریخ معتبر نیست */
    public const MIN_DAY = '2020-01-01';

    /** تاریخ محلی کاربر می‌تونه تا ~۱۴ ساعت جلوتر از UTC باشه؛ دو روز جا برای اختلاف ساعت هم هست */
    public const MAX_DAYS_AHEAD = 2;

    /** «YYYY-MM-DD» معتبر (تاریخ واقعی تقویم) و توی بازه‌ی مجاز */
    public static function isValidDay(mixed $day, ?DateTimeImmutable $now = null): bool
    {
        if (! is_string($day) || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $day, $m) !== 1) {
            return false;
        }
        if (! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return false;
        }

        $utc = new DateTimeZone('UTC');
        $today = ($now ?? new DateTimeImmutable('now', $utc))->setTimezone($utc);

        // قالب ثابته (YYYY-MM-DD)، پس مقایسه‌ی رشته‌ای درسته
        return $day >= self::MIN_DAY && $day <= $today->modify('+'.self::MAX_DAYS_AHEAD.' days')->format('Y-m-d');
    }

    /** «HH:MM» معتبر (۲۴ ساعته) */
    public static function isTime(mixed $value): bool
    {
        return is_string($value) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/D', $value) === 1;
    }

    /**
     * رکورد یک روز. ms اگه اعشاری بیاد گرد می‌شه (نباید رد بشه، وگرنه ذخیره‌ی اون روز همیشه شکست می‌خوره).
     *
     * @return array{ms: int, xp: float}|null  null یعنی نامعتبر
     */
    public static function parseDay(mixed $record): ?array
    {
        if (! is_array($record)) {
            return null;
        }
        $ms = $record['ms'] ?? null;
        $xp = $record['xp'] ?? null;

        if (! self::isNumber($ms) || $ms < 0 || $ms > self::MAX_DAY_MS) {
            return null;
        }
        if (! self::isNumber($xp) || $xp < 0 || $xp > self::MAX_DAY_XP) {
            return null;
        }

        return ['ms' => (int) round($ms), 'xp' => round((float) $xp, 2)];
    }

    /**
     * ادغام یک روز: بیشترین ms و بیشترین xp.
     *
     * @param  array{ms: int, xp: float}|null  $current
     * @param  array{ms: int, xp: float}  $incoming
     * @return array{ms: int, xp: float}
     */
    public static function mergeDay(?array $current, array $incoming): array
    {
        if ($current === null) {
            return $incoming;
        }

        return ['ms' => max($current['ms'], $incoming['ms']), 'xp' => max($current['xp'], $incoming['xp'])];
    }

    /**
     * شمارنده‌ها: کلید گم‌شده صفر حساب می‌شه، کلید ناشناخته نادیده گرفته می‌شه، و مقدار نامعتبر کل ورودی رو رد می‌کنه.
     *
     * @return array<string, int>|null
     */
    public static function parseCounters(mixed $counters): ?array
    {
        if (! is_array($counters)) {
            return null;
        }

        $out = [];
        foreach (self::COUNTER_KEYS as $key) {
            $n = $counters[$key] ?? 0;
            if (! self::isWhole($n) || $n < 0 || $n > self::COUNTER_CAP) {
                return null;
            }
            $out[$key] = (int) $n;
        }

        return $out;
    }

    /**
     * @param  array<string, int>  $current
     * @param  array<string, int>  $incoming
     * @return array<string, int>
     */
    public static function mergeCounters(array $current, array $incoming): array
    {
        $out = [];
        foreach (self::COUNTER_KEYS as $key) {
            $out[$key] = max($current[$key] ?? 0, $incoming[$key] ?? 0);
        }

        return $out;
    }

    /**
     * نشان‌ها: {id: زمان کسب (ISO)}. فقط idهای شناخته‌شده می‌مونن؛ زمان نامعتبر یا آینده‌ی دور با «الان» عوض می‌شه.
     *
     * @return array<string, string>|null  id ← 'Y-m-d H:i:s.v' (UTC)
     */
    public static function parseBadges(mixed $badges, ?DateTimeImmutable $now = null): ?array
    {
        if (! is_array($badges)) {
            return null;
        }

        $out = [];
        foreach ($badges as $id => $at) {
            $id = (string) $id;
            if (in_array($id, self::BADGE_IDS, true)) {
                $out[$id] = ScoreRules::normalizeAt($at, $now);
            }
        }

        return $out;
    }

    /**
     * اجتماع دو مجموعه نشان؛ برای نشان تکراری زمان زودتر می‌مونه (قالب زمان ثابته، پس مقایسه‌ی رشته‌ای درسته).
     *
     * @param  array<string, string>  $current
     * @param  array<string, string>  $incoming
     * @return array<string, string>
     */
    public static function mergeBadges(array $current, array $incoming): array
    {
        $out = $current;
        foreach ($incoming as $id => $at) {
            $out[$id] = isset($out[$id]) ? min($out[$id], $at) : $at;
        }

        return $out;
    }

    /** @return array{on: bool, time: string}|null */
    public static function parseReminder(mixed $reminder): ?array
    {
        if (! is_array($reminder) || ! is_bool($reminder['on'] ?? null) || ! self::isTime($reminder['time'] ?? null)) {
            return null;
        }

        return ['on' => $reminder['on'], 'time' => $reminder['time']];
    }

    /**
     * متادیتای کامل (PracticeMeta فرانت). همه‌ی کلیدها باید باشن چون تنظیمات «آخرین مقدار» می‌شن و
     * کلید گم‌شده نباید ناخواسته مقدار پیش‌فرض رو جایگزین کنه.
     *
     * @return array{xp: float, goalMin: int, badges: array<string, string>, counters: array<string, int>, reminder: array{on: bool, time: string}, reminded: string}|null
     */
    public static function parseMeta(mixed $meta, ?DateTimeImmutable $now = null): ?array
    {
        if (! is_array($meta)) {
            return null;
        }

        $xp = $meta['xp'] ?? null;
        $goal = $meta['goalMin'] ?? null;
        $reminded = $meta['reminded'] ?? null;

        if (! self::isNumber($xp) || $xp < 0 || $xp > self::MAX_XP) {
            return null;
        }
        if (! self::isWhole($goal) || ! in_array((int) $goal, self::GOAL_OPTIONS, true)) {
            return null;
        }
        // «آخرین روزی که یادآور نشون داده شد»؛ خالی یعنی هیچ‌وقت
        if ($reminded !== '' && ! self::isValidDay($reminded, $now)) {
            return null;
        }

        $counters = self::parseCounters($meta['counters'] ?? null);
        $badges = self::parseBadges($meta['badges'] ?? null, $now);
        $reminder = self::parseReminder($meta['reminder'] ?? null);
        if ($counters === null || $badges === null || $reminder === null) {
            return null;
        }

        return [
            'xp' => round((float) $xp, 2),
            'goalMin' => (int) $goal,
            'badges' => $badges,
            'counters' => $counters,
            'reminder' => $reminder,
            'reminded' => $reminded,
        ];
    }

    /**
     * ادغام متادیتا: xp و شمارنده‌ها بیشینه، نشان‌ها اجتماع. تنظیمات (هدف، یادآور) اگه $takeSettings باشه از ورودی میاد،
     * وگرنه همون تنظیمات فعلی می‌مونه (برای انتقال مهمان به حسابی که از قبل داده داره).
     * اگه هنوز چیزی ذخیره نشده ($current خالی) کل ورودی پذیرفته می‌شه.
     *
     * @param  array<string, mixed>|null  $current
     * @param  array<string, mixed>  $incoming
     * @return array<string, mixed>
     */
    public static function mergeMeta(?array $current, array $incoming, bool $takeSettings = true): array
    {
        if ($current === null) {
            return $incoming;
        }

        $settings = $takeSettings ? $incoming : $current;

        return [
            'xp' => max($current['xp'], $incoming['xp']),
            'goalMin' => $settings['goalMin'],
            'badges' => self::mergeBadges($current['badges'], $incoming['badges']),
            'counters' => self::mergeCounters($current['counters'], $incoming['counters']),
            'reminder' => $settings['reminder'],
            'reminded' => $settings['reminded'],
        ];
    }

    /** متادیتای کاربری که هنوز چیزی ذخیره نکرده (همون emptyPractice فرانت) */
    public static function emptyMeta(): array
    {
        return [
            'xp' => 0.0,
            'goalMin' => self::DEFAULT_GOAL,
            'badges' => [],
            'counters' => array_fill_keys(self::COUNTER_KEYS, 0),
            'reminder' => ['on' => false, 'time' => self::DEFAULT_REMINDER_TIME],
            'reminded' => '',
        ];
    }

    /** خروجی API: زمان نشان‌ها ISO-8601 UTC و badges همیشه آبجکت ({} وقتی خالیه، نه []) */
    public static function presentMeta(array $meta): array
    {
        return [
            'xp' => (float) $meta['xp'],
            'goalMin' => $meta['goalMin'],
            'badges' => (object) array_map(fn (string $at) => str_replace(' ', 'T', $at).'Z', $meta['badges']),
            'counters' => $meta['counters'],
            'reminder' => $meta['reminder'],
            'reminded' => $meta['reminded'],
        ];
    }

    private static function isNumber(mixed $value): bool
    {
        return is_int($value) || (is_float($value) && is_finite($value));
    }

    private static function isWhole(mixed $value): bool
    {
        return is_int($value) || (is_float($value) && is_finite($value) && floor($value) === $value);
    }
}