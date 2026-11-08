<?php

namespace App\Modules\Devices\Models;

use Illuminate\Database\Eloquent\Model;

class Device extends Model
{
    protected $fillable = ['user_id', 'name', 'kind', 'session_id', 'last_seen_at', 'revoked_at'];

    protected $hidden = ['session_id'];

    protected function casts(): array
    {
        return ['last_seen_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    public static function nameFromAgent(?string $ua): string
    {
        $ua = (string) $ua;
        $os = match (true) {
            (bool) preg_match('/Tizen|Web0S|webOS|SMART-TV|SmartTV|BRAVIA|Android TV|AFT\w|CrKey/i', $ua) => 'TV',
            str_contains($ua, 'iPhone') => 'iPhone', str_contains($ua, 'iPad') => 'iPad', str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'Windows') => 'Windows', str_contains($ua, 'Mac OS') => 'Mac', str_contains($ua, 'Linux') => 'Linux', default => 'Device',
        };
        $br = match (true) {
            str_contains($ua, 'Edg/') => 'Edge', str_contains($ua, 'Firefox') => 'Firefox', str_contains($ua, 'Chrome') => 'Chrome', str_contains($ua, 'Safari') => 'Safari', default => '',
        };

        return trim("$os $br");
    }
}
