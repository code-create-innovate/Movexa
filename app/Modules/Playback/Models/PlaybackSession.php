<?php

namespace App\Modules\Playback\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlaybackSession extends Model
{
    protected $fillable = ['uuid', 'user_id', 'profile_id', 'media_file_id', 'channel_id', 'method', 'client', 'last_seen_at', 'counted'];

    protected function casts(): array
    {
        return ['last_seen_at' => 'datetime', 'counted' => 'boolean'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeActive($q)
    {
        return $q->where('last_seen_at', '>=', now()->subSeconds(config('movexa.limits.session_ttl')));
    }
}
