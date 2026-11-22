<?php

namespace App\Support\Sms;

use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/** ارسال با «ارسال الگو» (verify/lookup) کاوه‌نگار؛ قالب باید توی پنل با یک توکن (%token) ساخته و تأیید شده باشه */
final class KavenegarSmsSender implements SmsSender
{
    public function __construct(private readonly string $apiKey, private readonly string $template) {}

    public function sendCode(string $phone, string $code): void
    {
        try {
            $res = Http::timeout(8)->get("https://api.kavenegar.com/v1/{$this->apiKey}/verify/lookup.json", [
                'receptor' => $phone,
                'token' => $code,
                'template' => $this->template,
            ]);
        } catch (Throwable $e) {
            throw new RuntimeException('SMS provider unreachable: '.$e->getMessage(), 0, $e);
        }

        if (! $res->successful() || ($res->json('return.status') !== 200)) {
            throw new RuntimeException('SMS provider rejected the request (HTTP '.$res->status().')');
        }
    }
}
