<?php

namespace App\Modules\Library\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Library\Models\MediaItem;
use App\Modules\Library\Services\MediaPresenter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * "شن نتفرج؟" — a weighted random pick. Server-side rules: access policy (age + blocks), only READY files,
 * excludes titles the profile fully watched, applies the optional filters, avoids repeating recent picks in this session.
 */
class PickController extends Controller
{
    public function __invoke(Request $request, MediaPresenter $present)
    {
        $profile = $request->attributes->get('profile');
        $f = $request->validate([
            'type' => ['nullable', 'in:movie,show'], 'genre' => ['nullable', 'string', 'max:60'], 'max_minutes' => ['nullable', 'integer', 'min:1'],
            'min_vote' => ['nullable', 'numeric', 'between:0,10'], 'lang' => ['nullable', 'string', 'max:8'],
        ]);
        $q = MediaItem::visibleTo($profile)->with(['files' => fn ($x) => $x->where('status', 'ready')->with('streams', 'subtitles')]);
        if (! empty($f['type'])) {
            $q->where('type', $f['type']);
        }
        if (! empty($f['min_vote'])) {
            $q->where('vote_average', '>=', $f['min_vote']);
        }
        // Fully watched: movies with a completed view; shows where every episode was completed.
        $done = DB::table('watch_progress')->where('profile_id', $profile->id)->where('completed', true)->pluck('media_file_id')->flip();
        $history = DB::table('watch_history')->where('profile_id', $profile->id)->pluck('media_item_id')->flip();
        $recent = $request->session()->get('pick_recent', []);

        $taste = MediaItem::whereIn('id', $history->keys())->pluck('genres')->flatten()->filter()->countBy();
        $lang = isset($f['lang']) ? strtolower(substr($f['lang'], 0, 2)) : null;

        $candidates = $q->get()->filter(function ($i) use ($f, $done, $history, $recent, $lang) {
            if (in_array($i->id, $recent, true)) {
                return false;
            }
            $files = $i->files;
            if ($i->type === 'movie' && ($history->has($i->id) || $files->contains(fn ($x) => $done->has($x->id)))) {
                return false;
            }
            if ($i->type === 'show' && $files->every(fn ($x) => $done->has($x->id))) {
                return false;
            }
            if (! empty($f['genre']) && ! in_array(mb_strtolower($f['genre']), array_map('mb_strtolower', $i->genres ?? []), true)) {
                return false;
            }
            if (! empty($f['max_minutes']) && ! $files->contains(fn ($x) => $x->duration && $x->duration <= $f['max_minutes'] * 60)) {
                return false;
            }
            if ($lang && ! $files->contains(fn ($x) => $x->streams->contains(fn ($s) => $s->type === 'audio' && str_starts_with(strtolower((string) $s->language), $lang))
                || $x->subtitles->contains(fn ($s) => str_starts_with(strtolower((string) $s->language), $lang)) || ($lang === 'ar' && $x->streams->contains(fn ($s) => $s->language === 'ara')))) {
                return false;
            }

            return true;
        })->values();

        if ($candidates->isEmpty()) {
            return ['item' => null];
        }
        // Weight: 1 + how often the profile watched each of the title's genres.
        $weights = $candidates->map(fn ($i) => 1 + collect($i->genres ?? [])->sum(fn ($g) => $taste[$g] ?? 0));
        $r = random_int(1, max(1, (int) $weights->sum()));
        $pick = $candidates->first();
        foreach ($candidates as $k => $c) {
            $r -= $weights[$k];
            if ($r <= 0) {
                $pick = $c;
                break;
            }
        }
        $request->session()->put('pick_recent', array_slice(array_merge([$pick->id], $recent), 0, 10));
        $file = $pick->type === 'movie' ? $pick->files->first() : $pick->files->sortBy(fn ($x) => $done->has($x->id))->first();

        return ['item' => $present->card($pick, $profile) + [
            'overview' => app()->getLocale() === 'ar' ? ($pick->overview_ar ?: $pick->overview) : ($pick->overview ?: $pick->overview_ar),
            'file_id' => $file?->id,
        ]];
    }
}
