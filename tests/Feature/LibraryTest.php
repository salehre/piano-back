<?php

namespace Tests\Feature;

use App\Models\ImportedSong;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsSpa;
use Tests\TestCase;

class LibraryTest extends TestCase
{
    use ActsAsSpa, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSpa();
    }

    private function song(string $id = 'midi-abc123', array $override = []): array
    {
        return array_merge([
            'id' => $id, 'title' => 'My song', 'bpm' => 120, 'beatsPerBar' => 4, 'level' => 2, 'source' => 'midi',
            'notes' => [[60, 0, 500, 0], [64, 500, 500, 1]],
        ], $override);
    }

    public function test_requires_login(): void
    {
        $this->getJson('/api/library/songs')->assertUnauthorized();
        $this->putJson('/api/library/songs/midi-abc123', $this->song())->assertUnauthorized();
        $this->deleteJson('/api/library/songs/midi-abc123')->assertUnauthorized();
    }

    public function test_save_list_update_and_delete(): void
    {
        $this->login();
        $this->getJson('/api/library/songs')->assertOk()->assertExactJson(['songs' => []]);

        $this->putJson('/api/library/songs/midi-abc123', $this->song())->assertOk()->assertJsonPath('song.title', 'My song');
        $this->putJson('/api/library/songs/xml-1', $this->song('xml-1', ['source' => null, 'title' => '  Second  ']))->assertOk();

        $list = $this->getJson('/api/library/songs')->assertOk();
        $list->assertJsonCount(2, 'songs')
            ->assertJsonPath('songs.0.id', 'midi-abc123')
            ->assertJsonPath('songs.0.beatsPerBar', 4)
            ->assertJsonPath('songs.0.notes.1', [64, 500, 500, 1])
            ->assertJsonPath('songs.1.title', 'Second');
        $this->assertArrayNotHasKey('source', $list->json('songs.1')); // بدون منبع، کلیدش نمیاد

        // بروزرسانی همون id (جایگزینی)
        $this->putJson('/api/library/songs/midi-abc123', $this->song('midi-abc123', ['title' => 'Renamed', 'level' => 3]))->assertOk();
        $this->assertDatabaseCount('imported_songs', 2);
        $this->getJson('/api/library/songs')->assertJsonPath('songs.0.title', 'Renamed')->assertJsonPath('songs.0.level', 3);

        $this->deleteJson('/api/library/songs/midi-abc123')->assertNoContent();
        $this->deleteJson('/api/library/songs/midi-abc123')->assertNoContent(); // idempotent
        $this->getJson('/api/library/songs')->assertJsonCount(1, 'songs');
    }

    public function test_rejects_invalid_songs_and_ids(): void
    {
        $this->login();
        foreach ([['title' => ''], ['bpm' => 0], ['level' => 9], ['notes' => []], ['notes' => [[200, 0, 1, 0]]]] as $override) {
            $this->putJson('/api/library/songs/midi-abc123', $this->song('midi-abc123', $override))
                ->assertStatus(422)->assertJsonPath('error.key', 'api.invalid');
        }
        $this->putJson('/api/library/songs/bad.id', $this->song())->assertNotFound();
        $this->assertDatabaseCount('imported_songs', 0);
    }

    public function test_limit_of_20_songs_returns_the_frontend_message_key(): void
    {
        $user = $this->login();
        $now = now();
        ImportedSong::insert(array_map(
            fn ($i) => [
                'user_id' => $user->id, 'song_id' => "midi-$i", 'title' => "S$i", 'bpm' => 100, 'beats_per_bar' => 4, 'level' => 1,
                'source' => 'midi', 'notes' => json_encode([[60, 0, 100, 0]]), 'created_at' => $now, 'updated_at' => $now,
            ],
            range(1, 20),
        ));

        $this->putJson('/api/library/songs/midi-new', $this->song('midi-new'))
            ->assertStatus(409)->assertJsonPath('error.key', 'songs.import.errors.limit')->assertJsonPath('error.params.max', 20);
        // به‌روزرسانی آهنگ موجود وقتی سقف پره مجازه
        $this->putJson('/api/library/songs/midi-1', $this->song('midi-1', ['title' => 'Changed']))->assertOk();
        $this->assertDatabaseCount('imported_songs', 20);
    }

    public function test_users_are_isolated(): void
    {
        $a = $this->makeUser('09120000001');
        $b = $this->makeUser('09120000002');
        $this->actAs($a)->putJson('/api/library/songs/midi-abc123', $this->song())->assertOk();

        $this->actAs($b)->getJson('/api/library/songs')->assertExactJson(['songs' => []]);
        // هر کاربر می‌تونه همون id رو برای خودش داشته باشه، و حذف دیگری رو حذف نمی‌کنه
        $this->actAs($b)->putJson('/api/library/songs/midi-abc123', $this->song('midi-abc123', ['title' => 'B']))->assertOk();
        $this->actAs($b)->deleteJson('/api/library/songs/midi-abc123')->assertNoContent();

        $this->actAs($a)->getJson('/api/library/songs')->assertJsonPath('songs.0.title', 'My song');
    }
}
