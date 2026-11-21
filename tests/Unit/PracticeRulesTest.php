<?php

namespace Tests\Unit;

use App\Support\PracticeRules;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

class PracticeRulesTest extends TestCase
{
    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-11-21 12:00:00', new DateTimeZone('UTC'));
    }

    private function meta(array $override = []): array
    {
        return array_merge([
            'xp' => 50.5,
            'goalMin' => 15,
            'badges' => ['firstPractice' => '2026-01-05T10:00:00.000Z'],
            'counters' => ['lessons' => 2, 'earRounds' => 1, 'perfectEar' => 0, 'songRuns' => 3, 'threeStars' => 1, 'perfectRuns' => 0, 'goalDays' => 1],
            'reminder' => ['on' => true, 'time' => '20:30'],
            'reminded' => '',
        ], $override);
    }

    public function test_lists_match_the_frontend(): void
    {
        $this->assertSame(['lessons', 'earRounds', 'perfectEar', 'songRuns', 'threeStars', 'perfectRuns', 'goalDays'], PracticeRules::COUNTER_KEYS);
        $this->assertCount(16, PracticeRules::BADGE_IDS);
        $this->assertSame([5, 10, 15, 20], PracticeRules::GOAL_OPTIONS);
    }

    public function test_valid_days(): void
    {
        foreach (['2026-11-21', '2026-11-23', '2020-01-01', '2024-02-29'] as $ok) {
            $this->assertTrue(PracticeRules::isValidDay($ok, $this->now()), $ok);
        }
        // فرمت غلط، تاریخ ناموجود، قبل از بازه، و بیشتر از دو روز جلوتر از امروز (UTC)
        foreach (['2026-1-2', '2026-02-30', '2025-02-29', '2026-13-01', '', '2019-12-31', '2026-11-24', null, 20261121, ['2026-11-21'], "2026-11-21\n"] as $bad) {
            $this->assertFalse(PracticeRules::isValidDay($bad, $this->now()), json_encode($bad));
        }
    }

    public function test_valid_times(): void
    {
        foreach (['00:00', '19:00', '23:59'] as $ok) {
            $this->assertTrue(PracticeRules::isTime($ok), $ok);
        }
        foreach (['24:00', '7:30', '19:60', '19:00:00', '', null, 1900] as $bad) {
            $this->assertFalse(PracticeRules::isTime($bad), json_encode($bad));
        }
    }

    public function test_parse_day(): void
    {
        $this->assertSame(['ms' => 120000, 'xp' => 10.5], PracticeRules::parseDay(['ms' => 120000, 'xp' => 10.5]));
        $this->assertSame(['ms' => 0, 'xp' => 0.0], PracticeRules::parseDay(['ms' => 0, 'xp' => 0]));
        // ms اعشاری گرد می‌شه و xp دو رقم اعشار
        $this->assertSame(['ms' => 1235, 'xp' => 3.33], PracticeRules::parseDay(['ms' => 1234.6, 'xp' => 3.3333]));
        $this->assertSame(['ms' => PracticeRules::MAX_DAY_MS, 'xp' => PracticeRules::MAX_DAY_XP], PracticeRules::parseDay(['ms' => PracticeRules::MAX_DAY_MS, 'xp' => PracticeRules::MAX_DAY_XP]));

        foreach ([
            null, 'x', [], ['ms' => 1], ['xp' => 1],
            ['ms' => -1, 'xp' => 0], ['ms' => 0, 'xp' => -0.5],
            ['ms' => PracticeRules::MAX_DAY_MS + 1, 'xp' => 0], ['ms' => 0, 'xp' => PracticeRules::MAX_DAY_XP + 1],
            ['ms' => '5', 'xp' => 1], ['ms' => 1, 'xp' => '1'], ['ms' => NAN, 'xp' => 1], ['ms' => 1, 'xp' => INF],
        ] as $bad) {
            $this->assertNull(PracticeRules::parseDay($bad), json_encode($bad));
        }
    }

    public function test_merge_day_keeps_the_highest_ms_and_xp(): void
    {
        $a = ['ms' => 120000, 'xp' => 10.0];
        $b = ['ms' => 60000, 'xp' => 20.0];

        $this->assertSame($b, PracticeRules::mergeDay(null, $b));
        $this->assertSame(['ms' => 120000, 'xp' => 20.0], PracticeRules::mergeDay($a, $b));
        $this->assertSame(['ms' => 120000, 'xp' => 20.0], PracticeRules::mergeDay($b, $a));
    }

    public function test_parse_counters(): void
    {
        $full = ['lessons' => 2, 'earRounds' => 1, 'perfectEar' => 0, 'songRuns' => 3, 'threeStars' => 1, 'perfectRuns' => 0, 'goalDays' => 1];
        $this->assertSame($full, PracticeRules::parseCounters($full));
        // کلید گم‌شده صفره، کلید ناشناخته نادیده گرفته می‌شه، و عدد صحیحِ اعشاری‌نما (۳.۰) قبوله
        $this->assertSame(
            ['lessons' => 3, 'earRounds' => 0, 'perfectEar' => 0, 'songRuns' => 0, 'threeStars' => 0, 'perfectRuns' => 0, 'goalDays' => 0],
            PracticeRules::parseCounters(['lessons' => 3.0, 'future' => 99]),
        );
        $this->assertSame(array_fill_keys(PracticeRules::COUNTER_KEYS, 0), PracticeRules::parseCounters([]));

        foreach ([null, 'x', ['lessons' => -1], ['lessons' => 1.5], ['lessons' => '2'], ['lessons' => PracticeRules::COUNTER_CAP + 1], ['goalDays' => false]] as $bad) {
            $this->assertNull(PracticeRules::parseCounters($bad), json_encode($bad));
        }
    }

    public function test_merge_counters_takes_the_max_of_each(): void
    {
        $a = array_merge(array_fill_keys(PracticeRules::COUNTER_KEYS, 0), ['lessons' => 5, 'songRuns' => 1]);
        $b = array_merge(array_fill_keys(PracticeRules::COUNTER_KEYS, 0), ['lessons' => 2, 'songRuns' => 9]);

        $merged = PracticeRules::mergeCounters($a, $b);
        $this->assertSame(5, $merged['lessons']);
        $this->assertSame(9, $merged['songRuns']);
        $this->assertSame(0, $merged['goalDays']);
    }

    public function test_parse_badges(): void
    {
        $parsed = PracticeRules::parseBadges([
            'firstPractice' => '2026-01-05T10:00:00.000Z',
            'unknownBadge' => '2026-01-05T10:00:00.000Z',
            'streak3' => 'garbage',
            'streak7' => '2999-01-01T00:00:00.000Z',
        ], $this->now());

        $this->assertSame(['firstPractice', 'streak3', 'streak7'], array_keys($parsed));
        $this->assertSame('2026-01-05 10:00:00.000', $parsed['firstPractice']);
        // زمان نامعتبر یا آینده‌ی دور با «الان» عوض می‌شه
        $this->assertSame('2026-11-21 12:00:00.000', $parsed['streak3']);
        $this->assertSame('2026-11-21 12:00:00.000', $parsed['streak7']);

        $this->assertSame([], PracticeRules::parseBadges([], $this->now()));
        $this->assertNull(PracticeRules::parseBadges('x', $this->now()));
        $this->assertNull(PracticeRules::parseBadges(null, $this->now()));
    }

    public function test_merge_badges_is_a_union_that_keeps_the_earlier_time(): void
    {
        $merged = PracticeRules::mergeBadges(
            ['firstPractice' => '2026-01-06 10:00:00.000', 'hour1' => '2026-02-01 10:00:00.000'],
            ['firstPractice' => '2026-01-05 10:00:00.000', 'streak3' => '2026-01-07 10:00:00.000'],
        );

        $this->assertSame([
            'firstPractice' => '2026-01-05 10:00:00.000',
            'hour1' => '2026-02-01 10:00:00.000',
            'streak3' => '2026-01-07 10:00:00.000',
        ], $merged);
    }

    public function test_parse_reminder(): void
    {
        $this->assertSame(['on' => true, 'time' => '07:05'], PracticeRules::parseReminder(['on' => true, 'time' => '07:05', 'extra' => 1]));
        foreach ([null, [], ['on' => 1, 'time' => '07:05'], ['on' => true], ['on' => true, 'time' => '7:05'], ['time' => '07:05']] as $bad) {
            $this->assertNull(PracticeRules::parseReminder($bad), json_encode($bad));
        }
    }

    public function test_parse_meta(): void
    {
        $parsed = PracticeRules::parseMeta($this->meta(), $this->now());
        $this->assertSame(50.5, $parsed['xp']);
        $this->assertSame(15, $parsed['goalMin']);
        $this->assertSame(['firstPractice' => '2026-01-05 10:00:00.000'], $parsed['badges']);
        $this->assertSame(['on' => true, 'time' => '20:30'], $parsed['reminder']);
        $this->assertSame('', $parsed['reminded']);

        $this->assertSame('2026-11-20', PracticeRules::parseMeta($this->meta(['reminded' => '2026-11-20']), $this->now())['reminded']);

        $withoutKey = function (string $key): array {
            $m = $this->meta();
            unset($m[$key]);

            return $m;
        };

        foreach ([
            null, 'x', [],
            $this->meta(['xp' => -1]), $this->meta(['xp' => PracticeRules::MAX_XP + 1]), $this->meta(['xp' => '5']),
            $this->meta(['goalMin' => 7]), $this->meta(['goalMin' => '10']), $this->meta(['goalMin' => 10.5]),
            $this->meta(['reminded' => 'yesterday']), $this->meta(['reminded' => '2026-11-30']), $this->meta(['reminded' => null]),
            $this->meta(['counters' => ['lessons' => -1]]), $this->meta(['badges' => 'all']), $this->meta(['reminder' => ['on' => true, 'time' => '25:00']]),
            $withoutKey('xp'), $withoutKey('goalMin'), $withoutKey('counters'), $withoutKey('badges'), $withoutKey('reminder'), $withoutKey('reminded'),
        ] as $bad) {
            $this->assertNull(PracticeRules::parseMeta($bad, $this->now()), json_encode($bad));
        }
    }

    public function test_merge_meta(): void
    {
        $current = PracticeRules::parseMeta($this->meta([
            'xp' => 100.0,
            'goalMin' => 15,
            'counters' => ['lessons' => 5, 'songRuns' => 1],
            'badges' => ['firstPractice' => '2026-01-06T10:00:00.000Z'],
            'reminder' => ['on' => true, 'time' => '20:30'],
            'reminded' => '2026-11-19',
        ]), $this->now());
        $incoming = PracticeRules::parseMeta($this->meta([
            'xp' => 40.0,
            'goalMin' => 5,
            'counters' => ['lessons' => 2, 'songRuns' => 9],
            'badges' => ['firstPractice' => '2026-01-05T10:00:00.000Z', 'streak3' => '2026-01-07T10:00:00.000Z'],
            'reminder' => ['on' => false, 'time' => '08:00'],
            'reminded' => '2026-11-20',
        ]), $this->now());

        // بدون ردیف قبلی: ورودی عیناً پذیرفته می‌شه
        $this->assertSame($incoming, PracticeRules::mergeMeta(null, $incoming));
        $this->assertSame($incoming, PracticeRules::mergeMeta(null, $incoming, takeSettings: false));

        // تنظیمات آخرین مقدارن، ولی xp و شمارنده‌ها بیشینه و نشان‌ها اجتماع
        $merged = PracticeRules::mergeMeta($current, $incoming);
        $this->assertSame(100.0, $merged['xp']);
        $this->assertSame(5, $merged['counters']['lessons']);
        $this->assertSame(9, $merged['counters']['songRuns']);
        $this->assertSame(['firstPractice' => '2026-01-05 10:00:00.000', 'streak3' => '2026-01-07 10:00:00.000'], $merged['badges']);
        $this->assertSame(5, $merged['goalMin']);
        $this->assertSame(['on' => false, 'time' => '08:00'], $merged['reminder']);
        $this->assertSame('2026-11-20', $merged['reminded']);

        // انتقال مهمان به حساب دارای داده: تنظیمات حساب می‌مونه
        $kept = PracticeRules::mergeMeta($current, $incoming, takeSettings: false);
        $this->assertSame(100.0, $kept['xp']);
        $this->assertSame(9, $kept['counters']['songRuns']);
        $this->assertSame(15, $kept['goalMin']);
        $this->assertSame(['on' => true, 'time' => '20:30'], $kept['reminder']);
        $this->assertSame('2026-11-19', $kept['reminded']);
    }

    public function test_empty_and_present_meta(): void
    {
        $empty = PracticeRules::emptyMeta();
        $this->assertSame(10, $empty['goalMin']);
        $this->assertSame(['on' => false, 'time' => '19:00'], $empty['reminder']);

        // badges همیشه آبجکته، حتی وقتی خالیه
        $json = json_encode(PracticeRules::presentMeta($empty));
        $this->assertStringContainsString('"badges":{}', $json);
        $this->assertStringContainsString('"reminded":""', $json);

        $present = PracticeRules::presentMeta(array_merge($empty, ['badges' => ['firstPractice' => '2026-01-05 10:00:00.000']]));
        $this->assertSame('2026-01-05T10:00:00.000Z', $present['badges']->firstPractice);
    }
}