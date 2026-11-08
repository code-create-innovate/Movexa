<?php

namespace App\Modules\Library\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class IngestJob extends Model
{
    protected $fillable = ['library_id', 'user_id', 'source', 'original_name', 'inbox_path', 'quarantine_path', 'detected_mime', 'status', 'reason', 'threat', 'sanitized', 'link_only', 'media_file_id', 'purge_after'];

    protected $hidden = ['inbox_path', 'quarantine_path'];

    protected function casts(): array
    {
        return ['sanitized' => 'boolean', 'link_only' => 'boolean', 'purge_after' => 'datetime'];
    }

    public function library(): BelongsTo
    {
        return $this->belongsTo(Library::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(SecurityEvent::class);
    }
}
