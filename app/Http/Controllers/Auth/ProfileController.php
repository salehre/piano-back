<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\ApiError;
use App\Support\AuthRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class ProfileController extends Controller
{
    private const AVATAR_PREFIX = 'data:image/jpeg;base64,';

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

        // عکس سه حالت داره: لینک همین عکس فعلی (بدون تغییر)، خالی (حذف)، یا data URL جدید (آپلود)
        $avatar = $input['avatar'] ?? '';
        $keepAvatar = is_string($avatar) && $avatar !== '' && str_starts_with($avatar, User::avatarEndpoint());
        $forValidation = $keepAvatar ? array_merge($input, ['avatar' => '']) : $input;

        if ($error = AuthRules::validateProfile($forValidation)) {
            return ApiError::response($error, 422);
        }

        $user = $request->user();
        $disk = Storage::disk('local');
        $oldPath = $user->avatar_path;
        $newPath = $oldPath;

        if (! $keepAvatar) {
            if ($avatar === '') {
                $newPath = null;
            } else {
                // validateProfile مطمئن شده JPEG واقعیه
                $bytes = base64_decode(substr($avatar, strlen(self::AVATAR_PREFIX)), true);
                $newPath = 'avatars/'.$user->id.'-'.Str::random(10).'.jpg';
                $disk->put($newPath, $bytes);
            }
        }

        try {
            $user->fill(AuthRules::normalizeProfile($input));
            $user->avatar_path = $newPath;
            $user->save();
        } catch (Throwable $e) {
            if ($newPath !== null && $newPath !== $oldPath) {
                $disk->delete($newPath);
            }
            throw $e;
        }

        if ($oldPath !== null && $oldPath !== $newPath) {
            $disk->delete($oldPath);
        }

        return response()->json(['user' => $user->toAuthPayload()]);
    }
}