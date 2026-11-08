<?php

namespace App\Modules\Playback\Models;

use App\Modules\Library\Models\MediaFile;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WatchParty extends Model
{
    protected $fillable = ['uuid', 'host_profile_id', 'media_file_id', 'position', 'playing', 'state_at'];

    protected function casts(): array
    {
        return ['playing' => 'boolean', 'position' => 'float', 'state_at' => 'datetime'];
    }

    public function file(): BelongsTo
    {
        return $this->belongsTo(MediaFile::class, 'media_file_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(WatchPartyMessage::class);
    }

    /** Current position, extrapolated while playing. */
    public function currentPosition(): float
    {
        return $this->playing && $this->state_at ? $this->position + $this->state_at->diffInRealMilliseconds(now()) / 1000 : $this->position;
    }
}
