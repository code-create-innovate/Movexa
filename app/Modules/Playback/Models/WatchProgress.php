<?php

namespace App\Modules\Playback\Models;

use App\Modules\Library\Models\Episode;
use App\Modules\Library\Models\MediaFile;
use App\Modules\Library\Models\MediaItem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WatchProgress extends Model
{
    protected $table = 'watch_progress';

    protected $fillable = ['profile_id', 'media_file_id', 'media_item_id', 'episode_id', 'position', 'duration', 'completed'];

    protected function casts(): array
    {
        return ['position' => 'float', 'duration' => 'float', 'completed' => 'boolean'];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(MediaItem::class, 'media_item_id');
    }

    public function file(): BelongsTo
    {
        return $this->belongsTo(MediaFile::class, 'media_file_id');
    }

    public function episode(): BelongsTo
    {
        return $this->belongsTo(Episode::class);
    }
}
