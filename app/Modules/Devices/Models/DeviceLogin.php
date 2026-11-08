<?php

namespace App\Modules\Devices\Models;

use Illuminate\Database\Eloquent\Model;

class DeviceLogin extends Model
{
    public const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // no 0/O/1/I

    protected $fillable = ['code', 'poll_hash', 'device_name', 'status', 'user_id', 'ip', 'expires_at'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime'];
    }

    public function isUsable(): bool
    {
        return $this->expires_at->isFuture() && in_array($this->status, ['pending', 'approved'], true);
    }

    public static function newCode(): string
    {
        do {
            $code = '';
            for ($i = 0; $i < 6; $i++) {
                $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }
        } while (static::where('code', $code)->exists());

        return $code;
    }
}
