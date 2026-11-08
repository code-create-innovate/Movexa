<?php

namespace App\Modules\LiveTV\Models;

use App\Modules\Core\Models\Profile;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Channel extends Model
{
    protected $fillable = ['live_source_id', 'name', 'number', 'category', 'epg_id', 'stream_url', 'logo_path', 'age_rating', 'sort', 'enabled'];

    protected $hidden = ['stream_url'];

    protected function casts(): array
    {
        return ['stream_url' => 'encrypted', 'enabled' => 'boolean'];
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(LiveSource::class, 'live_source_id');
    }

    public function stream(): HasOne
    {
        return $this->hasOne(LiveStream::class);
    }

    public function scopeVisibleTo(Builder $q, Profile $profile): Builder
    {
        $q->where('enabled', true);
        if (($limit = $profile->ageLimit()) !== null) {
            // Kids: only channels explicitly rated within the limit, or the "kids" category.
            $q->where(fn ($w) => $w->where('category', 'kids')->orWhere(fn ($r) => $r->whereNotNull('age_rating')->where('age_rating', '<=', $limit)));
        }

        return $q;
    }
}
