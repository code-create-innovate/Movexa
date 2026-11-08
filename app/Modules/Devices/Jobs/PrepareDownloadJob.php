<?php

namespace App\Modules\Devices\Jobs;

use App\Modules\Devices\Models\Download;
use App\Modules\Media\Services\Sandbox;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/** Builds the offline copy: single MP4, H.264/AAC, 480p or 720p, one chosen audio track — from the sanitized library file only. */
class PrepareDownloadJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public int $timeout = 6 * 3600;

    public int $tries = 1;

    public function __construct(public int $downloadId)
    {
        $this->onQueue('transcode');
    }

    public function handle(Sandbox $sandbox): void
    {
        $dl = Download::with('file')->find($this->downloadId);
        if (! $dl || $dl->status !== 'queued' || ! $dl->file?->isReady()) {
            return;
        }
        $dir = config('movexa.paths.cache').'/downloads';
        @mkdir($dir, 0755, true);
        $out = "{$dir}/{$dl->uuid}.mp4";
        $dl->update(['status' => 'processing', 'output_path' => $out]);
        $kbps = $dl->quality >= 720 ? 2500 : 1100;
        $p = $sandbox->process([
            config('movexa.binaries.ffmpeg'), '-nostdin', '-hide_banner', '-loglevel', 'error', '-progress', 'pipe:1', '-y', '-i', $dl->file->path,
            '-map', '0:V:0', '-map', '0:a:'.$dl->audio_index.'?', '-vf', 'scale=-2:\'min('.$dl->quality.',ih)\'',
            '-c:v', 'libx264', '-preset', 'veryfast', '-b:v', $kbps.'k', '-maxrate', (int) ($kbps * 1.3).'k', '-bufsize', ($kbps * 2).'k', '-pix_fmt', 'yuv420p',
            '-c:a', 'aac', '-b:a', '128k', '-ac', '2', '-sn', '-dn', '-map_metadata', '-1', '-movflags', '+faststart', $out,
        ], [$dir], null, [dirname($dl->file->path)]);
        $duration = max(1, (float) $dl->file->duration);
        $p->run(function ($type, $buf) use ($dl, $duration) {
            if ($type === 'out' && preg_match_all('/out_time_us=(\d+)/', $buf, $m)) {
                $dl->forceFill(['progress' => (int) min(99, end($m[1]) / 1e6 / $duration * 100)])->saveQuietly();
            }
        });
        $p->isSuccessful() && is_file($out)
            ? $dl->update(['status' => 'ready', 'progress' => 100, 'size' => filesize($out)])
            : $dl->update(['status' => 'failed']);
    }
}
