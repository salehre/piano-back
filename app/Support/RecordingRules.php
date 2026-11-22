<?php

namespace App\Support;

/**
 * قوانین ضبط‌ها؛ هم‌قانون با composables/useRecordings.ts و RecEvent فرانت.
 * وابستگی به لاراول نداره، تا مستقیم تست‌پذیر باشه.
 *
 * شکل داخلی رویداد: ['t' => float (ms از شروع ضبط), 'note' => 'C#4', 'on' => bool, 'velocity' => float 0..1]
 */
final class RecordingRules
{
    public const MAX_NAME_LENGTH = 60;

    public const DEFAULT_NAME = 'Recording';

    public const MAX_EVENTS = 20000;

    /** بیشترین طول ضبط: ۳۰ دقیقه */
    public const MAX_DURATION_MS = 1_800_000;

    /** سقف فایل صدا (WAV خام حدود ۱۰ مگابایت در دقیقه‌ست)؛ upload_max_filesize و post_max_size پی‌اچ‌پی باید ازش بیشتر باشن */
    public const MAX_AUDIO_BYTES = 30 * 1024 * 1024;

    public static function isValidId(mixed $id): bool
    {
        return is_string($id) && preg_match('/^[A-Za-z0-9-]{1,64}$/D', $id) === 1;
    }

    /** نام: بریده‌شده به ۶۰ کاراکتر؛ null اگه رشته نباشه. خالی می‌مونه (تصمیم با صدازننده) */
    public static function parseName(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        return mb_substr(AuthRules::jsTrim($value), 0, self::MAX_NAME_LENGTH);
    }

    public static function parseDuration(mixed $value): ?int
    {
        if (is_string($value) && preg_match('/^\d{1,8}(\.\d+)?$/D', $value) === 1) {
            $value = (float) $value;
        }
        if ((! is_int($value) && ! is_float($value)) || ! is_finite($value) || $value < 0 || $value > self::MAX_DURATION_MS) {
            return null;
        }

        return (int) round($value);
    }

    /**
     * رویدادها: آرایه‌ی JSON (یا رشته‌ی JSON برای multipart). null یعنی نامعتبر.
     *
     * @return list<array{t: float, note: string, on: bool, velocity: float}>|null
     */
    public static function parseEvents(mixed $value): ?array
    {
        if (is_string($value)) {
            $value = json_decode($value, true);
        }
        if (! is_array($value) || ! array_is_list($value) || count($value) > self::MAX_EVENTS) {
            return null;
        }

        $events = [];
        foreach ($value as $event) {
            if (! is_array($event)) {
                return null;
            }
            $t = $event['t'] ?? null;
            $note = $event['note'] ?? null;
            $on = $event['on'] ?? null;
            $velocity = $event['velocity'] ?? null;

            if ((! is_int($t) && ! is_float($t)) || ! is_finite($t) || $t < 0 || $t > self::MAX_DURATION_MS + 10_000) {
                return null;
            }
            if (! is_string($note) || preg_match('/^[A-G][#b]?[0-8]$/D', $note) !== 1 || ! is_bool($on)) {
                return null;
            }
            if ((! is_int($velocity) && ! is_float($velocity)) || ! is_finite($velocity) || $velocity < 0 || $velocity > 1) {
                return null;
            }

            $events[] = ['t' => round((float) $t, 2), 'note' => $note, 'on' => $on, 'velocity' => round((float) $velocity, 3)];
        }

        return $events;
    }

    /** @param  list<array{on: bool}>  $events */
    public static function countNotes(array $events): int
    {
        return count(array_filter($events, fn (array $e) => $e['on']));
    }

    /** فقط WAV می‌پذیریم (همون چیزی که فرانت می‌سازه): ۱۲ بایت اول «RIFF....WAVE» */
    public static function isWavHeader(string $header): bool
    {
        return strlen($header) >= 12 && str_starts_with($header, 'RIFF') && substr($header, 8, 4) === 'WAVE';
    }

    /** مسیر فایل صدا روی دیسک private؛ هر کاربر پوشه‌ی خودش رو داره */
    public static function audioPath(int $userId, string $recId): string
    {
        return "recordings/{$userId}/{$recId}.wav";
    }
}
