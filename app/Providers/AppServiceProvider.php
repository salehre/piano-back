<?php

namespace App\Providers;

use App\Support\AuthRules;
use App\Support\Sms\KavenegarSmsSender;
use App\Support\Sms\LogSmsSender;
use App\Support\Sms\SmsSender;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // درایور پیامک از SMS_DRIVER: log (توسعه) | kavenegar
        $this->app->bind(SmsSender::class, fn () => match (config('services.sms.driver')) {
            'kavenegar' => new KavenegarSmsSender(
                (string) config('services.sms.kavenegar.key'),
                (string) config('services.sms.kavenegar.template'),
            ),
            default => new LogSmsSender,
        });
    }

    public function boot(): void
    {
        // ورود: ۵ تلاش در دقیقه برای هر (شماره + IP) و سقف کلی ۳۰ در دقیقه برای هر IP
        RateLimiter::for('auth-login', fn (Request $request) => [
            Limit::perMinute(5)->by(AuthRules::normalizePhone(AuthRules::asString($request->input('phone'))).'|'.$request->ip()),
            Limit::perMinute(30)->by((string) $request->ip()),
        ]);

        // lookup می‌گه شماره حساب داره یا نه؛ برای جلوگیری از پیمایش انبوه شماره‌ها محدودش می‌کنیم
        RateLimiter::for('auth-lookup', fn (Request $request) => Limit::perMinute(20)->by((string) $request->ip()));

        // درخواست کد پیامکی: ۵ بار در ساعت برای هر شماره و ۱۰ بار در ساعت برای هر IP (هزینه‌ی پیامک)
        RateLimiter::for('auth-forgot', fn (Request $request) => [
            Limit::perHour(5)->by('forgot|'.AuthRules::normalizePhone(AuthRules::asString($request->input('phone')))),
            Limit::perHour(10)->by('forgot-ip|'.$request->ip()),
        ]);

        // تأیید کد و رمز جدید؛ حدس‌های اشتباه هر کد جداگانه (۵ بار) محدود می‌شن، این سقف کلیه
        RateLimiter::for('auth-reset', fn (Request $request) => Limit::perMinute(10)->by((string) $request->ip()));

        RateLimiter::for('auth-register', fn (Request $request) => Limit::perHour(10)->by((string) $request->ip()));

        // داده‌ی کاربر وارد‌شده (امتیاز، علاقه‌مندی، آهنگ‌ها): ۳۰۰ درخواست در دقیقه برای هر کاربر
        RateLimiter::for('user-data', fn (Request $request) => Limit::perMinute(300)->by((string) ($request->user()?->id ?? $request->ip())));
    }
}
