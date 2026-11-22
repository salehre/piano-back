<?php

namespace Tests\Feature;

use App\Models\Recording;
use App\Models\Subscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\ActsAsSpa;
use Tests\TestCase;

class RecordingsTest extends TestCase
{
    use ActsAsSpa, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSpa();
        Storage::fake('local');
    }

    private function wav(): string
    {
        return 'RIFF'.pack('V', 36).'WAVE'.str_repeat("\0", 200);
    }

    private function events(): array
    {
        return [
            ['t' => 0, 'note' => 'C4', 'on' => true, 'velocity' => 0.8],
            ['t' => 500, 'note' => 'C4', 'on' => false, 'velocity' => 0],
            ['t' => 600, 'note' => 'E4', 'on' => true, 'velocity' => 0.6],
            ['t' => 900, 'note' => 'E4', 'on' => false, 'velocity' => 0],
        ];
    }

    /** ذخیره‌ی multipart مثل فرانت؛ $audio خالی یعنی بدون صدا */
    private function save(string $id, array $override = [], bool $withAudio = true)
    {
        $body = array_merge([
            'id' => $id, 'name' => 'My song', 'durationMs' => 1000, 'events' => json_encode($this->events()),
        ], $override);
        if ($withAudio) {
            $body['audio'] = UploadedFile::fake()->createWithContent('rec.wav', $this->wav());
        }

        return $this->post('/api/recordings', $body);
    }

    public function test_requires_login(): void
    {
        $this->getJson('/api/recordings')->assertUnauthorized();
        $this->postJson('/api/recordings', [])->assertUnauthorized();
        $this->getJson('/api/recordings/abc')->assertUnauthorized();
        $this->getJson('/api/recordings/abc/audio')->assertUnauthorized();
        $this->patchJson('/api/recordings/abc', ['name' => 'x'])->assertUnauthorized();
        $this->deleteJson('/api/recordings/abc')->assertUnauthorized();
    }

    public function test_index_is_empty_for_a_new_user(): void
    {
        $this->login();

        $this->getJson('/api/recordings')->assertOk()->assertExactJson(['recordings' => []]);
    }

    public function test_save_with_audio_then_list_open_and_stream(): void
    {
        $user = $this->login();

        $this->save('rec-1')->assertCreated()
            ->assertJsonPath('recording.id', 'rec-1')
            ->assertJsonPath('recording.name', 'My song')
            ->assertJsonPath('recording.durationMs', 1000)
            ->assertJsonPath('recording.noteCount', 2)
            ->assertJsonPath('recording.hasAudio', true);

        Storage::disk('local')->assertExists("recordings/{$user->id}/rec-1.wav");

        $this->getJson('/api/recordings')->assertOk()
            ->assertJsonCount(1, 'recordings')
            ->assertJsonPath('recordings.0.id', 'rec-1')
            ->assertJsonMissingPath('recordings.0.events');

        $this->getJson('/api/recordings/rec-1')->assertOk()
            ->assertJsonPath('hasAudio', true)
            ->assertJsonCount(4, 'events')
            ->assertJsonPath('events.2.note', 'E4');

        $res = $this->get('/api/recordings/rec-1/audio')->assertOk()->assertHeader('Content-Type', 'audio/wav');
        $this->assertSame($this->wav(), $res->streamedContent());
    }

    public function test_save_without_audio_as_json(): void
    {
        $this->login();

        $this->postJson('/api/recordings', [
            'id' => 'rec-1', 'name' => 'Silent', 'durationMs' => 900, 'events' => $this->events(),
        ])->assertCreated()->assertJsonPath('recording.hasAudio', false);

        $this->getJson('/api/recordings/rec-1/audio')->assertNotFound();
        $this->getJson('/api/recordings/rec-1')->assertOk()->assertJsonPath('hasAudio', false);
    }

    public function test_list_is_newest_first(): void
    {
        $this->login();
        $this->save('a', withAudio: false)->assertCreated();
        $this->save('b', withAudio: false)->assertCreated();

        $this->getJson('/api/recordings')->assertOk()
            ->assertJsonPath('recordings.0.id', 'b')
            ->assertJsonPath('recordings.1.id', 'a');
    }

    public function test_saving_the_same_id_again_does_not_duplicate(): void
    {
        $this->login();
        $this->save('rec-1')->assertCreated();
        $this->save('rec-1', ['name' => 'Other'])->assertOk()->assertJsonPath('recording.name', 'My song');

        $this->assertDatabaseCount('recordings', 1);
    }

    public function test_name_defaults_and_is_truncated(): void
    {
        $this->login();

        $this->save('a', ['name' => '   '], withAudio: false)->assertCreated()->assertJsonPath('recording.name', 'Recording');
        $this->save('b', ['name' => str_repeat('x', 100)], withAudio: false)->assertCreated();

        $this->assertSame(60, mb_strlen(Recording::where('rec_id', 'b')->value('name')));
    }

    public function test_save_validates_input(): void
    {
        $this->login();
        $textFile = UploadedFile::fake()->createWithContent('rec.wav', 'this is not a wav file at all');
        $bad = [
            ['id' => 'a b'],
            ['id' => '../x'],
            ['durationMs' => -5],
            ['durationMs' => 'abc'],
            ['events' => 'not json'],
            ['events' => json_encode([['t' => 0, 'note' => 'H9', 'on' => true, 'velocity' => 0.5]])],
            ['audio' => $textFile],
            ['audio' => 'just a string'],
        ];

        foreach ($bad as $override) {
            $this->save('rec-x', $override, withAudio: false)->assertStatus(422)->assertJsonPath('error.key', 'api.invalid');
        }
        $this->post('/api/recordings', [])->assertStatus(422);

        $this->assertDatabaseCount('recordings', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_free_plan_limit_is_enforced_by_the_server(): void
    {
        $this->login();
        foreach (['a', 'b', 'c'] as $id) {
            $this->save($id, withAudio: false)->assertCreated();
        }

        $this->save('d')->assertStatus(409)->assertJsonPath('error.key', 'recordings.limit');

        $this->assertDatabaseCount('recordings', 3);
        $this->assertSame([], Storage::disk('local')->allFiles()); // صدای ضبط ردشده ذخیره نمی‌شه
    }

    public function test_premium_gets_a_higher_limit(): void
    {
        $user = $this->login();
        Subscription::create(['user_id' => $user->id, 'source' => 'purchase', 'expires_at' => now()->addDays(30)]);

        for ($i = 1; $i <= 4; $i++) {
            $this->save("rec-$i", withAudio: false)->assertCreated();
        }
        $this->assertDatabaseCount('recordings', 4);
    }

    public function test_expired_premium_keeps_recordings_but_blocks_new_ones(): void
    {
        $user = $this->login();
        Subscription::create(['user_id' => $user->id, 'source' => 'purchase', 'expires_at' => now()->addDay()]);
        for ($i = 1; $i <= 4; $i++) {
            $this->save("rec-$i", withAudio: false)->assertCreated();
        }

        $this->travel(2)->days();

        $this->getJson('/api/recordings')->assertOk()->assertJsonCount(4, 'recordings');
        $this->save('new', withAudio: false)->assertStatus(409);
    }

    public function test_rename(): void
    {
        $this->login();
        $this->save('rec-1', withAudio: false)->assertCreated();

        $this->patchJson('/api/recordings/rec-1', ['name' => '  Better name '])
            ->assertOk()->assertJsonPath('recording.name', 'Better name');
        $this->getJson('/api/recordings')->assertJsonPath('recordings.0.name', 'Better name');

        $this->patchJson('/api/recordings/rec-1', ['name' => '   '])->assertStatus(422);
        $this->patchJson('/api/recordings/rec-1', [])->assertStatus(422);
        $this->patchJson('/api/recordings/missing', ['name' => 'x'])->assertNotFound();
    }

    public function test_delete_removes_row_and_audio_file(): void
    {
        $user = $this->login();
        $this->save('rec-1')->assertCreated();
        Storage::disk('local')->assertExists("recordings/{$user->id}/rec-1.wav");

        $this->deleteJson('/api/recordings/rec-1')->assertNoContent();

        $this->assertDatabaseCount('recordings', 0);
        Storage::disk('local')->assertMissing("recordings/{$user->id}/rec-1.wav");
        $this->deleteJson('/api/recordings/rec-1')->assertNoContent(); // دوباره هم خطا نیست
    }

    public function test_deleting_frees_a_slot(): void
    {
        $this->login();
        foreach (['a', 'b', 'c'] as $id) {
            $this->save($id, withAudio: false)->assertCreated();
        }
        $this->deleteJson('/api/recordings/a')->assertNoContent();

        $this->save('d', withAudio: false)->assertCreated();
    }

    public function test_users_are_isolated(): void
    {
        $owner = $this->login();
        $this->save('rec-1')->assertCreated();

        $this->login($this->makeUser('09120000001'));
        $this->getJson('/api/recordings')->assertOk()->assertJsonCount(0, 'recordings');
        $this->getJson('/api/recordings/rec-1')->assertNotFound();
        $this->getJson('/api/recordings/rec-1/audio')->assertNotFound();
        $this->patchJson('/api/recordings/rec-1', ['name' => 'hacked'])->assertNotFound();
        $this->deleteJson('/api/recordings/rec-1')->assertNoContent();

        // هنوز مال صاحبشه
        $this->assertDatabaseHas('recordings', ['user_id' => $owner->id, 'rec_id' => 'rec-1', 'name' => 'My song']);
        Storage::disk('local')->assertExists("recordings/{$owner->id}/rec-1.wav");

        // و کاربر دوم می‌تونه همون id رو برای خودش داشته باشه
        $this->save('rec-1')->assertCreated();
        $this->assertDatabaseCount('recordings', 2);
    }
}
