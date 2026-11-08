<?php

namespace App\Modules\Playback\Models;

use Illuminate\Database\Eloquent\Model;

class WatchPartyMessage extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['watch_party_id', 'profile_id', 'body'];
}
