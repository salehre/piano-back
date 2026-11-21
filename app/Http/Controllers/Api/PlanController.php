<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Subscription;
use App\Support\ApiError;
use App\Support\AuthRules;
use App\Support\PlanRules;
use DateTimeImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PlanController extends Controller
{
    /** وضعیت فعلی اشتراک کاربر، به شکل PlanData فرانت */
    public function show(Request $request): JsonResponse
    {
        $sub = Subscription::where('user_id', $request->user()->id)->first();

        return response()->json(PlanRules::present($sub?->toState(), $this->now()));
    }

    /**
     * شروع دوره‌ی آزمایشی؛ برای هر حساب فقط یک بار. سرور زمان پایان رو خودش حساب می‌کنه.
     * خطاها: 409 با plan.trialUsed (قبلاً استفاده شده) یا plan.alreadyPremium (الان پریمیوم هست).
     */
    public function startTrial(Request $request): JsonResponse
    {
        $userId = $request->user()->id;
        $now = $this->now();

        try {
            [$decision, $sub] = DB::transaction(function () use ($userId, $now) {
                $sub = Subscription::where('user_id', $userId)->lockForUpdate()->first();

                $decision = PlanRules::trialDecision($sub?->toState(), $now);
                if ($decision !== 'ok') {
                    return [$decision, $sub];
                }

                $attributes = [
                    'source' => 'trial',
                    'expires_at' => PlanRules::trialExpiry($now),
                    'trial_used_at' => $now,
                ];
                if ($sub === null) {
                    $sub = Subscription::create(['user_id' => $userId] + $attributes);
                } else {
                    $sub->update($attributes);
                }

                return ['ok', $sub];
            });
        } catch (UniqueConstraintViolationException) {
            // دو درخواست همزمان؛ اون یکی ردیف رو ساخته و دوره‌ی آزمایشی همین الان استفاده شده
            return ApiError::response(AuthRules::ref('plan.trialUsed'), 409);
        }

        return match ($decision) {
            'already-premium' => ApiError::response(AuthRules::ref('plan.alreadyPremium'), 409),
            'used' => ApiError::response(AuthRules::ref('plan.trialUsed'), 409),
            default => response()->json(PlanRules::present($sub->toState(), $now)),
        };
    }

    private function now(): DateTimeImmutable
    {
        return DateTimeImmutable::createFromInterface(now());
    }
}