<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Concerns\ActsAsSpa;
use Tests\TestCase;

class ContactTest extends TestCase
{
    use ActsAsSpa, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSpa();
    }

    private function payload(array $override = []): array
    {
        return array_merge([
            'name' => 'Sara',
            'email' => 'Sara@Example.com',
            'subject' => 'Hello',
            'message' => 'I would like to ask about the premium plan.',
        ], $override);
    }

    public function test_stores_message_without_login(): void
    {
        $this->postJson('/api/contact', $this->payload(['name' => '  Sara  ']))->assertNoContent();

        $this->assertDatabaseHas('contact_messages', [
            'name' => 'Sara',
            'email' => 'sara@example.com',
            'subject' => 'Hello',
            'message' => 'I would like to ask about the premium plan.',
        ]);
    }

    public function test_rejects_invalid_fields(): void
    {
        $bad = [
            ['name' => 'A'],
            ['name' => ''],
            ['email' => 'nope'],
            ['email' => ''],
            ['subject' => 'ab'],
            ['subject' => str_repeat('x', 101)],
            ['message' => 'too short'],
            ['message' => str_repeat('x', 1001)],
            ['name' => ['Sara']],
            ['message' => 12345678901],
        ];

        foreach ($bad as $override) {
            Cache::flush();
            $this->postJson('/api/contact', $this->payload($override))
                ->assertStatus(422)
                ->assertJsonPath('error.key', 'api.invalid');
        }

        $this->assertDatabaseCount('contact_messages', 0);
    }

    public function test_accepts_boundary_lengths(): void
    {
        $this->postJson('/api/contact', $this->payload([
            'subject' => str_repeat('x', 100),
            'message' => str_repeat('y', 1000),
        ]))->assertNoContent();
    }

    public function test_is_throttled_per_ip(): void
    {
        foreach (range(1, 5) as $i) {
            $this->postJson('/api/contact', $this->payload())->assertNoContent();
        }

        $this->postJson('/api/contact', $this->payload())->assertStatus(429);
    }
}