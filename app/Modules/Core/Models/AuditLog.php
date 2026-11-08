<?php

namespace App\Modules\Core\Models;

use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['user_id', 'action', 'details'];

    protected function casts(): array
    {
        return ['details' => 'array'];
    }

    public static function record(string $action, array $details = [], ?int $userId = null): void
    {
        static::create(['user_id' => $userId ?? auth()->id(), 'action' => $action, 'details' => $details]);
    }
}
