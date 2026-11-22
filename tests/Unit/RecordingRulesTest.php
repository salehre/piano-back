<?php

namespace Tests\Unit;

use App\Support\PlanRules;
use App\Support\RecordingRules;
use PHPUnit\Framework\TestCase;

class RecordingRulesTest extends TestCase
{
    private function event(array $override = []): array
    {
        return array_merge(['t' => 120.5, 'note' => 'C#4', 'on' => true, 'velocity' => 0.8], $override);
    }

    public function test_ids(): void
    {
        $this->assertTrue(RecordingRules::isValidId('3f2504e0-4f89-41d3-9a0c-0305e82c3301'));
        $this->assertTrue(RecordingRules::isValidId('abc123'));

        foreach ([null, 12, '', 'a b', 'a/b', '../x', str_repeat('a', 65), "abc\n"] as $bad) {
            $this->assertFalse(RecordingRules::isValidId($bad), json_encode($bad));
        }
    }

    public function test_name_is_trimmed_and_truncated(): void
    {
        $this->assertSame('My song', RecordingRules::parseName('  My song '));
        $this->assertSame('', RecordingRules::parseName('   '));
        $this->assertSame(60, mb_strlen((string) RecordingRules::parseName(str_repeat('آ', 100))));
        $this->assertNull(RecordingRules::parseName(null));
        $this->assertNull(RecordingRules::parseName(['x']));
    }

    public function test_duration(): void
    {
        $this->assertSame(1500, RecordingRules::parseDuration(1500));
        $this->assertSame(1500, RecordingRules::parseDuration('1500'));
        $this->assertSame(1235, RecordingRules::parseDuration(1234.6));
        $this->assertSame(0, RecordingRules::parseDuration(0));

        foreach ([null, -1, 'abc', '-5', RecordingRules::MAX_DURATION_MS + 1, NAN, INF, [], true] as $bad) {
            $this->assertNull(RecordingRules::parseDuration($bad), json_encode($bad));
        }
    }

    public function test_valid_events_are_normalized(): void
    {
        $events = RecordingRules::parseEvents([$this->event(['t' => 1.23456, 'velocity' => 0.12345]), $this->event(['on' => false, 'velocity' => 0])]);

        $this->assertSame([
            ['t' => 1.23, 'note' => 'C#4', 'on' => true, 'velocity' => 0.123],
            ['t' => 120.5, 'note' => 'C#4', 'on' => false, 'velocity' => 0.0],
        ], $events);
        $this->assertSame([], RecordingRules::parseEvents([]));
    }

    public function test_events_can_be_a_json_string(): void
    {
        $this->assertCount(1, RecordingRules::parseEvents(json_encode([$this->event()])));
        $this->assertNull(RecordingRules::parseEvents('not json'));
        $this->assertNull(RecordingRules::parseEvents('{"a":1}'));
    }

    public function test_invalid_events_are_rejected(): void
    {
        $bad = [
            null, 'x', ['a' => $this->event()], [[]], ['x'],
            [$this->event(['t' => -1])], [$this->event(['t' => 'a'])], [$this->event(['t' => RecordingRules::MAX_DURATION_MS + 20000])],
            [$this->event(['note' => 'H4'])], [$this->event(['note' => 'C9'])], [$this->event(['note' => 'c4'])], [$this->event(['note' => "C4\n"])],
            [$this->event(['on' => 1])], [$this->event(['on' => 'true'])],
            [$this->event(['velocity' => 1.5])], [$this->event(['velocity' => -0.1])], [$this->event(['velocity' => null])],
        ];
        foreach ($bad as $value) {
            $this->assertNull(RecordingRules::parseEvents($value), json_encode($value));
        }
    }

    public function test_event_count_limit(): void
    {
        $this->assertNotNull(RecordingRules::parseEvents(array_fill(0, RecordingRules::MAX_EVENTS, $this->event())));
        $this->assertNull(RecordingRules::parseEvents(array_fill(0, RecordingRules::MAX_EVENTS + 1, $this->event())));
    }

    public function test_count_notes_counts_only_note_on(): void
    {
        $events = RecordingRules::parseEvents([
            $this->event(), $this->event(['on' => false, 'velocity' => 0]), $this->event(['note' => 'D4']),
        ]);

        $this->assertSame(2, RecordingRules::countNotes($events));
        $this->assertSame(0, RecordingRules::countNotes([]));
    }

    public function test_wav_header(): void
    {
        $this->assertTrue(RecordingRules::isWavHeader('RIFF'.pack('V', 36).'WAVE'));
        $this->assertFalse(RecordingRules::isWavHeader('RIFF'.pack('V', 36).'AVI '));
        $this->assertFalse(RecordingRules::isWavHeader('ID3'.str_repeat("\0", 20)));
        $this->assertFalse(RecordingRules::isWavHeader('RIFF'));
        $this->assertFalse(RecordingRules::isWavHeader(''));
    }

    public function test_audio_path_is_per_user(): void
    {
        $this->assertSame('recordings/7/abc-1.wav', RecordingRules::audioPath(7, 'abc-1'));
    }

    public function test_plan_recording_limits_match_the_frontend(): void
    {
        $this->assertSame(3, PlanRules::recordingLimit(false));
        $this->assertSame(30, PlanRules::recordingLimit(true));
    }
}
