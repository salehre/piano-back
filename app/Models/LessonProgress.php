<?php

namespace App\Models;

use App\Support\LearningRules;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['user_id', 'lesson_id', 'stars', 'completed_at'])]
class LessonProgress extends Model
{
    protected $table = 'lesson_progress';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'stars' => 'integer',
            'completed_at' => 'datetime',
        ];
    }

    /** شکل داخلی برای ادغام (همون خروجی LearningRules::parseLesson) */
    public function toMergeArray(): array
    {
        return [
            'stars' => $this->stars === null ? null : (int) $this->stars,
            'at' => $this->completed_at->copy()->setTimezone('UTC')->format('Y-m-d H:i:s.v'),
        ];
    }

    public function toApiEntry(): array
    {
        return LearningRules::presentLesson($this->toMergeArray());
    }
}