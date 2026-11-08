<?php

namespace App\Modules\Media\Jobs;

use App\Modules\Library\Models\MediaItem;
use App\Modules\Media\Services\Sandbox;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/** Silent 20 s teaser for the home banner, cut locally with ffmpeg (no internet). */
class GeneratePreviewJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public int $timeout = 1800;

    public function __construct(public int $itemId)
    {
        $this->onQueue('transcode');
    }

    public function handle(Sandbox $sandbox): void
    {
        $item = MediaItem::with(['files' => fn ($q) => $q->where('status', 'ready')])->find($this->itemId);
        $file = $item?->files->first();
        if (! $file || ! $file->video_codec) {
            return;
        }
        $rel = "items/{$item->id}/preview.mp4";
        $out = config('movexa.paths.images').'/'.$rel;
        @mkdir(dirname($out), 0755, true);
        $start = $file->duration ? max(0, $file->duration * 0.12) : 0;
        $len = $file->duration ? min(20, $file->duration) : 20;
        $p = $sandbox->run([
            config('movexa.binaries.ffmpeg'), '-nostdin', '-hide_banner', '-loglevel', 'error', '-y', '-ss', (string) $start, '-i', $file->path, '-t', (string) $len,
            '-an', '-sn', '-dn', '-map_metadata', '-1', '-vf', "scale=-2:'min(540,ih)'", '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '26', '-pix_fmt', 'yuv420p', '-movflags', '+faststart', $out,
        ], [dirname($out)], 1800, [dirname($file->path)]);
        if ($p->isSuccessful() && is_file($out)) {
            $item->update(['preview_path' => $rel]);
        }
    }
}
