<?php

namespace Tests\Feature;

use App\Models\Subscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\ActsAsSpa;
use Tests\TestCase;

class PlanTest extends TestCase
{
    use ActsAsSpa, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSpa();
        Carbon::setTestNow('2026-11-21 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function ms(string $time): int
    {
        return Carbon::parse($time)->getTimestamp() * 1000;
    }

    public function test_requires_login(): void
    {
        $this->getJson('/api/plan')->assertUnauthorized();
        $this->postJson('/api/plan/trial')->assertUnauthorized();
    }

    public function test_new_user_is_free_with_trial_available(): void
    {
        $this->login();

        $this->getJson('/api/plan')->assertOk()->assertExactJson([
            'v' => 1, 'plan' => 'free', 'expiresAt' => null, 'source' => null, 'trialUsed' => false,
        ]);
    }

    public function test_start_trial_makes_premium_for_seven_days(): void
    {
        $user = $this->login();

        $expected = [
            'v' => 1, 'plan' => 'premium', 'expiresAt' => $this->ms('2026-11-28 12:00:00'), 'source' => 'trial', 'trialUsed' => true,
        ];
        $this->postJson('/api/plan/trial')->assertOk()->assertExactJson($expected);
        $this->getJson('/api/plan')->assertOk()->assertExactJson($expected);
        $this->assertDatabaseCount('subscriptions', 1);
        $this->assertDatabaseHas('subscriptions', ['user_id' => $user->id, 'source' => 'trial']);
    }

    public function test_trial_cannot_be_started_while_premium(): void
    {
        $this->login();
        $this->postJson('/api/plan/trial')->assertOk();

        $this->postJson('/api/plan/trial')->assertStatus(409)->assertJsonPath('error.key', 'plan.alreadyPremium');
        $this->assertDatabaseCount('subscriptions', 1);
    }

    public function test_trial_is_only_once_per_account(): void
    {
        $this->login();
        $this->postJson('/api/plan/trial')->assertOk();

        Carbon::setTestNow('2026-11-29 12:00:00'); // بعد از انقضا
        $this->getJson('/api/plan')->assertOk()->assertExactJson([
            'v' => 1, 'plan' => 'free', 'expiresAt' => null, 'source' => null, 'trialUsed' => true,
        ]);
        $this->postJson('/api/plan/trial')->assertStatus(409)->assertJsonPath('error.key', 'plan.trialUsed');
    }

    public function test_each_account_has_its_own_trial(): void
    {
        $this->login($this->makeUser('09120000001'));
        $this->postJson('/api/plan/trial')->assertOk();

        $this->login($this->makeUser('09120000002'));
        $this->getJson('/api/plan')->assertOk()->assertJsonPath('plan', 'free');
        $this->postJson('/api/plan/trial')->assertOk()->assertJsonPath('plan', 'premium');
        $this->assertDatabaseCount('subscriptions', 2);
    }

    public function test_expired_purchase_without_trial_can_still_start_one(): void
    {
        $user = $this->login();
        Subscription::create(['user_id' => $user->id, 'source' => 'purchase', 'expires_at' => '2026-10-01 00:00:00']);

        $this->postJson('/api/plan/trial')->assertOk()
            ->assertJsonPath('source', 'trial')
            ->assertJsonPath('expiresAt', $this->ms('2026-11-28 12:00:00'));
        $this->assertDatabaseCount('subscriptions', 1);
        $this->assertNotNull(Subscription::where('user_id', $user->id)->value('trial_used_at'));
    }

    public function test_active_purchase_is_left_untouched(): void
    {
        $user = $this->login();
        Subscription::create(['user_id' => $user->id, 'source' => 'purchase', 'expires_at' => '2027-03-01 00:00:00']);

        $this->getJson('/api/plan')->assertOk()
            ->assertJsonPath('plan', 'premium')
            ->assertJsonPath('source', 'purchase')
            ->assertJsonPath('expiresAt', $this->ms('2027-03-01 00:00:00'))
            ->assertJsonPath('trialUsed', false);
        $this->postJson('/api/plan/trial')->assertStatus(409)->assertJsonPath('error.key', 'plan.alreadyPremium');
        $this->assertDatabaseHas('subscriptions', ['user_id' => $user->id, 'source' => 'purchase']);
    }
}