<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Sms\SmsSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    /** آخرین کدی که «فرستاده شد» */
    private object $sms;

    protected function setUp(): void
    {
        parent::setUp();
        config(['sanctum.stateful' => ['localhost:8087']]);
        $this->withHeaders(['Origin' => 'http://localhost:8087', 'Accept' => 'application/json']);

        $this->sms = new class implements SmsSender
        {
            /** @var array<int, array{0: string, 1: string}> */
            public array $sent = [];

            public function sendCode(string $phone, string $code): void
            {
                $this->sent[] = [$phone, $code];
            }
        };
        $this->app->instance(SmsSender::class, $this->sms);
    }

    private function makeUser(string $phone = '09123456789'): User
    {
        return User::create(['phone' => $phone, 'password' => 'oldpass123']);
    }

    private function lastCode(): string
    {
        return $this->sms->sent[array_key_last($this->sms->sent)][1];
    }

    public function test_forgot_sends_code_to_existing_phone(): void
    {
        $this->makeUser();

        $this->postJson('/api/auth/password/forgot', ['phone' => '۰۹۱۲۳۴۵۶۷۸۹'])->assertNoContent();

        $this->assertCount(1, $this->sms->sent);
        $this->assertSame('09123456789', $this->sms->sent[0][0]);
        $this->assertMatchesRegularExpression('/^\d{6}$/D', $this->sms->sent[0][1]);
    }

    public function test_forgot_answers_the_same_for_unknown_phone_but_sends_nothing(): void
    {
        $this->postJson('/api/auth/password/forgot', ['phone' => '09120000000'])->assertNoContent();

        $this->assertCount(0, $this->sms->sent);
    }

    public function test_forgot_validates_phone(): void
    {
        $this->postJson('/api/auth/password/forgot', ['phone' => '123'])
            ->assertStatus(422)->assertJsonPath('error.key', 'auth.validation.phoneInvalid');
    }

    public function test_forgot_has_a_cooldown_per_phone(): void
    {
        $this->makeUser();

        $this->postJson('/api/auth/password/forgot', ['phone' => '09123456789'])->assertNoContent();
        $this->postJson('/api/auth/password/forgot', ['phone' => '09123456789'])
            ->assertStatus(429)->assertJsonPath('error.key', 'auth.errors.tooManyRequests');

        $this->assertCount(1, $this->sms->sent);
    }

    public function test_reset_sets_password_verifies_phone_and_logs_in(): void
    {
        $user = $this->makeUser();
        $this->postJson('/api/auth/password/forgot', ['phone' => '09123456789'])->assertNoContent();

        $this->postJson('/api/auth/password/reset', [
            'phone' => '09123456789', 'code' => $this->lastCode(), 'password' => 'newpass456',
        ])->assertOk()->assertJsonPath('user.phone', '09123456789');

        $user->refresh();
        $this->assertTrue(Hash::check('newpass456', $user->password));
        $this->assertNotNull($user->phone_verified_at);
        $this->assertAuthenticatedAs($user, 'web');
    }

    public function test_code_is_single_use(): void
    {
        $this->makeUser();
        $this->postJson('/api/auth/password/forgot', ['phone' => '09123456789'])->assertNoContent();
        $code = $this->lastCode();
        $body = ['phone' => '09123456789', 'code' => $code, 'password' => 'newpass456'];

        $this->postJson('/api/auth/password/reset', $body)->assertOk();
        $this->postJson('/api/auth/password/reset', $body)
            ->assertStatus(422)->assertJsonPath('error.key', 'auth.errors.codeExpired');
    }

    public function test_wrong_code_is_rejected_and_password_unchanged(): void
    {
        $user = $this->makeUser();
        $this->postJson('/api/auth/password/forgot', ['phone' => '09123456789'])->assertNoContent();
        $wrong = $this->lastCode() === '000000' ? '111111' : '000000';

        $this->postJson('/api/auth/password/reset', ['phone' => '09123456789', 'code' => $wrong, 'password' => 'newpass456'])
            ->assertStatus(422)->assertJsonPath('error.key', 'auth.errors.wrongCode');

        $this->assertTrue(Hash::check('oldpass123', $user->fresh()->password));
        $this->assertNull($user->fresh()->phone_verified_at);
    }

    public function test_code_is_voided_after_too_many_wrong_attempts(): void
    {
        $this->makeUser();
        $this->postJson('/api/auth/password/forgot', ['phone' => '09123456789'])->assertNoContent();
        $right = $this->lastCode();
        $wrong = $right === '000000' ? '111111' : '000000';

        for ($i = 0; $i < 4; $i++) {
            $this->postJson('/api/auth/password/reset', ['phone' => '09123456789', 'code' => $wrong, 'password' => 'newpass456'])
                ->assertStatus(422)->assertJsonPath('error.key', 'auth.errors.wrongCode');
        }
        // حدس پنجم کد رو باطل می‌کنه؛ بعدش کد درست هم کار نمی‌کنه
        $this->postJson('/api/auth/password/reset', ['phone' => '09123456789', 'code' => $wrong, 'password' => 'newpass456'])
            ->assertStatus(422)->assertJsonPath('error.key', 'auth.errors.codeExpired');
        $this->postJson('/api/auth/password/reset', ['phone' => '09123456789', 'code' => $right, 'password' => 'newpass456'])
            ->assertStatus(422)->assertJsonPath('error.key', 'auth.errors.codeExpired');
    }

    public function test_weak_password_or_bad_code_format_does_not_burn_the_code(): void
    {
        $this->makeUser();
        $this->postJson('/api/auth/password/forgot', ['phone' => '09123456789'])->assertNoContent();
        $code = $this->lastCode();

        $this->postJson('/api/auth/password/reset', ['phone' => '09123456789', 'code' => $code, 'password' => 'short'])
            ->assertStatus(422);
        $this->postJson('/api/auth/password/reset', ['phone' => '09123456789', 'code' => '12ab', 'password' => 'newpass456'])
            ->assertStatus(422)->assertJsonPath('error.key', 'auth.validation.codeRequired');

        $this->postJson('/api/auth/password/reset', ['phone' => '09123456789', 'code' => $code, 'password' => 'newpass456'])
            ->assertOk();
    }

    public function test_code_for_one_phone_does_not_work_for_another(): void
    {
        $this->makeUser('09123456789');
        $victim = $this->makeUser('09129999999');
        $this->postJson('/api/auth/password/forgot', ['phone' => '09123456789'])->assertNoContent();

        $this->postJson('/api/auth/password/reset', ['phone' => '09129999999', 'code' => $this->lastCode(), 'password' => 'newpass456'])
            ->assertStatus(422)->assertJsonPath('error.key', 'auth.errors.codeExpired');

        $this->assertTrue(Hash::check('oldpass123', $victim->fresh()->password));
    }
}
