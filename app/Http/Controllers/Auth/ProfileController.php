<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Support\ApiError;
use App\Support\AuthRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProfileController extends Controller
{
    /** جایگزینی کامل پروفایل: فرانت همیشه هر هفت فیلد رو می‌فرسته */
    public function update(Request $request): JsonResponse
    {
        $input = $request->json()->all();

        // میان‌افزار ConvertEmptyStringsToNull لاراول رشته‌ی خالی رو توی بدنه‌ی JSON هم null می‌کنه؛
        // برای فیلدهای متنی null یعنی همون «خالی» (''). (نوع اشتباه مثل آرایه همچنان رد می‌شه)
        foreach (['displayName', 'email', 'avatar', 'nationalId', 'country', 'address'] as $field) {
            if (array_key_exists($field, $input) && $input[$field] === null) {
                $input[$field] = '';
            }
        }

        if ($error = AuthRules::validateProfile($input)) {
            return ApiError::response($error, 422);
        }

        $user = $request->user();
        $user->fill(AuthRules::normalizeProfile($input))->save();

        return response()->json(['user' => $user->toAuthPayload()]);
    }
}