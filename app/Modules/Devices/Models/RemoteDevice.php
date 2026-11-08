<?php

namespace App\Modules\Devices\Models;

use Illuminate\Database\Eloquent\Model;

class RemoteDevice extends Model
{
    protected $fillable = ['uuid', 'user_id', 'profile_id', 'name', 'state', 'state_at', 'last_seq'];

    protected function casts(): array
    {
        return ['state' => 'array', 'state_at' => 'datetime'];
    }

    public function online(): bool
    {
        return $this->state_at && $this->state_at->gt(now()->subSeconds(8)); // players report every 2 s
    }
}
