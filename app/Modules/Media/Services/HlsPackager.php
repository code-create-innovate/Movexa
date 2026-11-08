<?php

namespace App\Modules\Media\Services;

use App\Modules\Library\Models\MediaFile;
use App\Modules\Playback\Models\TranscodeJob;
use RuntimeException;

/**
 * Produces an HLS (fMP4) package: master.m3u8 + one rendition per video quality + one
 * AAC rendition per audio track (EXT-X-MEDIA group, switchable in hls.js and Safari).
 *   remux     : video copied (no re-encode), audio → AAC if needed
 *   transcode : H.264 ladder (1080/720/480 ≤ source), HW encoder when available
 */
class HlsPackager
{
    public function __construct(private Sandbox $sandbox, private Encoders $encoders) {}

    public static function profileKey(string $mode, ?array $heights = null): string
    {
        return $mode === 'remux' ? 'remux' : 'tx-'.implode('-', $heights ?? array_keys(config('movexa.transcode.ladder')));
    }

    public function ladderFor(MediaFile $file): array
    {
        $src = $file->height ?: 1080;
        $heights = array_values(array_filter(array_keys(config('movexa.transcode.ladder')), fn ($h) => $h <= $src + 8));

        return $heights ?: [min(array_keys(config('movexa.transcode.ladder')))];
    }

    public function command(MediaFile $file, TranscodeJob $job, string $outDir, ?string $encoder = null): array
    {
        $ff = config('movexa.binaries.ffmpeg');
        $seg = (string) config('movexa.transcode.segment_seconds');
        $audio = $file->streams->where('type', 'audio')->values();
        $encoder ??= $job->mode === 'transcode' ? $this->encoders->select() : 'copy';

        $args = [$ff, '-nostdin', '-hide_banner', '-loglevel', 'error', '-progress', 'pipe:1', '-y'];
        if ($encoder === 'vaapi') {
            $args = array_merge($args, ['-vaapi_device', config('movexa.transcode.vaapi_device')]);
        } elseif ($encoder === 'qsv') {
            $args = array_merge($args, ['-init_hw_device', 'qsv=hw', '-filter_hw_device', 'hw']);
        }
        $args = array_merge($args, ['-i', $file->path]);

        $map = [];
        $vi = 0;
        if ($job->mode === 'remux') {
            $args = array_merge($args, ['-map', '0:V:0', '-c:v:0', 'copy']);
            if ($file->video_codec === 'hevc') {
                $args = array_merge($args, ['-tag:v:0', 'hvc1']);
            }
            $map[] = 'v:0,agroup:aud,name:src';
            $vi = 1;
        } else {
            $heights = array_map('intval', explode('-', substr($job->profile_key, 3)));
            $split = count($heights);
            $hwUp = match ($encoder) { 'vaapi' => ',format=nv12,hwupload', 'qsv' => ',hwupload=extra_hw_frames=64,format=qsv', default => '' };
            $chains = ['[0:V:0]split='.$split.implode('', array_map(fn ($i) => "[s{$i}]", range(0, $split - 1)))];
            foreach ($heights as $i => $h) {
                $chains[] = "[s{$i}]scale=-2:{$h}{$hwUp}[v{$i}]";
            }
            $args = array_merge($args, ['-filter_complex', implode(';', $chains)]);
            foreach ($heights as $i => $h) {
                $kbps = config("movexa.transcode.ladder.{$h}.bitrate");
                $args = array_merge($args, ['-map', "[v{$i}]"], $this->encoders->videoArgs($encoder, $h, $kbps, (string) $i));
                $map[] = "v:{$i},agroup:aud,name:{$h}p";
            }
            $vi = $split;
        }

        foreach ($audio as $ai => $a) {
            $args = array_merge($args, ['-map', '0:a:'.$ai]);
            if ($a->codec === 'aac' && ($a->channels ?? 2) <= 2) {
                $args = array_merge($args, ["-c:a:{$ai}", 'copy']);
            } else {
                $args = array_merge($args, ["-c:a:{$ai}", 'aac', "-b:a:{$ai}", '160k', "-ac:a:{$ai}", '2']);
            }
            $lang = preg_replace('/[^a-z]/', '', strtolower((string) $a->language)) ?: 'und';
            $map[] = "a:{$ai},agroup:aud,language:{$lang},name:audio_{$ai}".($ai === 0 ? ',default:yes' : '');
        }
        if ($audio->isEmpty()) {
            $map = array_map(fn ($m) => str_replace(',agroup:aud', '', $m), $map);
        }

        return array_merge($args, [
            '-sn', '-dn', '-map_metadata', '-1',
            '-f', 'hls', '-hls_time', $seg, '-hls_playlist_type', 'event', '-hls_segment_type', 'fmp4',
            '-hls_flags', 'independent_segments+temp_file', '-hls_fmp4_init_filename', 'init.mp4',
            '-master_pl_name', 'ffmpeg_master.m3u8', '-var_stream_map', implode(' ', $map),
            '-hls_segment_filename', $outDir.'/%v/seg_%05d.m4s', $outDir.'/%v/index.m3u8',
        ]);
    }

