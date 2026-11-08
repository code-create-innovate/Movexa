<?php

namespace App\Modules\Core\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/** Tells players of the affected profiles to stop: they re-check access and quit if blocked. */
class AccessRevoked implements ShouldBroadcastNow
{
    /** @param list<int> $profileIds */
    public function __construct(public array $profileIds) {}

    public function broadcastOn(): array
    {
        return array_map(fn ($id) => new PrivateChannel('profile.'.$id), $this->profileIds);
    }

    public function broadcastAs(): string
    {
        return 'access.revoked';
    }
}
