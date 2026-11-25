<?php

namespace App\Support;

/**
 * قوانین اعتبارسنجی ورود/ثبت‌نام/پروفایل؛ عیناً هم‌قانون با app/utils/auth.ts فرانت (Nuxt).
 *
 * هر validate* یا null (معتبر) برمی‌گردونه یا یه «ارجاع پیام» به شکل ['key' => 'auth.validation.xxx', 'params' => [...]]؛
 * فرانت همین کلید رو با i18n ترجمه می‌کنه، پس متن‌ها سمت بک‌اند نیستن.
 * وابستگی به لاراول نداره (فقط mbstring)، تا مستقیم تست‌پذیر باشه.
 */
final class AuthRules
{
    public const PASSWORD_MIN = 8;

    /** bcrypt بعد از ۷۲ بایت بقیه‌ی رمز رو نادیده می‌گیره؛ پس بیشتر از این رو رد می‌کنیم */
    public const PASSWORD_MAX_BYTES = 72;

    public const DISPLAY_NAME_MIN = 2;

    public const DISPLAY_NAME_MAX = 40;

    public const EMAIL_MAX = 100;

    public const ADDRESS_MAX = 300;

    public const COUNTRY_MAX = 60;

    public const YEARS_PLAYING_MAX = 80;

    /** سقف طول data URL عکس پروفایل (حدود ۲۰۰ کیلوبایت تصویر؛ فرانت ۲۵۶×۲۵۶ JPEG می‌فرسته و معمولاً زیر ۴۰ کیلوبایته) */
    public const AVATAR_MAX_LENGTH = 300_000;

    /** @return array{key: string, params?: array<string, mixed>} */
    public static function ref(string $key, array $params = []): array
    {
        return $params ? ['key' => $key, 'params' => $params] : ['key' => $key];
    }

    public static function asString(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    /** معادل String.prototype.trim جاوااسکریپت (فضاهای یونیکد رو هم برمی‌داره) */
    public static function jsTrim(string $value): string
    {
        return preg_replace('/^[\s\x{FEFF}]+|[\s\x{FEFF}]+$/u', '', $value) ?? $value;
    }

    /** طول به شکل جاوااسکریپت (UTF-16 code unit)، تا با maxlength و String.length فرانت یکی باشه */
    public static function length(string $value): int
    {
        return intdiv(strlen(mb_convert_encoding($value, 'UTF-16LE', 'UTF-8')), 2);
    }

    public static function normalizeDigits(string $value): string
    {
        return strtr($value, [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]);
    }

    /** هر شکل شماره (+98…، 0098…، 98…، 9…) رو به 09xxxxxxxxx تبدیل می‌کنه */
    public static function normalizePhone(string $value): string
    {
        $v = self::normalizeDigits($value);
        $v = preg_replace('/[\s\-()]/u', '', $v) ?? $v;
        if (str_starts_with($v, '+98')) {
            return '0'.substr($v, 3);
        }
        if (str_starts_with($v, '0098')) {
            return '0'.substr($v, 4);
        }
        if (preg_match('/^98\d{10}$/D', $v)) {
            return '0'.substr($v, 2);
        }
        if (preg_match('/^9\d{9}$/D', $v)) {
            return '0'.$v;
        }

        return $v;
    }

    public static function validatePhone(string $value): ?array
    {
        if (self::jsTrim($value) === '') {
            return self::ref('auth.validation.phoneRequired');
        }
        if (! preg_match('/^09\d{9}$/D', self::normalizePhone($value))) {
            return self::ref('auth.validation.phoneInvalid');
        }

        return null;
    }

    public static function validatePassword(string $value): ?array
    {
        if ($value === '') {
            return self::ref('auth.validation.passwordRequired');
        }
        if (self::length($value) < self::PASSWORD_MIN) {
            return self::ref('auth.validation.passwordMin', ['min' => self::PASSWORD_MIN]);
        }
        if (! preg_match('/[A-Za-z]/', $value) || ! preg_match('/\d/', $value)) {
            return self::ref('auth.validation.passwordMix');
        }
        if (strlen($value) > self::PASSWORD_MAX_BYTES) {
            return self::ref('auth.validation.passwordMax', ['max' => self::PASSWORD_MAX_BYTES]);
        }

        return null;
    }

    public static function validateDisplayName(string $value): ?array
    {
        $v = self::jsTrim($value);
        if ($v === '') {
            return self::ref('auth.validation.nameRequired');
        }
        // طول واقعی نام یعنی تعداد حروف (عدد و علامت حساب نمی‌شن)
        if ((int) preg_match_all('/\p{L}/u', $v) < self::DISPLAY_NAME_MIN) {
            return self::ref('auth.validation.nameMin', ['min' => self::DISPLAY_NAME_MIN]);
        }
        if (self::length($v) > self::DISPLAY_NAME_MAX) {
            return self::ref('auth.validation.nameMax', ['max' => self::DISPLAY_NAME_MAX]);
        }

        return null;
    }

    public static function isEmail(string $value): bool
    {
        if (! preg_match('/^[A-Za-z0-9._%+-]+@[A-Za-z0-9-]+(\.[A-Za-z0-9-]+)*\.[A-Za-z]{2,}$/D', $value)) {
            return false;
        }
        [$local, $domain] = array_pad(explode('@', $value), 2, '');
        if (str_starts_with($local, '.') || str_ends_with($local, '.') || str_contains($local, '..')) {
            return false;
        }
        foreach (explode('.', $domain) as $part) {
            if (str_starts_with($part, '-') || str_ends_with($part, '-')) {
                return false;
            }
        }

        return true;
    }

    public static function validateEmail(string $value): ?array
    {
        $v = self::jsTrim($value);
        if ($v === '') {
            return null;
        }
        if (self::length($v) > self::EMAIL_MAX) {
            return self::ref('auth.validation.emailMax', ['max' => self::EMAIL_MAX]);
        }
        if (! self::isEmail($v)) {
            return self::ref('auth.validation.emailInvalid');
        }

        return null;
    }

    public static function validateNationalId(string $value): ?array
    {
        $v = self::jsTrim(self::normalizeDigits($value));
        if ($v === '') {
            return null;
        }
        if (! preg_match('/^\d{10}$/D', $v)) {
            return self::ref('auth.validation.nationalIdLength', ['length' => 10]);
        }
        if (preg_match('/^(\d)\1{9}$/D', $v)) {
            return self::ref('auth.validation.nationalIdInvalid');
        }
        $digits = array_map('intval', str_split($v));
        $sum = 0;
        for ($i = 0; $i < 9; $i++) {
            $sum += $digits[$i] * (10 - $i);
        }
        $r = $sum % 11;
        $check = $r < 2 ? $r : 11 - $r;

        return $check === $digits[9] ? null : self::ref('auth.validation.nationalIdInvalid');
    }

    /** null یعنی «وارد نشده» و معتبره؛ فقط عدد صحیح ۰ تا ۸۰ */
    public static function validateYears(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }
        $isInt = is_int($value) || (is_float($value) && is_finite($value) && floor($value) === $value);
        if (! $isInt || $value < 0 || $value > self::YEARS_PLAYING_MAX) {
            return self::ref('auth.validation.yearsRange', ['min' => 0, 'max' => self::YEARS_PLAYING_MAX]);
        }

        return null;
    }

