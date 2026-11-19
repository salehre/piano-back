<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    private const NATIONAL_ID = '0013542419';

    protected function setUp(): void
    {
        parent::setUp();
        // درخواست‌هایی که از origin فرانت میان «stateful» حساب می‌شن (نشست + CSRF؛ CSRF توی تست‌ها چک نمی‌شه)
        config(['sanctum.stateful' => ['localhost:8087']]);
        $this->withHeaders(['Origin' => 'http://localhost:8087', 'Accept' => 'application/json']);
    }

    private function makeUser(string $phone = '09123456789', string $password = 'secret123'): User
    {
        return User::create(['phone' => $phone, 'password' => $password]);
    }

    private function profile(array $override = []): array
    {
        return array_merge([
            'displayName' => 'Sara', 'email' => '', 'avatar' => '', 'nationalId' => '',
            'country' => '', 'address' => '', 'yearsPlaying' => null,
        ], $override);
    }

    public function test_register_creates_user_and_logs_in(): void
    {
        $res = $this->postJson('/api/auth/register', ['phone' => '۰۹۱۲۳۴۵۶۷۸۹', 'password' => 'secret123']);

        $res->assertCreated()
            ->assertJsonPath('isNew', true)
            ->assertJsonPath('user.phone', '09123456789')
            ->assertJsonPath('user.profile.displayName', '')
            ->assertJsonPath('user.profile.yearsPlaying', null);
        $this->assertIsString($res->json('user.id'));
        $this->assertDatabaseHas('users', ['phone' => '09123456789']);
        $this->assertAuthenticated('web');
        $this->assertNotSame('secret123', User::first()->password);
    }

    public function test_register_rejects_taken_phone_without_touching_the_account(): void
    {
        $this->makeUser();

        $this->postJson('/api/auth/register', ['phone' => '09123456789', 'password' => 'other-pass1'])
            ->assertStatus(409)
            ->assertJsonPath('error.key', 'auth.register.phoneTaken');
        $this->assertGuest('web');
        $this->postJson('/api/auth/login', ['phone' => '09123456789', 'password' => 'secret123'])->assertOk();
    }

    public function test_register_validates_input(): void
    {
        $this->postJson('/api/auth/register', ['phone' => '123', 'password' => 'secret123'])
            ->assertStatus(422)->assertJsonPath('error.key', 'auth.validation.phoneInvalid');
        $this->postJson('/api/auth/register', ['phone' => '09123456789', 'password' => 'onlyletters'])
            ->assertStatus(422)->assertJsonPath('error.key', 'auth.validation.passwordMix');
        $this->postJson('/api/auth/register', ['phone' => ['x'], 'password' => ['y']])
            ->assertStatus(422)->assertJsonPath('error.key', 'auth.validation.phoneRequired');
        $this->assertDatabaseCount('users', 0);
    }

    public function test_login_success_and_failures(): void
    {
        $user = $this->makeUser();

        $this->postJson('/api/auth/login', ['phone' => '+989123456789', 'password' => 'secret123'])
            ->assertOk()->assertJsonPath('user.id', (string) $user->id);
        $this->assertAuthenticatedAs($user, 'web');

        $this->postJson('/api/auth/login', ['phone' => '09123456789', 'password' => 'wrong-pass1'])
            ->assertStatus(401)->assertJsonPath('error.key', 'auth.errors.incorrectPassword');
        // شماره‌ی ناموجود همون پیام رو می‌گیره
        $this->postJson('/api/auth/login', ['phone' => '09000000000', 'password' => 'secret123'])
            ->assertStatus(401)->assertJsonPath('error.key', 'auth.errors.incorrectPassword');
    }

    public function test_login_is_rate_limited(): void
    {
        $this->makeUser();
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/login', ['phone' => '09123456789', 'password' => 'wrong-pass1'])->assertStatus(401);
        }
        $this->postJson('/api/auth/login', ['phone' => '09123456789', 'password' => 'secret123'])->assertStatus(429);
    }

    public function test_requests_without_a_frontend_origin_get_a_clear_error_instead_of_a_500(): void
    {
        $this->makeUser();
        $this->withHeaders(['Origin' => 'http://evil.example'])
            ->postJson('/api/auth/login', ['phone' => '09123456789', 'password' => 'secret123'])
            ->assertStatus(400)->assertJsonPath('error.key', 'auth.errors.login');
        $this->assertGuest('web');
    }

    public function test_lookup(): void
    {
        $this->postJson('/api/auth/lookup', ['phone' => '09123456789'])->assertOk()->assertExactJson(['hasPassword' => false]);
        $this->makeUser();
        $this->postJson('/api/auth/lookup', ['phone' => '09123456789'])->assertOk()->assertExactJson(['hasPassword' => true]);
        $this->postJson('/api/auth/lookup', ['phone' => 'abc'])->assertStatus(422);
    }

    public function test_me_for_guest_and_user(): void
    {
        $this->getJson('/api/auth/me')->assertOk()->assertExactJson(['user' => null]);

        $user = $this->makeUser();
        $this->actingAs($user, 'web')->getJson('/api/auth/me')
            ->assertOk()->assertJsonPath('user.phone', '09123456789')->assertJsonMissingPath('user.password');
    }

    public function test_logout_is_idempotent(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user, 'web')->postJson('/api/auth/logout')->assertNoContent();
        $this->postJson('/api/auth/logout')->assertNoContent();
    }

    public function test_profile_update_requires_login(): void
    {
        $this->putJson('/api/auth/profile', $this->profile())->assertUnauthorized();
    }

    public function test_profile_update_saves_normalized_values_and_encrypts_national_id(): void
    {
        $user = $this->makeUser();

        $res = $this->actingAs($user, 'web')->putJson('/api/auth/profile', $this->profile([
            'displayName' => '  Sara ', 'email' => 'sara@example.com', 'nationalId' => '۰۰۱۳۵۴۲۴۱۹',
            'country' => 'ایران', 'address' => 'تهران', 'yearsPlaying' => 4,
        ]));

        $res->assertOk()
            ->assertJsonPath('user.profile.displayName', 'Sara')
            ->assertJsonPath('user.profile.nationalId', self::NATIONAL_ID)
            ->assertJsonPath('user.profile.yearsPlaying', 4);
        $raw = DB::table('users')->where('id', $user->id)->value('national_id');
        $this->assertNotSame(self::NATIONAL_ID, $raw);
        $this->assertSame(self::NATIONAL_ID, $user->fresh()->national_id);
    }

    public function test_profile_update_returns_message_keys_for_invalid_input(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user, 'web');

        $this->putJson('/api/auth/profile', $this->profile(['displayName' => 'a1']))
            ->assertStatus(422)->assertJsonPath('error.key', 'auth.validation.nameMin')->assertJsonPath('error.params.min', 2);
        $this->putJson('/api/auth/profile', $this->profile(['nationalId' => '1234567890']))
            ->assertStatus(422)->assertJsonPath('error.key', 'auth.validation.nationalIdInvalid');
        $this->putJson('/api/auth/profile', ['displayName' => 'Sara'])
            ->assertStatus(422)->assertJsonPath('error.key', 'auth.errors.saveProfile');
    }

    public function test_profile_can_be_cleared_and_empty_values_come_back_as_empty_strings(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user, 'web');
        $this->putJson('/api/auth/profile', $this->profile(['email' => 'sara@example.com', 'yearsPlaying' => 4]))->assertOk();

        $this->putJson('/api/auth/profile', $this->profile())->assertOk()
            ->assertJsonPath('user.profile.email', '')
            ->assertJsonPath('user.profile.yearsPlaying', null);
    }
}
