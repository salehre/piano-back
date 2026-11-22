<?php

namespace App\Support\Sms;

use Illuminate\Support\Facades\Log;

/** فقط برای توسعه: کد توی storage/logs/laravel.log نوشته می‌شه. روی سرور واقعی استفاده نکن (SMS_DRIVER=kavenegar) */
final class LogSmsSender implements SmsSender
{
    public function sendCode(string $phone, string $code): void
    {
        Log::info("SMS code for {$phone}: {$code}");
    }
}
