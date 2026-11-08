<?php

namespace App\Modules\LiveTV\Models;

use Illuminate\Database\Eloquent\Model;

class LiveViewer extends Model
{
    public $timestamps = false;

    protected $fillable = ['live_stream_id', 'viewer_key', 'last_seen_at'];

    protected function casts(): array
    {
        return ['last_seen_at' => 'datetime'];
    }
}
