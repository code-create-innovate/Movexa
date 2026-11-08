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
        return app(\App\Modules\Core\Services\AccessPolicy::class)->scopeChannels($q, $profile);
    }
}
