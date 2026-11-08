<?php

namespace App\Modules\LiveTV\Models;

use Illuminate\Database\Eloquent\Model;

class EpgProgram extends Model
{
    public $timestamps = false;

    protected $fillable = ['epg_id', 'title', 'description', 'category', 'starts_at', 'ends_at'];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime'];
    }
}
