<?php

namespace App\Modules\Core\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class AccessBlock extends Model
{
    protected $fillable = ['target_type', 'target_id', 'target_value', 'subject_type', 'subject_id', 'expires_at', 'created_by'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime'];
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where(fn ($w) => $w->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    /** Blocks applying to a profile: on the profile itself, or on its whole account. */
    public function scopeForProfile(Builder $q, Profile $p): Builder
    {
        return $q->where(fn ($w) => $w->where(fn ($a) => $a->where('subject_type', 'profile')->where('subject_id', $p->id))
            ->orWhere(fn ($a) => $a->where('subject_type', 'user')->where('subject_id', $p->user_id)));
    }
}
