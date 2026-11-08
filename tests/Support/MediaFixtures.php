<?php

namespace Tests\Support;

use App\Modules\Library\Models\Library;
use App\Modules\Library\Models\MediaFile;
use App\Modules\Library\Models\MediaItem;
use RuntimeException;
use Symfony\Component\Process\Process;

/** Generates small real media files with ffmpeg (lavfi test sources). */
class MediaFixtures
{
    public static function make(string $path, string $kind = 'mp4', float $seconds = 4): string
    {
        @mkdir(dirname($path), 0755, true);
        $src = ['-f', 'lavfi', '-i', "testsrc2=size=320x180:rate=24:duration={$seconds}", '-f', 'lavfi', '-i', "sine=frequency=440:duration={$seconds}"];
        $args = match ($kind) {
            'mp4' => [...$src, '-c:v', 'libx264', '-preset', 'ultrafast', '-pix_fmt', 'yuv420p', '-c:a', 'aac', '-shortest'],
            'mkv-hevc-dts' => [...$src, '-c:v', 'libx265', '-preset', 'ultrafast', '-x265-params', 'log-level=error', '-c:a', 'dca', '-strict', '-2', '-ar', '48000', '-shortest'],
            'avi' => [...$src, '-c:v', 'mpeg4', '-q:v', '5', '-c:a', 'libmp3lame', '-shortest'],
            'mkv-2audio' => [...$src, '-f', 'lavfi', '-i', "sine=frequency=880:duration={$seconds}", '-map', '0:v', '-map', '1:a', '-map', '2:a', '-c:v', 'libx264', '-preset', 'ultrafast', '-pix_fmt', 'yuv420p', '-c:a', 'aac', '-metadata:s:a:0', 'language=ara', '-metadata:s:a:1', 'language=eng', '-shortest'],
            default => throw new RuntimeException("unknown fixture {$kind}"),
        };
        self::ffmpeg([...$args, $path]);

        return $path;
    }

    /** MKV with an embedded attachment (e.g. a fake executable or an EICAR payload) and chapters metadata. */
    public static function mkvWithAttachment(string $path, string $attachmentContent, string $attachmentName = 'readme.exe', string $mime = 'application/x-msdownload', float $seconds = 4): string
    {
        $att = dirname($path).'/'.$attachmentName;
        @mkdir(dirname($path), 0755, true);
        file_put_contents($att, $attachmentContent);
        $srt = dirname($path).'/emb.srt';
        file_put_contents($srt, "1\n00:00:00,500 --> 00:00:02,000\n<script>alert(1)</script>Hello <b>world</b>\n\n");
        self::ffmpeg([
            '-f', 'lavfi', '-i', "testsrc2=size=320x180:rate=24:duration={$seconds}", '-f', 'lavfi', '-i', "sine=duration={$seconds}", '-i', $srt,
            '-map', '0:v', '-map', '1:a', '-map', '2:s', '-c:v', 'libx264', '-preset', 'ultrafast', '-pix_fmt', 'yuv420p', '-c:a', 'aac', '-c:s', 'srt',
            '-metadata', 'title=evil', '-metadata:s:s:0', 'language=ara',
            '-attach', $att, '-metadata:s:t', 'mimetype='.$mime, '-shortest', $path,
        ]);
        @unlink($att);
        @unlink($srt);

        return $path;
    }

    public static function ffmpeg(array $args): void
    {
        $p = new Process(array_merge(['ffmpeg', '-nostdin', '-hide_banner', '-loglevel', 'error', '-y'], $args));
        $p->setTimeout(120);
        $p->run();
        if (! $p->isSuccessful()) {
            throw new RuntimeException('fixture ffmpeg failed: '.$p->getErrorOutput());
        }
    }

    /** A ready library item backed by a real file. */
    public static function readyMovie(string $dir, array $item = [], string $kind = 'mp4'): MediaFile
    {
        $lib = Library::firstOrCreate(['type' => 'movies'], ['name' => 'Movies', 'path' => $dir]);
        $media = MediaItem::create($item + ['library_id' => $lib->id, 'type' => 'movie', 'title' => 'Film '.uniqid(), 'match_key' => uniqid()]);
        $path = self::make($dir.'/'.uniqid().'.'.($kind === 'avi' ? 'avi' : ($kind === 'mp4' ? 'mp4' : 'mkv')), $kind);
        $info = app(\App\Modules\Media\Services\MediaProbe::class)->analyze($path);
        $file = MediaFile::create([
            'media_item_id' => $media->id, 'library_id' => $lib->id, 'path' => $path, 'original_name' => basename($path), 'size' => filesize($path),
            'container' => \App\Modules\Media\Services\MediaProbe::containerFromFormat($info['format_name'], $path),
            'video_codec' => $info['video']['codec'], 'audio_codec' => $info['audio']['codec'] ?? null,
            'width' => $info['video']['width'], 'height' => $info['video']['height'], 'duration' => $info['duration'], 'status' => 'ready',
        ]);
        foreach ($info['streams'] as $s) {
            $file->streams()->create(['index' => $s['index'], 'type' => $s['type'], 'codec' => $s['codec'], 'language' => $s['language'], 'channels' => $s['channels']]);
        }

        return $file->fresh(['item', 'streams']);
    }
}
