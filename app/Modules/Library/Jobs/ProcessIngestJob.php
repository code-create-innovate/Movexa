<?php

namespace App\Modules\Library\Jobs;

use App\Modules\Library\Models\IngestJob;
use App\Modules\Library\Services\IngestPipeline;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

class ProcessIngestJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 21600;

    public int $tries = 1;

    public function __construct(public int $ingestJobId)
    {
        $this->onQueue('scan');
    }

    public function handle(IngestPipeline $pipeline): void
    {
        $job = IngestJob::find($this->ingestJobId);
        if ($job && $job->status === 'queued') {
            $pipeline->process($job);
        }
    }
}
