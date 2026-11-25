<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable([
    'phone', 'password',
    'display_name', 'email', 'national_id', 'country', 'address', 'years_playing',
])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            // کد ملی اطلاعات حساسه؛ رمزنگاری‌شده ذخیره می‌شه (با APP_KEY)
            'national_id' => 'encrypted',
            'years_playing' => 'integer',
        ];
    }

    /** شکلی که فرانت به‌عنوان AuthUser انتظار داره (بدون هیچ فیلد حساسی مثل رمز) */
    public function toAuthPayload(): array
    {
        return [
            'id' => (string) $this->id,
            'phone' => (string) $this->phone,
            'createdAt' => $this->created_at?->toIso8601ZuluString(),
            'profile' => [
                'displayName' => (string) $this->display_name,
                'email' => (string) $this->email,
                'avatar' => $this->avatarUrl(),
                'nationalId' => (string) $this->national_id,
                'country' => (string) $this->country,
                'address' => (string) $this->address,
                'yearsPlaying' => $this->years_playing,
            ],
        ];
    }

    /** آدرس endpoint عکس پروفایل؛ باید همون آدرسی باشه که مرورگر به بک‌اند می‌زنه (APP_URL) */
    public static function avatarEndpoint(): string
    {
        return rtrim((string) config('app.url'), '/').'/api/profile/avatar';
    }

    /** لینک عکس پروفایل (با نسخه برای کش مرورگر) یا رشته‌ی خالی */
    public function avatarUrl(): string
    {
        if ($this->avatar_path === null || $this->avatar_path === '') {
            return '';
        }

        return self::avatarEndpoint().'?v='.pathinfo($this->avatar_path, PATHINFO_FILENAME);
    }
}
