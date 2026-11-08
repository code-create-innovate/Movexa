<?php

namespace Tests\Unit\Library;

use App\Modules\Library\Services\FilenameParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class FilenameParserTest extends TestCase
{
    public static function samples(): array
    {
        // [path, type, title, year, season, episode]
        return [
            ['Movie Name (2024).mkv', 'movie', 'Movie Name', 2024, null, null],
            ['Inception (2010).mp4', 'movie', 'Inception', 2010, null, null],
            ['The.Dark.Knight.2008.1080p.BluRay.x264-GRP.mkv', 'movie', 'The Dark Knight', 2008, null, null],
            ['Interstellar.2014.2160p.UHD.BluRay.REMUX.HDR.HEVC.Atmos.mkv', 'movie', 'Interstellar', 2014, null, null],
            ['Spirited Away [2001].mkv', 'movie', 'Spirited Away', 2001, null, null],
            ['2001 A Space Odyssey (1968).mkv', 'movie', '2001 A Space Odyssey', 1968, null, null],
            ['Blade.Runner.2049.2017.720p.WEB-DL.mkv', 'movie', 'Blade Runner 2049', 2017, null, null],
            ['Parasite_2019_1080p.mkv', 'movie', 'Parasite', 2019, null, null],
            ['Movies/Amelie (2001)/Amelie (2001).mkv', 'movie', 'Amelie', 2001, null, null],
            ['الرسالة (1976).mkv', 'movie', 'الرسالة', 1976, null, null],
            ['Wadjda.2012.DVDRip.avi', 'movie', 'Wadjda', 2012, null, null],
            ['Coco.mp4', 'movie', 'Coco', null, null, null],
            ['Mad Max Fury Road 2015 1080p WEBRip x265.mkv', 'movie', 'Mad Max Fury Road', 2015, null, null],
            ['Show S01E02.mkv', 'episode', 'Show', null, 1, 2],
            ['Breaking.Bad.S05E14.Ozymandias.720p.HDTV.x264.mkv', 'episode', 'Breaking Bad', null, 5, 14],
            ['game.of.thrones.s08e03.1080p.web.h264.mkv', 'episode', 'game of thrones', null, 8, 3],
            ['The Office (US) - S02E01 - The Dundies.mkv', 'episode', 'The Office (US)', null, 2, 1],
            ['Doctor.Who.2005.S10E01.mkv', 'episode', 'Doctor Who', 2005, 10, 1],
            ['Friends 1x05.avi', 'episode', 'Friends', null, 1, 5],
            ['Lost.S01E01E02.Pilot.mkv', 'episode', 'Lost', null, 1, 1],
            ['Sherlock - s3e2 - The Sign of Three.mp4', 'episode', 'Sherlock', null, 3, 2],
            ['Shows/Dark/Season 01/Episode 03.mkv', 'episode', 'Dark', null, 1, 3],
            ['Shows/Fargo (2014)/Season 2/04 - Fear and Trembling.mkv', 'episode', 'Fargo', null, 2, 4],
            ['Shows/The Wire/Season 1/S01E05.mkv', 'episode', 'The Wire', null, 1, 5],
            ['[SubsPlease] Frieren - 05 [1080p].mkv', 'episode', 'Frieren', null, 1, 5],
            ['[Erai-raws] One Piece - 1085 [720p][ABCD1234].mkv', 'episode', 'One Piece', null, 1, 1085],
            ['باب الحارة الموسم 1 الحلقة 7.mp4', 'episode', 'باب الحارة', null, 1, 7],
            ['Planet Earth Season 2 Episode 3.mkv', 'episode', 'Planet Earth', null, 2, 3],
            ['Chernobyl.S01E05.Vichnaya.Pamyat.2160p.mkv', 'episode', 'Chernobyl', null, 1, 5],
            ['The.Mandalorian.S02E08.Chapter.16.HDR.2160p.WEB.H265.mkv', 'episode', 'The Mandalorian', null, 2, 8],
            ['Shows/Severance/S01/Severance - 01.mkv', 'episode', 'Severance', null, 1, 1],
            ['Avatar.The.Way.of.Water.2022.IMAX.1080p.mkv', 'movie', 'Avatar The Way of Water', 2022, null, null],
            ['The Matrix Reloaded (2003) [1080p].mkv', 'movie', 'The Matrix Reloaded', 2003, null, null],
            ['Grave of the Fireflies 1988.mkv', 'movie', 'Grave of the Fireflies', 1988, null, null],
        ];
    }

    #[DataProvider('samples')]
    public function test_parses(string $path, string $type, string $title, ?int $year, ?int $season, ?int $episode): void
    {
        $r = (new FilenameParser)->parse($path);
        $this->assertSame([$type, $title, $year, $season, $episode], [$r['type'], $r['title'], $r['year'], $r['season'], $r['episode']], $path);
    }

    public function test_multi_episode_end(): void
    {
        $this->assertSame(2, (new FilenameParser)->parse('Lost.S01E01E02.mkv')['episode_end']);
    }

    public function test_sample_size_is_at_least_thirty(): void
    {
        $this->assertGreaterThanOrEqual(30, count(self::samples()));
    }
}
