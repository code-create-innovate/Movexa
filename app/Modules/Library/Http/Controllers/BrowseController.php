<?php

namespace App\Modules\Library\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Library\Models\Episode;
use App\Modules\Library\Models\MediaItem;
use App\Modules\Library\Services\MediaPresenter;
use App\Modules\Playback\Models\WatchProgress;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class BrowseController extends Controller
{
    public function __construct(private MediaPresenter $present) {}

    public function home(Request $request)
    {
        $profile = $request->attributes->get('profile');
        $visible = fn () => MediaItem::query()->visibleTo($profile);

        $continue = WatchProgress::with('item', 'episode.season')->where('profile_id', $profile->id)->where('completed', false)
            ->where('position', '>', 15)->latest('updated_at')->limit(20)->get()
            ->filter(fn ($p) => $p->file && $p->file->viewableBy($profile))
            ->map(fn ($p) => $this->present->card($p->item, $profile) + [
                'progress' => $p->duration ? min(1, $p->position / $p->duration) : 0,
                'file_id' => $p->media_file_id,
                'episode' => $p->episode ? ['season' => $p->episode->season->number, 'number' => $p->episode->number] : null,
            ])->values();

        $rows = [
            ['key' => 'continue', 'items' => $continue],
            ['key' => 'next_episode', 'items' => $this->nextEpisodes($profile)],
            ['key' => 'my_list', 'items' => $this->present->cards($visible()->whereIn('id', DB::table('my_list')->where('profile_id', $profile->id)->pluck('media_item_id'))->get(), $profile)],
            ['key' => 'recent', 'items' => $this->present->cards($visible()->latest()->limit(20)->get(), $profile)],
        ];
        $genres = $visible()->whereNotNull('genres')->pluck('genres')->flatten()->countBy()->sortDesc()->keys()->take(6);
        foreach ($genres as $g) {
            $items = $visible()->get()->filter(fn ($i) => in_array($g, $i->genres ?? [], true))->take(20);
            $rows[] = ['key' => 'genre', 'label' => $g, 'items' => $this->present->cards($items, $profile)];
        }
        $hero = $visible()->whereNotNull('backdrop_path')->inRandomOrder()->first();

        return Inertia::render('browse/home', [
            'hero' => $hero ? $this->present->card($hero, $profile) + ['overview' => app()->getLocale() === 'ar' ? ($hero->overview_ar ?: $hero->overview) : $hero->overview] : null,
            'rows' => array_values(array_filter($rows, fn ($r) => count($r['items']) > 0)),
        ]);
    }

    /** For each show in progress: the episode after the last one watched. */
    private function nextEpisodes($profile): array
    {
        $last = WatchProgress::with('episode.season')->where('profile_id', $profile->id)->whereNotNull('episode_id')
            ->latest('updated_at')->get()->unique('media_item_id')->take(10);
        $out = [];
        foreach ($last as $p) {
            if (! $p->completed || ! $p->episode) {
                continue;
            }
            $next = $this->episodeAfter($p->episode);
            $item = $next?->show;
            if ($next && $next->file && $item && $next->file->viewableBy($profile)) {
                $out[] = $this->present->card($item, $profile) + ['file_id' => $next->file->id, 'episode' => ['season' => $next->season->number, 'number' => $next->number]];
            }
        }

        return $out;
    }

    public static function episodeAfter(Episode $ep): ?Episode
    {
        $ep->loadMissing('season');

        return Episode::with('season', 'file', 'show')->join('seasons', 'seasons.id', '=', 'episodes.season_id')
            ->where('episodes.media_item_id', $ep->media_item_id)
            ->where(fn ($q) => $q->where('seasons.number', '>', $ep->season->number)
                ->orWhere(fn ($w) => $w->where('seasons.number', $ep->season->number)->where('episodes.number', '>', $ep->number)))
            ->whereHas('file')
            ->orderBy('seasons.number')->orderBy('episodes.number')->select('episodes.*')->first();
    }

    public function show(Request $request, MediaItem $item)
    {
        $profile = $request->attributes->get('profile');
        abort_unless(MediaItem::visibleTo($profile)->whereKey($item->id)->exists(), 404); // kids get 404, not 403
        $ar = app()->getLocale() === 'ar';
        $progress = WatchProgress::where('profile_id', $profile->id)->where('media_item_id', $item->id)->get()->keyBy('media_file_id');

        $files = $item->files()->where('status', 'ready')->with('streams', 'subtitles', 'item', 'episode')->get()->filter(fn ($f) => $f->viewableBy($profile))->values();
        $fileInfo = fn ($f) => $f ? [
            'id' => $f->id, 'duration' => $f->duration, 'height' => $f->height, 'sanitized' => $f->sanitized,
            'audio' => $f->streams->where('type', 'audio')->map(fn ($s) => ['language' => $s->language, 'title' => $s->title, 'codec' => $s->codec, 'channels' => $s->channels])->values(),
            'subtitles' => $f->subtitles->map(fn ($s) => ['id' => $s->id, 'language' => $s->language, 'label' => $s->label])->values(),
            'progress' => isset($progress[$f->id]) ? ['position' => $progress[$f->id]->position, 'completed' => $progress[$f->id]->completed] : null,
        ] : null;

        $policy = app(\App\Modules\Core\Services\AccessPolicy::class);
        $seasons = $item->type === 'show' ? $item->seasons()->with(['episodes.file.streams', 'episodes.file.subtitles'])->get()->map(fn ($s) => [
            'id' => $s->id,
            'number' => $s->number,
            'episodes' => $s->episodes->filter(fn ($e) => $e->file && $policy->allowsEpisode($profile, $e))->map(fn ($e) => [
                'id' => $e->id, 'number' => $e->number, 'title' => $ar && $e->title_ar ? $e->title_ar : $e->title,
                'overview' => $e->overview, 'file' => $fileInfo($e->file),
            ])->values(),
        ])->filter(fn ($s) => count($s['episodes']) > 0)->values() : [];

        return Inertia::render('browse/title', [
            'item' => $this->present->card($item, $profile) + [
                'overview' => $ar ? ($item->overview_ar ?: $item->overview) : ($item->overview ?: $item->overview_ar),
                'original_title' => $item->original_title,
                'certification' => $item->certification,
                'cast' => $item->cast ?? [],
                'in_list' => DB::table('my_list')->where('profile_id', $profile->id)->where('media_item_id', $item->id)->exists(),
                'rating' => DB::table('ratings')->where('profile_id', $profile->id)->where('media_item_id', $item->id)->value('value'),
            ],
            'file' => $item->type === 'movie' ? $fileInfo($files->first()) : null,
            'seasons' => $seasons,
        ]);
    }

    public function search(Request $request)
    {
        $profile = $request->attributes->get('profile');
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:100'], 'genre' => ['nullable', 'string', 'max:50'],
            'year' => ['nullable', 'integer'], 'min_vote' => ['nullable', 'numeric', 'between:0,10'], 'type' => ['nullable', 'in:movie,show'],
        ]);
        $q = MediaItem::visibleTo($profile);
        if ($term = trim($data['q'] ?? '')) {
            $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';
            $q->where(fn ($w) => $w->where('title', 'like', $like)->orWhere('title_ar', 'like', $like)->orWhere('original_title', 'like', $like));
        }
        foreach (['year' => 'year', 'type' => 'type'] as $k => $col) {
            if (! empty($data[$k])) {
                $q->where($col, $data[$k]);
            }
        }
        if (! empty($data['min_vote'])) {
            $q->where('vote_average', '>=', $data['min_vote']);
        }
        $items = $q->orderBy('title')->limit(60)->get();
        if (! empty($data['genre'])) {
            $items = $items->filter(fn ($i) => in_array($data['genre'], $i->genres ?? [], true));
        }
        $cards = $this->present->cards($items, $profile);

        if ($request->wantsJson()) {
            return ['results' => $cards];
        }

        return Inertia::render('browse/search', [
            'results' => $cards, 'filters' => $data,
            'genres' => MediaItem::visibleTo($profile)->pluck('genres')->flatten()->filter()->unique()->sort()->values(),
        ]);
    }

    public function toggleList(Request $request, MediaItem $item)
    {
        $profile = $request->attributes->get('profile');
        abort_unless(MediaItem::visibleTo($profile)->whereKey($item->id)->exists(), 404);
        $q = DB::table('my_list')->where('profile_id', $profile->id)->where('media_item_id', $item->id);
        $q->exists() ? $q->delete() : DB::table('my_list')->insert(['profile_id' => $profile->id, 'media_item_id' => $item->id, 'created_at' => now(), 'updated_at' => now()]);

        return back();
    }

    public function rate(Request $request, MediaItem $item)
    {
        $profile = $request->attributes->get('profile');
        abort_unless(MediaItem::visibleTo($profile)->whereKey($item->id)->exists(), 404);
        $v = $request->validate(['value' => ['nullable', 'in:-1,1,2']])['value'] ?? null;
        $v === null
            ? DB::table('ratings')->where('profile_id', $profile->id)->where('media_item_id', $item->id)->delete()
            : DB::table('ratings')->updateOrInsert(['profile_id' => $profile->id, 'media_item_id' => $item->id], ['value' => $v, 'updated_at' => now(), 'created_at' => now()]);

        return back();
    }

    public function history(Request $request)
    {
        $profile = $request->attributes->get('profile');
        $rows = DB::table('watch_history')->where('profile_id', $profile->id)->latest('watched_at')->limit(100)->get();
        $items = MediaItem::visibleTo($profile)->whereIn('id', $rows->pluck('media_item_id'))->get()->keyBy('id');

        return Inertia::render('browse/history', ['entries' => $rows->filter(fn ($r) => isset($items[$r->media_item_id]))
            ->map(fn ($r) => $this->present->card($items[$r->media_item_id], $profile) + ['watched_at' => $r->watched_at])->values()]);
    }
}
