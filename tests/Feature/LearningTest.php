<?php

namespace Tests\Feature;

use App\Models\LessonProgress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsSpa;
use Tests\TestCase;

class LearningTest extends TestCase
{
    use ActsAsSpa, RefreshDatabase;

    private const EMPTY_EAR = ['rounds' => 0, 'answered' => 0, 'correct' => 0, 'bestRound' => 0];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSpa();
    }

    public function test_requires_login(): void
    {
        $this->getJson('/api/learning')->assertUnauthorized();
        $this->putJson('/api/learning/lessons/basics-1', ['stars' => 2])->assertUnauthorized();
        $this->postJson('/api/learning/ear-rounds', ['mode' => 'note', 'correct' => 5, 'total' => 10])->assertUnauthorized();
        $this->postJson('/api/learning/merge', [])->assertUnauthorized();
    }

    public function test_index_for_a_new_user_has_empty_objects_and_all_ear_modes(): void
    {
        $this->login();
        $res = $this->getJson('/api/learning')->assertOk();

        // {} نه []
        $this->assertStringContainsString('"lessons":{}', $res->getContent());
        $this->assertStringContainsString('"stars":{}', $res->getContent());
        $res->assertJsonPath('v', 1);
        foreach (['interval', 'chord', 'scale', 'note', 'progression'] as $mode) {
            $res->assertJsonPath("ear.$mode", self::EMPTY_EAR);
        }
    }

    public function test_complete_lesson_keeps_first_time_and_best_stars(): void
    {
        $this->login();

        $first = $this->putJson('/api/learning/lessons/basics-1', ['stars' => 2])->assertOk()->json('lesson');
        $this->assertSame(2, $first['stars']);

        // ستاره‌ی کمتر چیزی رو پایین نمیاره و زمان اولین تکمیل عوض نمی‌شه
        $this->putJson('/api/learning/lessons/basics-1', ['stars' => 1])->assertOk()
            ->assertJsonPath('lesson.stars', 2)->assertJsonPath('lesson.at', $first['at']);

        $this->putJson('/api/learning/lessons/basics-1', ['stars' => 3])->assertOk()
            ->assertJsonPath('lesson.stars', 3)->assertJsonPath('lesson.at', $first['at']);

        $this->getJson('/api/learning')->assertOk()
            ->assertJsonPath('lessons.basics-1', $first['at'])
            ->assertJsonPath('stars.basics-1', 3);
        $this->assertDatabaseCount('lesson_progress', 1);
    }

    public function test_complete_lesson_rejects_invalid_input(): void
    {
        $this->login();
        foreach ([[], ['stars' => 0], ['stars' => 4], ['stars' => '2'], ['stars' => 1.5], ['stars' => null]] as $body) {
            $this->putJson('/api/learning/lessons/basics-1', $body)->assertStatus(422)->assertJsonPath('error.key', 'api.invalid');
        }
        // شناسه‌ی نامعتبر توی مسیر (route constraint) پیدا نمی‌شه
        $this->putJson('/api/learning/lessons/'.str_repeat('a', 61), ['stars' => 1])->assertNotFound();
        $this->assertDatabaseCount('lesson_progress', 0);
    }

    public function test_lesson_limit_is_enforced(): void
    {
        $user = $this->login();
        $now = now();
        LessonProgress::insert(array_map(
            fn ($i) => ['user_id' => $user->id, 'lesson_id' => "l$i", 'stars' => 1, 'completed_at' => $now, 'created_at' => $now, 'updated_at' => $now],
            range(1, 500),
        ));

        $this->putJson('/api/learning/lessons/one-too-many', ['stars' => 1])
            ->assertStatus(422)->assertJsonPath('error.key', 'api.limitReached');
        // درس موجود هنوز قابل به‌روزرسانیه
        $this->putJson('/api/learning/lessons/l1', ['stars' => 3])->assertOk()->assertJsonPath('lesson.stars', 3);
        $this->assertDatabaseCount('lesson_progress', 500);
    }

    public function test_ear_rounds_accumulate_per_mode(): void
    {
        $this->login();

        $this->postJson('/api/learning/ear-rounds', ['mode' => 'interval', 'correct' => 7, 'total' => 10])->assertOk()
            ->assertExactJson(['ear' => ['rounds' => 1, 'answered' => 10, 'correct' => 7, 'bestRound' => 7]]);
        $this->postJson('/api/learning/ear-rounds', ['mode' => 'interval', 'correct' => 4, 'total' => 10])->assertOk()
            ->assertExactJson(['ear' => ['rounds' => 2, 'answered' => 20, 'correct' => 11, 'bestRound' => 7]]);
        $this->postJson('/api/learning/ear-rounds', ['mode' => 'chord', 'correct' => 9, 'total' => 10])->assertOk();

        $this->getJson('/api/learning')->assertOk()
            ->assertJsonPath('ear.interval', ['rounds' => 2, 'answered' => 20, 'correct' => 11, 'bestRound' => 7])
            ->assertJsonPath('ear.chord', ['rounds' => 1, 'answered' => 10, 'correct' => 9, 'bestRound' => 9])
            ->assertJsonPath('ear.note', self::EMPTY_EAR);
    }

    public function test_ear_round_rejects_invalid_input(): void
    {
        $this->login();
        $bodies = [
            ['mode' => 'rhythm', 'correct' => 5, 'total' => 10],
            ['mode' => 'note', 'correct' => 11, 'total' => 10],
            ['mode' => 'note', 'correct' => -1, 'total' => 10],
            ['mode' => 'note', 'correct' => 5, 'total' => 0],
            ['mode' => 'note', 'correct' => 5, 'total' => 51],
            ['mode' => 'note', 'correct' => '5', 'total' => 10],
            ['correct' => 5, 'total' => 10],
        ];
        foreach ($bodies as $body) {
            $this->postJson('/api/learning/ear-rounds', $body)->assertStatus(422)->assertJsonPath('error.key', 'api.invalid');
        }
        $this->assertDatabaseCount('ear_stats', 0);
    }

    public function test_merge_adopts_guest_data(): void
    {
        $this->login();
        $this->putJson('/api/learning/lessons/basics-1', ['stars' => 2])->assertOk();
        $this->postJson('/api/learning/ear-rounds', ['mode' => 'note', 'correct' => 5, 'total' => 10])->assertOk();

        $res = $this->postJson('/api/learning/merge', [
            'lessons' => ['basics-1' => '2020-06-01T00:00:00.000Z', 'basics-2' => '2026-11-20T06:00:00.000Z', 'bad id' => '2026-11-20T06:00:00.000Z'],
            'stars' => ['basics-1' => 3, 'basics-2' => 1],
            'ear' => [
                'note' => ['rounds' => 2, 'answered' => 20, 'correct' => 18, 'bestRound' => 10],
                'rhythm' => ['rounds' => 1, 'answered' => 10, 'correct' => 5, 'bestRound' => 5],
                'scale' => ['rounds' => 1, 'answered' => 10, 'correct' => 11, 'bestRound' => 5],
            ],
        ])->assertOk();
        // basics-1 + basics-2 + note ادغام شدن؛ «bad id»، حالت ناشناخته و آمار ناسازگار رد شدن
        $res->assertExactJson(['merged' => 3, 'skipped' => 3]);

        $data = $this->getJson('/api/learning')->assertOk();
        $data->assertJsonPath('stars.basics-1', 3)->assertJsonPath('stars.basics-2', 1);
        // زودترین زمان تکمیل می‌مونه
        $data->assertJsonPath('lessons.basics-1', '2020-06-01T00:00:00.000Z');
        $data->assertJsonPath('ear.note', ['rounds' => 3, 'answered' => 30, 'correct' => 23, 'bestRound' => 10]);
        $data->assertJsonPath('ear.scale', self::EMPTY_EAR);
    }

    public function test_merge_accepts_lessons_without_stars_and_empty_input(): void
    {
        $this->login();
        $this->postJson('/api/learning/merge', ['lessons' => ['old-lesson' => '2026-01-01T00:00:00Z'], 'stars' => [], 'ear' => []])
            ->assertOk()->assertExactJson(['merged' => 1, 'skipped' => 0]);
        $data = $this->getJson('/api/learning')->assertOk();
        $this->assertSame(['old-lesson'], array_keys($data->json('lessons')));
        $this->assertSame([], $data->json('stars'));

        $this->postJson('/api/learning/merge', [])->assertOk()->assertExactJson(['merged' => 0, 'skipped' => 0]);
    }

    public function test_merge_rejects_bad_shapes_and_over_limit(): void
    {
        $this->login();
        $this->postJson('/api/learning/merge', ['lessons' => 'x'])->assertStatus(422)->assertJsonPath('error.key', 'api.invalid');
        $this->postJson('/api/learning/merge', ['ear' => 5])->assertStatus(422);

        $many = array_combine(array_map(fn ($i) => "l$i", range(1, 501)), array_fill(0, 501, '2026-01-01T00:00:00Z'));
        $this->postJson('/api/learning/merge', ['lessons' => $many])->assertStatus(422);
        $this->assertDatabaseCount('lesson_progress', 0);
    }

    public function test_users_are_isolated(): void
    {
        $a = $this->makeUser('09120000001');
        $b = $this->makeUser('09120000002');

        $this->actAs($a)->putJson('/api/learning/lessons/basics-1', ['stars' => 3])->assertOk();
        $this->actAs($a)->postJson('/api/learning/ear-rounds', ['mode' => 'note', 'correct' => 10, 'total' => 10])->assertOk();

        $this->actAs($b)->getJson('/api/learning')->assertOk()
            ->assertJsonPath('ear.note', self::EMPTY_EAR)
            ->assertJsonCount(0, 'lessons');
        $this->actAs($b)->putJson('/api/learning/lessons/basics-1', ['stars' => 1])->assertOk()->assertJsonPath('lesson.stars', 1);
        $this->actAs($a)->getJson('/api/learning')->assertJsonPath('stars.basics-1', 3);
    }
}