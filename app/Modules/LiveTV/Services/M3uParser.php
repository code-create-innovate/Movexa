<?php

namespace App\Modules\LiveTV\Services;

class M3uParser
{
    /** @return list<array{name:string,url:string,epg_id:?string,logo:?string,group:?string,number:?string}> */
    public function parse(string $content): array
    {
        $content = str_replace(["\r\n", "\r"], "\n", preg_replace('/^\xEF\xBB\xBF/', '', $content));
        $out = [];
        $current = null;
        foreach (explode("\n", $content) as $line) {
            $line = trim($line);
            if ($line === '' || $line === '#EXTM3U' || str_starts_with($line, '#EXTM3U ')) {
                continue;
            }
            if (str_starts_with($line, '#EXTINF')) {
                preg_match_all('/([a-z\-]+)="([^"]*)"/i', $line, $attrs, PREG_SET_ORDER);
                $a = [];
                foreach ($attrs as $m) {
                    $a[strtolower($m[1])] = $m[2];
                }
                $name = trim(substr($line, strrpos($line, ',') + 1));
                $current = [
                    'name' => $name !== '' ? $name : ($a['tvg-name'] ?? 'Channel'),
                    'epg_id' => ($a['tvg-id'] ?? '') ?: null,
                    'logo' => ($a['tvg-logo'] ?? '') ?: null,
                    'group' => ($a['group-title'] ?? '') ?: null,
                    'number' => ($a['tvg-chno'] ?? $a['channel-number'] ?? '') ?: null,
                ];

                continue;
            }
            if (str_starts_with($line, '#')) {
                continue;
            }
            if ($current && preg_match('#^(https?|rtsp|rtmp|udp|rtp)://#i', $line)) {
                $out[] = $current + ['url' => $line];
            }
            $current = null;
        }

        return $out;
    }

    public static function category(?string $group, string $name): ?string
    {
        $s = mb_strtolower(($group ?? '').' '.$name);

        return match (true) {
            (bool) preg_match('/sport|رياض|bein|football|كرة/u', $s) => 'sports',
            (bool) preg_match('/news|أخبار|اخبار|جزيرة|العربية/u', $s) => 'news',
            (bool) preg_match('/kid|child|cartoon|أطفال|اطفال|كرتون|spacetoon/u', $s) => 'kids',
            (bool) preg_match('/movie|cinema|أفلام|افلام/u', $s) => 'movies',
            (bool) preg_match('/music|موسيق|أغاني/u', $s) => 'music',
            (bool) preg_match('/doc|وثائق/u', $s) => 'documentary',
            default => $group ? mb_substr($group, 0, 32) : null,
        };
    }
}
