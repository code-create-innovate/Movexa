<?php

namespace App\Modules\Library\Models;

use App\Modules\Core\Models\Profile;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MediaItem extends Model
{
    protected $fillable = ['library_id', 'type', 'title', 'title_ar', 'original_title', 'overview', 'overview_ar', 'year', 'age_rating', 'certification', 'genres', 'cast', 'vote_average', 'tmdb_id', 'poster_path', 'backdrop_path', 'match_locked', 'match_key', 'dominant_color', 'tags', 'featured', 'preview_path'];

    protected function casts(): array
    {
        return ['tags' => 'array', 'featured' => 'boolean', 'genres' => 'array', 'cast' => 'array', 'match_locked' => 'boolean', 'age_rating' => 'integer', 'vote_average' => 'float'];
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

    /** Ready + age limit + custom blocks (single AccessPolicy, fails closed). */
    public function scopeVisibleTo(Builder $q, Profile $profile): Builder
    {
        return app(\App\Modules\Core\Services\AccessPolicy::class)->scopeItems($q, $profile);
    }

    public function collections(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(\App\Modules\Library\Models\Collection::class);
    }

    public function displayTitle(?string $locale = null): string
    {
        return ($locale ?? app()->getLocale()) === 'ar' && $this->title_ar ? $this->title_ar : $this->title;
    }
}
