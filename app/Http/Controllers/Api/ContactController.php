<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ContactMessage;
use App\Support\ApiError;
use App\Support\AuthRules;
use App\Support\ContactRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ContactController extends Controller
{
    /** ذخیره‌ی پیام تماس: {"name","email","subject","message"}. موفق 204، نامعتبر 422 (سقف تعداد با throttle:contact) */
    public function store(Request $request): JsonResponse|Response
    {
        $data = ContactRules::parse($request->json()->all());
        if ($data === null) {
            return ApiError::response(AuthRules::ref('api.invalid'), 422);
        }

        ContactMessage::create($data);

        return response()->noContent();
    }
}