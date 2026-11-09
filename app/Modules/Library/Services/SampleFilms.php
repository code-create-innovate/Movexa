<?php

namespace App\Modules\Library\Services;

use App\Modules\Library\Models\Library;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

/**
 * Ten legally free films for trying Movexa: Blender open movies (Creative Commons Attribution) and
 * feature films in the public domain, downloaded only from the Blender Foundation and the Internet Archive.
 * Nothing else is fetched: no search engines, no streaming sites, no torrents.
 */
class SampleFilms
{
    public const ARCHIVE = 'https://archive.org';

    /** title, year, license, optional official direct URL, search title on archive.org, accepted length in minutes */
    public const FILMS = [
        ['Big Buck Bunny', 2008, 'CC BY 3.0 — Blender Foundation', 'https://download.blender.org/peach/bigbuckbunny_movies/big_buck_bunny_1080p_h264.mov', 'Big Buck Bunny', [8, 12]],
        ['Sintel', 2010, 'CC BY 3.0 — Blender Foundation', 'https://download.blender.org/durian/movies/Sintel.2010.1080p.mkv', 'Sintel', [12, 17]],
        ['Tears of Steel', 2012, 'CC BY 3.0 — Blender Foundation', 'https://download.blender.org/demo/movies/ToS/tears_of_steel_1080p.mov', 'Tears of Steel', [10, 15]],
        ['Elephants Dream', 2006, 'CC BY 2.5 — Blender Foundation', null, 'Elephants Dream', [9, 13]],
        ['Night of the Living Dead', 1968, 'Public domain (published without a copyright notice)', null, 'Night of the Living Dead', [85, 105]],
        ['Charade', 1963, 'Public domain (invalid copyright notice)', null, 'Charade', [100, 120]],
        ['His Girl Friday', 1940, 'Public domain (copyright not renewed)', null, 'His Girl Friday', [85, 100]],
        ['Nosferatu', 1922, 'Public domain (published 1922)', null, 'Nosferatu', [60, 100]],
        ['The General', 1926, 'Public domain (published 1926)', null, 'The General', [65, 90]],
        ['Detour', 1945, 'Public domain (copyright not renewed)', null, 'Detour', [60, 75]],
    ];

    public function directory(): string
    {
        return rtrim(config('movexa.paths.library'), '/').'/free-films';
    }

    /** The "Free films" library (created on first use, watched like any other library). */
    public function library(): Library
    {
        File::ensureDirectoryExists($this->directory());

        return Library::firstOrCreate(['path' => $this->directory()], ['name' => 'Free films', 'type' => 'movies', 'source' => 'local', 'watch' => true]);
    }

    public static function folder(array $film): string
    {
        return "{$film[0]} ({$film[1]})";
    }

    /** Where the film already is on disk, if downloaded. */
    public function existing(array $film): ?string
    {
        $dir = $this->directory().'/'.self::folder($film);

        return collect(File::isDirectory($dir) ? File::files($dir) : [])->first(fn ($f) => $f->getSize() > 1_000_000)?->getPathname();
    }

    /**
     * Download URL for a film: the official direct link if it answers, otherwise the best MP4 of the most
     * downloaded matching Internet Archive item whose length fits the film.
     *
     * @return array{url:string, ext:string, source:string}|null
     */
    public function resolve(array $film): ?array
    {
        [, , , $direct, $title, [$min, $max]] = $film;
        if ($direct && Http::timeout(20)->head($direct)->successful()) {
            return ['url' => $direct, 'ext' => strtolower(pathinfo(parse_url($direct, PHP_URL_PATH), PATHINFO_EXTENSION)), 'source' => parse_url($direct, PHP_URL_HOST)];
        }

        $hits = Http::timeout(30)->get(self::ARCHIVE.'/advancedsearch.php', [
            'q' => 'title:("'.$title.'") AND mediatype:(movies)',
            'fl' => ['identifier'], 'sort' => ['downloads desc'], 'rows' => 8, 'output' => 'json',
        ])->json('response.docs') ?? [];

        foreach ($hits as $hit) {
            $id = $hit['identifier'] ?? null;
            if (! is_string($id) || ! preg_match('/^[\w.\-]+$/', $id)) {
                continue;
            }
            $files = Http::timeout(30)->get(self::ARCHIVE.'/metadata/'.$id)->json('files') ?? [];
            $best = collect($files)
                ->filter(fn ($f) => str_ends_with(strtolower($f['name'] ?? ''), '.mp4') && ! str_contains($f['name'], '/'))
                ->filter(function ($f) use ($min, $max) {
                    $m = self::minutes($f['length'] ?? null);

                    return $m !== null && $m >= $min && $m <= $max;
                })
                ->sortByDesc(fn ($f) => (int) ($f['size'] ?? 0))
                ->first();
            if ($best) {
                return ['url' => self::ARCHIVE.'/download/'.$id.'/'.rawurlencode($best['name']), 'ext' => 'mp4', 'source' => "archive.org/details/{$id}"];
            }
        }

        return null;
    }

    /** archive.org lengths are "5880.23" or "01:38:00". */
    public static function minutes(mixed $length): ?float
    {
        if (is_numeric($length)) {
            return $length / 60;
        }
        if (is_string($length) && preg_match('/^(?:(\d+):)?(\d+):(\d+)(?:\.\d+)?$/', $length, $m)) {
            return (int) $m[1] * 60 + (int) $m[2] + (int) $m[3] / 60;
        }

        return null;
    }

    /**
     * Downloads outside the library (so the watcher never sees a half file), then moves it into
     * "free-films/Title (Year)/Title (Year).ext" with the license next to it.
     */
    public function download(array $film, array $resolved, ?callable $progress = null): string
    {
        $tmpDir = rtrim(config('movexa.paths.cache'), '/').'/sample-downloads';
        File::ensureDirectoryExists($tmpDir);
        $tmp = $tmpDir.'/'.md5($resolved['url']).'.part';
        @unlink($tmp);

        try {
            Http::timeout(0)->connectTimeout(30)->withOptions([
                'sink' => $tmp,
                'progress' => fn ($total, $done) => $progress && $total > 0 ? $progress($done, $total) : null,
            ])->get($resolved['url'])->throw();

            if (! is_file($tmp) || filesize($tmp) < 1_000_000) {
                throw new \RuntimeException('download too small');
            }
            $name = self::folder($film);
            $dir = $this->directory().'/'.$name;
            File::ensureDirectoryExists($dir);
            File::put($dir.'/LICENSE.txt', "{$name}\nLicense: {$film[2]}\nSource: {$resolved['source']}\n");
            $dest = $dir.'/'.$name.'.'.$resolved['ext'];
            File::move($tmp, $dest);

            return $dest;
        } finally {
            @unlink($tmp);
        }
    }
}
