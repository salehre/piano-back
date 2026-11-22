<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['user_id', 'rec_id', 'name', 'duration_ms', 'note_count', 'events', 'audio_path'])]
class Recording extends Model
{
    /** ستون‌های سبک برای فهرست (events حجیمه و فقط موقع باز کردن ضبط خونده می‌شه) */
    public const META_COLUMNS = ['id', 'user_id', 'rec_id', 'name', 'duration_ms', 'note_count', 'audio_path', 'created_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['events' => 'array'];
    }

    /** شکل RecordingMeta فرانت؛ createdAt میلی‌ثانیه‌ست (مثل Date.now()) */
    public function toApiPayload(): array
    {
        return [
            'id' => $this->rec_id,
            'name' => $this->name,
            'createdAt' => (int) $this->created_at->format('Uv'),
            'durationMs' => (int) $this->duration_ms,
            'noteCount' => (int) $this->note_count,
            'hasAudio' => $this->audio_path !== null,
        ];
    }
}
