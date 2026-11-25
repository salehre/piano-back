<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['email'])]
class ClubMember extends Model
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['confirmed_at' => 'datetime'];
    }
}