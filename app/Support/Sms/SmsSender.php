<?php

namespace App\Support\Sms;

interface SmsSender
{
    /**
     * کد تأیید رو برای شماره می‌فرسته (شماره به شکل نرمال 09xxxxxxxxx).
     *
     * @throws \RuntimeException اگه ارسال نشد
     */
    public function sendCode(string $phone, string $code): void;
}
