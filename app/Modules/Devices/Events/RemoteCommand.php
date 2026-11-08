<?php

namespace App\Modules\Devices\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

class RemoteCommand implements ShouldBroadcastNow
{
    public function __construct(public string $device, public array $command) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('remote.'.$this->device)];
    }

    /** Flat payload: the client reads the fields directly. */
    public function broadcastWith(): array
    {
        return $this->command;
    }

    public function broadcastAs(): string
    {
        return 'command';
    }
}
