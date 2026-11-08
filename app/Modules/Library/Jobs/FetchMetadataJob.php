<?php

namespace App\Modules\Library\Jobs;

use App\Modules\Library\Models\MediaItem;
use App\Modules\Library\Services\MetadataService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

class FetchMetadataJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public int $tries = 3;

    public function __construct(public int $itemId, public ?int $tmdbId = null) {}

    public function handle(MetadataService $meta): void
    {
        $item = MediaItem::find($this->itemId);
        if ($item && (! $item->tmdb_id || $this->tmdbId)) {
            $meta->refresh($item, $this->tmdbId);
        }
    }
}
