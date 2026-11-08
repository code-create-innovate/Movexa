<?php

namespace App\Modules\Library\Models;

use App\Modules\Core\Models\Profile;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MediaFile extends Model
{
    protected $fillable = ['media_item_id', 'episode_id', 'library_id', 'path', 'original_name', 'sha256', 'size', 'container', 'video_codec', 'audio_codec', 'width', 'height', 'duration', 'bitrate', 'probe', 'sanitized', 'linked', 'status', 'intro_start', 'intro_end', 'thumbnails_path'];

    protected $hidden = ['path', 'probe'];

    protected function casts(): array
    {
        return ['probe' => 'array', 'sanitized' => 'boolean', 'linked' => 'boolean', 'duration' => 'float', 'intro_start' => 'float', 'intro_end' => 'float'];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(MediaItem::class, 'media_item_id');
    }

    public function episode(): BelongsTo
    {
        return $this->belongsTo(Episode::class);
    }

    public function streams(): HasMany
    {
        return $this->hasMany(MediaStream::class)->orderBy('index');
    }

    public function subtitles(): HasMany
    {
        return $this->hasMany(Subtitle::class);
    }

    public function isReady(): bool
    {
        return $this->status === 'ready';
    }

    public function viewableBy(Profile $profile): bool
    {
        return app(\App\Modules\Core\Services\AccessPolicy::class)->allowsFile($profile, $this);
    }
}
