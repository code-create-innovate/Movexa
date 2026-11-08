<?php

namespace App\Modules\Media\Jobs;

use App\Modules\Media\Events\TranscodeProgressed;
use App\Modules\Media\Services\HlsPackager;
use App\Modules\Playback\Models\TranscodeJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

class TranscodeMediaJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 6 * 3600;

    public int $tries = 1;

    public function __construct(public int $transcodeJobId)
    {
        $this->onQueue('transcode');
    }

    public function handle(HlsPackager $packager): void
    {
        $job = TranscodeJob::find($this->transcodeJobId);
        if (! $job || $job->status === 'done') {
            return;
        }
        $running = TranscodeJob::where('status', 'running')->whereKeyNot($job->id)->count();
        if ($running >= config('movexa.transcode.max_concurrent')) {
            $this->release(15);

            return;
        }
        $packager->run($job, fn (int $pct) => rescue(fn () => broadcast(new TranscodeProgressed($job->id, $job->media_file_id, $pct)), report: false));
    }
}
