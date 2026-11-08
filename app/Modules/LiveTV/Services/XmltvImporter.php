<?php

namespace App\Modules\LiveTV\Services;

use App\Modules\LiveTV\Models\EpgProgram;
use Carbon\Carbon;
use XMLReader;

/** Streaming XMLTV parser (large guides). External entities are never loaded. */
class XmltvImporter
{
    public function import(string $xmlPath, ?array $onlyIds = null): int
    {
        $r = new XMLReader;
        if (! $r->open($xmlPath, null, LIBXML_NONET)) {
            return 0;
        }
        $batch = [];
        $count = 0;
        $ids = $onlyIds ? array_flip($onlyIds) : null;
        while ($r->read()) {
            if ($r->nodeType !== XMLReader::ELEMENT || $r->name !== 'programme') {
                continue;
            }
            $channel = $r->getAttribute('channel');
            if ($ids && ! isset($ids[$channel])) {
                continue;
            }
            $start = $this->time($r->getAttribute('start'));
            $stop = $this->time($r->getAttribute('stop'));
            $node = simplexml_load_string($r->readOuterXml(), 'SimpleXMLElement', LIBXML_NONET);
            if (! $start || ! $stop || ! $node || $stop->lt(now()->subDay())) {
                continue;
            }
            $batch[] = [
                'epg_id' => mb_substr((string) $channel, 0, 190),
                'title' => mb_substr(trim((string) ($node->title[0] ?? '')) ?: '—', 0, 250),
                'description' => mb_substr(trim((string) ($node->desc[0] ?? '')), 0, 2000) ?: null,
                'category' => mb_substr(trim((string) ($node->category[0] ?? '')), 0, 190) ?: null,
                'starts_at' => $start, 'ends_at' => $stop,
            ];
            if (count($batch) >= 500) {
                $count += $this->flush($batch);
            }
        }
        $r->close();

        return $count + $this->flush($batch);
    }

    private function flush(array &$batch): int
    {
        if (! $batch) {
            return 0;
        }
        $ids = array_unique(array_column($batch, 'epg_id'));
        $min = min(array_column($batch, 'starts_at'));
        EpgProgram::whereIn('epg_id', $ids)->where('starts_at', '>=', $min)->whereIn('starts_at', array_column($batch, 'starts_at'))->delete();
        EpgProgram::insert($batch);
        $n = count($batch);
        $batch = [];

        return $n;
    }

    private function time(?string $s): ?Carbon
    {
        if (! $s || ! preg_match('/^(\d{14})\s*([+-]\d{4})?/', $s, $m)) {
            return null;
        }

        return Carbon::createFromFormat('YmdHis O', $m[1].' '.($m[2] ?? '+0000'))->utc();
    }
}
