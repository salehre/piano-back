<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['user_id', 'mode', 'rounds', 'answered', 'correct', 'best_round'])]
class EarStat extends Model
{
    protected $table = 'ear_stats';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'rounds' => 'integer',
            'answered' => 'integer',
            'correct' => 'integer',
            'best_round' => 'integer',
        ];
    }

    /** شکل داخلی (همون EarStat فرانت) */
    public function toStatArray(): array
    {
        return [
            'rounds' => (int) $this->rounds,
            'answered' => (int) $this->answered,
            'correct' => (int) $this->correct,
            'bestRound' => (int) $this->best_round,
        ];
    }
}