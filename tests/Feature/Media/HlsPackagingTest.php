<?php

namespace Tests\Feature\Media;

use App\Modules\Media\Services\HlsPackager;
use App\Modules\Media\Services\MediaProbe;
use App\Modules\Media\Services\PlaybackDecider;
use App\Modules\Playback\Models\TranscodeJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\MediaFixtures;
use Tests\TestCase;

class HlsPackagingTest extends TestCase
{
    use RefreshDatabase;

    public static function formats(): array
    {
        return [
            'MKV H.265 + DTS (transcode)' => ['mkv-hevc-dts', 'transcode'],
            'MKV H.265 + DTS (remux, video copy)' => ['mkv-hevc-dts', 'remux'],
            'AVI MPEG-4 + MP3' => ['avi', 'transcode'],
            'MP4 H.264 + AAC (remux)' => ['mp4', 'remux'],
            'MKV 2 audio tracks' => ['mkv-2audio', 'remux'],
        ];
    }

    #[DataProvider('formats')]
    public function test_packages_valid_hls_with_matching_duration(string $kind, string $mode): void
    {
        config(['movexa.transcode.hwaccel' => 'none']);
        $file = MediaFixtures::readyMovie($this->dataRoot.'/library', ['age_rating' => 0], $kind);
        $packager = app(HlsPackager::class);
        $key = HlsPackager::profileKey($mode, $mode === 'transcode' ? $packager->ladderFor($file) : null);
        $job = TranscodeJob::create(['media_file_id' => $file->id, 'mode' => $mode, 'profile_key' => $key]);

        $packager->run($job);
        $job->refresh();
        $this->assertSame('done', $job->status, (string) $job->error);
        $this->assertSame(100, $job->progress);

        $master = file_get_contents($job->output_dir.'/master.m3u8');
        $this->assertStringStartsWith('#EXTM3U', $master);
        $this->assertStringContainsString('#EXT-X-STREAM-INF', $master);
        $audioCount = $file->streams->where('type', 'audio')->count();
        $this->assertSame($audioCount, substr_count($master, 'TYPE=AUDIO'));

        // Each variant playlist is complete and its total duration matches the source.
        foreach (glob($job->output_dir.'/*/index.m3u8') as $playlist) {
            $text = file_get_contents($playlist);
            $this->assertStringContainsString('#EXT-X-ENDLIST', $text);
            preg_match_all('/#EXTINF:([\d.]+)/', $text, $m);
            $this->assertEqualsWithDelta($file->duration, array_sum($m[1]), 0.6, basename(dirname($playlist)));
        }
        // ffprobe can open the HLS output and sees H.264 (transcode) or the copied codec (remux).
        $probe = app(MediaProbe::class)->raw(glob($job->output_dir.'/*/index.m3u8')[0]);
        $codec = collect($probe['streams'])->firstWhere('codec_type', 'video')['codec_name'] ?? null;
        if (glob($job->output_dir.'/audio_*') !== [] && $codec === null) {
            $codec = 'audio-only-variant';
        }
        $this->assertContains($codec, $mode === 'transcode' ? ['h264'] : [$file->video_codec, 'audio-only-variant']);
    }

    public function test_decider_picks_direct_remux_transcode(): void
    {
        $d = app(PlaybackDecider::class);
        $mp4 = MediaFixtures::readyMovie($this->dataRoot.'/library', [], 'mp4');
        $hevc = MediaFixtures::readyMovie($this->dataRoot.'/library', [], 'mkv-hevc-dts');
        $chrome = ['video' => ['h264', 'vp9', 'av1'], 'audio' => ['aac', 'mp3', 'opus', 'flac'], 'containers' => ['mp4', 'webm']];
        $safari = ['video' => ['h264', 'hevc'], 'audio' => ['aac', 'mp3', 'ac3', 'eac3'], 'containers' => ['mp4']];

        $this->assertSame('direct', $d->decide($mp4, $chrome)['method']);
        $this->assertSame('transcode', $d->decide($hevc, $chrome)['method']);   // no HEVC in Chrome
        $this->assertSame('remux', $d->decide($hevc, $safari)['method']);       // HEVC ok, DTS + MKV not
        $this->assertSame('transcode', $d->decide($mp4, $chrome, 0, true)['method']); // manual quality
    }
}
