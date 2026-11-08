<?php

namespace App\Modules\LiveTV\Services;

use App\Modules\LiveTV\Models\Channel;
use App\Modules\LiveTV\Models\LiveSource;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/** Imports channels from an M3U playlist (licensed IPTV) or an HDHomeRun lineup.json; then the XMLTV guide. */
class LiveSourceImporter
{
    public function __construct(private M3uParser $m3u, private XmltvImporter $xmltv) {}

    public function refresh(LiveSource $source): int
    {
        $entries = match ($source->type) {
            'hdhomerun' => $this->hdhomerun($source->url),
            default => $this->m3u->parse($this->fetch($source->url)),
        };
        $existing = $source->channels()->get()->keyBy(fn ($c) => $c->epg_id ?: $c->name);
        foreach ($entries as $i => $e) {
            $key = $e['epg_id'] ?: $e['name'];
            $attrs = ['name' => mb_substr($e['name'], 0, 120), 'number' => $e['number'], 'epg_id' => $e['epg_id'], 'stream_url' => $e['url']];
            if ($c = $existing[$key] ?? null) {
                $c->update($attrs);
            } else {
                $source->channels()->create($attrs + ['category' => M3uParser::category($e['group'] ?? null, $e['name']), 'sort' => $i]);
            }
        }
        if ($source->epg_url) {
            $tmp = sys_get_temp_dir().'/mx-epg-'.Str::random(8).'.xml';
            file_put_contents($tmp, $this->fetch($source->epg_url));
            $this->xmltv->import($tmp, $source->channels()->pluck('epg_id')->filter()->values()->all());
            @unlink($tmp);
        }
        $source->update(['refreshed_at' => now()]);

        return count($entries);
    }

    private function fetch(string $url): string
    {
        if (preg_match('#^https?://#i', $url)) {
            $body = Http::timeout(60)->get($url)->throw()->body();

            return str_ends_with(strtolower(parse_url($url, PHP_URL_PATH) ?? ''), '.gz') ? (gzdecode($body) ?: '') : $body;
        }

        throw new \RuntimeException('only http(s) playlists can be fetched');
    }

    /** HDHomeRun: http://<device-ip>/lineup.json → [{GuideNumber, GuideName, URL}] */
    private function hdhomerun(string $base): array
    {
        $lineup = Http::timeout(10)->get(rtrim($base, '/').'/lineup.json')->throw()->json() ?? [];

        return array_map(fn ($c) => ['name' => $c['GuideName'] ?? 'Channel', 'number' => $c['GuideNumber'] ?? null, 'epg_id' => null, 'url' => $c['URL'], 'group' => null, 'logo' => null], $lineup);
    }
}
