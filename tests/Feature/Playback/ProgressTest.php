<?php

namespace Tests\Feature\Playback;

use App\Modules\Core\Services\ProfileContext;
use App\Modules\Playback\Models\PlaybackSession;
use App\Modules\Playback\Models\WatchProgress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\MediaFixtures;
use Tests\TestCase;

class ProgressTest extends TestCase
{
    use RefreshDatabase;

    private array $chrome = ['video' => ['h264'], 'audio' => ['aac'], 'containers' => ['mp4']];

    public function test_position_is_saved_and_restored_across_devices(): void
    {
        $profile = $this->actingAsProfile();
        $file = MediaFixtures::readyMovie($this->dataRoot.'/library', ['age_rating' => 0]);

        $start = $this->postJson("/playback/{$file->id}/start", ['caps' => $this->chrome])->assertOk();
        $start->assertJson(['method' => 'direct', 'type' => 'file', 'position' => 0]);
        $this->postJson("/playback/session/{$start['session']}/progress", ['position' => 2.5, 'duration' => 4])->assertOk()->assertJson(['completed' => false]);
        $this->postJson("/playback/session/{$start['session']}/stop")->assertOk();

        // "Another device": a fresh playback session for the same profile resumes at 2.5s.
        $again = $this->postJson("/playback/{$file->id}/start", ['caps' => $this->chrome])->assertOk();
        $this->assertEqualsWithDelta(2.5, $again['position'], 0.01);
        $this->assertNotSame($start['session'], $again['session']);

        // Another person (own account) has their own progress.
        $this->actingAs($this->account());
        $this->postJson("/playback/{$file->id}/start", ['caps' => $this->chrome])->assertJson(['position' => 0]);
    }

    public function test_completion_resets_position_and_logs_history(): void
    {
        $profile = $this->actingAsProfile();
        $file = MediaFixtures::readyMovie($this->dataRoot.'/library', ['age_rating' => 0]);
        $s = $this->postJson("/playback/{$file->id}/start", ['caps' => $this->chrome])['session'];
        $this->postJson("/playback/session/{$s}/progress", ['position' => 3.9, 'duration' => 4])->assertJson(['completed' => true]);
        $this->assertTrue(WatchProgress::where('profile_id', $profile->id)->first()->completed);
        $this->assertDatabaseCount('watch_history', 1);
    }

    public function test_incompatible_file_goes_to_hls_and_stream_limit_applies(): void
    {
        Queue::fake();
        $this->actingAsProfile(null, ['max_concurrent_streams' => 1]);
        $file = MediaFixtures::readyMovie($this->dataRoot.'/library', ['age_rating' => 0], 'mkv-hevc-dts');
        $r = $this->postJson("/playback/{$file->id}/start", ['caps' => $this->chrome])->assertOk();
        $r->assertJson(['method' => 'transcode', 'type' => 'hls', 'ready' => false]);
        $this->assertStringContainsString('/master.m3u8', $r['src']);
        $this->assertSame(1, PlaybackSession::count());
        $this->postJson("/playback/{$file->id}/start", ['caps' => $this->chrome])->assertStatus(429);
        // The same session can switch quality without counting twice.
        $this->postJson("/playback/{$file->id}/start", ['caps' => $this->chrome, 'session' => $r['session'], 'quality' => '480'])->assertOk();
    }

    public function test_watch_party_state_is_shared(): void
    {
        $profile = $this->actingAsProfile();
        $file = MediaFixtures::readyMovie($this->dataRoot.'/library', ['age_rating' => 0]);
        $redirect = $this->post("/party/{$file->id}")->assertRedirect();
        $uuid = str($redirect->headers->get('Location'))->after('party=')->toString();
        $this->postJson("/party/{$uuid}/state", ['action' => 'seek', 'position' => 1.5])->assertOk()->assertJson(['playing' => false, 'position' => 1.5]);
        $this->postJson("/party/{$uuid}/state", ['action' => 'play', 'position' => 1.5])->assertJson(['playing' => true]);
        $this->postJson("/party/{$uuid}/messages", ['body' => '<b>hi</b> there'])->assertJson(['body' => 'hi there']);

        $this->actingAs($this->account()); // a second family member joins the party
        $state = $this->getJson("/party/{$uuid}/state")->assertOk();
        $this->assertTrue($state['playing']);
        $this->assertGreaterThanOrEqual(1.5, $state['position']);
        $this->assertCount(1, $state['messages']);
    }
}
