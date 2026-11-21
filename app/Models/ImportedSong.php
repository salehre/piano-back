<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['user_id', 'song_id', 'title', 'bpm', 'beats_per_bar', 'level', 'source', 'notes'])]
class ImportedSong extends Model
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'bpm' => 'float',
            'beats_per_bar' => 'integer',
            'level' => 'integer',
            'notes' => 'array',
        ];
    }

    /** شکلی که فرانت به‌عنوان StoredSong انتظار داره */
    public function toApiPayload(): array
    {
        $song = [
            'id' => $this->song_id,
            'title' => $this->title,
            'bpm' => $this->bpm,
            'beatsPerBar' => $this->beats_per_bar,
            'level' => $this->level,
        ];
        if ($this->source !== null) {
            $song['source'] = $this->source;
        }
        $song['notes'] = $this->notes;

        return $song;
    }
}