    /** خالی یا یه data URL از نوع JPEG که واقعاً JPEG باشه (فرانت همیشه JPEG می‌فرسته) */
    public static function validateAvatar(string $value): ?array
    {
        if ($value === '') {
            return null;
        }
        $invalid = self::ref('auth.profile.avatar.unreadable');
        if (strlen($value) > self::AVATAR_MAX_LENGTH) {
            return $invalid;
        }
        if (! preg_match('#^data:image/jpeg;base64,([A-Za-z0-9+/]+={0,2})$#D', $value, $m)) {
            return $invalid;
        }
        $bytes = base64_decode($m[1], true);
        if ($bytes === false || ! str_starts_with($bytes, "\xFF\xD8\xFF")) {
            return $invalid;
        }

        return null;
    }

    public const PROFILE_FIELDS = ['displayName', 'email', 'avatar', 'nationalId', 'country', 'address', 'yearsPlaying'];

    /** اولین خطای پروفایل (به همون ترتیب فرم) یا null. ورودی باید هر هفت فیلد رو داشته باشه (جایگزینی کامل) */
    public static function validateProfile(array $p): ?array
    {
        $generic = self::ref('auth.errors.saveProfile');
        foreach (self::PROFILE_FIELDS as $field) {
            if (! array_key_exists($field, $p)) {
                return $generic;
            }
        }
        foreach (['displayName', 'email', 'avatar', 'nationalId', 'country', 'address'] as $field) {
            if (! is_string($p[$field])) {
                return $generic;
            }
        }

        return self::validateDisplayName($p['displayName'])
            ?? self::validateEmail($p['email'])
            ?? self::validateNationalId($p['nationalId'])
            ?? self::validateYears($p['yearsPlaying'])
            ?? self::validateAvatar($p['avatar'])
            ?? (self::length(self::jsTrim($p['country'])) > self::COUNTRY_MAX
                || self::length(self::jsTrim($p['address'])) > self::ADDRESS_MAX ? $generic : null);
    }

    /**
     * مقادیر تمیز برای ذخیره (بعد از validateProfile).
     *
     * @return array{display_name: string, email: ?string, national_id: ?string, country: ?string, address: ?string, years_playing: ?int}
     */
    public static function normalizeProfile(array $p): array
    {
        $orNull = static fn (string $v): ?string => $v === '' ? null : $v;

        return [
            'display_name' => self::jsTrim($p['displayName']),
            'email' => $orNull(self::jsTrim($p['email'])),
            'national_id' => $orNull(self::jsTrim(self::normalizeDigits($p['nationalId']))),
            'country' => $orNull(self::jsTrim($p['country'])),
            'address' => $orNull(self::jsTrim($p['address'])),
            'years_playing' => $p['yearsPlaying'] === null ? null : (int) $p['yearsPlaying'],
        ];
    }
}
