<?php

namespace Tests\Feature;

use App\Mail\ClubConfirmMail;
use App\Models\ClubMember;
use App\Support\ClubLinks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\ActsAsSpa;
use Tests\TestCase;

class ClubTest extends TestCase
{
    use ActsAsSpa, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSpa();
        Mail::fake();
    }

    /** مسیر نسبی لینک تأیید/لغو یه عضو (بدون دامنه، چون تست مستقیم به برنامه می‌زنه) */
    private function linkPath(string $purpose, ClubMember $member, ?string $secret = null): string
    {
        return ClubLinks::url('', $purpose, $member->id, $member->email, $secret ?? (string) config('app.key'));
    }

    public function test_join_creates_a_pending_member_and_sends_a_confirmation(): void
    {
        $this->postJson('/api/club/join', ['email' => '  Sara@Example.COM '])
            ->assertStatus(202)
            ->assertExactJson(['pending' => true]);

        $this->assertDatabaseHas('club_members', ['email' => 'sara@example.com', 'confirmed_at' => null]);
        Mail::assertSent(ClubConfirmMail::class, fn (ClubConfirmMail $mail) => $mail->hasTo('sara@example.com')
            && str_contains($mail->confirmUrl, '/api/club/confirm/')
            && str_contains($mail->unsubscribeUrl, '/api/club/unsubscribe/'));
    }

    public function test_repeated_join_does_not_duplicate_or_spam(): void
    {
        $this->postJson('/api/club/join', ['email' => 'sara@example.com'])->assertStatus(202);
        $this->postJson('/api/club/join', ['email' => 'SARA@example.com'])->assertStatus(202);

        $this->assertDatabaseCount('club_members', 1);
        Mail::assertSent(ClubConfirmMail::class, 1);
    }

    public function test_confirmed_member_gets_the_same_answer_and_no_mail(): void
    {
        $member = ClubMember::create(['email' => 'sara@example.com']);
        $member->forceFill(['confirmed_at' => now()])->save();

        $this->postJson('/api/club/join', ['email' => 'sara@example.com'])
            ->assertStatus(202)->assertExactJson(['pending' => true]);

        Mail::assertNothingSent();
    }

    public function test_confirm_link_confirms_the_member(): void
    {
        $member = ClubMember::create(['email' => 'sara@example.com']);

        $this->get($this->linkPath(ClubLinks::CONFIRM, $member))->assertOk()->assertSee('Your sign-up is confirmed');

        $this->assertNotNull($member->fresh()->confirmed_at);
    }

    public function test_confirm_rejects_bad_signatures_and_other_purposes(): void
    {
        $member = ClubMember::create(['email' => 'sara@example.com']);

        $this->get($this->linkPath(ClubLinks::CONFIRM, $member, 'another-secret'))->assertStatus(400);
        // لینک لغو نباید به‌جای لینک تأیید کار کنه
        $unsubscribe = $this->linkPath(ClubLinks::UNSUBSCRIBE, $member);
        $this->get(str_replace('/unsubscribe/', '/confirm/', $unsubscribe))->assertStatus(400);

        $this->assertNull($member->fresh()->confirmed_at);
    }

    public function test_unsubscribe_link_removes_the_member(): void
    {
        $member = ClubMember::create(['email' => 'sara@example.com']);

        $this->get($this->linkPath(ClubLinks::UNSUBSCRIBE, $member))->assertOk()->assertSee('unsubscribed');

        $this->assertDatabaseCount('club_members', 0);
    }

    public function test_confirmation_mail_renders_both_links(): void
    {
        $html = (new ClubConfirmMail('https://example.com/confirm-link', 'https://example.com/unsubscribe-link'))->render();

        $this->assertStringContainsString('https://example.com/confirm-link', $html);
        $this->assertStringContainsString('https://example.com/unsubscribe-link', $html);
    }

    public function test_rejects_invalid_email(): void
    {
        foreach ([[], ['email' => ''], ['email' => 'abc'], ['email' => 'a@b'], ['email' => ['x@y.com']], ['email' => 123]] as $body) {
            $this->postJson('/api/club/join', $body)->assertStatus(422)->assertJsonPath('error.key', 'api.invalid');
        }

        $this->assertDatabaseCount('club_members', 0);
    }

    public function test_join_is_throttled_per_ip(): void
    {
        foreach (range(1, 10) as $i) {
            $this->postJson('/api/club/join', ['email' => "member{$i}@example.com"])->assertStatus(202);
        }

        $this->postJson('/api/club/join', ['email' => 'member11@example.com'])->assertStatus(429);
    }
}