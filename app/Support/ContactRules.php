<?php

namespace App\Support;

/**
 * قوانین فرم تماس؛ هم‌قانون با pages/contact.vue فرانت (نام، ایمیل، موضوع ۳ تا ۱۰۰، پیام ۱۰ تا ۱۰۰۰ کاراکتر).
 * وابستگی به لاراول نداره، تا مستقیم تست‌پذیر باشه.
 */
final class ContactRules
{
    public const SUBJECT_MIN = 3;

    public const SUBJECT_MAX = 100;

    public const MESSAGE_MIN = 10;

    public const MESSAGE_MAX = 1000;

    /**
     * ورودی خام رو بررسی و تمیز می‌کنه؛ null یعنی نامعتبر.
     *
     * @return array{name: string, email: string, subject: string, message: string}|null
     */
    public static function parse(array $input): ?array
    {
        $name = self::text($input['name'] ?? null);
        $email = self::text($input['email'] ?? null);
        $subject = self::text($input['subject'] ?? null);
        $message = self::text($input['message'] ?? null);

        if ($name === null || $email === null || $subject === null || $message === null) {
            return null;
        }
        if (AuthRules::validateDisplayName($name) !== null) {
            return null;
        }
        // validateEmail برای ایمیل خالی null می‌ده (چون توی پروفایل اختیاریه)؛ اینجا اجباریه
        if ($email === '' || AuthRules::validateEmail($email) !== null) {
            return null;
        }
        if (! self::between($subject, self::SUBJECT_MIN, self::SUBJECT_MAX)
            || ! self::between($message, self::MESSAGE_MIN, self::MESSAGE_MAX)) {
            return null;
        }

        return ['name' => $name, 'email' => mb_strtolower($email), 'subject' => $subject, 'message' => $message];
    }

    /** رشته‌ی بریده‌شده؛ null اگه رشته نباشه */
    private static function text(mixed $value): ?string
    {
        return is_string($value) ? AuthRules::jsTrim($value) : null;
    }

    private static function between(string $value, int $min, int $max): bool
    {
        $length = AuthRules::length($value);

        return $length >= $min && $length <= $max;
    }
}