<?php

namespace Tests\Feature\Security;

use App\Modules\Library\Jobs\ProcessIngestJob;
use App\Modules\Library\Models\IngestJob;
use App\Modules\Library\Models\Library;
use App\Modules\Library\Models\MediaFile;
use App\Modules\Library\Models\MediaItem;
use App\Modules\Library\Models\SecurityEvent;
use App\Modules\Library\Services\IngestPipeline;
use App\Modules\Library\Services\Security\ClamdScanner;
use App\Modules\Library\Services\Security\Quarantine;
use App\Modules\Media\Services\MediaProbe;
use App\Modules\Media\Services\Sandbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\MediaFixtures;
use Tests\TestCase;

class IngestPipelineTest extends TestCase
{
    use RefreshDatabase;

    // Standard, harmless antivirus test string (https://www.eicar.org).
    private const EICAR = 'X5O!P%@AP[4\PZX54(P^)7CC)7}$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*';

    private Library $lib;

    protected function setUp(): void
    {
        parent::setUp();
        if (! $this->clamdAvailable()) {
            $this->markTestSkipped('clamd not reachable (set MOVEXA_TEST_CLAMD)');
        }
        $this->lib = Library::create(['name' => 'Movies', 'type' => 'movies', 'path' => $this->dataRoot.'/library/movies']);
    }

    private function ingest(string $path, string $name): IngestJob
    {
        return app(IngestPipeline::class)->process(IngestPipeline::enqueue($path, $name, $this->lib, 'upload'));
    }

    public function test_clamav_detects_eicar(): void
    {
        $f = $this->dataRoot.'/inbox/eicar.com';
        file_put_contents($f, self::EICAR);
        $r = (new ClamdScanner)->scan($f);
        $this->assertFalse($r->clean);
        $this->assertNotNull($r->threat);
    }

    public function test_eicar_file_is_rejected_and_quarantined_encrypted(): void
    {
        $f = $this->dataRoot.'/inbox/movie.mkv';
        file_put_contents($f, self::EICAR);
        $job = $this->ingest($f, 'movie.mkv');
        $this->assertContains($job->status, ['rejected', 'quarantined']);
        $this->assertFileDoesNotExist($f);
        $this->assertFileExists($job->quarantine_path);
        $this->assertStringNotContainsString('EICAR', file_get_contents($job->quarantine_path)); // encrypted at rest
        $this->assertSame(0, MediaFile::count());
    }

    public function test_executable_with_mp4_extension_is_rejected(): void
    {
        $f = $this->dataRoot.'/inbox/x.mp4';
        file_put_contents($f, "MZ\x90\x00".str_repeat("\x00", 200).'This program cannot be run in DOS mode');
        $job = $this->ingest($f, 'Great Movie (2020).mp4');
        $this->assertSame('rejected', $job->status);
        $this->assertStringContainsString('disguised_windows_executable', $job->reason);
        $this->assertSame(0, MediaFile::count());
    }

    public function test_double_extension_is_rejected(): void
    {
        $f = MediaFixtures::make($this->dataRoot.'/inbox/a.mp4');
        $job = $this->ingest($f, 'movie.mp4.exe');
        $this->assertSame('rejected', $job->status);
        $job = $this->ingest(MediaFixtures::make($this->dataRoot.'/inbox/b.mp4'), 'movie.exe.mp4');
        $this->assertSame('rejected', $job->status);
    }

    public function test_mkv_with_suspicious_attachment_is_rebuilt_without_it(): void
    {
        $src = MediaFixtures::mkvWithAttachment($this->dataRoot.'/inbox/src/Clean Movie (2021).mkv', "MZ fake payload\x00\x01");
        $before = app(MediaProbe::class)->analyze($src);
        $this->assertTrue(collect($before['streams'])->contains('type', 'attachment'));

        $job = $this->ingest($src, 'Clean Movie (2021).mkv');
        $this->assertSame('done', $job->status, (string) $job->reason);
        $file = MediaFile::findOrFail($job->media_file_id);
        $after = app(MediaProbe::class)->analyze($file->path);

        $this->assertTrue($file->sanitized);
        $this->assertFalse(collect($after['streams'])->contains('type', 'attachment'));
        $this->assertEqualsWithDelta($before['duration'], $after['duration'], 0.5);
        $this->assertSame(collect($before['streams'])->where('type', 'video')->count(), collect($after['streams'])->where('type', 'video')->count());
        $this->assertSame(collect($before['streams'])->where('type', 'audio')->count(), collect($after['streams'])->where('type', 'audio')->count());
        $this->assertArrayNotHasKey('title', array_change_key_case($after['tags'])); // global metadata stripped
        $this->assertSame('Clean Movie', $file->item->title);
        $this->assertSame(2021, $file->item->year);
        // embedded subtitle extracted as clean WebVTT
        $vtt = file_get_contents($file->subtitles()->firstOrFail()->path);
        $this->assertStringContainsString('Hello world', $vtt);
        $this->assertStringNotContainsString('script', $vtt);
        $this->assertFileDoesNotExist($src);
    }

