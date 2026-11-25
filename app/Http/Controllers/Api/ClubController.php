<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\ClubConfirmMail;
use App\Models\ClubMember;
use App\Support\ApiError;
use App\Support\AuthRules;
use App\Support\ClubLinks;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Throwable;

class ClubController extends Controller
{
    /** حداقل فاصله‌ی دو ایمیل تأیید به یک آدرس (جلوگیری از ایمیل‌بمبی با عضویت‌های پشت‌سرهم) */
    private const MAIL_COOLDOWN_MINUTES = 10;

    /**
     * عضویت در باشگاه با ایمیل: {"email": "..."}.
     * همیشه 202 و {"pending": true}، چه ایمیل جدید باشه چه تکراری یا تأییدشده؛ پس از روی جواب نمی‌شه فهمید
     * ایمیلی عضوه یا نه. عضو واقعی با زدن لینک داخل ایمیل تأیید می‌شه (double opt-in).
     */
    public function join(Request $request): JsonResponse
    {
        $email = AuthRules::jsTrim(AuthRules::asString($request->input('email')));
        if ($email === '' || AuthRules::validateEmail($email) !== null) {
            return ApiError::response(AuthRules::ref('api.invalid'), 422);
        }
        $email = mb_strtolower($email);

        $member = ClubMember::where('email', $email)->first();
        if ($member === null) {
            try {
                $member = ClubMember::create(['email' => $email]);
            } catch (UniqueConstraintViolationException) {
                // همزمان با درخواست دیگه‌ای با همین ایمیل
                $member = ClubMember::where('email', $email)->first();
            }
        }

        if ($member !== null && $member->confirmed_at === null) {
            $this->sendConfirmation($member);
        }

        return response()->json(['pending' => true], 202);
    }

    /** لینک تأیید داخل ایمیل (GET چون مستقیم از ایمیل باز می‌شه): صفحه‌ی نتیجه‌ی ساده برمی‌گردونه */
    public function confirm(int $id, string $signature): Response
    {
        $member = ClubMember::find($id);
        if ($member === null || ! ClubLinks::isValid(ClubLinks::CONFIRM, $member->id, $member->email, (string) config('app.key'), $signature)) {
            return response()->view('club.result', ['state' => 'invalid'], 400);
        }

        if ($member->confirmed_at === null) {
            $member->forceFill(['confirmed_at' => now()])->save();
        }

        return response()->view('club.result', ['state' => 'confirmed']);
    }

    /** لغو عضویت: ایمیل از لیست حذف می‌شه */
    public function unsubscribe(int $id, string $signature): Response
    {
        $member = ClubMember::find($id);
        if ($member === null || ! ClubLinks::isValid(ClubLinks::UNSUBSCRIBE, $member->id, $member->email, (string) config('app.key'), $signature)) {
            return response()->view('club.result', ['state' => 'invalid'], 400);
        }

        $member->delete();

        return response()->view('club.result', ['state' => 'unsubscribed']);
    }

    private function sendConfirmation(ClubMember $member): void
    {
        $cooldownKey = 'club-confirm-mail:'.sha1($member->email);
        if (! Cache::add($cooldownKey, 1, now()->addMinutes(self::MAIL_COOLDOWN_MINUTES))) {
            return;
        }

        $secret = (string) config('app.key');
        $base = (string) config('app.url');

        try {
            Mail::to($member->email)->send(new ClubConfirmMail(
                ClubLinks::url($base, ClubLinks::CONFIRM, $member->id, $member->email, $secret),
                ClubLinks::url($base, ClubLinks::UNSUBSCRIBE, $member->id, $member->email, $secret),
            ));
        } catch (Throwable $e) {
            // خطای ایمیل (مثلاً SMTP) نباید فرم رو خراب کنه؛ ثبت می‌شه و بار بعد دوباره تلاش می‌شه
            report($e);
            Cache::forget($cooldownKey);
        }
    }
}