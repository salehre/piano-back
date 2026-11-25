<?php

namespace App\Support;

/**
 * لینک‌های تأیید و لغو عضویت باشگاه. امضا HMAC از (هدف، شناسه، ایمیل) با کلید برنامه‌ست، پس چیزی توی دیتابیس
 * ذخیره نمی‌شه و لینک تأیید نمی‌تونه برای لغو (یا ایمیل دیگه) استفاده بشه. وابستگی به لاراول نداره.
 */
final class ClubLinks
{
    public const CONFIRM = 'confirm';

    public const UNSUBSCRIBE = 'unsubscribe';

    public static function signature(string $purpose, int $id, string $email, string $secret): string
    {
        return hash_hmac('sha256', $purpose.'|'.$id.'|'.mb_strtolower($email), $secret);
    }

    public static function isValid(string $purpose, int $id, string $email, string $secret, string $signature): bool
    {
        return hash_equals(self::signature($purpose, $id, $email, $secret), $signature);
    }

    /** آدرس کامل روی بک‌اند: $baseUrl همون APP_URL ـه (با /piano/Api) */
    public static function url(string $baseUrl, string $purpose, int $id, string $email, string $secret): string
    {
        return rtrim($baseUrl, '/').'/api/club/'.$purpose.'/'.$id.'/'.self::signature($purpose, $id, $email, $secret);
    }
}