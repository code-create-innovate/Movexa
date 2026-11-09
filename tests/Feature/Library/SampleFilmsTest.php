<?php

namespace Tests\Feature\Library;

use App\Modules\Library\Jobs\ProcessIngestJob;
use App\Modules\Library\Models\IngestJob;
use App\Modules\Library\Models\Library;
use App\Modules\Library\Services\SampleFilms;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SampleFilmsTest extends TestCase
{
    use RefreshDatabase;

    private function video(): string
    {
        return str_repeat('v', 1_200_000);
    }

    public function test_official_direct_link_is_used_and_file_lands_in_free_films_library(): void
    {
        Queue::fake();
        Http::fake([
            'download.blender.org/*' => Http::response($this->video()),
            '*' => Http::response('unexpected', 500),
        ]);

        $this->artisan('movexa:samples', ['--only' => ['1']])->assertExitCode(0);

        $dir = config('movexa.paths.library').'/free-films/Big Buck Bunny (2008)';
        $this->assertFileExists($dir.'/Big Buck Bunny (2008).mov');
        $this->assertSame(1_200_000, filesize($dir.'/Big Buck Bunny (2008).mov'));
        $this->assertStringContainsString('CC BY 3.0', file_get_contents($dir.'/LICENSE.txt'));
        $lib = Library::where('name', 'Free films')->sole();
        $this->assertSame('movies', $lib->type);
        // goes through the normal security pipeline like any other library file; the license file is ignored
        $this->assertSame([$dir.'/Big Buck Bunny (2008).mov'], IngestJob::pluck('inbox_path')->all());
        Queue::assertPushed(ProcessIngestJob::class, 1);
        $this->assertSame([], glob(config('movexa.paths.cache').'/sample-downloads/*'));
    }

    public function test_internet_archive_fallback_picks_the_full_film_not_a_trailer(): void
    {
        Queue::fake();
        Http::fake([
            'archive.org/advancedsearch.php*' => Http::response(['response' => ['docs' => [['identifier' => 'nld_trailer'], ['identifier' => 'night_of_the_living_dead']]]]),
            'archive.org/metadata/nld_trailer' => Http::response(['files' => [['name' => 'trailer.mp4', 'length' => '150.0', 'size' => '9000000']]]),
            'archive.org/metadata/night_of_the_living_dead' => Http::response(['files' => [
                ['name' => 'nld_512kb.mp4', 'length' => '01:35:30', 'size' => '400000000'],
                ['name' => 'nld HQ.mp4', 'length' => '5730.5', 'size' => '900000000'],
                ['name' => 'nld.ogv', 'length' => '5730.5', 'size' => '990000000'],
            ]]),
            'archive.org/download/night_of_the_living_dead/nld%20HQ.mp4' => Http::response($this->video()),
            '*' => Http::response('', 404),
        ]);

        $this->artisan('movexa:samples', ['--only' => ['night of the living dead']])->assertExitCode(0);

        $this->assertFileExists(config('movexa.paths.library').'/free-films/Night of the Living Dead (1968)/Night of the Living Dead (1968).mp4');
        Http::assertSent(fn ($r) => $r->url() === 'https://archive.org/download/night_of_the_living_dead/nld%20HQ.mp4');
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'trailer.mp4'));
    }

    public function test_failures_are_reported_and_existing_films_are_skipped(): void
    {
        Queue::fake();
        $svc = app(SampleFilms::class);
        $dir = $svc->directory().'/Sintel (2010)';
        mkdir($dir, 0775, true);
        file_put_contents($dir.'/Sintel (2010).mkv', $this->video());
        Http::fake(['*' => Http::response('', 404)]);

        $this->artisan('movexa:samples', ['--only' => ['2', '4']])
            ->expectsOutputToContain('Sintel (2010) — already downloaded')
            ->expectsOutputToContain('Elephants Dream (2006): no matching file found')
            ->assertExitCode(1);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'Sintel'));
    }

    public function test_only_official_sources_are_contacted(): void
    {
        foreach (SampleFilms::FILMS as $f) {
            $this->assertTrue($f[3] === null || str_starts_with($f[3], 'https://download.blender.org/'));
        }
        $this->assertSame(95.5, SampleFilms::minutes('01:35:30'));
        $this->assertNull(SampleFilms::minutes('n/a'));
    }
}
