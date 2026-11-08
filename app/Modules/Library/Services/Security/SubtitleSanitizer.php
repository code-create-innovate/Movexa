<?php

namespace App\Modules\Library\Services\Security;

/**
 * Converts SRT / ASS / SSA / VTT (any legacy encoding) to clean UTF-8 WebVTT plain text.
 * Strips HTML, scripts, links, ASS override codes. Keeps only cue timing + text.
 */
class SubtitleSanitizer
{
    /** Legacy encodings tried in order; Arabic first since it's the common corruption case. */
    private const CANDIDATES = ['UTF-8', 'Windows-1256', 'ISO-8859-6', 'UTF-16', 'Windows-1252'];

    /** @return array{0:string,1:string} [utf8 text, detected encoding] */
    public function toUtf8(string $raw): array
    {
        if (str_starts_with($raw, "\xEF\xBB\xBF")) {
            return [substr($raw, 3), 'UTF-8'];
        }
        if (str_starts_with($raw, "\xFF\xFE") || str_starts_with($raw, "\xFE\xFF")) {
            return [mb_convert_encoding(substr($raw, 2), 'UTF-8', str_starts_with($raw, "\xFF\xFE") ? 'UTF-16LE' : 'UTF-16BE'), 'UTF-16'];
        }
        if (mb_check_encoding($raw, 'UTF-8')) {
            return [$raw, 'UTF-8'];
        }
        // Heuristic: bytes 0xC1-0xFE dominate in Windows-1256 Arabic text.
        $high = preg_match_all('/[\xC1-\xFE]/', $raw);
        if ($high > 0) {
            $converted = @iconv('WINDOWS-1256', 'UTF-8//IGNORE', $raw);
            if ($converted !== false && preg_match('/\p{Arabic}/u', $converted)) {
                return [$converted, 'Windows-1256'];
            }
        }
        $converted = @iconv('WINDOWS-1252', 'UTF-8//IGNORE', $raw);

        return [$converted !== false ? $converted : mb_convert_encoding($raw, 'UTF-8', 'ISO-8859-1'), 'Windows-1252'];
    }

    /** @return array{vtt:string, encoding:string, cues:int} */
    public function sanitize(string $raw, string $formatHint = 'srt'): array
    {
        [$text, $encoding] = $this->toUtf8($raw);
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $format = $this->detectFormat($text, $formatHint);

        $cues = match ($format) {
            'ass' => $this->parseAss($text),
            default => $this->parseSrtOrVtt($text),
        };

        $out = "WEBVTT\n\n";
        $n = 0;
        foreach ($cues as [$start, $end, $body]) {
            $clean = $this->cleanText($body);
            if ($clean === '' || $end <= $start) {
                continue;
            }
            $n++;
            $out .= $n."\n".$this->ts($start).' --> '.$this->ts($end)."\n".$clean."\n\n";
        }

        return ['vtt' => $out, 'encoding' => $encoding, 'cues' => $n];
    }

    public function cleanText(string $body): string
    {
        $body = preg_replace('/\{[^}]*\}/u', '', $body);                       // ASS override tags
        $body = str_replace(['\\N', '\\n', '\\h'], ["\n", "\n", ' '], $body);
        $body = preg_replace('#<(script|style|iframe|object|embed)\b[^>]*>.*?</\1\s*>#isu', '', $body);
        $body = strip_tags($body);                                              // all HTML, incl. <i>/<font>
        $body = html_entity_decode($body, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $body = strip_tags($body);                                              // entity-encoded markup
        $body = preg_replace('#\b(?:https?|ftp|file|javascript|data|vbscript):\S*#iu', '', $body); // links / URIs
        $body = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $body);
        $body = str_replace('-->', '→', $body);                                 // never break cue syntax
        $lines = array_filter(array_map(fn ($l) => trim(preg_replace('/[ \t]{2,}/u', ' ', $l)), explode("\n", $body)), fn ($l) => $l !== '');

        return implode("\n", $lines);
    }

    private function detectFormat(string $text, string $hint): string
    {
        if (preg_match('/^\s*\[Script Info\]/mi', $text) || preg_match('/^Dialogue:/m', $text)) {
            return 'ass';
        }

        return in_array($hint, ['vtt', 'srt'], true) ? $hint : 'srt';
    }

    private function parseSrtOrVtt(string $text): array
    {
        $cues = [];
        $re = '/(\d{1,2}:)?(\d{1,2}):(\d{2})[,.](\d{1,3})\s*-->\s*(\d{1,2}:)?(\d{1,2}):(\d{2})[,.](\d{1,3})[^\n]*\n(.*?)(?=\n\s*\n|\z)/su';
        preg_match_all($re, $text, $m, PREG_SET_ORDER);
        foreach ($m as $c) {
            $start = (int) rtrim($c[1] ?: '0', ':') * 3600 + (int) $c[2] * 60 + (int) $c[3] + (int) str_pad($c[4], 3, '0') / 1000;
            $end = (int) rtrim($c[5] ?: '0', ':') * 3600 + (int) $c[6] * 60 + (int) $c[7] + (int) str_pad($c[8], 3, '0') / 1000;
            $cues[] = [$start, $end, $c[9]];
        }

        return $cues;
    }

    private function parseAss(string $text): array
    {
        $cues = [];
        $fields = ['Layer', 'Start', 'End', 'Style', 'Name', 'MarginL', 'MarginR', 'MarginV', 'Effect', 'Text'];
        foreach (explode("\n", $text) as $line) {
            if (preg_match('/^Format:\s*(.+)$/i', $line, $fm) && str_contains($fm[1], 'Text')) {
                $fields = array_map('trim', explode(',', $fm[1]));
            }
            if (! str_starts_with($line, 'Dialogue:')) {
                continue;
            }
            $vals = explode(',', substr($line, 9), count($fields));
            $row = array_combine(array_slice($fields, 0, count($vals)), $vals);
            if (! isset($row['Start'], $row['End'], $row['Text'])) {
                continue;
            }
            $cues[] = [$this->assTime($row['Start']), $this->assTime($row['End']), $row['Text']];
        }
        usort($cues, fn ($a, $b) => $a[0] <=> $b[0]);

        return $cues;
    }

    private function assTime(string $t): float
    {
        [$h, $m, $s] = array_pad(explode(':', trim($t)), 3, '0');

        return (int) $h * 3600 + (int) $m * 60 + (float) $s;
    }

    private function ts(float $sec): string
    {
        $ms = (int) round($sec * 1000);

        return sprintf('%02d:%02d:%02d.%03d', intdiv($ms, 3600000), intdiv($ms % 3600000, 60000), intdiv($ms % 60000, 1000), $ms % 1000);
    }
}
