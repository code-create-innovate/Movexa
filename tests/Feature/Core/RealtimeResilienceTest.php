<?php

namespace Tests\Feature\Core;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\MediaFixtures;
use Tests\TestCase;

/** Reverb down must never break playback, transcoding, watch party or blocking. */
class RealtimeResilienceTest extends TestCase
{
    use RefreshDatabase;

    public function test_everything_keeps_working_when_reverb_is_unreachable(): void
    {
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb' => ['driver' => 'reverb', 'key' => 'k', 'secret' => 's', 'app_id' => 'a',
                'options' => ['host' => '127.0.0.1', 'port' => 9, 'scheme' => 'http', 'useTLS' => false], 'client_options' => ['timeout' => 1]],
            'movexa.transcode.hwaccel' => 'none',
        ]);
        $profile = $this->actingAsProfile(null, ['password' => 'pw-123456', 'is_admin' => true]);
        $file = MediaFixtures::readyMovie($this->dataRoot.'/library', ['age_rating' => 0], 'mkv-hevc-dts');

        // transcode runs synchronously here and broadcasts progress → must not fail
        $this->postJson("/playback/{$file->id}/start", ['caps' => ['video' => ['h264'], 'audio' => ['aac'], 'containers' => ['mp4']]])
            ->assertOk()->assertJson(['type' => 'hls', 'ready' => true]);

        $uuid = str($this->post("/party/{$file->id}")->headers->get('Location'))->after('party=')->toString();
        $this->postJson("/party/{$uuid}/state", ['action' => 'play', 'position' => 1])->assertOk();
        $this->postJson("/party/{$uuid}/messages", ['body' => 'hi'])->assertOk();

        $other = $this->account();
        $this->post('/blocks', ['target_type' => 'item', 'target_id' => $file->media_item_id, 'users' => [$other->id], 'secret' => 'pw-123456'])->assertRedirect();
    }
}
