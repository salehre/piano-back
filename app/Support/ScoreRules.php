<?php

namespace App\Support;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * اعتبارسنجی و ادغام نتیجه‌ی آهنگ‌ها؛ هم‌قانون با useProgress / repositories/progress.ts فرانت.
 * وابستگی به لاراول نداره، تا مستقیم تست‌پذیر باشه.
 *
 * شکل داخلی هر نتیجه: ['stars' => int, 'accuracy' => float, 'plays' => int, 'at' => 'Y-m-d H:i:s.v' (UTC)]
 */
final class ScoreRules
{
    public const MAX_PLAYS = 1_000_000;

    /** کلید نتیجه: آهنگ:حالت:دست:سرعت (فرانت با scoreKey می‌سازه) */
    public static function isValidKey(mixed $key): bool
    {
        return is_string($key) && preg_match('/^[A-Za-z0-9_.:\-]{1,160}$/D', $key) === 1;
    }

    /** @return array{stars: int, accuracy: float, plays: int, at: string}|null  null یعنی نامعتبر */
    public static function parseEntry(mixed $entry, ?DateTimeImmutable $now = null): ?array
    {
        if (! is_array($entry)) {
            return null;
        }
        $stars = $entry['stars'] ?? null;
        $accuracy = $entry['accuracy'] ?? null;
        $plays = $entry['plays'] ?? null;

        if (! self::isWholeNumber($stars) || $stars < 0 || $stars > 3) {
            return null;
        }
        if ((! is_int($accuracy) && ! is_float($accuracy)) || ! is_finite((float) $accuracy) || $accuracy < 0 || $accuracy > 1) {
            return null;
        }
        if (! self::isWholeNumber($plays) || $plays < 0 || $plays > self::MAX_PLAYS) {
            return null;
        }

        return [
            'stars' => (int) $stars,
            'accuracy' => (float) $accuracy,
            'plays' => (int) $plays,
            'at' => self::normalizeAt($entry['at'] ?? null, $now),
        ];
    }

    /**
     * ادغام مثل mergeScores فرانت: نتیجه‌ی با دقت بالاتر برنده است (ستاره و زمانش هم از همون)،
     * و تعداد دفعات بازی بیشینه‌ی دو طرفه. در برابری، رکورد فعلی می‌مونه.
     *
     * @param  array{stars: int, accuracy: float, plays: int, at: string}|null  $current
     * @param  array{stars: int, accuracy: float, plays: int, at: string}  $incoming
     * @return array{stars: int, accuracy: float, plays: int, at: string}
     */
    public static function merge(?array $current, array $incoming): array
    {
        if ($current === null) {
            return $incoming;
        }
        $winner = $incoming['accuracy'] > $current['accuracy'] ? $incoming : $current;
        $winner['plays'] = max($current['plays'], $incoming['plays']);

        return $winner;
    }

    /** خروجی API: زمان به شکل ISO-8601 UTC */
    public static function present(array $entry): array
    {
        return [
            'stars' => $entry['stars'],
            'accuracy' => $entry['accuracy'],
            'plays' => $entry['plays'],
            'at' => str_replace(' ', 'T', $entry['at']).'Z',
        ];
    }

    private static function isWholeNumber(mixed $value): bool
    {
        return is_int($value) || (is_float($value) && is_finite($value) && floor($value) === $value);
    }

    /** زمان نامعتبر یا آینده‌ی دور رو با «الان» عوض می‌کنه (ساعت دستگاه کاربر قابل‌اعتماد نیست) */
    private static function normalizeAt(mixed $at, ?DateTimeImmutable $now): string
    {
        $utc = new DateTimeZone('UTC');
        $now ??= new DateTimeImmutable('now', $utc);
        $format = 'Y-m-d H:i:s.v';

        if (! is_string($at) || ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d{1,6})?(Z|[+-]\d{2}:\d{2})$/D', $at)) {
            return $now->format($format);
        }
        try {
            $date = (new DateTimeImmutable($at))->setTimezone($utc);
        } catch (Throwable) {
            return $now->format($format);
        }
        if ($date > $now->modify('+1 day') || (int) $date->format('Y') < 2020) {
            return $now->format($format);
        }

        return $date->format($format);
    }
}
