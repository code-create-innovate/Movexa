<?php

namespace App\Modules\Library\Models;

use App\Modules\Core\Models\Profile;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MediaItem extends Model
{
    protected $fillable = ['library_id', 'type', 'title', 'title_ar', 'original_title', 'overview', 'overview_ar', 'year', 'age_rating', 'certification', 'genres', 'cast', 'vote_average', 'tmdb_id', 'poster_path', 'backdrop_path', 'match_locked', 'match_key'];

    protected function casts(): array
    {
        return ['genres' => 'array', 'cast' => 'array', 'match_locked' => 'boolean', 'age_rating' => 'integer', 'vote_average' => 'float'];
    }

    public function library(): BelongsTo
    {
        return $this->belongsTo(Library::class);
    }

    public function files(): HasMany
    {
        return $this->hasMany(MediaFile::class);
    }

    public function seasons(): HasMany
    {
        return $this->hasMany(Season::class)->orderBy('number');
    }

    public function episodes(): HasMany
    {
        return $this->hasMany(Episode::class);
    }

    /** Only items with at least one READY file, filtered by the profile's age limit. */
    public function scopeVisibleTo(Builder $q, Profile $profile): Builder
    {
        $q->whereHas('files', fn ($f) => $f->where('status', 'ready'));
        if (($limit = $profile->ageLimit()) !== null) {
            $q->whereNotNull('age_rating')->where('age_rating', '<=', $limit);
        }

        return $q;
    }

    public function displayTitle(?string $locale = null): string
    {
        return ($locale ?? app()->getLocale()) === 'ar' && $this->title_ar ? $this->title_ar : $this->title;
    }
}
