<?php

namespace App\Modules\LiveTV\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Services\MediaSigner;
use App\Modules\LiveTV\Models\Channel;
use App\Modules\LiveTV\Models\EpgProgram;
use App\Modules\LiveTV\Models\LiveStream;
use App\Modules\LiveTV\Services\LiveStreamManager;
use App\Modules\Playback\Models\PlaybackSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use RuntimeException;

class LiveController extends Controller
{
    public function __construct(private LiveStreamManager $manager, private MediaSigner $signer) {}

    public function index(Request $request)
    {
        $profile = $request->attributes->get('profile');
        $channels = Channel::visibleTo($profile)->orderBy('sort')->orderBy('number')->orderBy('name')->get();
        $fav = DB::table('favorite_channels')->where('profile_id', $profile->id)->pluck('sort', 'channel_id');
        $epgIds = $channels->pluck('epg_id')->filter();
        $programs = EpgProgram::whereIn('epg_id', $epgIds)->where('ends_at', '>', now())->where('starts_at', '<', now()->addHours(12))
            ->orderBy('starts_at')->get()->groupBy('epg_id');

        return Inertia::render('live/index', [
            'channels' => $channels->map(fn ($c) => [
                'id' => $c->id, 'name' => $c->name, 'number' => $c->number, 'category' => $c->category, 'favorite' => isset($fav[$c->id]),
                'now' => ($p = $programs[$c->epg_id] ?? collect())->first() ? $this->program($p->first()) : null,
                'next' => $p->skip(1)->first() ? $this->program($p->skip(1)->first()) : null,
                'schedule' => $p->take(8)->map(fn ($x) => $this->program($x))->values(),
            ])->values(),
        ]);
    }

    public function watch(Request $request, Channel $channel)
    {
        $profile = $request->attributes->get('profile');
        abort_unless(Channel::visibleTo($profile)->whereKey($channel->id)->exists(), 404);
        $user = $request->user();
        $limit = $user->max_concurrent_streams ?? config('movexa.limits.streams_per_user');
        if (PlaybackSession::active()->where('user_id', $user->id)->where('channel_id', '!=', $channel->id)->count() >= $limit) {
            return response()->json(['message' => 'stream_limit'], 429);
        }
        try {
            $stream = $this->manager->acquire($channel, $request->session()->getId());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], $e->getMessage() === 'tuner_limit' ? 409 : 502);
        }
        $h = hash('sha256', 'live|'.$request->session()->getId());
        $uuid = substr($h, 0, 8).'-'.substr($h, 8, 4).'-'.substr($h, 12, 4).'-'.substr($h, 16, 4).'-'.substr($h, 20, 12);
        PlaybackSession::updateOrCreate(['uuid' => $uuid], [
            'user_id' => $user->id, 'profile_id' => $profile->id, 'channel_id' => $channel->id, 'method' => 'live', 'last_seen_at' => now(), 'client' => mb_substr((string) $request->userAgent(), 0, 190),
        ]);

        return ['stream' => $stream->id, 'src' => $this->signer->url('live', $stream->id, $user->id, $profile->id, 'index.m3u8', 12 * 3600), 'status' => $this->manager->status($stream)];
    }

    public function status(Request $request, LiveStream $stream)
    {
        $this->manager->heartbeat($stream, $request->session()->getId());

        return $this->manager->status($stream);
    }

    public function leave(Request $request, LiveStream $stream)
    {
        $this->manager->release($stream, $request->session()->getId());

        return ['ok' => true];
    }

    public function favorite(Request $request, Channel $channel)
    {
        $profile = $request->attributes->get('profile');
        $q = DB::table('favorite_channels')->where('profile_id', $profile->id)->where('channel_id', $channel->id);
        $q->exists() ? $q->delete() : DB::table('favorite_channels')->insert(['profile_id' => $profile->id, 'channel_id' => $channel->id]);

        return back();
    }

    private function program(EpgProgram $p): array
    {
        return ['id' => $p->id, 'title' => $p->title, 'description' => $p->description, 'starts_at' => $p->starts_at->toIso8601String(), 'ends_at' => $p->ends_at->toIso8601String()];
    }
}
