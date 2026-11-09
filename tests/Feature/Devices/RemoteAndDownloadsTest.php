<?php

namespace Tests\Feature\Devices;

use App\Models\User;
use App\Modules\Core\Models\AccessBlock;
use App\Modules\Core\Services\MediaSigner;
use App\Modules\Core\Services\ProfileContext;
use App\Modules\Devices\Events\RemoteCommand;
use App\Modules\Devices\Models\Download;
use App\Modules\Playback\Models\WatchProgress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Support\MediaFixtures;
use Tests\TestCase;

class RemoteAndDownloadsTest extends TestCase
{
    use RefreshDatabase;

    private function cmd(string $uuid, array $extra = [])
    {
        return $this->postJson("/remote/{$uuid}/command", $extra + ['action' => 'pause', 'nonce' => bin2hex(random_bytes(8)), 'sent_at' => now()->getTimestampMs()]);
    }

    public function test_remote_commands_are_validated_sequenced_and_deduplicated(): void
    {
        Event::fake([RemoteCommand::class]);
        $profile = $this->actingAsProfile();
        $tv = $this->postJson('/remote/register', ['name' => 'TV'])->json('uuid');
        $this->postJson("/remote/{$tv}/state", ['state' => ['title' => 'Film', 'position' => 10, 'duration' => 100, 'paused' => false]])->assertOk();
        $this->getJson('/remote/devices?self=x')->assertJsonPath('devices.0.state.title', 'Film');

        $a = $this->cmd($tv)->assertOk()->json('seq');
        $b = $this->cmd($tv, ['action' => 'seek', 'value' => 42])->assertOk()->json('seq');
        $this->assertSame($a + 1, $b);
        Event::assertDispatched(RemoteCommand::class, fn ($e) => $e->command['action'] === 'seek' && $e->command['value'] === 42 && $e->command['expires_at'] > now()->getTimestampMs()
            && $e->broadcastWith()['seq'] === $b); // flat payload as the player expects

        $nonce = 'same-nonce';
        $this->cmd($tv, ['nonce' => $nonce])->assertOk();
        $this->cmd($tv, ['nonce' => $nonce])->assertStatus(409)->assertJson(['message' => 'duplicate']);
        $this->cmd($tv, ['sent_at' => now()->subSeconds(30)->getTimestampMs()])->assertStatus(409)->assertJson(['message' => 'stale']);

        // Another account can neither see nor control this device.
        $this->actingAsProfile();
        $this->cmd($tv)->assertNotFound();
        $this->postJson("/remote/{$tv}/state", ['state' => ['title' => 'x']])->assertNotFound();
        $this->getJson('/remote/devices')->assertJsonCount(0, 'devices');
    }

    public function test_remote_reports_unavailable_when_reverb_is_down_but_playback_continues(): void
    {
        config(['broadcasting.default' => 'reverb', 'broadcasting.connections.reverb' => ['driver' => 'reverb', 'key' => 'k', 'secret' => 's', 'app_id' => 'a',
            'options' => ['host' => '127.0.0.1', 'port' => 9, 'scheme' => 'http', 'useTLS' => false], 'client_options' => ['timeout' => 1]]]);
        $this->actingAsProfile();
        $file = MediaFixtures::readyMovie($this->dataRoot.'/library', ['age_rating' => 0]);
        $tv = $this->postJson('/remote/register', ['name' => 'TV'])->json('uuid');
        $this->postJson("/remote/{$tv}/state", ['state' => ['title' => 'Film']])->assertOk();
        $this->cmd($tv)->assertStatus(503)->assertJson(['message' => 'realtime_unavailable']);
        $s = $this->postJson("/playback/{$file->id}/start", ['caps' => ['video' => ['h264'], 'audio' => ['aac'], 'containers' => ['mp4']]])->assertOk();
        $this->postJson("/playback/session/{$s['session']}/progress", ['position' => 1, 'duration' => 4])->assertOk();
    }

    public function test_downloads_are_policy_checked_synced_and_revoked(): void
    {
        config(['movexa.transcode.hwaccel' => 'none']);
        $profile = $this->actingAsProfile(null, ['permissions' => ['live']]);
        $file = MediaFixtures::readyMovie($this->dataRoot.'/library', ['age_rating' => 13]);
        $this->postJson('/downloads', ['file_id' => $file->id, 'quality' => 480])->assertForbidden(); // not enabled by admin

        $profile->user->update(['permissions' => ['download'], 'downloads_max' => 1]);
        $r = $this->postJson('/downloads', ['file_id' => $file->id, 'quality' => 480])->assertCreated(); // job runs (sync queue)
        $dl = Download::where('uuid', $r['uuid'])->first();
        $this->assertSame('ready', $dl->status);
        $this->postJson('/downloads', ['file_id' => $file->id, 'quality' => 720])->assertStatus(422); // count limit

        $m = $this->getJson("/downloads/{$dl->uuid}/manifest")->assertOk();
        $video = $this->get($m['video'])->assertOk();
        $this->assertStringContainsString('attachment', $video->headers->get('Content-Disposition'));

        // Offline position is synced back and the download renewed.
        $this->postJson('/downloads/sync', ['items' => [['uuid' => $dl->uuid, 'position' => 2.5]]])->assertJsonPath("items.{$dl->uuid}.action", 'keep');
        $this->assertEqualsWithDelta(2.5, WatchProgress::where('profile_id', $profile->id)->value('position'), 0.01);

        // A child account (age limit 7, download allowed) can't download 13+ content nor use someone else's copy.
        $kid = $this->account(['max_age_rating' => 7, 'permissions' => ['download']])->primaryProfile();
        $this->actingAs($kid->user);
        $this->postJson('/downloads', ['file_id' => $file->id, 'quality' => 480])->assertNotFound();
        $this->get(app(MediaSigner::class)->url('dl', $dl->id, $kid->user_id, $kid->id, 'video.mp4'))->assertNotFound();

        // A block makes the device delete it on next contact.
        $this->actingAs($profile->user);
        AccessBlock::create(['target_type' => 'item', 'target_id' => $file->media_item_id, 'subject_type' => 'user', 'subject_id' => $profile->user_id]);
        $this->postJson('/downloads/sync', ['items' => [['uuid' => $dl->uuid]]])->assertJsonPath("items.{$dl->uuid}.action", 'delete');
        $this->assertSame('revoked', $dl->fresh()->status);
        $this->assertFileDoesNotExist($dl->output_path);
    }

    public function test_download_expiry(): void
    {
        config(['movexa.transcode.hwaccel' => 'none', 'movexa.downloads.ttl_days' => 30]);
        $profile = $this->actingAsProfile(null, ['permissions' => ['download']]);
        $file = MediaFixtures::readyMovie($this->dataRoot.'/library', ['age_rating' => 0]);
        $uuid = $this->postJson('/downloads', ['file_id' => $file->id, 'quality' => 480])->json('uuid');
        $this->travel(31)->days();
        $this->getJson("/downloads/{$uuid}/manifest")->assertNotFound();
        $this->postJson('/downloads/sync', ['items' => [['uuid' => $uuid]]])->assertJsonPath("items.{$uuid}.action", 'delete');
    }
}
