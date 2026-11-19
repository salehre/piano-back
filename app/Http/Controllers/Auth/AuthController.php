<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\ApiError;
use App\Support\AuthRules;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    /**
     * هش bcrypt یه رشته‌ی تصادفی؛ وقتی شماره‌ای وجود نداره هم یک Hash::check انجام می‌دیم
     * تا زمان پاسخ «شماره‌ی ناموجود» با «رمز اشتباه» فرق قابل‌تشخیص نداشته باشه.
     */
    private const DUMMY_HASH = '$2y$12$sDfmowv1QoAULHIr.AgRyu7xLTxfAV2dDQ8M66EXf89J/D0mZ3nbW';

    /** کاربر فعلی یا null (مهمان). برای مهمان 401 نمی‌دیم تا کنسول مرورگر با هر بار باز شدن سایت خطا نشون نده */
    public function me(Request $request): JsonResponse
    {
        $user = $request->hasSession() ? Auth::guard('web')->user() : null;

        return response()->json(['user' => $user?->toAuthPayload()]);
    }

    /** می‌گه این شماره حساب با رمز داره یا نه (صفحه‌ی ثبت‌نام برای «شماره قبلاً ثبت شده» ازش استفاده می‌کنه) */
    public function lookup(Request $request): JsonResponse
    {
        $phone = AuthRules::asString($request->input('phone'));
        if ($error = AuthRules::validatePhone($phone)) {
            return ApiError::response($error, 422);
        }

        $user = User::where('phone', AuthRules::normalizePhone($phone))->first();

        return response()->json(['hasPassword' => $user !== null && $user->password !== null]);
    }

    public function register(Request $request): JsonResponse
    {
        if (! $request->hasSession()) {
            return $this->notStateful();
        }

        $phone = AuthRules::asString($request->input('phone'));
        $password = AuthRules::asString($request->input('password'));

        $error = AuthRules::validatePhone($phone) ?? AuthRules::validatePassword($password);
        if ($error) {
            return ApiError::response($error, 422);
        }

        $phone = AuthRules::normalizePhone($phone);
        // ثبت‌نام هیچ‌وقت حساب موجود رو تصاحب نمی‌کنه (تا وقتی تأیید شماره با پیامک نداریم، این مهمه)
        $taken = ApiError::response(AuthRules::ref('auth.register.phoneTaken'), 409);

        if (User::where('phone', $phone)->exists()) {
            return $taken;
        }

        try {
            $user = User::create(['phone' => $phone, 'password' => $password]);
        } catch (UniqueConstraintViolationException) {
            return $taken; // همزمان با یه درخواست دیگه
        }

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return response()->json(['user' => $user->fresh()->toAuthPayload(), 'isNew' => true], 201);
    }

    public function login(Request $request): JsonResponse
    {
        if (! $request->hasSession()) {
            return $this->notStateful();
        }

        $phone = AuthRules::asString($request->input('phone'));
        $password = AuthRules::asString($request->input('password'));

        // فقط فرمت شماره/خالی‌نبودن رمز رو چک می‌کنیم؛ قوانین «قدرت رمز» موقع ورود اعمال نمی‌شن
        if ($error = AuthRules::validatePhone($phone)) {
            return ApiError::response($error, 422);
        }
        if ($password === '') {
            return ApiError::response(AuthRules::ref('auth.validation.passwordRequired'), 422);
        }

        $user = User::where('phone', AuthRules::normalizePhone($phone))->first();
        $valid = Hash::check($password, $user?->password ?? self::DUMMY_HASH) && $user !== null;

        if (! $valid) {
            // پیام یکسان برای «شماره ناموجود» و «رمز اشتباه»
            return ApiError::response(AuthRules::ref('auth.errors.incorrectPassword'), 401);
        }

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return response()->json(['user' => $user->toAuthPayload()]);
    }

    /** idempotent: اگه لاگین هم نباشه 204 برمی‌گردونه */
    public function logout(Request $request): JsonResponse|\Illuminate\Http\Response
    {
        if ($request->hasSession()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->noContent();
    }

    /** درخواست از یه origin غیرمجاز (یا بدون Origin/Referer، مثل curl) اومده و نشست نداره */
    private function notStateful(): JsonResponse
    {
        return ApiError::response(AuthRules::ref('auth.errors.login'), 400);
    }
}
