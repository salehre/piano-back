<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AvatarController extends Controller
{
    /** عکس پروفایل خود کاربر (فایل روی دیسک private؛ فقط صاحبش می‌بینه). نسخه توی آدرس هست، پس کش بلندمدت امنه */
    public function show(Request $request): StreamedResponse
    {
        $path = $request->user()->avatar_path;
        $disk = Storage::disk('local');
        if ($path === null || $path === '' || ! $disk->exists($path)) {
            abort(404);
        }

        return $disk->response($path, null, [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'private, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}