<?php

namespace Tests\Unit;

use App\Support\AuthRules;
use PHPUnit\Framework\TestCase;

class AuthRulesTest extends TestCase
{
    public function test_normalize_phone_accepts_all_common_shapes(): void
    {
        foreach (['09123456789', '+989123456789', '00989123456789', '989123456789', '9123456789', '۰۹۱۲۳۴۵۶۷۸۹', '٠٩١٢٣٤٥٦٧٨٩', '0912 345-6789', '(0912) 3456789'] as $input) {
            $this->assertSame('09123456789', AuthRules::normalizePhone($input), $input);
        }
    }

    public function test_validate_phone(): void
    {
        $this->assertNull(AuthRules::validatePhone('09123456789'));
        $this->assertSame('auth.validation.phoneRequired', AuthRules::validatePhone('   ')['key']);
        $this->assertSame('auth.validation.phoneInvalid', AuthRules::validatePhone('0812345678')['key']);
        // فاصله و خط‌جدید کنار شماره حذف می‌شن (مثل فرانت)
        $this->assertNull(AuthRules::validatePhone("09123456789\n"));
    }

    public function test_validate_password(): void
    {
        $this->assertNull(AuthRules::validatePassword('abcdef12'));
        $this->assertSame('auth.validation.passwordRequired', AuthRules::validatePassword('')['key']);
        $this->assertSame(['key' => 'auth.validation.passwordMin', 'params' => ['min' => 8]], AuthRules::validatePassword('abc12'));
        $this->assertSame('auth.validation.passwordMix', AuthRules::validatePassword('abcdefgh')['key']);
        $this->assertSame('auth.validation.passwordMix', AuthRules::validatePassword('12345678')['key']);
        $this->assertSame('auth.validation.passwordMax', AuthRules::validatePassword(str_repeat('a1', 37))['key']); // 74 بایت
        $this->assertNull(AuthRules::validatePassword(str_repeat('a1', 36))); // دقیقاً 72 بایت
    }

    public function test_validate_display_name(): void
    {
        $this->assertNull(AuthRules::validateDisplayName('علی'));
        $this->assertNull(AuthRules::validateDisplayName('  Sara 2  '));
        $this->assertSame('auth.validation.nameRequired', AuthRules::validateDisplayName('  ')['key']);
        $this->assertSame('auth.validation.nameMin', AuthRules::validateDisplayName('a1234')['key']);
        $this->assertSame('auth.validation.nameMax', AuthRules::validateDisplayName(str_repeat('a', 41))['key']);
    }

    public function test_validate_email_and_national_id(): void
    {
        $this->assertNull(AuthRules::validateEmail(''));
        $this->assertNull(AuthRules::validateEmail('a.b@example.com'));
        foreach (['a@b', '.a@example.com', 'a..b@example.com', 'a@-x.com', 'a b@example.com'] as $bad) {
            $this->assertSame('auth.validation.emailInvalid', AuthRules::validateEmail($bad)['key'], $bad);
        }
        $this->assertNull(AuthRules::validateNationalId(''));
        $this->assertNull(AuthRules::validateNationalId('۰۰۱۳۵۴۲۴۱۹'));
        $this->assertSame('auth.validation.nationalIdLength', AuthRules::validateNationalId('123')['key']);
        $this->assertSame('auth.validation.nationalIdInvalid', AuthRules::validateNationalId('1111111111')['key']);
        $this->assertSame('auth.validation.nationalIdInvalid', AuthRules::validateNationalId('0013542418')['key']);
    }

    public function test_validate_years_and_avatar(): void
    {
        $this->assertNull(AuthRules::validateYears(null));
        $this->assertNull(AuthRules::validateYears(0));
        $this->assertNull(AuthRules::validateYears(80));
        foreach ([-1, 81, 2.5, '5', true] as $bad) {
            $this->assertSame('auth.validation.yearsRange', AuthRules::validateYears($bad)['key']);
        }
        $this->assertNull(AuthRules::validateAvatar(''));
        $jpeg = 'data:image/jpeg;base64,'.base64_encode("\xFF\xD8\xFF\xE0rest");
        $this->assertNull(AuthRules::validateAvatar($jpeg));
        $this->assertSame('auth.profile.avatar.unreadable', AuthRules::validateAvatar('data:image/jpeg;base64,'.base64_encode('not a jpeg'))['key']);
        $this->assertSame('auth.profile.avatar.unreadable', AuthRules::validateAvatar('data:text/html;base64,PGI+')['key']);
        $this->assertSame('auth.profile.avatar.unreadable', AuthRules::validateAvatar('https://example.com/a.jpg')['key']);
    }

    public function test_validate_profile_requires_all_fields_and_orders_errors(): void
    {
        $ok = ['displayName' => 'Sara', 'email' => '', 'avatar' => '', 'nationalId' => '', 'country' => '', 'address' => '', 'yearsPlaying' => null];
        $this->assertNull(AuthRules::validateProfile($ok));

        $missing = $ok;
        unset($missing['address']);
        $this->assertSame('auth.errors.saveProfile', AuthRules::validateProfile($missing)['key']);

        $wrongType = $ok;
        $wrongType['email'] = ['x'];
        $this->assertSame('auth.errors.saveProfile', AuthRules::validateProfile($wrongType)['key']);

        $both = $ok;
        $both['displayName'] = '';
        $both['email'] = 'bad';
        $this->assertSame('auth.validation.nameRequired', AuthRules::validateProfile($both)['key']);

        $tooLong = $ok;
        $tooLong['address'] = str_repeat('x', 301);
        $this->assertSame('auth.errors.saveProfile', AuthRules::validateProfile($tooLong)['key']);
    }

    public function test_normalize_profile(): void
    {
        $n = AuthRules::normalizeProfile([
            'displayName' => '  Sara ', 'email' => ' a@b.co ', 'avatar' => '', 'nationalId' => '۰۰۱۳۵۴۲۴۱۹',
            'country' => '', 'address' => ' x ', 'yearsPlaying' => 3.0,
        ]);
        $this->assertSame(
            ['display_name' => 'Sara', 'email' => 'a@b.co', 'avatar' => null, 'national_id' => '0013542419', 'country' => null, 'address' => 'x', 'years_playing' => 3],
            $n,
        );
    }
}
