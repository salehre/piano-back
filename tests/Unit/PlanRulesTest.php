<?php

namespace Tests\Unit;

use App\Support\PlanRules;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

class PlanRulesTest extends TestCase
{
    private function at(string $time): DateTimeImmutable
    {
        return new DateTimeImmutable($time, new DateTimeZone('UTC'));
    }

    private function state(string $source, string $expiresAt, bool $trialUsed): array
    {
        return ['source' => $source, 'expiresAt' => $this->at($expiresAt), 'trialUsed' => $trialUsed];
    }

    public function test_no_subscription_is_free_with_trial_available(): void
    {
        $this->assertSame(
            ['v' => 1, 'plan' => 'free', 'expiresAt' => null, 'source' => null, 'trialUsed' => false],
            PlanRules::present(null, $this->at('2026-11-21 12:00:00')),
        );
        $this->assertSame('ok', PlanRules::trialDecision(null, $this->at('2026-11-21 12:00:00')));
    }

    public function test_active_subscription_is_premium_with_millisecond_expiry(): void
    {
        $state = $this->state('trial', '2026-11-28 12:00:00', true);
        $plan = PlanRules::present($state, $this->at('2026-11-21 12:00:00'));

        $this->assertSame('premium', $plan['plan']);
        $this->assertSame('trial', $plan['source']);
        $this->assertTrue($plan['trialUsed']);
        $this->assertSame($this->at('2026-11-28 12:00:00')->getTimestamp() * 1000, $plan['expiresAt']);
    }

    public function test_expired_subscription_is_free_but_remembers_the_trial(): void
    {
        $state = $this->state('trial', '2026-11-28 12:00:00', true);
        $now = $this->at('2026-11-28 12:00:00'); // لحظه‌ی انقضا دیگه پریمیوم نیست

        $this->assertSame(
            ['v' => 1, 'plan' => 'free', 'expiresAt' => null, 'source' => null, 'trialUsed' => true],
            PlanRules::present($state, $now),
        );
        $this->assertSame('used', PlanRules::trialDecision($state, $now));
    }

    public function test_trial_decision(): void
    {
        $now = $this->at('2026-11-21 12:00:00');

        $this->assertSame('already-premium', PlanRules::trialDecision($this->state('trial', '2026-11-25 00:00:00', true), $now));
        $this->assertSame('already-premium', PlanRules::trialDecision($this->state('purchase', '2027-01-01 00:00:00', false), $now));
        // خرید منقضی‌شده‌ای که دوره‌ی آزمایشی نداشته، هنوز حق دوره‌ی آزمایشی داره
        $this->assertSame('ok', PlanRules::trialDecision($this->state('purchase', '2026-10-01 00:00:00', false), $now));
        $this->assertSame('used', PlanRules::trialDecision($this->state('purchase', '2026-10-01 00:00:00', true), $now));
    }

    public function test_trial_expiry_is_seven_days_later(): void
    {
        $this->assertSame(7, PlanRules::TRIAL_DAYS);
        $this->assertEquals($this->at('2026-11-28 12:00:00'), PlanRules::trialExpiry($this->at('2026-11-21 12:00:00')));
    }
}