<?php

namespace App\Modules\Media\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Services\MediaSigner;
use App\Modules\Core\Services\ProfileContext;
use App\Modules\Library\Models\MediaFile;
use App\Modules\Library\Models\MediaItem;
use App\Modules\Library\Models\Subtitle;
use App\Modules\LiveTV\Models\LiveStream;
use App\Modules\LiveTV\Services\LiveStreamManager;
use App\Modules\Playback\Models\TranscodeJob;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * The ONLY door to media bytes. Requires: valid session + valid, unexpired, user-bound token
 * + profile permission (parental limits). Media dirs are never exposed by nginx directly.
 */
class MediaAccessController extends Controller
{
    private const TYPES = [
        'm3u8' => 'application/vnd.apple.mpegurl', 'm4s' => 'video/iso.segment', 'mp4' => 'video/mp4', 'ts' => 'video/mp2t',
        'vtt' => 'text/vtt; charset=utf-8', 'jpg' => 'image/jpeg', 'webp' => 'image/webp', 'png' => 'image/png',
        'mkv' => 'video/x-matroska', 'webm' => 'video/webm', 'm4v' => 'video/mp4', 'mov' => 'video/quicktime', 'avi' => 'video/x-msvideo',
        'mp3' => 'audio/mpeg', 'm4a' => 'audio/mp4', 'aac' => 'audio/aac', 'flac' => 'audio/flac',
    ];

    public function __construct(private MediaSigner $signer, private ProfileContext $profiles) {}

    public function __invoke(Request $request, string $token, string $path = 'stream'): Response
    {
        $claims = $this->signer->verify($token);
        $user = $request->user();
        abort_if(! $claims || ! $user || (int) $claims['u'] !== $user->id, 403);
        $profile = $this->profiles->current();
        abort_if($claims['p'] !== null && $profile?->id !== (int) $claims['p'], 403);
        abort_if(! $profile, 403);

        // Strict relative path: no traversal, no hidden files.
        abort_unless(preg_match('#^(?:[A-Za-z0-9_\-]+/)?[A-Za-z0-9_\-]+(?:\.[A-Za-z0-9]{1,5})?$#', $path), 404);

        [$abs, $download] = match ($claims['k']) {
            'file' => [$this->file((int) $claims['i'], $profile), false],
            'hls' => [$this->hls((int) $claims['i'], $path, $profile), false],
            'sub' => [$this->subtitle((int) $claims['i'], $profile), false],
            'img' => [$this->image((int) $claims['i'], $path, $profile), false],
            'thumb' => [$this->thumb((int) $claims['i'], $path, $profile), false],
            'dl' => [$this->download((int) $claims['i'], $profile), true],
            'live' => [$this->live((int) $claims['i'], $path, $request, $profile), false],
            default => abort(404),
        };
        abort_unless($abs && is_file($abs), 404);

        return $this->send($abs, $download);
    }

    private function file(int $id, $profile): ?string
    {
        $f = MediaFile::with('item')->find($id);

        return $f && $f->viewableBy($profile) ? $f->path : null;
    }

    private function hls(int $id, string $path, $profile): ?string
    {
        $job = TranscodeJob::with('file.item')->find($id);
        if (! $job || ! $job->file || ! $job->file->viewableBy($profile) || ! $job->output_dir) {
            return null;
        }
        if ($path === 'master.m3u8') {
            $job->forceFill(['last_accessed_at' => now()])->saveQuietly();
        }

        return $job->output_dir.'/'.$path;
    }

    private function download(int $id, $profile): ?string
    {
        $dl = \App\Modules\Devices\Models\Download::with('file.item', 'file.episode')->find($id);

        return $dl && $dl->profile_id === $profile->id && $dl->status === 'ready' && $dl->expires_at?->isFuture()
            && $dl->file && $dl->file->viewableBy($profile) ? $dl->output_path : null;
    }

    private function subtitle(int $id, $profile): ?string
    {
        $s = Subtitle::with('file.item')->find($id);

        return $s && $s->file && $s->file->viewableBy($profile) ? $s->path : null;
    }

    private function image(int $id, string $path, $profile): ?string
    {
        $item = MediaItem::find($id);
        if (! in_array($path, ['poster', 'backdrop', 'poster_small', 'preview'], true) || ! app(\App\Modules\Core\Services\AccessPolicy::class)->allowsItem($profile, $item)) {
            return null;
        }
        $rel = match ($path) {
            'poster' => $item->poster_path,
            'backdrop' => $item->backdrop_path,
            'poster_small' => $item->poster_path ? preg_replace('/\.(\w+)$/', '_small.webp', $item->poster_path) : null,
            'preview' => $item->preview_path,
        };
        if ($path === 'poster_small' && $rel && ! is_file(config('movexa.paths.images').'/'.$rel)) {
            $rel = $item->poster_path; // small version not generated yet
        }

        return $rel ? config('movexa.paths.images').'/'.$rel : null;
    }

    private function thumb(int $id, string $path, $profile): ?string
    {
        $f = MediaFile::with('item')->find($id);

        return $f && $f->viewableBy($profile) && $f->thumbnails_path ? $f->thumbnails_path.'/'.$path : null;
    }

    private function live(int $id, string $path, Request $request, $profile): ?string
    {
        $stream = LiveStream::with('channel')->find($id);
        if (! $stream || ! app(\App\Modules\Core\Services\AccessPolicy::class)->allowsChannel($profile, $stream->channel)) {
            return null;
        }
        if (str_ends_with($path, '.m3u8')) {
            app(LiveStreamManager::class)->heartbeat($stream, $request->session()->getId());
        }

        return $stream->output_dir.'/'.$path;
    }

    private function send(string $abs, bool $download): Response
    {
        $ext = strtolower(pathinfo($abs, PATHINFO_EXTENSION));
        $headers = [
            'Content-Type' => self::TYPES[$ext] ?? 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => ($download ? 'attachment' : 'inline').'; filename="media.'.$ext.'"',
            'Cache-Control' => str_ends_with($abs, '.m3u8') ? 'no-store' : 'private, max-age=3600',
            'Cross-Origin-Resource-Policy' => 'same-origin',
        ];

        if (config('movexa.serve_mode') === 'xaccel') {
            $internal = $this->internalPath($abs);
            abort_unless($internal, 404);

            return response('', 200, $headers + ['X-Accel-Redirect' => $internal, 'X-Accel-Buffering' => 'no']);
        }

        $r = new BinaryFileResponse($abs, 200, $headers, false, null, false, false);

        return $r; // Range/206 handled by Symfony
    }

    /** Maps an absolute path under an allowed root to the matching nginx `internal` location. */
    private function internalPath(string $abs): ?string
    {
        $real = realpath($abs);
        $roots = [
            config('movexa.xaccel_prefix') => realpath(config('movexa.data_root')),
            '/_cloudsync' => realpath((string) config('movexa.cloudsync.root')),
        ];
        foreach ($roots as $prefix => $root) {
            if ($real && $root && str_starts_with($real, $root.'/')) {
                return $prefix.'/'.implode('/', array_map('rawurlencode', explode('/', substr($real, strlen($root) + 1))));
            }
        }

        return null;
    }
}
