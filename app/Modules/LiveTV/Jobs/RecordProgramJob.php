<?php

namespace App\Modules\LiveTV\Jobs;

use App\Modules\Library\Models\Library;
use App\Modules\Library\Services\IngestPipeline;
use App\Modules\LiveTV\Models\Recording;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Symfony\Component\Process\Process;

/** DVR: records the channel for the scheduled window, then sends the file through the normal ingest pipeline. */
class RecordProgramJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public int $timeout = 6 * 3600;

    public int $tries = 1;

    public function __construct(public int $recordingId)
    {
        $this->onQueue('transcode');
    }

    public function handle(IngestPipeline $pipeline): void
    {
        $rec = Recording::with('channel')->find($this->recordingId);
        if (! $rec || $rec->status !== 'scheduled') {
            return;
        }
        $seconds = max(10, (int) now()->diffInSeconds($rec->ends_at, false));
        $dir = config('movexa.paths.inbox').'/recordings';
        @mkdir($dir, 0750, true);
        $out = $dir.'/'.$rec->id.'.ts';
        $rec->update(['status' => 'recording', 'output_path' => $out]);
        $url = $rec->channel->stream_url;
        $input = str_starts_with($url, 'file://') && config('movexa.live.allow_file_sources') ? ['-re', '-i', substr($url, 7)] : ['-rw_timeout', '15000000', '-i', $url];
        $p = new Process(array_merge([config('movexa.binaries.ffmpeg'), '-nostdin', '-hide_banner', '-loglevel', 'error', '-y'], $input, ['-t', (string) $seconds, '-map', '0:v:0?', '-map', '0:a?', '-c', 'copy', '-f', 'mpegts', $out]));
        $p->setTimeout($seconds + 120);
        $p->run();
        if (! is_file($out) || filesize($out) === 0) {
            $rec->update(['status' => 'failed', 'error' => mb_substr($p->getErrorOutput(), -250)]);

            return;
        }
        $name = preg_replace('/[^\p{L}\p{N} ._\-]/u', '', $rec->title).' ('.$rec->starts_at->format('Y').').ts';
        $job = IngestPipeline::enqueue($out, $name, Library::where('type', 'movies')->first(), 'recording', $rec->user_id);
        $job = $pipeline->process($job);
        $rec->update(['status' => $job->status === 'done' ? 'done' : 'failed', 'media_file_id' => $job->media_file_id, 'error' => $job->reason]);
    }
}
