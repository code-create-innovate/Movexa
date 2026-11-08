<?php

namespace App\Modules\Library\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Episode extends Model
{
    protected $fillable = ['season_id', 'media_item_id', 'number', 'title', 'title_ar', 'overview', 'overview_ar', 'still_path'];

    public function season(): BelongsTo
    {
        return $this->belongsTo(Season::class);
    }

    public function show(): BelongsTo
    {
        return $this->belongsTo(MediaItem::class, 'media_item_id');
    }

    public function file(): HasOne
    {
        return $this->hasOne(MediaFile::class)->where('status', 'ready');
    }
}
