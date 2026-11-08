<?php

namespace App\Modules\Library\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Library extends Model
{
    protected $fillable = ['name', 'type', 'path', 'source', 'watch'];

    protected function casts(): array
    {
        return ['watch' => 'boolean', 'last_scanned_at' => 'datetime'];
    }

    public function items(): HasMany
    {
        return $this->hasMany(MediaItem::class);
    }
}
