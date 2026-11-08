<?php

namespace App\Modules\Library\Services;

use App\Modules\Library\Models\MediaFile;
use App\Modules\Media\Services\Sandbox;

/** Seek-bar previews: JPEG sprite sheets + a WebVTT index (#xywh media fragments). */
class ThumbnailGenerator
{
    public const INTERVAL = 10;

    public const W = 160;

    public const H = 90;

    public const COLS = 10;

    public function __construct(private Sandbox $sandbox) {}

    public function generate(MediaFile $file): ?string
    {
        if (! $file->duration || ! $file->video_codec) {
            return null;
        }
        $dir = config('movexa.paths.cache').'/thumbs/'.$file->id;
        @mkdir($dir, 0755, true);
        array_map('unlink', glob($dir.'/*') ?: []);
        $p = $this->sandbox->run([
            config('movexa.binaries.ffmpeg'), '-nostdin', '-hide_banner', '-loglevel', 'error', '-y', '-skip_frame', 'nokey', '-i', $file->path,
            '-vf', 'fps=1/'.self::INTERVAL.',scale='.self::W.':'.self::H.':force_original_aspect_ratio=decrease,pad='.self::W.':'.self::H.':(ow-iw)/2:(oh-ih)/2,tile='.self::COLS.'x'.self::COLS,
            '-q:v', '5', $dir.'/sprite_%03d.jpg',
        ], [$dir], 3600, [dirname($file->path)]);
        if (! $p->isSuccessful()) {
            return null;
        }
        $vtt = "WEBVTT\n\n";
        $per = self::COLS * self::COLS;
        $n = (int) ceil($file->duration / self::INTERVAL);
        for ($i = 0; $i < $n; $i++) {
            $sheet = intdiv($i, $per) + 1;
            $cell = $i % $per;
            $x = ($cell % self::COLS) * self::W;
            $y = intdiv($cell, self::COLS) * self::H;
            $start = $i * self::INTERVAL;
            $end = min($file->duration, $start + self::INTERVAL);
            $vtt .= sprintf("%s --> %s\nsprite_%03d.jpg#xywh=%d,%d,%d,%d\n\n", $this->ts($start), $this->ts($end), $sheet, $x, $y, self::W, self::H);
        }
        file_put_contents($dir.'/thumbnails.vtt', $vtt);
        $file->update(['thumbnails_path' => $dir]);

        return $dir;
    }

    private function ts(float $s): string
    {
        return sprintf('%02d:%02d:%06.3f', intdiv((int) $s, 3600), intdiv((int) $s % 3600, 60), fmod($s, 60));
    }
}
