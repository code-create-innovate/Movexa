<?php

namespace App\Modules\Library\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Collection extends Model
{
    protected $fillable = ['name'];

    public function items(): BelongsToMany
    {
        return $this->belongsToMany(MediaItem::class);
    }
}
