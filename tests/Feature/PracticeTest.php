<?php

namespace Tests\Feature;

use App\Models\PracticeDay;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsSpa;
use Tests\TestCase;

class PracticeTest extends TestCase
{
    use ActsAsSpa, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSpa();
    }

    /** تاریخ «N روز پیش» به شکل YYYY-MM-DD */
    private function day(int $ago = 0): string
    {
        return now()->subDays($ago)->format('Y-m-d');
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

    public function test_requires_login(): void
    {
        $this->getJson('/api/practice')->assertUnauthorized();
        $this->putJson('/api/practice/days/'.$this->day(), ['ms' => 1000, 'xp' => 1])->assertUnauthorized();
        $this->putJson('/api/practice/meta', $this->meta())->assertUnauthorized();
        $this->postJson('/api/practice/merge', ['days' => []])->assertUnauthorized();
    }

    public function test_index_returns_the_defaults_for_a_new_user(): void
    {
        $this->login();
        $res = $this->getJson('/api/practice')->assertOk()
            ->assertJsonPath('v', 1)
            ->assertJsonPath('xp', 0)
            ->assertJsonPath('goalMin', 10)
            ->assertJsonPath('reminder.on', false)
            ->assertJsonPath('reminder.time', '19:00')
            ->assertJsonPath('reminded', '')
            ->assertJsonPath('counters.lessons', 0)
            ->assertJsonPath('counters.goalDays', 0);

        // days و badges وقتی خالین {} هستن، نه []
        $this->assertStringContainsString('"days":{}', $res->getContent());
        $this->assertStringContainsString('"badges":{}', $res->getContent());
    }

    public function test_save_day_then_index_returns_it(): void
    {
        $this->login();
        $day = $this->day();

        $this->putJson('/api/practice/days/'.$day, ['ms' => 120000, 'xp' => 10.5])
            ->assertOk()->assertJsonPath('day.ms', 120000)->assertJsonPath('day.xp', 10.5);

        $this->getJson('/api/practice')->assertOk()
            ->assertJsonPath('days.'.$day.'.ms', 120000)
            ->assertJsonPath('days.'.$day.'.xp', 10.5);
    }

    public function test_server_keeps_the_highest_ms_and_xp_of_a_day(): void
    {
        $this->login();
        $day = $this->day();
        $this->putJson('/api/practice/days/'.$day, ['ms' => 120000, 'xp' => 10])->assertOk();

        // ms کمتر و xp بیشتر: هر کدوم جدا بیشینه می‌شه
        $this->putJson('/api/practice/days/'.$day, ['ms' => 60000, 'xp' => 20])
            ->assertOk()->assertJsonPath('day.ms', 120000)->assertJsonPath('day.xp', 20);

        $this->assertDatabaseCount('practice_days', 1);
    }

    public function test_day_validation(): void
    {
        $this->login();

        // فرمت اشتباه توی مسیر: route اصلاً match نمی‌شه
        $this->putJson('/api/practice/days/abc', ['ms' => 1000, 'xp' => 1])->assertNotFound();

        // تاریخ ناموجود، آینده‌ی دور و قبل از بازه
        foreach (['2026-02-30', now()->addDays(10)->format('Y-m-d'), '2019-12-31'] as $bad) {
            $this->putJson('/api/practice/days/'.$bad, ['ms' => 1000, 'xp' => 1])
                ->assertStatus(422)->assertJsonPath('error.key', 'api.invalid');
        }

        // بدنه‌ی نامعتبر
        $day = $this->day();
        foreach ([
            ['ms' => -1, 'xp' => 0],
            ['ms' => 86_400_001, 'xp' => 0],
            ['ms' => 1000, 'xp' => -1],
            ['ms' => 1000, 'xp' => 'many'],
            ['ms' => 1000],
            [],
        ] as $body) {
            $this->putJson('/api/practice/days/'.$day, $body)->assertStatus(422);
        }

        $this->assertDatabaseCount('practice_days', 0);
    }

    public function test_meta_roundtrip_and_merge_rules(): void
    {
        $this->login();
        $this->putJson('/api/practice/meta', $this->meta(['xp' => 100.0]))->assertOk()
            ->assertJsonPath('meta.xp', 100)
            ->assertJsonPath('meta.goalMin', 15)
            ->assertJsonPath('meta.badges.firstPractice', '2026-01-05T10:00:00.000Z')
            ->assertJsonPath('meta.counters.lessons', 2);

        // xp و شمارنده‌ها بیشینه، نشان‌ها اجتماع، تنظیمات آخرین مقدار
        $this->putJson('/api/practice/meta', $this->meta([
            'xp' => 40.0,
            'goalMin' => 5,
            'badges' => ['streak3' => '2026-01-07T10:00:00.000Z'],
            'counters' => ['lessons' => 1, 'songRuns' => 9],
            'reminder' => ['on' => false, 'time' => '08:00'],
            'reminded' => $this->day(1),
        ]))->assertOk();

        $this->getJson('/api/practice')->assertOk()
            ->assertJsonPath('xp', 100)
            ->assertJsonPath('goalMin', 5)
            ->assertJsonPath('badges.firstPractice', '2026-01-05T10:00:00.000Z')
            ->assertJsonPath('badges.streak3', '2026-01-07T10:00:00.000Z')
            ->assertJsonPath('counters.lessons', 2)
            ->assertJsonPath('counters.earRounds', 1)
            ->assertJsonPath('counters.songRuns', 9)
            ->assertJsonPath('reminder.on', false)
            ->assertJsonPath('reminder.time', '08:00')
            ->assertJsonPath('reminded', $this->day(1));

        $this->assertDatabaseCount('practice_profiles', 1);
    }

    public function test_meta_validation(): void
    {
        $this->login();

        foreach ([
            $this->meta(['goalMin' => 7]),
            $this->meta(['xp' => -1]),
            $this->meta(['reminder' => ['on' => true, 'time' => '25:00']]),
            $this->meta(['reminded' => 'yesterday']),
            $this->meta(['counters' => ['lessons' => -1]]),
            $this->meta(['badges' => 'all']),
            ['xp' => 1],
            [],
        ] as $body) {
            $this->putJson('/api/practice/meta', $body)->assertStatus(422)->assertJsonPath('error.key', 'api.invalid');
        }

        $this->assertDatabaseCount('practice_profiles', 0);
    }

    public function test_unknown_badges_are_ignored(): void
    {
        $this->login();
        $this->putJson('/api/practice/meta', $this->meta(['badges' => ['firstPractice' => '2026-01-05T10:00:00.000Z', 'madeUp' => '2026-01-05T10:00:00.000Z']]))->assertOk();

        $res = $this->getJson('/api/practice')->assertOk()->assertJsonPath('badges.firstPractice', '2026-01-05T10:00:00.000Z');
        $this->assertArrayNotHasKey('madeUp', $res->json('badges'));
    }

    public function test_index_returns_only_the_last_800_days(): void
    {
        $user = $this->login();

        $rows = [];
        for ($ago = 0; $ago <= 800; $ago++) {
            $rows[] = ['user_id' => $user->id, 'day' => $this->day($ago), 'ms' => 60000, 'xp' => 5, 'created_at' => now(), 'updated_at' => now()];
        }
        foreach (array_chunk($rows, 200) as $chunk) {
            PracticeDay::insert($chunk);
        }

        $days = $this->getJson('/api/practice')->assertOk()->json('days');
        $this->assertCount(800, $days);
        $this->assertArrayHasKey($this->day(0), $days);
        $this->assertArrayHasKey($this->day(799), $days);
        $this->assertArrayNotHasKey($this->day(800), $days);
    }

    public function test_merge_adopts_guest_data_and_uses_guest_settings_for_a_new_account(): void
    {
        $this->login();

        $this->postJson('/api/practice/merge', [
            'days' => [
                $this->day(1) => ['ms' => 90000, 'xp' => 7.5],
                'not-a-day' => ['ms' => 1000, 'xp' => 1],
                $this->day(2) => ['ms' => -5, 'xp' => 1],
            ],
            'meta' => $this->meta(),
        ])->assertOk()->assertJsonPath('merged', 2)->assertJsonPath('skipped', 2);

        $this->getJson('/api/practice')->assertOk()
            ->assertJsonPath('days.'.$this->day(1).'.ms', 90000)
            ->assertJsonPath('days.'.$this->day(1).'.xp', 7.5)
            ->assertJsonPath('xp', 50.5)
            ->assertJsonPath('goalMin', 15)
            ->assertJsonPath('reminder.time', '20:30');

        $this->assertDatabaseCount('practice_days', 1);
    }

    public function test_merge_keeps_the_account_settings_when_it_already_has_data(): void
    {
        $this->login();
        $this->putJson('/api/practice/meta', $this->meta(['xp' => 50.5, 'goalMin' => 15]))->assertOk();

        $this->postJson('/api/practice/merge', [
            'days' => [],
            'meta' => $this->meta(['xp' => 80.0, 'goalMin' => 5, 'reminder' => ['on' => false, 'time' => '08:00']]),
        ])->assertOk()->assertJsonPath('merged', 1);

        // xp بیشینه می‌شه ولی تنظیمات حساب دست‌نخورده می‌مونه
        $this->getJson('/api/practice')->assertOk()
            ->assertJsonPath('xp', 80)
            ->assertJsonPath('goalMin', 15)
            ->assertJsonPath('reminder.on', true)
            ->assertJsonPath('reminder.time', '20:30');
    }

    public function test_merge_is_idempotent(): void
    {
        $this->login();
        $body = [
            'days' => [$this->day(1) => ['ms' => 90000, 'xp' => 7.5]],
            'meta' => $this->meta(),
        ];

        $this->postJson('/api/practice/merge', $body)->assertOk();
        $first = $this->getJson('/api/practice')->assertOk()->json();

        $this->postJson('/api/practice/merge', $body)->assertOk();
        $second = $this->getJson('/api/practice')->assertOk()->json();

        $this->assertSame($first, $second);
        $this->assertDatabaseCount('practice_days', 1);
        $this->assertDatabaseCount('practice_profiles', 1);
    }

    public function test_merge_rejects_invalid_meta_without_writing_anything(): void
    {
        $this->login();

        $this->postJson('/api/practice/merge', [
            'days' => [$this->day(1) => ['ms' => 90000, 'xp' => 7.5]],
            'meta' => $this->meta(['goalMin' => 7]),
        ])->assertStatus(422)->assertJsonPath('error.key', 'api.invalid');

        $this->assertDatabaseCount('practice_days', 0);
        $this->assertDatabaseCount('practice_profiles', 0);
    }

    public function test_merge_rejects_too_many_days(): void
    {
        $this->login();

        $this->postJson('/api/practice/merge', ['days' => array_fill(0, 1001, ['ms' => 1000, 'xp' => 1])])
            ->assertStatus(422)->assertJsonPath('error.key', 'api.invalid');
    }

    public function test_each_user_only_sees_their_own_data(): void
    {
        $this->login();
        $this->putJson('/api/practice/days/'.$this->day(), ['ms' => 60000, 'xp' => 5])->assertOk();
        $this->putJson('/api/practice/meta', $this->meta())->assertOk();

        $this->login($this->makeUser('09120000001'));
        $res = $this->getJson('/api/practice')->assertOk()
            ->assertJsonPath('xp', 0)
            ->assertJsonPath('goalMin', 10);
        $this->assertStringContainsString('"days":{}', $res->getContent());
    }
}