<?php

namespace App\Modules\Core\Services;

use App\Modules\Core\Models\AccessBlock;
use App\Modules\Core\Models\Profile;
use App\Modules\Library\Models\Episode;
use App\Modules\Library\Models\MediaFile;
use App\Modules\Library\Models\MediaItem;
use App\Modules\LiveTV\Models\Channel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The single access policy: age limit + custom blocks. Blocks win over everything.
 * Every check FAILS CLOSED: an exception means "not allowed" / "nothing visible".
 * Callers return 404 (never mention the block).
 */
class AccessPolicy
{

    /** @return array{items: array<int,true>, seasons: array<int,true>, episodes: array<int,true>, channels: array<int,true>, categories: array<string,true>, failed: bool} */
    public function blocked(Profile $profile): array
    {
        $store = request()->attributes->get('access_policy') ?? [];
        if (isset($store[$profile->id])) {
            return $store[$profile->id];
        }
        try {
            $blocks = AccessBlock::active()->forProfile($profile)->get();
            $items = $seasons = $episodes = $channels = $categories = [];
            $genres = $tags = $libraries = $collections = [];
            foreach ($blocks as $b) {
                match ($b->target_type) {
                    'item' => $items[$b->target_id] = true,
                    'season' => $seasons[$b->target_id] = true,
                    'episode' => $episodes[$b->target_id] = true,
                    'genre' => $genres[mb_strtolower($b->target_value)] = true,
                    'tag' => $tags[mb_strtolower($b->target_value)] = true,
                    'library' => $libraries[] = $b->target_id,
                    'collection' => $collections[] = $b->target_id,
                    'channel' => $channels[$b->target_id] = true,
                    'channel_category' => $categories[mb_strtolower($b->target_value)] = true,
                };
            }
            // Genre / tag / library / collection blocks resolve dynamically → new items are covered automatically.
            if ($libraries) {
                foreach (MediaItem::whereIn('library_id', $libraries)->pluck('id') as $id) {
                    $items[$id] = true;
                }
            }
            if ($collections) {
                foreach (DB::table('collection_media_item')->whereIn('collection_id', $collections)->pluck('media_item_id') as $id) {
                    $items[$id] = true;
                }
            }
            if ($genres || $tags) {
                foreach (MediaItem::query()->get(['id', 'genres', 'tags']) as $i) {
                    $g = array_map('mb_strtolower', $i->genres ?? []);
                    $t = array_map('mb_strtolower', $i->tags ?? []);
                    if (array_intersect_key(array_flip($g), $genres) || array_intersect_key(array_flip($t), $tags)) {
                        $items[$i->id] = true;
                    }
                }
            }

            $result = compact('items', 'seasons', 'episodes', 'channels', 'categories') + ['failed' => false];
            $store[$profile->id] = $result;
            request()->attributes->set('access_policy', $store);

            return $result;
        } catch (Throwable $e) {
            Log::error('access policy failed', ['error' => $e->getMessage()]);

            return ['items' => [], 'seasons' => [], 'episodes' => [], 'channels' => [], 'categories' => [], 'failed' => true];
        }
    }

    /** Items visible to the profile: ready, within age limit, not blocked. */
    public function scopeItems(Builder $q, Profile $profile): Builder
    {
        $b = $this->blocked($profile);
        if ($b['failed']) {
            return $q->whereRaw('1 = 0');
        }
        $q->whereHas('files', fn ($f) => $f->where('status', 'ready'));
        if (($limit = $profile->ageLimit()) !== null) {
            $q->whereNotNull('age_rating')->where('age_rating', '<=', $limit);
        }
        if (($libs = $profile->allowedLibraries()) !== null) {
            $q->whereIn($q->getModel()->getTable().'.library_id', $libs ?: [0]);
        }
        if ($b['items']) {
            $q->whereNotIn($q->getModel()->getTable().'.id', array_keys($b['items']));
        }

        return $q;
    }

    public function allowsItem(Profile $profile, ?MediaItem $item): bool
    {
        if (! $item) {
            return false;
        }
        $b = $this->blocked($profile);

        $libs = $profile->allowedLibraries();

        return ! $b['failed'] && ! isset($b['items'][$item->id]) && $profile->allowsRating($item->age_rating)
            && ($libs === null || in_array($item->library_id, $libs, true));
    }

    public function allowsEpisode(Profile $profile, ?Episode $episode): bool
    {
        if (! $episode) {
            return true;
        }
        $b = $this->blocked($profile);

        return ! $b['failed'] && ! isset($b['episodes'][$episode->id]) && ! isset($b['seasons'][$episode->season_id]);
    }

    public function allowsFile(Profile $profile, ?MediaFile $file): bool
    {
        try {
            return $file && $file->isReady() && $this->allowsItem($profile, $file->item) && $this->allowsEpisode($profile, $file->episode);
        } catch (Throwable) {
            return false;
        }
    }

    public function scopeChannels(Builder $q, Profile $profile): Builder
    {
        $b = $this->blocked($profile);
        if ($b['failed']) {
            return $q->whereRaw('1 = 0');
        }
        $q->where('enabled', true);
        if (($limit = $profile->ageLimit()) !== null) {
            $q->where(fn ($w) => $w->where('category', 'kids')->orWhere(fn ($r) => $r->whereNotNull('age_rating')->where('age_rating', '<=', $limit)));
        }
        if ($b['channels']) {
            $q->whereNotIn('id', array_keys($b['channels']));
        }
        if ($b['categories']) {
            $q->where(fn ($w) => $w->whereNull('category')->orWhereNotIn(DB::raw('LOWER(category)'), array_keys($b['categories'])));
        }

        return $q;
    }

    public function allowsChannel(Profile $profile, ?Channel $channel): bool
    {
        return $channel && $this->scopeChannels(Channel::query(), $profile)->whereKey($channel->id)->exists();
    }
}
