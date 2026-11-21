<?php

namespace Tests\Feature;

use App\Models\SongScore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsSpa;
use Tests\TestCase;

class ProgressTest extends TestCase
{
    use ActsAsSpa, RefreshDatabase;

    private const KEY = 'twinkle:play:both:1';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSpa();
    }

    private function entry(array $override = []): array
    {
        return array_merge(['key' => self::KEY, 'stars' => 2, 'accuracy' => 0.7, 'plays' => 1, 'at' => '2026-11-19T06:00:00.000Z'], $override);
    }

    public function test_requires_login(): void
    {
        $this->getJson('/api/progress/scores')->assertUnauthorized();
        $this->putJson('/api/progress/scores', $this->entry())->assertUnauthorized();
        $this->postJson('/api/progress/scores/merge', ['scores' => []])->assertUnauthorized();
    }

    public function test_index_is_an_empty_object_for_a_new_user(): void
    {
        $this->login();
        $res = $this->getJson('/api/progress/scores')->assertOk();
        $this->assertSame('{"scores":{}}', $res->getContent());
    }

    public function test_store_then_index_returns_the_entry(): void
    {
        $this->login();
        $this->putJson('/api/progress/scores', $this->entry())->assertOk()->assertJsonPath('score.stars', 2);

        $this->getJson('/api/progress/scores')->assertOk()
            ->assertJsonPath('scores.'.self::KEY.'.stars', 2)
            ->assertJsonPath('scores.'.self::KEY.'.accuracy', 0.7)
            ->assertJsonPath('scores.'.self::KEY.'.plays', 1)
            ->assertJsonPath('scores.'.self::KEY.'.at', '2026-11-19T06:00:00.000Z');
    }

    public function test_server_keeps_the_best_result_and_the_highest_play_count(): void
    {
        $this->login();
        $this->putJson('/api/progress/scores', $this->entry())->assertOk();

        // ضعیف‌تر: رکورد پایین نمیاد، فقط plays بالا می‌ره
        $this->putJson('/api/progress/scores', $this->entry(['stars' => 1, 'accuracy' => 0.4, 'plays' => 3, 'at' => '2026-11-19T07:00:00.000Z']))
            ->assertOk()
            ->assertJsonPath('score.stars', 2)->assertJsonPath('score.accuracy', 0.7)->assertJsonPath('score.plays', 3)
            ->assertJsonPath('score.at', '2026-11-19T06:00:00.000Z');

        // بهتر: جایگزین می‌شه
        $this->putJson('/api/progress/scores', $this->entry(['stars' => 3, 'accuracy' => 0.95, 'plays' => 2, 'at' => '2026-11-19T08:00:00.000Z']))
            ->assertOk()
            ->assertJsonPath('score.stars', 3)->assertJsonPath('score.accuracy', 0.95)->assertJsonPath('score.plays', 3);

        $this->assertDatabaseCount('song_scores', 1);
    }

    public function test_store_rejects_invalid_input(): void
    {
        $this->login();
        foreach ([['key' => 'has space'], ['key' => ''], ['stars' => 4], ['accuracy' => 1.5], ['plays' => -1]] as $override) {
            $this->putJson('/api/progress/scores', $this->entry($override))
                ->assertStatus(422)->assertJsonPath('error.key', 'api.invalid');
        }
        $this->assertDatabaseCount('song_scores', 0);
    }

    public function test_merge_adds_valid_entries_and_skips_invalid_ones(): void
    {
        $this->login();
        $this->putJson('/api/progress/scores', $this->entry(['accuracy' => 0.9, 'stars' => 3]))->assertOk();

        $res = $this->postJson('/api/progress/scores/merge', ['scores' => [
            self::KEY => ['stars' => 1, 'accuracy' => 0.2, 'plays' => 7, 'at' => '2026-11-19T09:00:00Z'], // ضعیف‌تر
            'ode:wait:r:0.75' => ['stars' => 2, 'accuracy' => 0.8, 'plays' => 1, 'at' => '2026-11-19T09:00:00Z'],
            'bad key' => ['stars' => 1, 'accuracy' => 0.2, 'plays' => 1],
            'ode:play:l:1' => ['stars' => 9, 'accuracy' => 0.2, 'plays' => 1],
        ]]);

        $res->assertOk()->assertJsonPath('merged', 2)->assertJsonPath('skipped', 2);
        $this->assertSame(2, SongScore::count());

        // کلید ode:wait:r:0.75 نقطه داره، پس با assertJsonPath (که نقطه رو جداکننده می‌گیره) چک نمی‌شه
        $scores = $this->getJson('/api/progress/scores')->json('scores');
        $this->assertSame(3, $scores[self::KEY]['stars']);
        $this->assertSame(7, $scores[self::KEY]['plays']);
        $this->assertSame(0.8, $scores['ode:wait:r:0.75']['accuracy']);
    }

    public function test_merge_rejects_too_many_keys_and_wrong_shapes(): void
    {
        $this->login();
        $many = [];
        for ($i = 0; $i < 501; $i++) {
            $many["song$i:play:both:1"] = ['stars' => 1, 'accuracy' => 0.5, 'plays' => 1];
        }
        $this->postJson('/api/progress/scores/merge', ['scores' => $many])->assertStatus(422);
        $this->postJson('/api/progress/scores/merge', ['scores' => 'x'])->assertStatus(422);
        $this->postJson('/api/progress/scores/merge', [])->assertStatus(422);
        $this->postJson('/api/progress/scores/merge', ['scores' => []])->assertOk()->assertJsonPath('merged', 0);
    }

    public function test_users_cannot_see_or_change_each_others_scores(): void
    {
        $a = $this->makeUser('09120000001');
        $b = $this->makeUser('09120000002');

        $this->actAs($a)->putJson('/api/progress/scores', $this->entry(['accuracy' => 0.9]))->assertOk();
        $this->actAs($b)->getJson('/api/progress/scores')->assertOk()->assertJsonCount(0, 'scores');
        $this->actAs($b)->putJson('/api/progress/scores', $this->entry(['accuracy' => 0.1, 'stars' => 1]))->assertOk();

        $this->assertSame(0.9, (float) SongScore::where('user_id', $a->id)->value('accuracy'));
        $this->assertSame(0.1, (float) SongScore::where('user_id', $b->id)->value('accuracy'));
    }
}
