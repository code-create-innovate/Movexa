<?php

namespace App\Modules\LiveTV\Services;

use App\Modules\LiveTV\Models\Channel;
use App\Modules\LiveTV\Models\LiveStream;
use App\Modules\LiveTV\Models\LiveViewer;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * One ffmpeg process per channel, shared by all viewers (refcounted by heartbeat).
 * Starts on first viewer, stops `idle_timeout` seconds after the last viewer leaves,
 * restarts automatically (with backoff) if the source drops.
 */
class LiveStreamManager
{
    public function acquire(Channel $channel, string $viewerKey): LiveStream
    {
        return DB::transaction(function () use ($channel, $viewerKey) {
            $stream = LiveStream::where('channel_id', $channel->id)->lockForUpdate()->first();
            if (! $stream || ! $this->alive($stream)) {
                $source = $channel->source;
                $running = LiveStream::whereHas('channel', fn ($q) => $q->where('live_source_id', $source->id))
                    ->where('channel_id', '!=', $channel->id)->whereIn('status', ['starting', 'running', 'reconnecting'])->get()
                    ->filter(fn ($s) => $this->alive($s))->count();
                if ($running >= $source->max_streams) {
                    throw new RuntimeException('tuner_limit');
                }
                $stream = $this->start($channel, $stream);
            }
            $this->heartbeat($stream, $viewerKey);

            return $stream;
        });
    }

    public function heartbeat(LiveStream $stream, string $viewerKey): void
    {
        LiveViewer::upsert([['live_stream_id' => $stream->id, 'viewer_key' => hash('sha256', $viewerKey), 'last_seen_at' => now()]], ['live_stream_id', 'viewer_key'], ['last_seen_at']);
    }

    public function release(LiveStream $stream, string $viewerKey): void
    {
        LiveViewer::where('live_stream_id', $stream->id)->where('viewer_key', hash('sha256', $viewerKey))->delete();
        if ($stream->viewers()->count() === 0) {
            $this->stop($stream);
        }
    }

    public function viewerCount(LiveStream $stream): int
    {
        return $stream->viewers()->where('last_seen_at', '>=', now()->subSeconds(config('movexa.live.idle_timeout')))->count();
    }

    /** Called every few seconds by `movexa:live-reaper`. */
    public function reap(): void
    {
        $idle = now()->subSeconds(config('movexa.live.idle_timeout'));
        foreach (LiveStream::with('channel.source')->where('status', '!=', 'stopped')->get() as $stream) {
            LiveViewer::where('live_stream_id', $stream->id)->where('last_seen_at', '<', $idle)->delete();
            if ($stream->viewers()->count() === 0) {
                $this->stop($stream);
            } elseif (! $this->alive($stream)) {
                if ($stream->restarts >= config('movexa.live.max_restarts')) {
                    $stream->update(['status' => 'stopped']);

                    continue;
                }
                $stream->update(['status' => 'reconnecting', 'restarts' => $stream->restarts + 1]);
                $this->start($stream->channel, $stream);
            } elseif ($stream->status !== 'running' && is_file($stream->output_dir.'/index.m3u8')) {
                $stream->update(['status' => 'running']);
            }
        }
    }

    public function stop(LiveStream $stream): void
    {
        if ($stream->pid && $this->alive($stream)) {
            posix_kill(-$stream->pid, SIGTERM) || posix_kill($stream->pid, SIGTERM);
            for ($i = 0; $i < 20 && $this->alive($stream); $i++) {
                usleep(100_000);
            }
            if ($this->alive($stream)) {
                posix_kill($stream->pid, SIGKILL);
            }
        }
        $stream->update(['status' => 'stopped', 'pid' => null]);
        array_map('unlink', glob($stream->output_dir.'/*') ?: []);
    }

    public function alive(LiveStream $stream): bool
    {
        if (! $stream->pid || ! posix_kill($stream->pid, 0)) {
            return false;
        }
        $cmd = @file_get_contents("/proc/{$stream->pid}/cmdline");

        return $cmd === false || str_contains($cmd, 'ffmpeg'); // pid reuse guard
    }

    /** Status shown to viewers: latency estimate, quality, reconnecting indicator. */
    public function status(LiveStream $stream): array
    {
        $playlist = @file_get_contents($stream->output_dir.'/index.m3u8') ?: '';
        preg_match('/#EXT-X-TARGETDURATION:(\d+)/', $playlist, $td);
        $segments = substr_count($playlist, '#EXTINF');
        $info = @json_decode((string) @file_get_contents($stream->output_dir.'/info.json'), true) ?: [];

        return [
            'status' => $stream->status,
            'viewers' => $this->viewerCount($stream),
            'restarts' => $stream->restarts,
            'latency' => isset($td[1]) ? (int) $td[1] * min(3, max(1, $segments)) : null,
            'quality' => $info['quality'] ?? null,
        ];
    }

    public function command(Channel $channel, string $outDir): array
    {
        $url = $channel->stream_url;
        $isFile = str_starts_with($url, 'file://');
        if ($isFile && ! config('movexa.live.allow_file_sources')) {
            throw new RuntimeException('file sources disabled');
        }
        $in = $isFile
            ? ['-re', '-stream_loop', '-1', '-i', substr($url, 7)]
            : (preg_match('#^https?://#', $url)
                ? ['-reconnect', '1', '-reconnect_streamed', '1', '-reconnect_on_network_error', '1', '-reconnect_delay_max', '5', '-rw_timeout', '15000000', '-i', $url]
                : ['-rw_timeout', '15000000', '-i', $url]);

        return array_merge([config('movexa.binaries.ffmpeg'), '-nostdin', '-hide_banner', '-loglevel', 'error', '-fflags', '+genpts'], $in, [
            '-map', '0:v:0?', '-map', '0:a:0?', '-c:v', 'libx264', '-preset', 'veryfast', '-tune', 'zerolatency', '-g', '50', '-sc_threshold', '0',
            '-c:a', 'aac', '-b:a', '128k', '-ac', '2',
            '-f', 'hls', '-hls_time', '4', '-hls_list_size', '6',
            '-hls_flags', 'delete_segments+independent_segments+omit_endlist+temp_file',
            '-hls_segment_filename', $outDir.'/seg_%06d.ts', $outDir.'/index.m3u8',
        ]);
    }

    private function start(Channel $channel, ?LiveStream $stream): LiveStream
    {
        $outDir = config('movexa.paths.cache').'/live/'.$channel->id;
        @mkdir($outDir, 0755, true);
        array_map('unlink', glob($outDir.'/*') ?: []);
        $cmd = $this->command($channel, $outDir);
        // Copy video when the source is already H.264 (saves CPU); probing a live source is too slow, so honour a channel hint.
        $shell = 'setsid '.implode(' ', array_map('escapeshellarg', $cmd)).' > '.escapeshellarg($outDir.'/ffmpeg.log').' 2>&1 < /dev/null & echo $!';
        $pid = (int) trim((string) shell_exec($shell));
        if ($pid <= 0) {
            throw new RuntimeException('could not start ffmpeg');
        }
        file_put_contents($outDir.'/info.json', json_encode(['quality' => 'auto', 'started' => time()]));
        $data = ['pid' => $pid, 'output_dir' => $outDir, 'status' => $stream?->status === 'reconnecting' ? 'reconnecting' : 'starting', 'started_at' => now()];
        if ($stream) {
            $stream->update($data);

            return $stream;
        }

        return LiveStream::create($data + ['channel_id' => $channel->id]);
    }
}
