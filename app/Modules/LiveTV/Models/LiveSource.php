<?php

namespace App\Modules\LiveTV\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LiveSource extends Model
{
    protected $fillable = ['name', 'type', 'url', 'epg_url', 'max_streams', 'legal_ack'];

    protected $hidden = ['url', 'epg_url'];

    protected function casts(): array
    {
        return ['url' => 'encrypted', 'epg_url' => 'encrypted', 'legal_ack' => 'boolean', 'refreshed_at' => 'datetime'];
    }

    public function channels(): HasMany
    {
        return $this->hasMany(Channel::class);
    }
}
