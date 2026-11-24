<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ClubMember;
use App\Support\ApiError;
use App\Support\AuthRules;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClubController extends Controller
{
    /**
     * عضویت در باشگاه با ایمیل: {"email": "..."}.
     * جدید 201 و {"alreadyMember": false}؛ تکراری 200 و {"alreadyMember": true} (چیزی دوباره ساخته نمی‌شه).
     * فعلاً فقط ذخیره می‌شه؛ تأیید ایمیل (double opt-in) بعداً.
     */
    public function join(Request $request): JsonResponse
    {
        $email = AuthRules::jsTrim(AuthRules::asString($request->input('email')));
        if ($email === '' || AuthRules::validateEmail($email) !== null) {
            return ApiError::response(AuthRules::ref('api.invalid'), 422);
        }
        $email = mb_strtolower($email);

        if (ClubMember::where('email', $email)->exists()) {
            return response()->json(['alreadyMember' => true]);
        }

        try {
            ClubMember::create(['email' => $email]);
        } catch (UniqueConstraintViolationException) {
            // همزمان با درخواست دیگه‌ای با همین ایمیل
            return response()->json(['alreadyMember' => true]);
        }

        return response()->json(['alreadyMember' => false], 201);
    }
}