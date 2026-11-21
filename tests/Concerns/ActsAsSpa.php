<?php

namespace Tests\Concerns;

use App\Models\User;

/** درخواست‌ها از origin فرانت میان (stateful: نشست + CSRF؛ CSRF توی تست‌ها چک نمی‌شه) */
trait ActsAsSpa
{
    protected function setUpSpa(): void
    {
        config(['sanctum.stateful' => ['localhost:8087']]);
        $this->withHeaders(['Origin' => 'http://localhost:8087', 'Accept' => 'application/json']);
    }

    protected function makeUser(string $phone = '09123456789'): User
    {
        return User::create(['phone' => $phone, 'password' => 'secret123']);
    }

    /** وارد‌شده به‌عنوان این کاربر؛ گاردهای کش‌شده رو فراموش می‌کنه تا با عوض‌کردن کاربر وسط تست، کاربر قبلی روی گارد sanctum باقی نمونه */
    protected function actAs(User $user): static
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($user, 'web');
    }

    protected function login(?User $user = null): User
    {
        $user ??= $this->makeUser();
        $this->actAs($user);

        return $user;
    }
}