    public function run(TranscodeJob $job, ?callable $onProgress = null): void
    {
        $file = $job->file()->with('streams')->firstOrFail();
        $outDir = config('movexa.paths.cache').'/hls/'.$file->id.'/'.$job->profile_key;
        @mkdir($outDir, 0755, true);
        array_map(fn ($p) => is_dir($p) ? null : @unlink($p), glob($outDir.'/{,*/}*', GLOB_BRACE) ?: []);

        $encoder = $job->mode === 'transcode' ? $this->encoders->select() : 'copy';
        file_put_contents($outDir.'/master.m3u8', $this->master($file, $job));
        $job->update(['status' => 'running', 'output_dir' => $outDir, 'encoder' => $encoder, 'progress' => 0, 'error' => null]);

        $attempts = $encoder !== 'copy' && $encoder !== 'cpu' ? [$encoder, 'cpu'] : [$encoder];
        foreach ($attempts as $enc) {
            $p = $this->sandbox->process($this->command($file, $job, $outDir, $enc), [$outDir], null, [dirname($file->path)]);
            $last = 0;
            $p->run(function ($type, $buf) use ($file, $job, &$last, $onProgress) {
                if ($type === 'out' && preg_match_all('/out_time_us=(\d+)/', $buf, $m) && $file->duration) {
                    $pct = (int) min(99, end($m[1]) / 1e6 / $file->duration * 100);
                    if ($pct >= $last + 2) {
                        $last = $pct;
                        $job->update(['progress' => $pct]);
                        $onProgress && $onProgress($pct);
                    }
                }
            });
            if ($p->isSuccessful()) {
                $job->update(['status' => 'done', 'progress' => 100, 'encoder' => $enc, 'output_size' => $this->dirSize($outDir), 'last_accessed_at' => now()]);
                $onProgress && $onProgress(100);

                return;
            }
            $error = mb_substr(trim($p->getErrorOutput()), -250);
        }
        $job->update(['status' => 'failed', 'error' => $error ?? 'ffmpeg failed']);
        throw new RuntimeException('HLS packaging failed: '.($error ?? ''));
    }

    /**
     * Our own master playlist (ffmpeg omits STREAM-INF when source bitrates are unknown, e.g. copied streams in MKV).
     * Written before encoding starts, so players can attach as soon as the first segments exist.
     */
    public function master(MediaFile $file, TranscodeJob $job): string
    {
        $audio = $file->streams->where('type', 'audio')->values();
        $out = "#EXTM3U\n#EXT-X-VERSION:7\n#EXT-X-INDEPENDENT-SEGMENTS\n";
        foreach ($audio as $i => $a) {
            $lang = preg_replace('/[^a-z]/', '', strtolower((string) $a->language)) ?: 'und';
            $name = $a->title ? preg_replace('/[^\p{L}\p{N} ._\-]/u', '', $a->title) : strtoupper($lang).' '.($i + 1);
            $out .= sprintf("#EXT-X-MEDIA:TYPE=AUDIO,GROUP-ID=\"aud\",NAME=\"%s\",LANGUAGE=\"%s\",DEFAULT=%s,AUTOSELECT=YES,CHANNELS=\"2\",URI=\"audio_%d/index.m3u8\"\n", $name, $lang, $i === 0 ? 'YES' : 'NO', $i);
        }
        $group = $audio->isNotEmpty() ? ',AUDIO="aud"' : '';
        $ratio = $file->width && $file->height ? $file->width / $file->height : 16 / 9;
        if ($job->mode === 'remux') {
            $bw = max(500_000, (int) ($file->bitrate ?: ($file->size && $file->duration ? $file->size * 8 / $file->duration : 8_000_000)));
            $codec = $file->video_codec === 'hevc' ? 'hvc1.1.6.L120.90' : 'avc1.640028';
            $res = $file->width && $file->height ? ",RESOLUTION={$file->width}x{$file->height}" : '';
            $out .= "#EXT-X-STREAM-INF:BANDWIDTH={$bw}{$res},CODECS=\"{$codec},mp4a.40.2\"{$group}\nsrc/index.m3u8\n";
        } else {
            foreach (array_map('intval', explode('-', substr($job->profile_key, 3))) as $h) {
                $w = (int) (round($h * $ratio / 2) * 2);
                $bw = (config("movexa.transcode.ladder.{$h}.bitrate") + 160) * 1000;
                $out .= "#EXT-X-STREAM-INF:BANDWIDTH={$bw},RESOLUTION={$w}x{$h},CODECS=\"avc1.640028,mp4a.40.2\"{$group}\n{$h}p/index.m3u8\n";
            }
        }

        return $out;
    }

    /** Players may start once the master and the first segment of each rendition exist. */
    public static function isPlayable(TranscodeJob $job): bool
    {
        if ($job->status === 'done') {
            return true;
        }

        return $job->output_dir && is_file($job->output_dir.'/master.m3u8') && count(glob($job->output_dir.'/*/seg_00001.m4s') ?: []) > 0;
    }

    private function dirSize(string $dir): int
    {
        $size = 0;
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $f) {
            $size += $f->getSize();
        }

        return $size;
    }
}
