<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;

/**
 * پاسخ خطای API: بدنه همیشه {"error": {"key": "...", "params": {...}}} هست.
 * key یک کلید i18n فرانته (مثل auth.errors.incorrectPassword)؛ متن ترجمه‌شده اینجا نیست.
 */
final class ApiError
{
    /** @param array{key: string, params?: array<string, mixed>} $ref */
    public static function response(array $ref, int $status): JsonResponse
    {
        return response()->json(['error' => $ref], $status);
    }
}
