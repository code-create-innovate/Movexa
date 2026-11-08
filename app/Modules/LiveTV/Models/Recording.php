<?php

namespace App\Modules\LiveTV\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Recording extends Model
{
    protected $fillable = ['channel_id', 'user_id', 'title', 'starts_at', 'ends_at', 'status', 'output_path', 'media_file_id', 'error'];

    protected $hidden = ['output_path'];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime'];
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }
}
