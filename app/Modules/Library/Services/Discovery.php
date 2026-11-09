<?php

namespace App\Modules\Library\Services;

use App\Modules\Core\Models\Profile;
use App\Modules\Core\Services\MediaSigner;
use App\Modules\Library\Models\MediaItem;
use Illuminate\Support\Facades\DB;

/** Banner, poster wall and Top 10 — always through the profile's access policy (server side). */
class Discovery
{
    public function __construct(private MediaPresenter $present, private MediaSigner $signer) {}

    /** 5–8 titles: admin-pinned first, then newest, then best-rated not yet watched. */
    public function banner(Profile $profile): array
    {
        $watched = DB::table('watch_history')->where('profile_id', $profile->id)->pluck('media_item_id')->all();
        $ids = collect();
        foreach ([
            MediaItem::visibleTo($profile)->where('featured', true)->whereNotNull('backdrop_path')->latest('updated_at'),
            MediaItem::visibleTo($profile)->whereNotNull('backdrop_path')->latest(),
            MediaItem::visibleTo($profile)->whereNotNull('backdrop_path')->whereNotIn('id', $watched)->orderByDesc('vote_average'),
        ] as $q) {
            $ids = $ids->merge($q->limit(8)->pluck('id'))->unique();
        }
        $ids = $ids->take(8);
        $items = MediaItem::whereIn('id', $ids)->get()->sortBy(fn ($i) => $ids->search($i->id))->values();
        $list = DB::table('my_list')->where('profile_id', $profile->id)->whereIn('media_item_id', $ids)->pluck('media_item_id')->flip();
        $ar = app()->getLocale() === 'ar';

        return $items->map(fn ($i) => $this->present->card($i, $profile) + [
            'overview' => $ar ? ($i->overview_ar ?: $i->overview) : ($i->overview ?: $i->overview_ar),
            'preview' => $i->preview_path ? $this->signer->url('img', $i->id, auth()->id() ?? $profile->user_id, $profile->id, 'preview') : null,
            'in_list' => $list->has($i->id),
        ])->all();
    }

    /** Up to 40 small posters for the animated background. */
    public function wall(Profile $profile): array
    {
        return MediaItem::visibleTo($profile)->whereNotNull('poster_path')->inRandomOrder()->limit(40)->get()
            ->map(fn ($i) => ['src' => $this->signer->url('img', $i->id, auth()->id() ?? $profile->user_id, $profile->id, 'poster_small'), 'color' => $i->dominant_color])->all();
    }


    /** Most-watched in the last N days (counted views only), aggregated, per type. Hidden below 5 titles. */
    public function top10(Profile $profile, string $type): array
    {
        $counts = DB::table('view_events')->join('media_items', 'media_items.id', '=', 'view_events.media_item_id')
            ->where('media_items.type', $type)->where('view_events.counted_at', '>=', now()->subDays(config('movexa.discovery.top10_days')))
            ->groupBy('view_events.media_item_id')->selectRaw('view_events.media_item_id as id, count(*) as views')->orderByDesc('views')->pluck('views', 'id');
        if ($counts->isEmpty()) {
            return [];
        }
        $items = MediaItem::visibleTo($profile)->whereIn('media_items.id', $counts->keys())->get()->keyBy('id');
        $ranked = $counts->keys()->filter(fn ($id) => $items->has($id))->take(10)->values();
        if ($ranked->count() < 5) {
            return []; // not enough real data → no row (never fake numbers)
        }

        return $ranked->map(fn ($id, $k) => $this->present->card($items[$id], $profile) + ['rank' => $k + 1])->all();
    }
}
