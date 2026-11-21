<?php

namespace Tests\Unit;

use App\Support\ScoreRules;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

class ScoreRulesTest extends TestCase
{
    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-11-19 12:00:00', new DateTimeZone('UTC'));
    }

    public function test_keys(): void
    {
        foreach (['twinkle:play:both:1', 'midi-ab12:wait:r:0.75', 'a'] as $ok) {
            $this->assertTrue(ScoreRules::isValidKey($ok), $ok);
        }
        foreach (['', 'has space', "new\nline", 'سلام', str_repeat('a', 161), 'x:y/z', null, 5, ['a']] as $bad) {
            $this->assertFalse(ScoreRules::isValidKey($bad), json_encode($bad));
        }
    }

    public function test_parse_entry_accepts_valid_and_normalizes_time(): void
    {
        $e = ScoreRules::parseEntry(['stars' => 3, 'accuracy' => 0.8765, 'plays' => 4, 'at' => '2026-11-19T06:00:00.123Z'], $this->now());
        $this->assertSame(['stars' => 3, 'accuracy' => 0.8765, 'plays' => 4, 'at' => '2026-11-19 06:00:00.123'], $e);

        // زمان با آفست به UTC تبدیل می‌شه؛ عدد صحیح به‌شکل float هم قبوله
        $e = ScoreRules::parseEntry(['stars' => 2.0, 'accuracy' => 1, 'plays' => 1, 'at' => '2026-11-19T10:30:00+03:30'], $this->now());
        $this->assertSame(['stars' => 2, 'accuracy' => 1.0, 'plays' => 1, 'at' => '2026-11-19 07:00:00.000'], $e);
    }

    public function test_parse_entry_replaces_bad_or_future_time_with_now(): void
    {
        $now = '2026-11-19 12:00:00.000';
        foreach ([null, '', 'tomorrow', 'yesterday', '2999-01-01T00:00:00Z', '2001-01-01T00:00:00Z', 12345, ['x']] as $at) {
            $e = ScoreRules::parseEntry(['stars' => 1, 'accuracy' => 0.5, 'plays' => 1, 'at' => $at], $this->now());
            $this->assertSame($now, $e['at'], json_encode($at));
        }
    }

    public function test_parse_entry_rejects_invalid(): void
    {
        $base = ['stars' => 1, 'accuracy' => 0.5, 'plays' => 1, 'at' => '2026-11-19T06:00:00Z'];
        foreach ([['stars' => 4], ['stars' => -1], ['stars' => 1.5], ['stars' => '1'], ['accuracy' => 1.01], ['accuracy' => -0.1],
            ['accuracy' => '0.5'], ['accuracy' => NAN], ['plays' => -1], ['plays' => 1_000_001], ['plays' => null]] as $override) {
            $this->assertNull(ScoreRules::parseEntry(array_merge($base, $override), $this->now()), json_encode($override));
        }
        $this->assertNull(ScoreRules::parseEntry('x', $this->now()));
        $this->assertNull(ScoreRules::parseEntry(['stars' => 1], $this->now()));
    }

    public function test_merge_keeps_the_better_accuracy_and_max_plays(): void
    {
        $cur = ['stars' => 2, 'accuracy' => 0.7, 'plays' => 5, 'at' => '2026-11-18 10:00:00.000'];

        // جدید بهتر: ستاره/دقت/زمان از جدید، plays بیشینه
        $better = ['stars' => 3, 'accuracy' => 0.9, 'plays' => 2, 'at' => '2026-11-19 10:00:00.000'];
        $this->assertSame(['stars' => 3, 'accuracy' => 0.9, 'plays' => 5, 'at' => '2026-11-19 10:00:00.000'], ScoreRules::merge($cur, $better));

        // جدید ضعیف‌تر یا مساوی: رکورد فعلی می‌مونه، فقط plays بالا می‌ره
        $worse = ['stars' => 1, 'accuracy' => 0.7, 'plays' => 9, 'at' => '2026-11-19 10:00:00.000'];
        $this->assertSame(['stars' => 2, 'accuracy' => 0.7, 'plays' => 9, 'at' => '2026-11-18 10:00:00.000'], ScoreRules::merge($cur, $worse));

        $this->assertSame($better, ScoreRules::merge(null, $better));
    }

    public function test_present_formats_iso_utc(): void
    {
        $this->assertSame(
            ['stars' => 1, 'accuracy' => 0.5, 'plays' => 2, 'at' => '2026-11-19T06:00:00.123Z'],
            ScoreRules::present(['stars' => 1, 'accuracy' => 0.5, 'plays' => 2, 'at' => '2026-11-19 06:00:00.123']),
        );
    }
}
