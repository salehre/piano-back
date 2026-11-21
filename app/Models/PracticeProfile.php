<?php

namespace App\Models;

use App\Support\PracticeRules;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class PracticeProfile extends Model
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'xp' => 'float',
            'goal_min' => 'integer',
            'reminder_on' => 'boolean',
            'badges' => 'array',
        ];
    }

    /** شکل داخلی برای ادغام (همون خروجی PracticeRules::parseMeta) */
    public function toMergeArray(): array
    {
        $counters = [];
        foreach (PracticeRules::COUNTER_KEYS as $key) {
            $counters[$key] = (int) $this->{Str::snake($key)};
        }

        $badges = [];
        foreach ($this->badges ?? [] as $id => $at) {
            $badges[(string) $id] = (string) $at;
        }

        return [
            'xp' => (float) $this->xp,
            'goalMin' => (int) $this->goal_min,
            'badges' => $badges,
            'counters' => $counters,
            'reminder' => ['on' => (bool) $this->reminder_on, 'time' => (string) $this->reminder_time],
            'reminded' => $this->reminded === null ? '' : (string) $this->reminded,
        ];
    }

    /**
     * ردیف آماده‌ی upsert. query builder کست‌های مدل رو اعمال نمی‌کنه، برای همین badges اینجا دستی JSON می‌شه
     * (JSON_FORCE_OBJECT: وقتی خالیه {} ذخیره بشه نه []).
     *
     * @param  array<string, mixed>  $meta
     */
    public static function toRow(int $userId, array $meta, DateTimeInterface $now): array
    {
        $row = [
            'user_id' => $userId,
            'xp' => $meta['xp'],
            'goal_min' => $meta['goalMin'],
            'reminder_on' => $meta['reminder']['on'] ? 1 : 0,
            'reminder_time' => $meta['reminder']['time'],
            'reminded' => $meta['reminded'] === '' ? null : $meta['reminded'],
            'badges' => json_encode($meta['badges'], JSON_FORCE_OBJECT),
            'created_at' => $now,
            'updated_at' => $now,
        ];
        foreach (PracticeRules::COUNTER_KEYS as $key) {
            $row[Str::snake($key)] = $meta['counters'][$key];
        }

        return $row;
    }

    /**
     * ستون‌هایی که موقع upsert عوض می‌شن (همه به‌جز user_id و created_at).
     *
     * @return list<string>
     */
    public static function updatableColumns(): array
    {
        return array_merge(
            ['xp', 'goal_min', 'reminder_on', 'reminder_time', 'reminded', 'badges', 'updated_at'],
            array_map(fn (string $key) => Str::snake($key), PracticeRules::COUNTER_KEYS),
        );
    }
}