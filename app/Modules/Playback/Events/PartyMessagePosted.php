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

    /** Flat payload: the client reads the fields directly. */
    public function broadcastWith(): array
    {
        return $this->message;
    }

    public function broadcastAs(): string
    {
        return 'message';
    }
}
