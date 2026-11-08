<?php

namespace App\Modules\Playback\Events;

use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

class PartyStateChanged implements ShouldBroadcastNow
{
    public function __construct(public string $party, public array $state) {}

    public function broadcastOn(): array
    {
        return [new PresenceChannel('party.'.$this->party)];
    }

    public function broadcastAs(): string
    {
        return 'state';
    }
}
