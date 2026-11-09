<?php

namespace App\Models;

use App\Modules\Core\Models\Profile;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Per-user permissions (admins implicitly have all of them).
     *  watching : live, download, party
     *  managing : upload, library, quarantine, live_manage, blocks, stats, users
     */
    public const PERMISSIONS = ['live', 'download', 'party', 'upload', 'library', 'quarantine', 'live_manage', 'blocks', 'stats', 'users'];

    public const DEFAULT_PERMISSIONS = ['live', 'party'];

    protected $fillable = ['name', 'username', 'email', 'password', 'locale', 'is_admin', 'is_emergency_admin', 'oidc_subject', 'max_concurrent_streams',
        'downloads_quota_mb', 'downloads_max', 'permissions', 'max_age_rating', 'allowed_library_ids', 'disabled_at'];

    protected $hidden = ['password', 'remember_token', 'two_factor_secret'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean',
            'is_emergency_admin' => 'boolean',
            'two_factor_secret' => 'encrypted',
            'two_factor_confirmed_at' => 'datetime',
            'permissions' => 'array',
            'allowed_library_ids' => 'array',
            'max_age_rating' => 'integer',
            'disabled_at' => 'datetime',
        ];
    }

    public function profiles(): HasMany
    {
        return $this->hasMany(Profile::class);
    }

    /** The account's single, internal viewing profile (progress, list, preferences). Created on demand. */
    public function primaryProfile(): Profile
    {
        return $this->profiles()->orderBy('id')->first() ?? $this->profiles()->create(['name' => $this->name]);
    }

    public function hasPermission(string $permission): bool
    {
        if ($this->disabled_at) {
            return false;
        }

        return $this->is_admin || in_array($permission, $this->permissions ?? self::DEFAULT_PERMISSIONS, true);
    }

    /** @return array<string,bool> */
    public function effectivePermissions(): array
    {
        return collect(self::PERMISSIONS)->mapWithKeys(fn ($p) => [$p => $this->hasPermission($p)])->all();
    }

    public function canManageAnything(): bool
    {
        return collect(['upload', 'library', 'quarantine', 'live_manage', 'blocks', 'stats', 'users'])->contains(fn ($p) => $this->hasPermission($p));
    }

    public function hasTwoFactor(): bool
    {
        return $this->two_factor_secret !== null && $this->two_factor_confirmed_at !== null;
    }

    protected static function booted(): void
    {
        static::created(fn (User $u) => $u->profiles()->exists() || $u->profiles()->create(['name' => $u->name]));
    }
}
