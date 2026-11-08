<?php

namespace App\Modules\Library\Services;

use App\Modules\Core\Models\Profile;
use App\Modules\Core\Services\MediaSigner;
use App\Modules\Library\Models\MediaItem;

class MediaPresenter
{
    public function __construct(private MediaSigner $signer) {}

    public function card(MediaItem $item, Profile $profile): array
    {
        $ar = app()->getLocale() === 'ar';

        return [
            'id' => $item->id,
            'type' => $item->type,
            'title' => $ar && $item->title_ar ? $item->title_ar : $item->title,
            'year' => $item->year,
            'age_rating' => $item->age_rating,
            'vote' => $item->vote_average,
            'genres' => $item->genres ?? [],
            'poster' => $item->poster_path ? $this->signer->url('img', $item->id, auth()->id() ?? $profile->user_id, $profile->id, 'poster') : null,
            'backdrop' => $item->backdrop_path ? $this->signer->url('img', $item->id, auth()->id() ?? $profile->user_id, $profile->id, 'backdrop') : null,
        ];
    }

    public function cards(iterable $items, Profile $profile): array
    {
        $out = [];
        foreach ($items as $i) {
            $out[] = $this->card($i, $profile);
        }

        return $out;
    }
}
