<?php

namespace App\Modules\Media\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

class TranscodeProgressed implements ShouldBroadcastNow
{
    public function __construct(public int $jobId, public int $fileId, public int $progress) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('admin.transcodes')];
    }

    public function broadcastAs(): string
    {
        return 'transcode.progress';
    }
}
