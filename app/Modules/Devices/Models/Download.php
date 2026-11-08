<?php

namespace App\Modules\Devices\Models;

use App\Modules\Library\Models\MediaFile;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Download extends Model
{
    protected $fillable = ['uuid', 'user_id', 'profile_id', 'media_file_id', 'quality', 'audio_index', 'subtitle_ids', 'status', 'progress', 'output_path', 'size', 'position', 'expires_at'];

    protected $hidden = ['output_path'];

    protected function casts(): array
    {
        return ['subtitle_ids' => 'array', 'expires_at' => 'datetime', 'position' => 'float'];
    }

    public function file(): BelongsTo
    {
        return $this->belongsTo(MediaFile::class, 'media_file_id');
    }
}
