<?php

namespace Tests\Unit;

use App\Support\OtpRules;
use PHPUnit\Framework\TestCase;

class OtpRulesTest extends TestCase
{
    public function test_generated_code_is_six_digits(): void
    {
        for ($i = 0; $i < 50; $i++) {
            $this->assertMatchesRegularExpression('/^\d{6}$/D', OtpRules::generate());
        }
    }

    public function test_hash_depends_on_phone_code_and_key(): void
    {
        $base = OtpRules::hash('09123456789', '123456', 'k');

        $this->assertSame($base, OtpRules::hash('09123456789', '123456', 'k'));
        $this->assertNotSame($base, OtpRules::hash('09123456780', '123456', 'k'));
        $this->assertNotSame($base, OtpRules::hash('09123456789', '123457', 'k'));
        $this->assertNotSame($base, OtpRules::hash('09123456789', '123456', 'other'));
    }

    public function test_code_validation_accepts_persian_digits_and_spaces(): void
    {
        $this->assertSame('123456', OtpRules::normalizeCode(' ۱۲۳۴۵۶ '));
        $this->assertNull(OtpRules::validateCode('۱۲۳۴۵۶'));
        $this->assertNull(OtpRules::validateCode('000123'));
    }

    public function test_code_validation_rejects_bad_codes(): void
    {
        foreach (['', '12345', '1234567', 'abcdef', '12 3456', "123456\n7"] as $bad) {
            $this->assertSame('auth.validation.codeInvalid', OtpRules::validateCode($bad)['key'] ?? null, "should reject: $bad");
        }
    }
}
