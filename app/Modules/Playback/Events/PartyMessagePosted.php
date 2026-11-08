<?php

namespace App\Modules\Playback\Events;

use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

class PartyMessagePosted implements ShouldBroadcastNow
{
    public function __construct(public string $party, public array $message) {}

    public function broadcastOn(): array
    {
        return [new PresenceChannel('party.'.$this->party)];
    }

    public function broadcastAs(): string
    {
        return 'message';
    }
}