    public function test_infected_media_is_disinfected_and_original_quarantined(): void
    {
        $src = MediaFixtures::mkvWithAttachment($this->dataRoot.'/inbox/src/Infected (2019).mkv', self::EICAR, 'eicar.txt', 'text/plain');
        $this->assertFalse((new ClamdScanner)->scan($src)->clean);

        $job = $this->ingest($src, 'Infected (2019).mkv');
        $this->assertSame('done', $job->status, (string) $job->reason);
        $this->assertSame('sanitized_after_detection', $job->reason);
        $this->assertNotNull($job->threat);
        $file = MediaFile::findOrFail($job->media_file_id);
        $this->assertTrue((new ClamdScanner)->scan($file->path)->clean); // rebuilt file is clean
        $this->assertTrue($file->sanitized);
        $this->assertFileExists($job->quarantine_path);                  // original kept, encrypted
        $this->assertTrue(SecurityEvent::where('ingest_job_id', $job->id)->where('result', 'infected')->exists());

        // Retry decrypts and re-runs CDR; the original never enters the library as-is.
        $restored = $this->dataRoot.'/check.mkv';
        app(Quarantine::class)->restoreTo($job->quarantine_path, $restored);
        $this->assertSame(filesize($restored), filesize($restored));
        $this->assertFalse((new ClamdScanner)->scan($restored)->clean);
    }

    public function test_nothing_is_visible_before_scan_completes(): void
    {
        Queue::fake();
        $src = MediaFixtures::make($this->dataRoot.'/inbox/Queued (2020).mp4');
        $job = IngestPipeline::enqueue($src, 'Queued (2020).mp4', $this->lib, 'upload');
        ProcessIngestJob::dispatch($job->id);
        Queue::assertPushedOn('scan', ProcessIngestJob::class);
        $this->assertSame(0, MediaFile::count());
        $this->assertSame(0, MediaItem::count());

        // Simulate a crash mid-pipeline: file record exists but not ready → still invisible.
        $profile = $this->actingAsProfile();
        $f = MediaFixtures::readyMovie($this->dataRoot.'/library', ['title' => 'Half', 'age_rating' => 0]);
        $f->update(['status' => 'scanning']);
        $this->assertSame(0, MediaItem::visibleTo($profile)->count());
        $this->get('/title/'.$f->media_item_id)->assertNotFound();
    }

    public function test_scanner_outage_fails_closed(): void
    {
        config(['movexa.security.clamd_socket' => '/nonexistent.sock']);
        $src = MediaFixtures::make($this->dataRoot.'/inbox/x.mp4');
        $job = $this->ingest($src, 'Movie (2020).mp4');
        $this->assertSame('quarantined', $job->status);
        $this->assertSame('scanner_unavailable', $job->reason);
        $this->assertSame(0, MediaFile::count());
    }

    public function test_ffmpeg_runs_in_sandbox_without_network(): void
    {
        foreach (['unshare', 'bwrap'] as $driver) {
            if ($driver === 'bwrap' && ! is_executable('/usr/bin/bwrap')) {
                continue;
            }
            $p = (new Sandbox($driver))->run(['ffmpeg', '-hide_banner', '-nostdin', '-timeout', '3000000', '-i', 'tcp://1.1.1.1:80', '-f', 'null', '-'], [], 20);
            $this->assertFalse($p->isSuccessful(), $driver);
            $this->assertMatchesRegularExpression('/Network is unreachable|Cannot assign requested address|Connection refused/i', $p->getErrorOutput(), $driver);
            $ls = (new Sandbox($driver))->run(['cat', '/proc/net/dev'], [], 5);
            $this->assertDoesNotMatchRegularExpression('/^\s*(eth|en|wl)\w*:/m', $ls->getOutput(), $driver.' must only see loopback');
        }
    }
}
