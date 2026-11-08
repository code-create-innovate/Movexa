<?php

namespace App\Modules\LiveTV\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LiveStream extends Model
{
    protected $fillable = ['channel_id', 'pid', 'output_dir', 'status', 'restarts', 'started_at'];

    protected $hidden = ['output_dir'];

    protected function casts(): array
    {
        return ['started_at' => 'datetime'];
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    public function viewers(): HasMany
    {
        return $this->hasMany(LiveViewer::class);
    }
}
