<?php

namespace App\Modules\Media\Services;

use App\Modules\Library\Models\MediaFile;
use App\Modules\Library\Models\MediaItem;

/** Dominant color of artwork, small poster thumbnails, and the per-title "color timeline" used by the ambient glow. */
class ColorService
{
    public const TIMELINE_STEP = 4; // seconds between samples (spec: 2–5 s)

    public function __construct(private Sandbox $sandbox) {}

    /** Average color, nudged towards saturation so the page tint is not muddy grey. */
    public function dominant(string $imagePath): ?string
    {
        $data = @file_get_contents($imagePath);
        $img = $data ? @imagecreatefromstring($data) : false;
        if (! $img) {
            return null;
        }
        $small = imagecreatetruecolor(16, 16);
        imagecopyresampled($small, $img, 0, 0, 0, 0, 16, 16, imagesx($img), imagesy($img));
        $best = null;
        $bestScore = -1;
        $sum = [0, 0, 0];
        for ($x = 0; $x < 16; $x++) {
            for ($y = 0; $y < 16; $y++) {
                $c = imagecolorat($small, $x, $y);
                $rgb = [($c >> 16) & 255, ($c >> 8) & 255, $c & 255];
                $sum = [$sum[0] + $rgb[0], $sum[1] + $rgb[1], $sum[2] + $rgb[2]];
                $max = max($rgb);
                $sat = $max ? ($max - min($rgb)) / $max : 0;
                $score = $sat * ($max / 255) * (($max > 40 && $max < 245) ? 1 : 0.2);
                if ($score > $bestScore) {
                    $bestScore = $score;
                    $best = $rgb;
                }
            }
        }
        $avg = array_map(fn ($v) => $v / 256, $sum);
        $mix = $bestScore > 0.15 ? array_map(fn ($a, $b) => (int) round($a * 0.7 + $b * 0.3), $best, $avg) : array_map('intval', $avg);

        return sprintf('#%02x%02x%02x', ...$mix);
    }

    /** ~200px WebP copy of the poster for the animated poster wall. */
    public function smallPoster(string $posterPath): ?string
    {
        $data = @file_get_contents($posterPath);
        $img = $data ? @imagecreatefromstring($data) : false;
        if (! $img) {
            return null;
        }
        $small = imagescale($img, 200);
        $dest = preg_replace('/\.(\w+)$/', '_small.webp', $posterPath);
        imagewebp($small, $dest, 70);

        return $dest;
    }

    public function refreshItem(MediaItem $item): void
    {
        $root = config('movexa.paths.images');
        $src = $item->backdrop_path ?: $item->poster_path;
        if ($src && is_file("$root/$src")) {
            $item->dominant_color = $this->dominant("$root/$src");
        }
        if ($item->poster_path && is_file("$root/{$item->poster_path}")) {
            $this->smallPoster("$root/{$item->poster_path}");
        }
        $item->saveQuietly();
    }

    /** One color every TIMELINE_STEP seconds over the whole duration → colors.json next to the seek thumbnails. */
    public function timeline(MediaFile $file): ?string
    {
        if (! $file->duration || ! $file->video_codec) {
            return null;
        }
        $dir = $file->thumbnails_path ?: config('movexa.paths.cache').'/thumbs/'.$file->id;
        @mkdir($dir, 0755, true);
        $p = $this->sandbox->run([
            config('movexa.binaries.ffmpeg'), '-nostdin', '-hide_banner', '-loglevel', 'error', '-i', $file->path,
            '-vf', 'fps=1/'.self::TIMELINE_STEP.',scale=4:4:flags=area,scale=1:1:flags=area', '-f', 'rawvideo', '-pix_fmt', 'rgb24', 'pipe:1',
        ], [], 3600, [dirname($file->path)]);
        $raw = $p->getOutput();
        if (! $p->isSuccessful() || strlen($raw) < 3) {
            return null;
        }
        $colors = [];
        foreach (str_split($raw, 3) as $px) {
            if (strlen($px) === 3) {
                $colors[] = sprintf('#%02x%02x%02x', ord($px[0]), ord($px[1]), ord($px[2]));
            }
        }
        // Make sure the timeline covers the full duration (pad with the last color).
        $need = (int) ceil($file->duration / self::TIMELINE_STEP);
        while (count($colors) < $need) {
            $colors[] = end($colors);
        }
        file_put_contents("$dir/colors.json", json_encode(['step' => self::TIMELINE_STEP, 'duration' => $file->duration, 'colors' => $colors]));
        if (! $file->thumbnails_path) {
            $file->update(['thumbnails_path' => $dir]);
        }

        return "$dir/colors.json";
    }
}
