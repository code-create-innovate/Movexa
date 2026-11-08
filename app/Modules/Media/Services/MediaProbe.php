<?php

namespace App\Modules\Media\Services;

use RuntimeException;

/** ffprobe wrapper (sandboxed). Returns normalized info about container, streams and duration. */
class MediaProbe
{
    public function __construct(private Sandbox $sandbox) {}

    public function raw(string $path): array
    {
        $p = $this->sandbox->run([
            config('movexa.binaries.ffprobe'), '-v', 'error', '-print_format', 'json',
            '-show_format', '-show_streams', '-show_chapters', $path,
        ], [], config('movexa.sandbox.probe_timeout'), [dirname($path)]);

        if (! $p->isSuccessful()) {
            throw new RuntimeException('ffprobe failed: '.mb_substr(trim($p->getErrorOutput()), 0, 300));
        }
        $data = json_decode($p->getOutput(), true);
        if (! is_array($data) || empty($data['format'])) {
            throw new RuntimeException('ffprobe returned no format');
        }

        return $data;
    }

    public function analyze(string $path): array
    {
        $raw = $this->raw($path);
        $format = $raw['format'];
        $streams = [];
        $video = $audio = null;
        foreach ($raw['streams'] ?? [] as $s) {
            $type = $s['codec_type'] ?? 'data';
            $entry = [
                'index' => (int) $s['index'],
                'type' => $type,
                'codec' => $s['codec_name'] ?? null,
                'language' => $s['tags']['language'] ?? null,
                'title' => $s['tags']['title'] ?? null,
                'channels' => $s['channels'] ?? null,
                'width' => $s['width'] ?? null,
                'height' => $s['height'] ?? null,
                'is_default' => (bool) ($s['disposition']['default'] ?? false),
                'attached_pic' => (bool) ($s['disposition']['attached_pic'] ?? false),
                'filename' => $s['tags']['filename'] ?? null,
                'mimetype' => $s['tags']['mimetype'] ?? null,
            ];
            if ($type === 'video' && ! $entry['attached_pic'] && ! $video) {
                $video = $entry;
            }
            if ($type === 'audio' && ! $audio) {
                $audio = $entry;
            }
            $streams[] = $entry;
        }

        return [
            'format_name' => $format['format_name'] ?? null,
            'duration' => isset($format['duration']) ? (float) $format['duration'] : null,
            'bitrate' => isset($format['bit_rate']) ? (int) $format['bit_rate'] : null,
            'size' => isset($format['size']) ? (int) $format['size'] : null,
            'tags' => $format['tags'] ?? [],
            'chapters' => count($raw['chapters'] ?? []),
            'video' => $video,
            'audio' => $audio,
            'streams' => $streams,
        ];
    }

    public static function containerFromFormat(?string $formatName, string $path): string
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $f = (string) $formatName;

        return match (true) {
            str_contains($f, 'matroska') => $ext === 'webm' ? 'webm' : 'mkv',
            str_contains($f, 'mp4') || str_contains($f, 'mov') => in_array($ext, ['mov'], true) ? 'mov' : 'mp4',
            str_contains($f, 'avi') => 'avi',
            str_contains($f, 'mpegts') => 'ts',
            default => $ext ?: 'unknown',
        };
    }
}
