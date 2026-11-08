<?php

namespace App\Modules\Integrations\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Core\Services\MediaSigner;
use App\Modules\Library\Models\MediaItem;
use App\Modules\LiveTV\Models\LiveStream;
use App\Modules\Playback\Models\PlaybackSession;
use App\Modules\Playback\Models\TranscodeJob;
use App\Modules\Playback\Models\WatchProgress;
use Illuminate\Http\Request;

/** Read-only API for the HomeCore dashboard (token ability "homecore:read"). */
class HomeCoreController extends Controller
{
    public function continueWatching(Request $request, MediaSigner $signer)
    {
        $request->validate(['user' => ['required', 'string']]);
        $user = User::where('oidc_subject', $request->query('user'))->first();
        if (! $user) {
            return ['items' => []];
        }
        // HomeCore has no Movexa profile; use the user's first NON-kid profile.
        $profile = $user->profiles()->where('is_kid', false)->orderBy('id')->first();
        if (! $profile) {
            return ['items' => []];
        }
        $rows = WatchProgress::with('item', 'episode.season', 'file.item', 'file.episode')->where('profile_id', $profile->id)->where('completed', false)
            ->where('position', '>', 30)->latest('updated_at')->limit(20)->get()
            ->filter(fn ($p) => $p->file && $p->file->viewableBy($profile))->take(3)->values();

        return ['items' => $rows->map(fn ($p) => [
            'id' => $p->item->id,
            'title' => $p->item->title,
            'title_ar' => $p->item->title_ar,
            'episode' => $p->episode ? ['season' => $p->episode->season->number, 'number' => $p->episode->number] : null,
            'progress' => $p->duration ? round($p->position / $p->duration, 3) : null,
            // HomeCore renders posters through its own proxy endpoint, which calls /api/homecore/poster/{id}.
            'poster' => $p->item->poster_path ? url('/api/homecore/poster/'.$p->item->id.'?user='.urlencode($request->query('user'))) : null,
            'url' => url('/title/'.$p->item->id),
        ])];
    }

    public function poster(Request $request, MediaItem $item)
    {
        // Only posters of titles the given user's main profile may see.
        $profile = $this->profileFor((string) $request->query('user'));
        abort_unless($profile && app(\App\Modules\Core\Services\AccessPolicy::class)->allowsItem($profile, $item), 404);
        abort_unless($item->poster_path, 404);
        $path = config('movexa.paths.images').'/'.$item->poster_path;
        abort_unless(is_file($path), 404);

        return response()->file($path, ['Content-Type' => str_ends_with($path, '.webp') ? 'image/webp' : 'image/jpeg', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, max-age=86400']);
    }

    public function nowStreaming()
    {
        $sessions = PlaybackSession::active()->with('user:id,name')->get();

        return [
            'count' => $sessions->count(),
            'sessions' => $sessions->map(fn ($s) => ['user' => $s->user->name, 'method' => $s->method])->values(),
            'live_channels' => LiveStream::where('status', '!=', 'stopped')->count(),
        ];
    }

    public function transcodes()
    {
        return ['jobs' => TranscodeJob::with('file.item:id,title')->whereIn('status', ['queued', 'running'])->get()
            ->map(fn ($j) => ['title' => $j->file?->item?->title, 'mode' => $j->mode, 'status' => $j->status, 'progress' => $j->progress])];
    }

    /** For HomeCore's "mx <query>" shortcut. Adult-filtering is HomeCore's own concern: only titles + links, no media. */
    private function profileFor(string $sub): ?\App\Modules\Core\Models\Profile
    {
        $user = $sub !== '' ? User::where('oidc_subject', $sub)->first() : null;

        return $user?->profiles()->where('is_kid', false)->orderBy('id')->first();
    }

    public function search(Request $request)
    {
        $q = trim((string) $request->query('q'));
        $profile = $this->profileFor((string) $request->query('user'));
        if (mb_strlen($q) < 2 || ! $profile) {
            return ['results' => []]; // no user → nothing (policy is per person)
        }
        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $q).'%';
        $items = MediaItem::visibleTo($profile)
            ->where(fn ($w) => $w->where('title', 'like', $like)->orWhere('title_ar', 'like', $like)->orWhere('original_title', 'like', $like))
            ->limit(10)->get(['id', 'type', 'title', 'title_ar', 'year']);

        return ['results' => $items->map(fn ($i) => $i->only('id', 'type', 'title', 'title_ar', 'year') + ['url' => url('/title/'.$i->id)])];
    }
}
