<?php

namespace App\Modules\Playback\Models;

use App\Modules\Library\Models\MediaFile;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TranscodeJob extends Model
{
    protected $fillable = ['media_file_id', 'mode', 'profile_key', 'status', 'progress', 'output_dir', 'output_size', 'encoder', 'error', 'pinned', 'last_accessed_at'];

    protected $hidden = ['output_dir'];

    protected function casts(): array
    {
        return ['pinned' => 'boolean', 'last_accessed_at' => 'datetime'];
    }

    public function file(): BelongsTo
    {
        return $this->belongsTo(MediaFile::class, 'media_file_id');
    }
}
