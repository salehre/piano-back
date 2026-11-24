<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsSpa;
use Tests\TestCase;

class ClubTest extends TestCase
{
    use ActsAsSpa, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSpa();
    }

    public function test_join_stores_trimmed_lowercase_email_without_login(): void
    {
        $this->postJson('/api/club/join', ['email' => '  Sara@Example.COM '])
            ->assertCreated()
            ->assertExactJson(['alreadyMember' => false]);

        $this->assertDatabaseHas('club_members', ['email' => 'sara@example.com']);
    }

    public function test_joining_twice_does_not_duplicate(): void
    {
        $this->postJson('/api/club/join', ['email' => 'sara@example.com'])->assertCreated();
        $this->postJson('/api/club/join', ['email' => 'SARA@example.com'])
            ->assertOk()
            ->assertExactJson(['alreadyMember' => true]);

        $this->assertDatabaseCount('club_members', 1);
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
            $this->postJson('/api/club/join', ['email' => "member{$i}@example.com"])->assertCreated();
        }

        $this->postJson('/api/club/join', ['email' => 'member11@example.com'])->assertStatus(429);
    }
}