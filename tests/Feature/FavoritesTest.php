<?php

namespace Tests\Feature;

use App\Models\FavoriteSong;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsSpa;
use Tests\TestCase;

class FavoritesTest extends TestCase
{
    use ActsAsSpa, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSpa();
    }

    public function test_requires_login(): void
    {
        $this->getJson('/api/favorites')->assertUnauthorized();
        $this->patchJson('/api/favorites', ['add' => ['ode']])->assertUnauthorized();
    }

    public function test_add_and_remove_keep_insertion_order(): void
    {
        $this->login();
        $this->getJson('/api/favorites')->assertOk()->assertExactJson(['ids' => []]);

        $this->patchJson('/api/favorites', ['add' => ['ode', 'twinkle', 'mary']])->assertOk()
            ->assertExactJson(['ids' => ['ode', 'twinkle', 'mary']]);

        // تکراری نادیده گرفته می‌شه، حذف و اضافه همزمان کار می‌کنه
        $this->patchJson('/api/favorites', ['add' => ['twinkle', 'midi-abc'], 'remove' => ['ode']])->assertOk()
            ->assertExactJson(['ids' => ['twinkle', 'mary', 'midi-abc']]);

        // حذف چیزی که نیست خطا نیست
        $this->patchJson('/api/favorites', ['remove' => ['nope']])->assertOk()->assertJsonCount(3, 'ids');
        $this->assertDatabaseCount('favorite_songs', 3);
    }

    public function test_removing_only_what_this_device_removed_keeps_what_other_devices_added(): void
    {
        $this->login();
        $this->patchJson('/api/favorites', ['add' => ['ode']])->assertOk();
        // دستگاه دوم چیزی اضافه می‌کنه که دستگاه اول خبر نداره؛ دستگاه اول فقط چیزی که خودش حذف کرده رو می‌فرسته
        $this->patchJson('/api/favorites', ['add' => ['mary']])->assertOk();
        $this->patchJson('/api/favorites', ['remove' => ['ode']])->assertOk()->assertExactJson(['ids' => ['mary']]);
    }

    public function test_rejects_invalid_ids_and_shapes(): void
    {
        $this->login();
        foreach ([['add' => 'ode'], ['add' => [1]], ['add' => ['has space']], ['remove' => [['x']]], ['add' => ['']]] as $body) {
            $this->patchJson('/api/favorites', $body)->assertStatus(422)->assertJsonPath('error.key', 'api.invalid');
        }
        $this->patchJson('/api/favorites', ['add' => array_map(fn ($i) => "s$i", range(1, 501))])->assertStatus(422);
        $this->assertDatabaseCount('favorite_songs', 0);
    }

    public function test_limit_is_enforced_without_partial_writes(): void
    {
        $user = $this->login();
        $now = now();
        FavoriteSong::insert(array_map(
            fn ($i) => ['user_id' => $user->id, 'song_id' => "song$i", 'created_at' => $now, 'updated_at' => $now],
            range(1, 1000),
        ));

        $this->patchJson('/api/favorites', ['add' => ['one-too-many'], 'remove' => ['song1']])->assertOk(); // جا باز می‌شه
        $this->patchJson('/api/favorites', ['add' => ['a-1', 'a-2']])
            ->assertStatus(422)->assertJsonPath('error.key', 'api.limitReached');
        $this->assertDatabaseCount('favorite_songs', 1000);
        $this->assertDatabaseMissing('favorite_songs', ['song_id' => 'a-1']);
    }

    public function test_users_are_isolated(): void
    {
        $a = $this->makeUser('09120000001');
        $b = $this->makeUser('09120000002');
        $this->actAs($a)->patchJson('/api/favorites', ['add' => ['ode']])->assertOk();
        $this->actAs($b)->patchJson('/api/favorites', ['add' => ['ode', 'mary']])->assertOk()->assertJsonCount(2, 'ids');
        $this->actAs($b)->patchJson('/api/favorites', ['remove' => ['ode']])->assertOk();
        $this->actAs($a)->getJson('/api/favorites')->assertExactJson(['ids' => ['ode']]);
    }
}
