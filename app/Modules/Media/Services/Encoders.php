<?php

namespace App\Modules\Media\Services;

use Illuminate\Support\Facades\Cache;
use Symfony\Component\Process\Process;

/** Detects hardware encoders (NVENC, QSV, VAAPI) and falls back to libx264. */
class Encoders
{
    public function available(): array
    {
        return Cache::remember('movexa.encoders', 3600, function () {
            $p = new Process([config('movexa.binaries.ffmpeg'), '-hide_banner', '-encoders']);
            $p->run();
            $out = $p->getOutput();
            $found = [];
            foreach (['h264_nvenc' => 'nvenc', 'h264_qsv' => 'qsv', 'h264_vaapi' => 'vaapi', 'libx264' => 'cpu'] as $enc => $key) {
                if (str_contains($out, ' '.$enc.' ')) {
                    $found[$key] = $enc;
                }
            }

            return $found;
        });
    }

    /** Hardware encoder actually usable (device present + test encode succeeds). */
    public function select(): string
    {
        $pref = config('movexa.transcode.hwaccel');
        if ($pref === 'none') {
            return 'cpu';
        }
        $candidates = $pref === 'auto' ? ['nvenc', 'qsv', 'vaapi'] : [$pref];

        return Cache::remember('movexa.encoder.selected.'.$pref, 3600, function () use ($candidates) {
            foreach ($candidates as $c) {
                if (isset($this->available()[$c]) && $this->probe($c)) {
                    return $c;
                }
            }

            return 'cpu';
        });
    }

    public function probe(string $kind): bool
    {
        $ff = config('movexa.binaries.ffmpeg');
        $dev = config('movexa.transcode.vaapi_device');
        $cmd = match ($kind) {
            'nvenc' => [$ff, '-hide_banner', '-f', 'lavfi', '-i', 'testsrc=size=256x144:rate=1', '-t', '1', '-c:v', 'h264_nvenc', '-f', 'null', '-'],
            'qsv' => [$ff, '-hide_banner', '-init_hw_device', 'qsv=hw', '-f', 'lavfi', '-i', 'testsrc=size=256x144:rate=1', '-t', '1', '-vf', 'hwupload=extra_hw_frames=64,format=qsv', '-c:v', 'h264_qsv', '-f', 'null', '-'],
            'vaapi' => is_readable($dev) ? [$ff, '-hide_banner', '-vaapi_device', $dev, '-f', 'lavfi', '-i', 'testsrc=size=256x144:rate=1', '-t', '1', '-vf', 'format=nv12,hwupload', '-c:v', 'h264_vaapi', '-f', 'null', '-'] : null,
            default => null,
        };
        if (! $cmd) {
            return false;
        }
        $p = new Process($cmd);
        $p->setTimeout(20);
        $p->run();

        return $p->isSuccessful();
    }

    /** ffmpeg args for encoding a single H.264 rendition. */
    public function videoArgs(string $kind, int $height, int $kbps, string $outIndex = '0'): array
    {
        $gop = ['-g', '48', '-keyint_min', '48', '-sc_threshold', '0'];

        return match ($kind) {
            'nvenc' => ["-c:v:{$outIndex}", 'h264_nvenc', '-preset', 'p4', "-b:v:{$outIndex}", "{$kbps}k", "-maxrate:v:{$outIndex}", (int) ($kbps * 1.2).'k', "-bufsize:v:{$outIndex}", ($kbps * 2).'k', ...$gop],
            'qsv' => ["-c:v:{$outIndex}", 'h264_qsv', '-preset', 'faster', "-b:v:{$outIndex}", "{$kbps}k", ...$gop],
            'vaapi' => ["-c:v:{$outIndex}", 'h264_vaapi', "-b:v:{$outIndex}", "{$kbps}k", ...$gop],
            default => ["-c:v:{$outIndex}", 'libx264', '-preset', 'veryfast', '-profile:v', 'high', "-b:v:{$outIndex}", "{$kbps}k", "-maxrate:v:{$outIndex}", (int) ($kbps * 1.2).'k', "-bufsize:v:{$outIndex}", ($kbps * 2).'k', '-pix_fmt', 'yuv420p', ...$gop],
        };
    }
}
