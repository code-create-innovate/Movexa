<?php

namespace App\Modules\Library\Models;

use Illuminate\Database\Eloquent\Model;

class MediaStream extends Model
{
    protected $fillable = ['media_file_id', 'index', 'type', 'codec', 'language', 'title', 'channels', 'is_default', 'is_text'];

    protected function casts(): array
    {
        return ['is_default' => 'boolean', 'is_text' => 'boolean'];
    }
}
