<?php

namespace App\Modules\Library\Services;

/** Parses common release naming patterns for movies and episodes. */
class FilenameParser
{
    private const JUNK = '/\b(2160p|1080p|1080i|720p|576p|480p|4k|uhd|hdr10?\+?|hdr|dv|dolby\s?vision|bluray|blu-ray|bdrip|brrip|web-?dl|webrip|web|hdtv|dvdrip|dvd|hdrip|remux|x264|x265|h\.?264|h\.?265|hevc|avc|aac\d?(\.\d)?|ac3|eac3|ddp?\d?(\.\d)?|dts(-hd)?|truehd|atmos|10bit|8bit|proper|repack|extended|unrated|imax|remastered|multi|dual|subbed|dubbed|internal|limited|amzn|nf|dsnp|hmax|atvp)\b.*$/iu';

    /** @return array{type:string,title:string,year:?int,season:?int,episode:?int,episode_end:?int} */
    public function parse(string $path): array
    {
        $file = pathinfo($path, PATHINFO_FILENAME);
        $dirs = array_values(array_filter(explode('/', str_replace('\\', '/', dirname($path))), fn ($d) => $d !== '' && $d !== '.'));
        $name = $this->stripGroupTags($file);

        // S01E02, S01E02E03, S01E02-E03, s1e2
        if (preg_match('/^(.*?)[\s._\-\[(]*S(\d{1,2})[\s._\-]?E(\d{1,3})(?:[\s._\-]?E?(\d{1,3}))?(?!\d)/iu', $name, $m)) {
            return $this->episode($m[1] ?: $this->showFromDirs($dirs), (int) $m[2], (int) $m[3], isset($m[4]) && $m[4] !== '' ? (int) $m[4] : null);
        }
        // 1x02
        if (preg_match('/^(.*?)[\s._\-]+(\d{1,2})x(\d{2,3})(?!\d)/iu', $name, $m)) {
            return $this->episode($m[1], (int) $m[2], (int) $m[3]);
        }
        // "Season 1 Episode 2" / Arabic "الموسم 1 الحلقة 2"
        if (preg_match('/^(.*?)[\s._\-]*(?:Season|الموسم)[\s._\-]*(\d{1,2})[\s._\-]*(?:Episode|Ep|الحلقة)[\s._\-]*(\d{1,3})/iu', $name, $m)) {
            return $this->episode($m[1] ?: $this->showFromDirs($dirs), (int) $m[2], (int) $m[3]);
        }
        // Folder layout: Show/Season 01/Episode 02.mkv | Show/Season 1/03 - Title.mkv
        $seasonDir = $dirs ? end($dirs) : '';
        if (preg_match('/^(?:Season|S|الموسم)[\s._\-]*(\d{1,2})$/iu', $seasonDir, $sm)) {
            if (preg_match('/^(?:.*?(?:Episode|Ep|E|الحلقة)[\s._\-]*)?(\d{1,3})(?!\d)/iu', $name, $em)) {
                return $this->episode($this->showFromDirs(array_slice($dirs, 0, -1)), (int) $sm[1], (int) $em[1]);
            }
        }
        // Anime: "Show - 05" (absolute numbering → season 1)
        if (preg_match('/^(.+?)\s+-\s+(\d{1,4})(?:v\d)?(?:\s|$)/u', $name, $m) && ! preg_match('/^(19|20)\d{2}$/', $m[2])) {
            return $this->episode($m[1], 1, (int) $m[2]);
        }

        return $this->movie($name, $dirs);
    }

    private function movie(string $name, array $dirs): array
    {
        $year = null;
        // Title (2024) | Title [2024] | Title.2024.1080p — take the LAST plausible year (titles like "2001 A Space Odyssey 1968").
        if (preg_match_all('/(?<![\d])(19[2-9]\d|20[0-4]\d)(?![\d])/u', $name, $all, PREG_OFFSET_CAPTURE)) {
            foreach (array_reverse($all[0]) as [$y, $pos]) {
                if ($pos > 0) {
                    $year = (int) $y;
                    $name = substr($name, 0, $pos);
                    break;
                }
            }
        }
        $title = $this->cleanTitle($name);
        if ($title === '' && $dirs) {
            return $this->movie(end($dirs), []);
        }

        return ['type' => 'movie', 'title' => $title, 'year' => $year, 'season' => null, 'episode' => null, 'episode_end' => null];
    }

    private function episode(string $show, int $season, int $episode, ?int $end = null): array
    {
        $year = null;
        if (preg_match('/[\s._(\[]((?:19|20)\d{2})[)\]]?\s*$/u', $show, $m)) {
            $year = (int) $m[1];
            $show = substr($show, 0, -strlen($m[0]));
        }

        return ['type' => 'episode', 'title' => $this->cleanTitle($show), 'year' => $year, 'season' => $season, 'episode' => $episode, 'episode_end' => $end];
    }

    private function showFromDirs(array $dirs): string
    {
        $dirs = array_values(array_filter($dirs, fn ($d) => ! preg_match('/^(?:Season|S|الموسم)[\s._\-]*\d{1,2}$/iu', $d)));
        $d = $dirs ? end($dirs) : '';

        return preg_replace('/\s*[(\[]?(19|20)\d{2}[)\]]?\s*$/', '', $d);
    }

    private function stripGroupTags(string $s): string
    {
        $s = preg_replace('/^\[[^\]]*\]\s*/u', '', $s);           // [Group] prefix
        $s = preg_replace('/\[(?!(19|20)\d{2}\])[^\]]*\]/u', ' ', $s); // [1080p] [ABCD1234] but keep [2024]
        $s = preg_replace('/\s*\[((19|20)\d{2})\]/u', ' ($1)', $s);

        return trim($s);
    }

    public function cleanTitle(string $s): string
    {
        $s = preg_replace(self::JUNK, '', $s);
        if (! preg_match('/\s/u', trim($s)) || preg_match('/[._]/', $s)) {
            $s = preg_replace('/(?<!\b[A-Z])\.(?![A-Z]\b)|_/u', ' ', $s); // dots → spaces, keep "S.H.I.E.L.D"
            $s = str_replace('.', ' ', preg_replace('/\b((?:[A-Z]\.){2,}[A-Z]?)/u', '$1', $s));
        }
        $s = preg_replace('/[\s\-–(\[]+$/u', '', trim($s));
        $s = preg_replace('/\s+-\s*$/', '', $s);

        $s = trim(preg_replace('/\s{2,}/u', ' ', $s), " -_.");
        if (substr_count($s, '(') !== substr_count($s, ')')) {
            $s = trim($s, ' ()');
        }

        return trim($s, " -_.[]");
    }

    public static function matchKey(string $title, ?int $year = null): string
    {
        $t = mb_strtolower($title);
        $t = preg_replace('/[^\p{L}\p{N}]+/u', '', $t);

        return $t.($year ? ':'.$year : '');
    }
}
