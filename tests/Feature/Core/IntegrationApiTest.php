<?php

namespace Tests\Feature\Core;

use App\Models\User;
use App\Modules\Core\Models\ApiToken;
use App\Modules\Library\Models\IngestJob;
use App\Modules\Library\Models\Library;
use App\Modules\Playback\Models\WatchProgress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\MediaFixtures;
use Tests\TestCase;

class IntegrationApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_health_returns_status_only(): void
    {
        $r = $this->getJson('/api/health')->assertOk()->assertJsonStructure(['status', 'service', 'checks' => ['database', 'redis', 'storage', 'ffmpeg']]);
        $this->assertContains($r['status'], ['ok', 'degraded']);
        $body = $r->getContent();
        foreach ([config('app.key'), $this->dataRoot, 'version', 'php', 'password'] as $secret) {
            $this->assertStringNotContainsStringIgnoringCase($secret, $body);
        }
        $r->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_homecore_api_requires_scoped_token(): void
    {
        $this->getJson('/api/homecore/now-streaming')->assertUnauthorized();
        [, $cloud] = ApiToken::issue('cloudsync', ['cloudsync:ingest']);
        $this->getJson('/api/homecore/now-streaming', ['Authorization' => "Bearer {$cloud}"])->assertForbidden();
        [, $token] = ApiToken::issue('homecore', ['homecore:read']);
        $this->getJson('/api/homecore/now-streaming', ['Authorization' => "Bearer {$token}"])->assertOk()->assertJsonStructure(['count', 'sessions', 'live_channels']);
        [, $expired] = ApiToken::issue('old', ['homecore:read'], now()->subDay());
        $this->getJson('/api/homecore/transcodes', ['Authorization' => "Bearer {$expired}"])->assertUnauthorized();
    }

    public function test_homecore_continue_watching_and_search(): void
    {
        $user = User::factory()->create(['oidc_subject' => 'hc-42']);
        $profile = $user->profiles()->create(['name' => 'Me']);
        $file = MediaFixtures::readyMovie($this->dataRoot.'/library', ['title' => 'Dune', 'title_ar' => 'كثيب', 'age_rating' => 13]);
        WatchProgress::create(['profile_id' => $profile->id, 'media_file_id' => $file->id, 'media_item_id' => $file->media_item_id, 'position' => 120, 'duration' => 600]);
        [, $token] = ApiToken::issue('homecore', ['homecore:read']);
        $h = ['Authorization' => "Bearer {$token}"];

        $this->getJson('/api/homecore/continue-watching?user=hc-42', $h)->assertOk()->assertJsonPath('items.0.title', 'Dune')->assertJsonPath('items.0.progress', 0.2);
        $this->getJson('/api/homecore/search?user=hc-42&q='.urlencode('كثيب'), $h)->assertJsonPath('results.0.title', 'Dune');
        $this->getJson('/api/homecore/search?user=hc-42&q=dun', $h)->assertJsonCount(1, 'results');
        $this->getJson('/api/homecore/search?q=dun', $h)->assertJsonCount(0, 'results');
    }

    public function test_cloudsync_link_verifies_permission_and_path(): void
    {
        Queue::fake();
        $root = $this->dataRoot.'/cloudsync';
        File::ensureDirectoryExists($root.'/u1');
        config(['movexa.cloudsync.root' => $root, 'movexa.cloudsync.url' => 'http://cloudsync.test', 'movexa.cloudsync.token' => 'cs']);
        $video = MediaFixtures::make($root.'/u1/Holiday (2023).mp4');
        User::factory()->create(['oidc_subject' => 'hc-1']);
        Library::create(['name' => 'Movies', 'type' => 'movies', 'path' => $root, 'source' => 'cloudsync']);
        [, $token] = ApiToken::issue('cloudsync', ['cloudsync:ingest']);
        $h = ['Authorization' => "Bearer {$token}"];

        Http::fake([
            'cloudsync.test/api/integrations/movexa/files/f1*' => Http::response(['id' => 'f1', 'name' => 'Holiday (2023).mp4', 'path' => $video, 'can_read' => true]),
            'cloudsync.test/api/integrations/movexa/files/f2*' => Http::response(['id' => 'f2', 'name' => 'x', 'path' => '/etc/passwd', 'can_read' => true]),
            'cloudsync.test/api/integrations/movexa/files/f3*' => Http::response(['id' => 'f3', 'can_read' => false]),
        ]);

        $this->postJson('/api/cloudsync/link', ['user' => 'hc-1', 'file_id' => 'f1', 'library' => 'movies'], $h)->assertStatus(202);
        $job = IngestJob::firstOrFail();
        $this->assertTrue($job->link_only);
        $this->assertSame($video, $job->inbox_path); // linked in place, not copied
        $this->postJson('/api/cloudsync/link', ['user' => 'hc-1', 'file_id' => 'f2', 'library' => 'movies'], $h)->assertStatus(422);
        $this->postJson('/api/cloudsync/link', ['user' => 'hc-1', 'file_id' => 'f3', 'library' => 'movies'], $h)->assertForbidden();
        $this->postJson('/api/cloudsync/link', ['user' => 'nobody', 'file_id' => 'f1', 'library' => 'movies'], $h)->assertStatus(422);

        // "Send to library": copied into the inbox for the full CDR pipeline.
        $this->postJson('/api/cloudsync/send', ['user' => 'hc-1', 'file_id' => 'f1', 'library' => 'movies'], $h)->assertStatus(202);
        $copy = IngestJob::latest('id')->first();
        $this->assertFalse($copy->link_only);
        $this->assertStringStartsWith($this->dataRoot.'/inbox/cloudsync/', $copy->inbox_path);
        $this->assertFileExists($video); // the user's original is never touched
    }

    public function test_movexa_works_when_cloudsync_and_homecore_are_down(): void
    {
        config(['movexa.cloudsync.url' => 'http://127.0.0.1:9', 'movexa.cloudsync.token' => 'x']);
        $this->assertFalse(app(\App\Modules\Integrations\Services\CloudSyncClient::class)->healthy());
        $this->getJson('/api/health')->assertOk();
        $this->actingAsProfile();
        $this->get('/home')->assertOk();
    }
}
