<?php

namespace App\Support;

/**
 * قوانین کد یک‌بارمصرف پیامکی. وابستگی به لاراول نداره، تا مستقیم تست‌پذیر باشه.
 */
final class OtpRules
{
    public const LENGTH = 6;

    /** عمر کد (ثانیه) */
    public const TTL_SECONDS = 300;

    /** بعد از این تعداد حدس اشتباه، کد باطل می‌شه */
    public const MAX_ATTEMPTS = 5;

    /** حداقل فاصله‌ی دو درخواست کد برای یک شماره (ثانیه) */
    public const RESEND_SECONDS = 60;

    public static function generate(): string
    {
        return str_pad((string) random_int(0, 10 ** self::LENGTH - 1), self::LENGTH, '0', STR_PAD_LEFT);
    }

    /** کد خام توی کش ذخیره نمی‌شه؛ فقط HMAC اون (وابسته به شماره و کلید برنامه) */
    public static function hash(string $phone, string $code, string $key): string
    {
        return hash_hmac('sha256', $phone.'|'.$code, $key);
    }

    /** ارقام فارسی/عربی رو لاتین می‌کنه و فاصله‌های دور کد رو برمی‌داره */
    public static function normalizeCode(string $value): string
    {
        return AuthRules::normalizeDigits(AuthRules::jsTrim($value));
    }

    /** @return array{key: string, params?: array<string, mixed>}|null */
    public static function validateCode(string $value): ?array
    {
        return preg_match('/^\d{'.self::LENGTH.'}$/D', self::normalizeCode($value)) === 1
            ? null
            : AuthRules::ref('auth.validation.codeRequired', ['length' => self::LENGTH]);
    }
}
