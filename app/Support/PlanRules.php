<?php

namespace App\Support;

use DateTimeImmutable;

/**
 * قوانین پلن (رایگان / پریمیوم) و دوره‌ی آزمایشی؛ هم‌قانون با repositories/plan.ts و utils/plans.ts فرانت.
 * وابستگی به لاراول نداره، تا مستقیم تست‌پذیر باشه.
 *
 * شکل داخلی اشتراک: ['source' => 'trial'|'purchase', 'expiresAt' => DateTimeImmutable, 'trialUsed' => bool]
 * (null یعنی کاربر هیچ‌وقت اشتراکی نداشته).
 */
final class PlanRules
{
    /** همون TRIAL_DAYS فرانت (utils/plans.ts) */
    public const TRIAL_DAYS = 7;

    public const SOURCES = ['trial', 'purchase'];

    /** @param  array{source: string, expiresAt: DateTimeImmutable, trialUsed: bool}|null  $state */
    public static function isPremium(?array $state, DateTimeImmutable $now): bool
    {
        return $state !== null && $state['expiresAt'] > $now;
    }

    /**
     * شکل PlanData فرانت. وضعیت منقضی‌شده همون رایگانه (expiresAt و source خالی)، ولی trialUsed می‌مونه.
     * زمان انقضا میلی‌ثانیه‌ست (مثل Date.now() جاوااسکریپت).
     *
     * @param  array{source: string, expiresAt: DateTimeImmutable, trialUsed: bool}|null  $state
     * @return array{v: int, plan: string, expiresAt: int|null, source: string|null, trialUsed: bool}
     */
    public static function present(?array $state, DateTimeImmutable $now): array
    {
        $premium = self::isPremium($state, $now);

        return [
            'v' => 1,
            'plan' => $premium ? 'premium' : 'free',
            'expiresAt' => $premium ? (int) $state['expiresAt']->format('Uv') : null,
            'source' => $premium ? $state['source'] : null,
            'trialUsed' => $state['trialUsed'] ?? false,
        ];
    }

    /**
     * @param  array{source: string, expiresAt: DateTimeImmutable, trialUsed: bool}|null  $state
     * @return 'ok'|'used'|'already-premium'
     */
    public static function trialDecision(?array $state, DateTimeImmutable $now): string
    {
        if (self::isPremium($state, $now)) {
            return 'already-premium';
        }

        return $state !== null && $state['trialUsed'] ? 'used' : 'ok';
    }

    public static function trialExpiry(DateTimeImmutable $now): DateTimeImmutable
    {
        return $now->modify('+'.self::TRIAL_DAYS.' days');
    }
}