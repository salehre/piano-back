<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['user_id', 'day', 'ms', 'xp'])]
class PracticeDay extends Model
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'ms' => 'integer',
            'xp' => 'float',
        ];
    }

    /** شکل داخلی برای ادغام (همون خروجی PracticeRules::parseDay) */
    public function toMergeArray(): array
    {
        return ['ms' => (int) $this->ms, 'xp' => (float) $this->xp];
    }
}