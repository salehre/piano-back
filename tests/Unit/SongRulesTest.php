<?php

namespace Tests\Unit;

use App\Support\SongRules;
use PHPUnit\Framework\TestCase;

class SongRulesTest extends TestCase
{
    private function song(array $override = []): array
    {
        return array_merge([
            'id' => 'midi-abc123', 'title' => 'My song', 'bpm' => 120, 'beatsPerBar' => 4, 'level' => 2, 'source' => 'midi',
            'notes' => [[60, 0, 500, 0], [64, 500, 500, 1]],
        ], $override);
    }

    public function test_ids(): void
    {
        foreach (['midi-abc123', 'xml-1', 'a_b-C'] as $ok) {
            $this->assertTrue(SongRules::isValidId($ok), $ok);
        }
        foreach (['', 'a b', 'a/b', 'سلام', str_repeat('a', 101), null] as $bad) {
            $this->assertFalse(SongRules::isValidId($bad), json_encode($bad));
        }
    }

    public function test_valid_songs(): void
    {
        $this->assertTrue(SongRules::isValid($this->song()));
        $this->assertTrue(SongRules::isValid($this->song(['source' => null, 'bpm' => 99.5])));
        $noSource = $this->song();
        unset($noSource['source']);
        $this->assertTrue(SongRules::isValid($noSource));
        $this->assertTrue(SongRules::isValid($this->song(['notes' => [[60.0, 0.0, 0, 1]]]))); // عدد صحیح به شکل float و طول صفر
    }

    public function test_invalid_songs(): void
    {
        $cases = [
            ['title' => ''], ['title' => '   '], ['title' => 5],
            ['bpm' => 0], ['bpm' => -1], ['bpm' => 1001], ['bpm' => 'fast'],
            ['beatsPerBar' => 0], ['beatsPerBar' => 33], ['beatsPerBar' => 2.5],
            ['level' => 0], ['level' => 4], ['level' => '2'],
            ['source' => 'wav'],
            ['notes' => []], ['notes' => 'x'], ['notes' => ['a' => [60, 0, 1, 0]]],
            ['notes' => [[128, 0, 1, 0]]], ['notes' => [[-1, 0, 1, 0]]],
            ['notes' => [[60, -1, 1, 0]]], ['notes' => [[60, 36_000_001, 1, 0]]],
            ['notes' => [[60, 0, 3_600_001, 0]]], ['notes' => [[60, 0, 1, 2]]],
            ['notes' => [[60, 0, 1]]], ['notes' => [[60, 0, 1, 0, 9]]], ['notes' => [['m' => 60, 0, 1, 0]]],
        ];
        foreach ($cases as $override) {
            $this->assertFalse(SongRules::isValid($this->song($override)), json_encode($override));
        }
        $this->assertFalse(SongRules::isValid('x'));
    }

    public function test_note_count_limit(): void
    {
        $max = array_fill(0, SongRules::MAX_NOTES, [60, 0, 100, 0]);
        $this->assertTrue(SongRules::isValid($this->song(['notes' => $max])));
        $this->assertFalse(SongRules::isValid($this->song(['notes' => [...$max, [60, 0, 100, 0]]])));
    }

    public function test_normalize(): void
    {
        $n = SongRules::normalize($this->song(['title' => '  '.str_repeat('ا', 250).'  ', 'notes' => [[60.0, 1.0, 2.0, 1.0]]]));
        $this->assertSame(200, mb_strlen($n['title']));
        $this->assertSame([[60, 1, 2, 1]], $n['notes']);
        $this->assertSame(120.0, $n['bpm']);
        $this->assertSame('midi', $n['source']);
        $this->assertNull(SongRules::normalize($this->song(['source' => null]))['source']);
    }
}
