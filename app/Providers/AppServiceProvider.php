<?php

namespace App\Providers;

use App\Support\AuthRules;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
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

        RateLimiter::for('auth-register', fn (Request $request) => Limit::perHour(10)->by((string) $request->ip()));
    }
}
