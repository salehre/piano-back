<?php

namespace App\Models;

use DateTimeImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['user_id', 'source', 'expires_at', 'trial_used_at'])]
class Subscription extends Model
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'trial_used_at' => 'datetime',
        ];
    }

    /**
     * شکل داخلی برای PlanRules.
     *
     * @return array{source: string, expiresAt: DateTimeImmutable, trialUsed: bool}
     */
    public function toState(): array
    {
        return [
            'source' => (string) $this->source,
            'expiresAt' => DateTimeImmutable::createFromInterface($this->expires_at),
            'trialUsed' => $this->trial_used_at !== null,
        ];
    }
}