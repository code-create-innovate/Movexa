<?php

namespace App\Modules\Library\Services;

use App\Modules\Library\Models\Episode;
use App\Modules\Library\Models\Library;
use App\Modules\Library\Models\MediaFile;
use App\Modules\Library\Models\MediaItem;
use App\Modules\Library\Models\Season;

/** Attaches a file to a movie or show/season/episode based on its name. */
class LibraryIndexer
{
    public function __construct(private FilenameParser $parser) {}

    public function index(MediaFile $file, string $name, ?Library $library): void
    {
        $p = $this->parser->parse($name);
        $libraryId = $library?->id ?? Library::where('type', $p['type'] === 'episode' ? 'shows' : 'movies')->value('id');
        if (! $libraryId) {
            return;
        }
        $isShow = $p['type'] === 'episode';
        $key = FilenameParser::matchKey($p['title'], $isShow ? null : $p['year']);

        $item = MediaItem::firstOrCreate(
            ['library_id' => $libraryId, 'type' => $isShow ? 'show' : 'movie', 'match_key' => $key],
            ['title' => $p['title'] ?: $name, 'year' => $p['year']],
        );

        if ($isShow) {
            $season = Season::firstOrCreate(['media_item_id' => $item->id, 'number' => $p['season']]);
            $episode = Episode::firstOrCreate(['season_id' => $season->id, 'number' => $p['episode']], ['media_item_id' => $item->id]);
            $file->update(['media_item_id' => $item->id, 'episode_id' => $episode->id, 'library_id' => $libraryId]);
        } else {
            $file->update(['media_item_id' => $item->id, 'library_id' => $libraryId]);
        }
    }

    /** Manual correction: move a file to another (new or existing) item. */
    public function rematch(MediaFile $file, string $type, string $title, ?int $year, ?int $season = null, ?int $episode = null): MediaItem
    {
        $old = $file->item;
        $item = MediaItem::firstOrCreate(
            ['library_id' => $file->library_id, 'type' => $type, 'match_key' => FilenameParser::matchKey($title, $type === 'movie' ? $year : null)],
            ['title' => $title, 'year' => $year],
        );
        $item->update(['match_locked' => true]);
        $episodeId = null;
        if ($type === 'show') {
            $s = Season::firstOrCreate(['media_item_id' => $item->id, 'number' => $season ?? 1]);
            $episodeId = Episode::firstOrCreate(['season_id' => $s->id, 'number' => $episode ?? 1], ['media_item_id' => $item->id])->id;
        }
        $file->update(['media_item_id' => $item->id, 'episode_id' => $episodeId]);
        if ($old && $old->id !== $item->id && $old->files()->count() === 0) {
            $old->delete();
        }

        return $item;
    }
}
