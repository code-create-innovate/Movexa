<?php

namespace App\Modules\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ApiToken extends Model
{
    protected $fillable = ['name', 'token_hash', 'abilities', 'expires_at'];

    protected function casts(): array
    {
        return ['abilities' => 'array', 'expires_at' => 'datetime', 'last_used_at' => 'datetime'];
    }

    /** @return array{0: ApiToken, 1: string} model and the plain token (shown once). */
    public static function issue(string $name, array $abilities, ?\DateTimeInterface $expires = null): array
    {
        $plain = 'mx_'.Str::random(48);
        $token = static::create([
            'name' => $name,
            'token_hash' => hash('sha256', $plain),
            'abilities' => array_values($abilities),
            'expires_at' => $expires,
        ]);

        return [$token, $plain];
    }

    public static function findByPlain(string $plain): ?self
    {
        $token = static::where('token_hash', hash('sha256', $plain))->first();
        if (! $token || ($token->expires_at && $token->expires_at->isPast())) {
            return null;
        }

        return $token;
    }

    public function can(string $ability): bool
    {
        return in_array($ability, $this->abilities ?? [], true);
    }
}
