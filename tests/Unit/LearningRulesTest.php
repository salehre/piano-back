<?php

namespace Tests\Unit;

use App\Support\LearningRules;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

class LearningRulesTest extends TestCase
{
    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-11-21 12:00:00', new DateTimeZone('UTC'));
    }

    public function test_lesson_ids_and_modes(): void
    {
        foreach (['basics-1', 'lesson_2', 'a', str_repeat('a', 60)] as $ok) {
            $this->assertTrue(LearningRules::isValidLessonId($ok), $ok);
        }
        foreach (['', 'has space', 'a:b', "new\nline", 'سلام', str_repeat('a', 61), null, 5, ['a']] as $bad) {
            $this->assertFalse(LearningRules::isValidLessonId($bad), json_encode($bad));
        }

        foreach (['interval', 'chord', 'scale', 'note', 'progression'] as $mode) {
            $this->assertTrue(LearningRules::isValidEarMode($mode), $mode);
        }
        foreach (['', 'Interval', 'rhythm', null, 1] as $bad) {
            $this->assertFalse(LearningRules::isValidEarMode($bad), json_encode($bad));
        }
    }

    public function test_parse_stars(): void
    {
        $this->assertSame(1, LearningRules::parseStars(1));
        $this->assertSame(3, LearningRules::parseStars(3.0));
        foreach ([0, 4, -1, 2.5, '2', null, true] as $bad) {
            $this->assertNull(LearningRules::parseStars($bad), json_encode($bad));
        }
    }

    public function test_parse_lesson_allows_missing_stars_but_not_invalid_ones(): void
    {
        $this->assertSame(
            ['stars' => 2, 'at' => '2026-11-20 06:00:00.123'],
            LearningRules::parseLesson(2, '2026-11-20T06:00:00.123Z', $this->now()),
        );
        // درسی که قبل از اضافه‌شدن ستاره تموم شده
        $this->assertSame(
            ['stars' => null, 'at' => '2026-11-20 06:00:00.000'],
            LearningRules::parseLesson(null, '2026-11-20T06:00:00Z', $this->now()),
        );
        $this->assertNull(LearningRules::parseLesson(7, '2026-11-20T06:00:00Z', $this->now()));
        $this->assertNull(LearningRules::parseLesson('2', '2026-11-20T06:00:00Z', $this->now()));
        // زمان نامعتبر یا آینده‌ی دور با «الان» عوض می‌شه
        $this->assertSame('2026-11-21 12:00:00.000', LearningRules::parseLesson(1, 'garbage', $this->now())['at']);
        $this->assertSame('2026-11-21 12:00:00.000', LearningRules::parseLesson(1, '2030-01-01T00:00:00Z', $this->now())['at']);
    }

    public function test_merge_lesson_keeps_first_completion_and_best_stars(): void
    {
        $old = ['stars' => 2, 'at' => '2026-11-10 08:00:00.000'];
        $new = ['stars' => 3, 'at' => '2026-11-20 08:00:00.000'];

        $this->assertSame($new, LearningRules::mergeLesson(null, $new));
        $this->assertSame(['stars' => 3, 'at' => '2026-11-10 08:00:00.000'], LearningRules::mergeLesson($old, $new));
        // ستاره‌ی کمتر چیزی رو پایین نمیاره
        $this->assertSame(['stars' => 3, 'at' => '2026-11-10 08:00:00.000'], LearningRules::mergeLesson($new, $old));
        // بدون ستاره + با ستاره = با ستاره؛ هر دو بدون ستاره = بدون ستاره
        $this->assertSame(2, LearningRules::mergeLesson(['stars' => null, 'at' => $old['at']], $old)['stars']);
        $this->assertNull(LearningRules::mergeLesson(['stars' => null, 'at' => $old['at']], ['stars' => null, 'at' => $new['at']])['stars']);
    }

    public function test_present_lesson_omits_empty_stars(): void
    {
        $this->assertSame(['at' => '2026-11-20T06:00:00.123Z', 'stars' => 2], LearningRules::presentLesson(['stars' => 2, 'at' => '2026-11-20 06:00:00.123']));
        $this->assertSame(['at' => '2026-11-20T06:00:00.123Z'], LearningRules::presentLesson(['stars' => null, 'at' => '2026-11-20 06:00:00.123']));
    }

    public function test_parse_ear_round(): void
    {
        $this->assertSame(['rounds' => 1, 'answered' => 10, 'correct' => 7, 'bestRound' => 7], LearningRules::parseEarRound(7, 10));
        $this->assertSame(['rounds' => 1, 'answered' => 10, 'correct' => 0, 'bestRound' => 0], LearningRules::parseEarRound(0, 10));
        foreach ([[11, 10], [-1, 10], [1, 0], [1, 51], [1.5, 10], ['5', 10], [null, 10], [5, null]] as [$c, $t]) {
            $this->assertNull(LearningRules::parseEarRound($c, $t), json_encode([$c, $t]));
        }
    }

    public function test_parse_ear_stat_checks_internal_consistency(): void
    {
        $ok = ['rounds' => 3, 'answered' => 30, 'correct' => 21, 'bestRound' => 9];
        $this->assertSame($ok, LearningRules::parseEarStat($ok));
        $this->assertSame(['rounds' => 0, 'answered' => 0, 'correct' => 0, 'bestRound' => 0], LearningRules::parseEarStat(['rounds' => 0, 'answered' => 0, 'correct' => 0, 'bestRound' => 0]));

        $bad = [
            ['rounds' => 1, 'answered' => 10, 'correct' => 11, 'bestRound' => 5],     // correct > answered
            ['rounds' => 1, 'answered' => 51, 'correct' => 5, 'bestRound' => 5],      // answered > rounds × max
            ['rounds' => 3, 'answered' => 30, 'correct' => 5, 'bestRound' => 6],      // best > correct
            ['rounds' => -1, 'answered' => 0, 'correct' => 0, 'bestRound' => 0],
            ['rounds' => 2_000_000, 'answered' => 0, 'correct' => 0, 'bestRound' => 0],
            ['rounds' => 1, 'answered' => 10, 'correct' => 5],                          // فیلد کم
            ['rounds' => '1', 'answered' => 10, 'correct' => 5, 'bestRound' => 5],
        ];
        foreach ($bad as $i => $stat) {
            $this->assertNull(LearningRules::parseEarStat($stat), "case $i");
        }
        $this->assertNull(LearningRules::parseEarStat('x'));
        $this->assertNull(LearningRules::parseEarStat(null));
    }

    public function test_merge_ear_sums_counters_and_takes_best_round(): void
    {
        $current = ['rounds' => 2, 'answered' => 20, 'correct' => 12, 'bestRound' => 8];
        $this->assertSame(
            ['rounds' => 3, 'answered' => 30, 'correct' => 17, 'bestRound' => 8],
            LearningRules::mergeEar($current, ['rounds' => 1, 'answered' => 10, 'correct' => 5, 'bestRound' => 5]),
        );
        $this->assertSame(10, LearningRules::mergeEar($current, ['rounds' => 1, 'answered' => 10, 'correct' => 10, 'bestRound' => 10])['bestRound']);
        // اولین دور
        $this->assertSame(['rounds' => 1, 'answered' => 10, 'correct' => 4, 'bestRound' => 4], LearningRules::mergeEar(null, ['rounds' => 1, 'answered' => 10, 'correct' => 4, 'bestRound' => 4]));
    }

    public function test_merge_ear_counters_are_capped(): void
    {
        $near = ['rounds' => LearningRules::COUNTER_CAP - 1, 'answered' => LearningRules::COUNTER_CAP, 'correct' => LearningRules::COUNTER_CAP, 'bestRound' => 3];
        $merged = LearningRules::mergeEar($near, ['rounds' => 1_000_000, 'answered' => 50_000_000, 'correct' => 50_000_000, 'bestRound' => 1]);
        $this->assertSame(LearningRules::COUNTER_CAP, $merged['rounds']);
        $this->assertSame(LearningRules::COUNTER_CAP, $merged['answered']);
        $this->assertSame(LearningRules::COUNTER_CAP, $merged['correct']);
    }
}