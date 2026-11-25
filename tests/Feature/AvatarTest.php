<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\ActsAsSpa;
use Tests\TestCase;

class AvatarTest extends TestCase
{
    use ActsAsSpa, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSpa();
        Storage::fake('local');
        config(['app.url' => 'http://localhost:9000']);
    }

    private function jpegBytes(string $tail = 'x'): string
    {
        return "\xFF\xD8\xFF\xE0".$tail;
    }

    private function dataUrl(string $tail = 'x'): string
    {
        return 'data:image/jpeg;base64,'.base64_encode($this->jpegBytes($tail));
    }

    private function profile(array $override = []): array
    {
        return array_merge([
            'displayName' => 'Sara', 'email' => '', 'avatar' => '', 'nationalId' => '',
            'country' => '', 'address' => '', 'yearsPlaying' => null,
        ], $override);
    }

    public function test_upload_stores_a_private_file_and_returns_a_link(): void
    {
        $user = $this->login();

        $res = $this->putJson('/api/auth/profile', $this->profile(['avatar' => $this->dataUrl()]))->assertOk();

        $user->refresh();
        $this->assertNotNull($user->avatar_path);
        Storage::disk('local')->assertExists($user->avatar_path);
        $this->assertStringStartsWith('http://localhost:9000/api/profile/avatar?v=', $res->json('user.profile.avatar'));
    }

    public function test_avatar_endpoint_serves_the_owner_file(): void
    {
        $this->getJson('/api/profile/avatar')->assertUnauthorized();

        $this->login();
        $this->getJson('/api/profile/avatar')->assertNotFound();

        $this->putJson('/api/auth/profile', $this->profile(['avatar' => $this->dataUrl('abc')]))->assertOk();
        $res = $this->get('/api/profile/avatar')->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->assertSame($this->jpegBytes('abc'), $res->streamedContent());
    }

    public function test_sending_the_same_link_back_keeps_the_avatar(): void
    {
        $user = $this->login();
        $link = $this->putJson('/api/auth/profile', $this->profile(['avatar' => $this->dataUrl()]))->json('user.profile.avatar');
        $path = $user->fresh()->avatar_path;

        $this->putJson('/api/auth/profile', $this->profile(['displayName' => 'Mina', 'avatar' => $link]))
            ->assertOk()->assertJsonPath('user.profile.avatar', $link);

        $this->assertSame($path, $user->fresh()->avatar_path);
        Storage::disk('local')->assertExists($path);
    }

    public function test_replacing_removes_the_old_file(): void
    {
        $user = $this->login();
        $this->putJson('/api/auth/profile', $this->profile(['avatar' => $this->dataUrl('one')]))->assertOk();
        $old = $user->fresh()->avatar_path;

        $this->putJson('/api/auth/profile', $this->profile(['avatar' => $this->dataUrl('two')]))->assertOk();

        $new = $user->fresh()->avatar_path;
        $this->assertNotSame($old, $new);
        Storage::disk('local')->assertMissing($old);
        Storage::disk('local')->assertExists($new);
    }

    public function test_empty_avatar_removes_it(): void
    {
        $user = $this->login();
        $this->putJson('/api/auth/profile', $this->profile(['avatar' => $this->dataUrl()]))->assertOk();
        $path = $user->fresh()->avatar_path;

        $this->putJson('/api/auth/profile', $this->profile())->assertOk()->assertJsonPath('user.profile.avatar', '');

        $this->assertNull($user->fresh()->avatar_path);
        Storage::disk('local')->assertMissing($path);
    }

    public function test_rejects_foreign_links_and_non_jpeg_data(): void
    {
        $user = $this->login();

        foreach (['https://example.com/a.jpg', 'data:image/jpeg;base64,'.base64_encode('not a jpeg')] as $bad) {
            $this->putJson('/api/auth/profile', $this->profile(['avatar' => $bad]))
                ->assertStatus(422)->assertJsonPath('error.key', 'auth.profile.avatar.unreadable');
        }

        $this->assertNull($user->fresh()->avatar_path);
    }
}