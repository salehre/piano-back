<?php

namespace App\Models;

use App\Support\ScoreRules;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['user_id', 'score_key', 'stars', 'accuracy', 'plays', 'played_at'])]
class SongScore extends Model
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'stars' => 'integer',
            'accuracy' => 'float',
            'plays' => 'integer',
            'played_at' => 'datetime',
        ];
    }

    /** شکل داخلی برای ادغام (همون خروجی ScoreRules::parseEntry) */
    public function toMergeArray(): array
    {
        return [
            'stars' => (int) $this->stars,
            'accuracy' => (float) $this->accuracy,
            'plays' => (int) $this->plays,
            'at' => $this->played_at->copy()->setTimezone('UTC')->format('Y-m-d H:i:s.v'),
        ];
    }

    /** شکلی که فرانت به‌عنوان ScoreEntry انتظار داره */
    public function toApiEntry(): array
    {
        return ScoreRules::present($this->toMergeArray());
    }
}
