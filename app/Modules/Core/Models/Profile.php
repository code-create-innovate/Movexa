<?php

namespace App\Modules\Core\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Hash;

class Profile extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'avatar_color', 'is_kid', 'max_age_rating', 'locale', 'subtitle_prefs', 'preferred_audio_lang', 'preferred_subtitle_lang', 'settings'];

    protected $hidden = ['pin_hash'];

    protected $appends = ['has_pin'];

    protected function casts(): array
    {
        return ['settings' => 'array', 'is_kid' => 'boolean', 'subtitle_prefs' => 'array', 'max_age_rating' => 'integer'];
    }

    protected static function newFactory()
    {
        return \Database\Factories\ProfileFactory::new();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function getHasPinAttribute(): bool
    {
        return $this->pin_hash !== null;
    }

    public function setPin(?string $pin): void
    {
        $this->pin_hash = $pin === null || $pin === '' ? null : Hash::make($pin);
    }

    public function checkPin(?string $pin): bool
    {
        return $this->pin_hash === null || ($pin !== null && Hash::check($pin, $this->pin_hash));
    }

    public const DEFAULT_SETTINGS = ['intro' => true, 'pause_screen' => true, 'ambient' => 'soft', 'poster_wall' => true, 'recap' => true];

    public function setting(string $key): mixed
    {
        return ($this->settings ?? [])[$key] ?? self::DEFAULT_SETTINGS[$key] ?? null;
    }

    public function allSettings(): array
    {
        return array_merge(self::DEFAULT_SETTINGS, $this->settings ?? []);
    }

    /** Effective max age rating, or null for unrestricted profiles. */
    public function ageLimit(): ?int
    {
        return $this->is_kid ? ($this->max_age_rating ?? 7) : null;
    }

    /** Kids see only content with a KNOWN rating within the limit. Unknown rating = hidden. */
    public function allowsRating(?int $rating): bool
    {
        $limit = $this->ageLimit();

        return $limit === null || ($rating !== null && $rating <= $limit);
    }
}
