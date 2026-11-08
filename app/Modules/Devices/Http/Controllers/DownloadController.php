<?php

namespace App\Modules\Devices\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Services\MediaSigner;
use App\Modules\Devices\Jobs\PrepareDownloadJob;
use App\Modules\Devices\Models\Download;
use App\Modules\Library\Models\MediaFile;
use App\Modules\Playback\Models\WatchProgress;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;

/**
 * Offline downloads. Server side is fully enforced (enabled per user, quota, count, profile access policy, expiry).
 * Documented exception: a copy already stored on a device that stays offline cannot be revoked until it reconnects.
 */
class DownloadController extends Controller
{
    public function __construct(private MediaSigner $signer) {}

    private function usage(int $userId): array
    {
        $q = Download::where('user_id', $userId)->whereNotIn('status', ['failed', 'revoked']);

        return ['count' => (clone $q)->count(), 'bytes' => (int) (clone $q)->sum('size')];
    }

    public function index(Request $request)
    {
        $user = $request->user();
        $profile = $request->attributes->get('profile');

        return Inertia::render('devices/downloads', [
            'enabled' => (bool) $user->downloads_enabled,
            'quota' => ['max' => $user->downloads_max, 'mb' => $user->downloads_quota_mb] + $this->usage($user->id),
            'downloads' => Download::with('file.item', 'file.episode.season')->where('profile_id', $profile->id)->whereNotIn('status', ['revoked'])->latest()->get()
                ->filter(fn ($d) => $d->file && $d->file->viewableBy($profile))
                ->map(fn ($d) => $this->present($d))->values(),
        ]);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        $profile = $request->attributes->get('profile');
        abort_unless($user->downloads_enabled, 403, 'downloads disabled');
        $d = $request->validate(['file_id' => ['required', 'integer'], 'quality' => ['required', 'in:480,720'], 'audio_index' => ['nullable', 'integer', 'min:0'], 'subtitle_ids' => ['array'], 'subtitle_ids.*' => ['integer']]);
        $file = MediaFile::with('item', 'episode', 'subtitles')->find($d['file_id']);
        abort_unless($file && $file->viewableBy($profile), 404);
        $usage = $this->usage($user->id);
        if ($usage['count'] >= $user->downloads_max) {
            return response()->json(['message' => 'count_limit'], 422);
        }
        $estimate = (int) (($d['quality'] == 720 ? 2628 : 1228) * 1000 / 8 * ($file->duration ?? 0));
        if ($usage['bytes'] + $estimate > $user->downloads_quota_mb * 1024 * 1024) {
            return response()->json(['message' => 'quota'], 422);
        }
        $dl = Download::create([
            'uuid' => (string) Str::uuid(), 'user_id' => $user->id, 'profile_id' => $profile->id, 'media_file_id' => $file->id, 'quality' => (int) $d['quality'],
            'audio_index' => $d['audio_index'] ?? 0, 'subtitle_ids' => $file->subtitles->whereIn('id', $d['subtitle_ids'] ?? [])->pluck('id')->values(),
            'expires_at' => now()->addDays(config('movexa.downloads.ttl_days')),
        ]);
        PrepareDownloadJob::dispatch($dl->id);

        return response()->json($this->present($dl->fresh(['file.item'])), 201);
    }

    /** What the device stores: signed URLs for the MP4, poster and sanitized subtitles. */
    public function manifest(Request $request, string $uuid)
    {
        $dl = $this->own($request, $uuid);
        abort_unless($dl->status === 'ready' && $dl->expires_at->isFuture(), 404);
        $u = $request->user()->id;
        $p = $dl->profile_id;
        $item = $dl->file->item;

        return $this->present($dl) + [
            'video' => $this->signer->url('dl', $dl->id, $u, $p, 'video.mp4', 3600),
            'poster' => $item->poster_path ? $this->signer->url('img', $item->id, $u, $p, 'poster', 3600) : null,
            'subtitles' => $dl->file->subtitles->whereIn('id', $dl->subtitle_ids ?? [])->map(fn ($s) => ['id' => $s->id, 'label' => $s->label, 'language' => $s->language, 'url' => $this->signer->url('sub', $s->id, $u, $p, 'subtitle.vtt', 3600)])->values(),
        ];
    }

    /** Called by the device whenever it is online: renew, sync positions, and tell it what to delete. */
    public function sync(Request $request)
    {
        $items = $request->validate(['items' => ['array'], 'items.*.uuid' => ['required', 'string'], 'items.*.position' => ['nullable', 'numeric']])['items'] ?? [];
        $user = $request->user();
        $profile = $request->attributes->get('profile');
        $out = [];
        foreach ($items as $it) {
            $dl = Download::with('file.item', 'file.episode')->where('uuid', $it['uuid'])->where('user_id', $user->id)->first();
            $allowed = $dl && $user->downloads_enabled && $dl->status === 'ready' && $dl->expires_at->isFuture()
                && $dl->file && $dl->file->viewableBy($dl->profile_id === $profile->id ? $profile : \App\Modules\Core\Models\Profile::find($dl->profile_id));
            if (! $allowed) {
                $dl?->update(['status' => 'revoked']);
                if ($dl?->output_path) {
                    @unlink($dl->output_path);
                }
                $out[$it['uuid']] = ['action' => 'delete'];

                continue;
            }
            if (isset($it['position'])) {
                $dl->update(['position' => $it['position']]);
                WatchProgress::updateOrCreate(['profile_id' => $dl->profile_id, 'media_file_id' => $dl->media_file_id], [
                    'media_item_id' => $dl->file->media_item_id, 'episode_id' => $dl->file->episode_id, 'position' => $it['position'], 'duration' => $dl->file->duration,
                ]);
            }
            $dl->update(['expires_at' => now()->addDays(config('movexa.downloads.ttl_days'))]);
            $out[$it['uuid']] = ['action' => 'keep', 'expires_at' => $dl->expires_at->toIso8601String()];
        }

        return ['items' => $out];
    }

    public function destroy(Request $request, string $uuid)
    {
        $dl = $this->own($request, $uuid);
        if ($dl->output_path) {
            @unlink($dl->output_path);
        }
        $dl->update(['status' => 'revoked']);

        return ['ok' => true];
    }

    private function own(Request $request, string $uuid): Download
    {
        $dl = Download::with('file.item', 'file.episode', 'file.subtitles')->where('uuid', $uuid)->where('user_id', $request->user()->id)->first();
        abort_unless($dl && $dl->file && $dl->file->viewableBy($request->attributes->get('profile')), 404);

        return $dl;
    }

    private function present(Download $d): array
    {
        $item = $d->file->item;

        return ['uuid' => $d->uuid, 'status' => $d->status, 'progress' => $d->progress, 'size' => $d->size, 'quality' => $d->quality, 'position' => $d->position,
            'expires_at' => $d->expires_at?->toIso8601String(), 'title' => $item->title_ar && app()->getLocale() === 'ar' ? $item->title_ar : $item->title,
            'episode' => $d->file->episode ? ['season' => $d->file->episode->season?->number, 'number' => $d->file->episode->number] : null, 'duration' => $d->file->duration];
    }
}
