<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\ApiError;
use App\Support\AuthRules;
use App\Support\OtpRules;
use App\Support\Sms\SmsSender;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * بازیابی رمز با کد پیامکی. موفق‌شدن این جریان مالکیت شماره رو ثابت می‌کنه (phone_verified_at)،
 * پس کسی که شماره‌ی دیگری رو بدون تأیید ثبت کرده باشه با همین راه از حساب بیرون می‌ره و صاحب واقعی شماره حساب رو می‌گیره.
 */
class PasswordResetController extends Controller
{
    /**
     * درخواست کد. برای شماره‌ی ثبت‌نشده هم همون پاسخ ۲۰۴ داده می‌شه (و پیامک نمی‌ره)،
     * تا این endpoint خودش یه «آیا این شماره حساب داره» جدید نشه و هزینه‌ی پیامک به شماره‌ی غریبه‌ها نره.
     */
    public function forgot(Request $request, SmsSender $sms): JsonResponse|Response
    {
        $phone = AuthRules::asString($request->input('phone'));
        if ($error = AuthRules::validatePhone($phone)) {
            return ApiError::response($error, 422);
        }
        $phone = AuthRules::normalizePhone($phone);

        // فاصله‌ی دو درخواست؛ add فقط وقتی true می‌ده که کلید نباشه (اتمیک)
        if (! Cache::add($this->cooldownKey($phone), 1, OtpRules::RESEND_SECONDS)) {
            return ApiError::response(AuthRules::ref('auth.errors.tooManyRequests'), 429);
        }

        if (User::where('phone', $phone)->exists()) {
            $code = OtpRules::generate();
            Cache::put($this->codeKey($phone), [
                'hash' => OtpRules::hash($phone, $code, $this->appKey()),
                'attempts' => 0,
                'expiresAt' => time() + OtpRules::TTL_SECONDS,
            ], OtpRules::TTL_SECONDS);

            try {
                $sms->sendCode($phone, $code);
            } catch (Throwable $e) {
                Log::error('OTP SMS failed: '.$e->getMessage());
                Cache::forget($this->codeKey($phone));
                Cache::forget($this->cooldownKey($phone));

                return ApiError::response(AuthRules::ref('auth.errors.smsFailed'), 503);
            }
        }

        return response()->noContent();
    }

    /** تأیید کد و گذاشتن رمز جدید؛ بعدش کاربر وارد می‌شه */
    public function reset(Request $request): JsonResponse
    {
        if (! $request->hasSession()) {
            return ApiError::response(AuthRules::ref('auth.errors.login'), 400);
        }

        $phone = AuthRules::asString($request->input('phone'));
        $code = AuthRules::asString($request->input('code'));
        $password = AuthRules::asString($request->input('password'));

        // اول فرمت‌ها؛ رمز ضعیف کد رو نمی‌سوزونه
        $error = AuthRules::validatePhone($phone) ?? OtpRules::validateCode($code) ?? AuthRules::validatePassword($password);
        if ($error) {
            return ApiError::response($error, 422);
        }

        $phone = AuthRules::normalizePhone($phone);
        $code = OtpRules::normalizeCode($code);

        // قفل کوتاه تا دو درخواست همزمان شمارنده‌ی حدس رو دور نزنن
        $outcome = Cache::lock('otp:lock:'.$phone, 5)->get(fn () => $this->consume($phone, $code));
        if ($outcome === false) {
            return ApiError::response(AuthRules::ref('auth.errors.tooManyRequests'), 429);
        }
        if ($outcome !== 'ok') {
            return ApiError::response(AuthRules::ref($outcome === 'expired' ? 'auth.errors.codeExpired' : 'auth.errors.codeIncorrect'), 422);
        }

        $user = User::where('phone', $phone)->first();
        if ($user === null) {
            return ApiError::response(AuthRules::ref('auth.errors.codeExpired'), 422);
        }

        DB::transaction(function () use ($user, $password) {
            $user->forceFill(['password' => $password, 'phone_verified_at' => now()])->save();

            // نشست‌های قبلی این حساب (مثلاً کسی که شماره رو بی‌اجازه ثبت کرده بود) باطل می‌شن
            if (config('session.driver') === 'database') {
                DB::table(config('session.table', 'sessions'))->where('user_id', $user->id)->delete();
            }
        });

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return response()->json(['user' => $user->fresh()->toAuthPayload()]);
    }

    /** @return 'ok'|'invalid'|'expired' */
    private function consume(string $phone, string $code): string
    {
        $key = $this->codeKey($phone);
        $entry = Cache::get($key);

        if (! is_array($entry) || ($entry['expiresAt'] ?? 0) <= time()) {
            Cache::forget($key);

            return 'expired';
        }

        if (hash_equals((string) $entry['hash'], OtpRules::hash($phone, $code, $this->appKey()))) {
            Cache::forget($key); // یک‌بارمصرف

            return 'ok';
        }

        $entry['attempts'] = (int) $entry['attempts'] + 1;
        if ($entry['attempts'] >= OtpRules::MAX_ATTEMPTS) {
            Cache::forget($key);

            return 'expired';
        }
        Cache::put($key, $entry, max(1, $entry['expiresAt'] - time()));

        return 'invalid';
    }

    private function codeKey(string $phone): string
    {
        return 'otp:reset:'.$phone;
    }

    private function cooldownKey(string $phone): string
    {
        return 'otp:cooldown:'.$phone;
    }

    private function appKey(): string
    {
        return (string) config('app.key');
    }
}
