<?php

namespace App\Support;

/**
 * اعتبارسنجی آهنگ واردشده (StoredSong فرانت). سقف نت‌ها همون MAX_MIDI_NOTES فرانته.
 * وابستگی به لاراول نداره.
 */
final class SongRules
{
    public const MAX_NOTES = 8000;

    public const MAX_SONGS_PER_USER = 20;

    public const TITLE_MAX = 200;

    /** ۱۰ ساعت */
    private const MAX_START_MS = 36_000_000;

    /** ۱ ساعت */
    private const MAX_DURATION_MS = 3_600_000;

    public static function isValidId(mixed $id): bool
    {
        return is_string($id) && preg_match('/^[A-Za-z0-9_\-]{1,100}$/D', $id) === 1;
    }

    /** true اگه آهنگ معتبره */
    public static function isValid(mixed $song): bool
    {
        if (! is_array($song)) {
            return false;
        }
        if (! is_string($song['title'] ?? null) || AuthRules::jsTrim($song['title']) === '') {
            return false;
        }
        $bpm = $song['bpm'] ?? null;
        if ((! is_int($bpm) && ! is_float($bpm)) || ! is_finite((float) $bpm) || $bpm <= 0 || $bpm > 1000) {
            return false;
        }
        if (! self::isInt($song['beatsPerBar'] ?? null) || $song['beatsPerBar'] < 1 || $song['beatsPerBar'] > 32) {
            return false;
        }
        if (! in_array($song['level'] ?? null, [1, 2, 3], true)) {
            return false;
        }
        if (array_key_exists('source', $song) && $song['source'] !== null && ! in_array($song['source'], ['midi', 'musicxml'], true)) {
            return false;
        }

        $notes = $song['notes'] ?? null;
        if (! is_array($notes) || ! array_is_list($notes) || $notes === [] || count($notes) > self::MAX_NOTES) {
            return false;
        }
        foreach ($notes as $note) {
            if (! is_array($note) || count($note) !== 4 || ! array_is_list($note)) {
                return false;
            }
            [$midi, $start, $duration, $hand] = $note;
            if (! self::isInt($midi) || $midi < 0 || $midi > 127) {
                return false;
            }
            if (! self::isInt($start) || $start < 0 || $start > self::MAX_START_MS) {
                return false;
            }
            if (! self::isInt($duration) || $duration < 0 || $duration > self::MAX_DURATION_MS) {
                return false;
            }
            if ($hand !== 0 && $hand !== 1) {
                return false;
            }
        }

        return true;
    }

    /**
     * مقادیر تمیز برای ذخیره (بعد از isValid).
     *
     * @return array{title: string, bpm: float, beats_per_bar: int, level: int, source: ?string, notes: list<array{int, int, int, int}>}
     */
    public static function normalize(array $song): array
    {
        return [
            'title' => mb_substr(AuthRules::jsTrim($song['title']), 0, self::TITLE_MAX),
            'bpm' => (float) $song['bpm'],
            'beats_per_bar' => (int) $song['beatsPerBar'],
            'level' => (int) $song['level'],
            'source' => $song['source'] ?? null,
            'notes' => array_map(
                static fn (array $n): array => [(int) $n[0], (int) $n[1], (int) $n[2], (int) $n[3]],
                $song['notes'],
            ),
        ];
    }

    private static function isInt(mixed $value): bool
    {
        return is_int($value) || (is_float($value) && is_finite($value) && floor($value) === $value);
    }
}
