<?php

namespace App\Modules\Library\Services;

use App\Modules\Library\Models\Episode;
use App\Modules\Library\Models\MediaItem;
use App\Modules\Library\Services\Security\ImageSanitizer;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/** TMDB metadata (Arabic + English). Optional: everything can also be edited manually offline. */
class MetadataService
{
    public function __construct(private ImageSanitizer $images) {}

    public function enabled(): bool
    {
        return (bool) config('movexa.tmdb.key');
    }

    private function get(string $path, array $query = []): ?array
    {
        $key = config('movexa.tmdb.key');
        $req = Http::timeout(15)->acceptJson();
        // v4 read tokens are JWTs (Bearer); v3 keys go in the query string.
        $req = str_starts_with((string) $key, 'eyJ') ? $req->withToken($key) : $req;
        if (! str_starts_with((string) $key, 'eyJ')) {
            $query['api_key'] = $key;
        }
        $r = $req->get(config('movexa.tmdb.base').$path, $query);

        return $r->successful() ? $r->json() : null;
    }

    public function search(string $type, string $title, ?int $year): array
    {
        $kind = $type === 'show' ? 'tv' : 'movie';
        $q = ['query' => $title, 'language' => 'en-US'];
        if ($year) {
            $q[$kind === 'tv' ? 'first_air_date_year' : 'year'] = $year;
        }

        return $this->get("/search/{$kind}", $q)['results'] ?? [];
    }

    public function refresh(MediaItem $item, ?int $tmdbId = null): bool
    {
        if (! $this->enabled()) {
            return false;
        }
        $kind = $item->type === 'show' ? 'tv' : 'movie';
        $tmdbId ??= $item->tmdb_id ?: ($this->search($item->type, $item->title, $item->year)[0]['id'] ?? null);
        if (! $tmdbId) {
            return false;
        }
        $append = $kind === 'tv' ? 'credits,content_ratings' : 'credits,release_dates';
        $en = $this->get("/{$kind}/{$tmdbId}", ['language' => 'en-US', 'append_to_response' => $append]);
        $ar = $this->get("/{$kind}/{$tmdbId}", ['language' => 'ar']);
        if (! $en) {
            return false;
        }
        [$cert, $age] = $this->certification($en, $kind);
        $date = $en['release_date'] ?? $en['first_air_date'] ?? null;

        $item->fill([
            'tmdb_id' => $tmdbId,
            'title' => $en['title'] ?? $en['name'] ?? $item->title,
            'title_ar' => ($ar['title'] ?? $ar['name'] ?? null) ?: null,
            'original_title' => $en['original_title'] ?? $en['original_name'] ?? null,
            'overview' => $en['overview'] ?: null,
            'overview_ar' => ($ar['overview'] ?? null) ?: null,
            'year' => $date ? (int) substr($date, 0, 4) : $item->year,
            'genres' => array_map(fn ($g) => $g['name'], $en['genres'] ?? []),
            'cast' => array_map(fn ($c) => ['name' => $c['name'], 'character' => $c['character'] ?? null], array_slice($en['credits']['cast'] ?? [], 0, 12)),
            'vote_average' => $en['vote_average'] ?? null,
            'certification' => $cert,
            'age_rating' => $age ?? $item->age_rating,
        ]);
        $item->poster_path = $this->download($ar['poster_path'] ?? $en['poster_path'] ?? null, 'w500', "items/{$item->id}/poster") ?? $item->poster_path;
        $item->backdrop_path = $this->download($en['backdrop_path'] ?? null, 'w1280', "items/{$item->id}/backdrop") ?? $item->backdrop_path;
        $item->save();

        if ($kind === 'tv') {
            foreach ($item->seasons as $season) {
                $s = $this->get("/tv/{$tmdbId}/season/{$season->number}", ['language' => 'en-US']);
                $sAr = $this->get("/tv/{$tmdbId}/season/{$season->number}", ['language' => 'ar']);
                $arEps = collect($sAr['episodes'] ?? [])->keyBy('episode_number');
                foreach ($s['episodes'] ?? [] as $e) {
                    Episode::where('season_id', $season->id)->where('number', $e['episode_number'])->update([
                        'title' => $e['name'] ?? null, 'title_ar' => $arEps[$e['episode_number']]['name'] ?? null, 'overview' => $e['overview'] ?? null,
                    ]);
                }
            }
        }

        return true;
    }

    /** Maps US/regional certifications to a numeric minimum age. */
    public static function ageFromCertification(?string $cert): ?int
    {
        $c = strtoupper(trim((string) $cert));

        return match (true) {
            $c === '' => null,
            in_array($c, ['G', 'TV-Y', 'TV-G', 'U', '0', 'ALL', 'FSK 0'], true) => 0,
            in_array($c, ['TV-Y7', 'TV-Y7-FV', 'PG', 'TV-PG', '6', '7', 'FSK 6'], true) => 7,
            in_array($c, ['12', '12A', 'FSK 12'], true) => 12,
            in_array($c, ['PG-13', '13', '13+'], true) => 13,
            in_array($c, ['TV-14', '14', '15', '16', 'FSK 16'], true) => 16,
            in_array($c, ['R', 'NC-17', 'TV-MA', '18', '18+', 'X', 'FSK 18'], true) => 18,
            (bool) preg_match('/^(\d{1,2})\+?$/', $c, $m) => min(18, (int) $m[1]),
            default => null,
        };
    }

    private function certification(array $data, string $kind): array
    {
        $country = config('movexa.tmdb.country');
        if ($kind === 'tv') {
            $list = collect($data['content_ratings']['results'] ?? []);
            $cert = $list->firstWhere('iso_3166_1', $country)['rating'] ?? $list->first()['rating'] ?? null;
        } else {
            $list = collect($data['release_dates']['results'] ?? []);
            $entry = $list->firstWhere('iso_3166_1', $country) ?? $list->first();
            $cert = collect($entry['release_dates'] ?? [])->pluck('certification')->filter()->first();
        }

        return [$cert, self::ageFromCertification($cert)];
    }

    private function download(?string $path, string $size, string $dest): ?string
    {
        if (! $path) {
            return null;
        }
        $r = Http::timeout(30)->get(config('movexa.tmdb.image_base').$size.$path);
        if (! $r->successful()) {
            return null;
        }
        $tmp = sys_get_temp_dir().'/mx-img-'.Str::random(8);
        file_put_contents($tmp, $r->body());
        try {
            $abs = $this->images->sanitize($tmp, config('movexa.paths.images').'/'.$dest); // re-encode, strip EXIF
        } finally {
            @unlink($tmp);
        }

        return ltrim(str_replace(config('movexa.paths.images'), '', $abs), '/');
    }
}
