<?php

namespace App\Modules\Library\Services\Security;

use App\Modules\Media\Services\MediaProbe;
use App\Modules\Media\Services\Sandbox;
use RuntimeException;

/**
 * Content Disarm & Reconstruction for audio/video: builds a NEW file holding only
 * video, audio and (for MKV) subtitle streams. Drops attachments, embedded fonts,
 * cover-art streams, data streams, global metadata and chapters.
 * Falls back to a full re-encode when remuxing fails.
 */
class MediaSanitizer
{
    private const MP4_VIDEO = ['h264', 'hevc', 'av1', 'mpeg4'];

    private const MP4_AUDIO = ['aac', 'mp3', 'ac3', 'eac3', 'opus', 'flac', 'alac'];

    public function __construct(private Sandbox $sandbox, private MediaProbe $probe) {}

    /** @return array{path:string, mode:string, before:array, after:array, removed:list<string>} */
    public function rebuild(string $input, string $outputBase, ?array $before = null): array
    {
        $before ??= $this->probe->analyze($input);
        $video = array_values(array_filter($before['streams'], fn ($s) => $s['type'] === 'video' && ! $s['attached_pic']));
        $audio = array_values(array_filter($before['streams'], fn ($s) => $s['type'] === 'audio'));
        $subs = array_values(array_filter($before['streams'], fn ($s) => $s['type'] === 'subtitle'));
        if (! $video && ! $audio) {
            throw new RuntimeException('no audio/video streams');
        }

        $removed = [];
        foreach ($before['streams'] as $s) {
            if (! in_array($s['type'], ['video', 'audio', 'subtitle'], true) || $s['attached_pic']) {
                $removed[] = $s['type'].':'.($s['filename'] ?? $s['codec'] ?? '?');
            }
        }
        if ($before['chapters'] > 0) {
            $removed[] = 'chapters:'.$before['chapters'];
        }

        $mp4 = (! $video || in_array($video[0]['codec'], self::MP4_VIDEO, true))
            && collect($audio)->every(fn ($a) => in_array($a['codec'], self::MP4_AUDIO, true));
        $out = $outputBase.($mp4 ? '.mp4' : '.mkv');
        @unlink($out);

        $args = [config('movexa.binaries.ffmpeg'), '-nostdin', '-hide_banner', '-loglevel', 'error', '-y', '-i', $input];
        $args = array_merge($args, $video ? ['-map', '0:V'] : [], $audio ? ['-map', '0:a'] : []);
        $textSubs = array_filter($subs, fn ($s) => in_array($s['codec'], ['subrip', 'ass', 'ssa', 'webvtt', 'mov_text', 'text'], true));
        if (! $mp4 && $subs) {
            $args = array_merge($args, ['-map', '0:s']);
        }
        $args = array_merge($args, ['-map_metadata', '-1', '-map_chapters', '-1', '-dn', '-sn']);
        if (! $mp4 && $subs) {
            array_pop($args); // keep subs for MKV (bitmap subs like PGS can't be extracted to text)
        }
        $args = array_merge($args, ['-c', 'copy'], $this->streamTags($audio, 'a'), $this->streamTags($subs, 's'));
        if ($mp4) {
            $args = array_merge($args, ['-movflags', '+faststart']);
        }
        if (! $mp4 && $subs) {
            // ASS subtitles keep their text but embedded fonts are attachments (not mapped).
            $args = array_merge($args, ['-c:s', 'copy']);
        }
        $args[] = $out;

        $mode = 'remux';
        $p = $this->sandbox->run($args, [dirname($out)], null, [dirname($input)]);
        if (! $p->isSuccessful() || ! is_file($out) || filesize($out) === 0) {
            $mode = 'reencode';
            $out = $outputBase.'.mp4';
            $p = $this->sandbox->run([
                config('movexa.binaries.ffmpeg'), '-nostdin', '-hide_banner', '-loglevel', 'error', '-y', '-err_detect', 'ignore_err',
                '-i', $input, ...($video ? ['-map', '0:V:0'] : []), ...($audio ? ['-map', '0:a'] : []),
                '-map_metadata', '-1', '-map_chapters', '-1', '-dn', '-sn',
                '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '20', '-pix_fmt', 'yuv420p', '-c:a', 'aac', '-b:a', '192k',
                '-movflags', '+faststart', $out,
            ], [dirname($out)], null, [dirname($input)]);
            if (! $p->isSuccessful()) {
                throw new RuntimeException('sanitize failed: '.mb_substr(trim($p->getErrorOutput()), 0, 300));
            }
        }

        $after = $this->probe->analyze($out);
        $this->verify($before, $after, $mode, count($video) > 0 ? 1 : 0);

        return ['path' => $out, 'mode' => $mode, 'before' => $before, 'after' => $after, 'removed' => $removed, 'text_subtitles' => array_values($textSubs)];
    }

    /** Output must match input: same duration (± tolerance) and same number of A/V streams. */
    public function verify(array $before, array $after, string $mode, int $minVideo): void
    {
        $tol = (float) config('movexa.security.duration_tolerance');
        if ($before['duration'] && abs(($after['duration'] ?? 0) - $before['duration']) > max($tol, $before['duration'] * 0.01)) {
            throw new RuntimeException(sprintf('duration mismatch %.2f vs %.2f', $before['duration'], $after['duration'] ?? 0));
        }
        $count = fn ($info, $t) => count(array_filter($info['streams'], fn ($s) => $s['type'] === $t && ! $s['attached_pic']));
        if ($count($after, 'video') < $minVideo) {
            throw new RuntimeException('video stream missing after sanitize');
        }
        if ($mode === 'remux' && $count($after, 'video') !== $count($before, 'video')) {
            throw new RuntimeException('video stream count mismatch');
        }
        if ($count($after, 'audio') !== $count($before, 'audio')) {
            throw new RuntimeException('audio stream count mismatch');
        }
        foreach ($after['streams'] as $s) {
            if (! in_array($s['type'], ['video', 'audio', 'subtitle'], true) || $s['attached_pic']) {
                throw new RuntimeException('unexpected stream after sanitize: '.$s['type']);
            }
        }
    }

    /** Extract one embedded text subtitle stream as raw SRT (later sanitized to VTT). */
    public function extractSubtitle(string $input, int $streamIndex, string $outSrt): bool
    {
        $p = $this->sandbox->run([config('movexa.binaries.ffmpeg'), '-nostdin', '-hide_banner', '-loglevel', 'error', '-y', '-i', $input, '-map', '0:'.$streamIndex, '-c:s', 'srt', $outSrt], [dirname($outSrt)], 600, [dirname($input)]);

        return $p->isSuccessful() && is_file($outSrt);
    }

    private function streamTags(array $streams, string $type): array
    {
        $args = [];
        foreach (array_values($streams) as $i => $s) {
            $lang = preg_replace('/[^a-z]/', '', strtolower((string) $s['language'])) ?: 'und';
            $args = array_merge($args, ["-metadata:s:{$type}:{$i}", 'language='.substr($lang, 0, 3)]);
            $title = trim(preg_replace('/[^\p{L}\p{N} ._()\-]/u', '', (string) $s['title']));
            if ($title !== '') {
                $args = array_merge($args, ["-metadata:s:{$type}:{$i}", 'title='.mb_substr($title, 0, 60)]);
            }
        }

        return $args;
    }
}
