<?php

namespace Tests\Feature\Core;

use App\Models\User;
use App\Modules\Core\Services\MediaSigner;
use App\Modules\Core\Services\ProfileContext;
use App\Modules\Library\Models\Subtitle;
use App\Modules\Playback\Models\TranscodeJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\MediaFixtures;
use Tests\TestCase;

/** No video file, HLS segment, subtitle or image is reachable without a valid session + token. */
class MediaAccessTest extends TestCase
{
    use RefreshDatabase;

    private function setupFile(): array
    {
        $profile = $this->actingAsProfile();
        $file = MediaFixtures::readyMovie($this->dataRoot.'/library', ['age_rating' => 0]);
        $hlsDir = $this->dataRoot.'/cache/hls/'.$file->id.'/remux';
        @mkdir($hlsDir.'/src', 0755, true);
        file_put_contents($hlsDir.'/master.m3u8', "#EXTM3U\n");
        file_put_contents($hlsDir.'/src/seg_00001.m4s', 'SEGMENT');
        $job = TranscodeJob::create(['media_file_id' => $file->id, 'mode' => 'remux', 'profile_key' => 'remux', 'status' => 'done', 'output_dir' => $hlsDir]);
        file_put_contents($this->dataRoot.'/library/s.vtt', "WEBVTT\n");
        $sub = Subtitle::create(['media_file_id' => $file->id, 'label' => 'AR', 'path' => $this->dataRoot.'/library/s.vtt', 'source' => 'upload']);

        return [$profile, $file, $job, $sub];
    }

    public function test_valid_session_and_token_streams_with_range_support(): void
    {
        [$profile, $file] = $this->setupFile();
        $url = app(MediaSigner::class)->url('file', $file->id, $profile->user_id, $profile->id, 'stream.mp4');
        $r = $this->get($url, ['Range' => 'bytes=0-99']);
        $r->assertStatus(206);
        $r->assertHeader('Content-Range', 'bytes 0-99/'.filesize($file->path));
        $r->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringStartsWith('video/mp4', $r->headers->get('Content-Type'));
    }

    public function test_every_media_kind_is_blocked_without_session(): void
    {
        [$profile, $file, $job, $sub] = $this->setupFile();
        $s = app(MediaSigner::class);
        $urls = [
            $s->url('file', $file->id, $profile->user_id, $profile->id, 'stream.mp4'),
            $s->url('hls', $job->id, $profile->user_id, $profile->id, 'master.m3u8'),
            $s->url('hls', $job->id, $profile->user_id, $profile->id, 'src/seg_00001.m4s'),
            $s->url('sub', $sub->id, $profile->user_id, $profile->id, 'subtitle.vtt'),
        ];
        // sanity: all reachable with the session
        foreach ($urls as $u) {
            $this->get($u)->assertOk();
        }
        auth()->logout();
        $this->flushSession();
        foreach ($urls as $u) {
            $this->get($u)->assertForbidden();
        }
    }

    public function test_token_of_another_user_is_rejected(): void
    {
        [, $file] = $this->setupFile();
        $other = User::factory()->create();
        $url = app(MediaSigner::class)->url('file', $file->id, $other->id, null, 'stream.mp4');
        $this->get($url)->assertForbidden();
    }

    public function test_expired_and_tampered_tokens_are_rejected(): void
    {
        [$profile, $file] = $this->setupFile();
        $s = app(MediaSigner::class);
        $this->get($s->url('file', $file->id, $profile->user_id, $profile->id, 'x.mp4', -5))->assertForbidden();

        $token = $s->sign('file', $file->id, $profile->user_id, $profile->id);
        [$body, $sig] = explode('.', $token);
        $payload = json_decode(base64_decode(strtr($body, '-_', '+/')), true);
        $payload['i'] = '999';
        $forged = rtrim(strtr(base64_encode(json_encode($payload)), '+/', '-_'), '=').'.'.$sig;
        $this->get("/m/{$forged}/x.mp4")->assertForbidden();
        $this->get('/m/garbage/x.mp4')->assertForbidden();
    }

    public function test_token_bound_to_profile(): void
    {
        [$profile, $file] = $this->setupFile();
        $second = $profile->user->profiles()->create(['name' => 'Other']);
        $url = app(MediaSigner::class)->url('file', $file->id, $profile->user_id, $profile->id, 'x.mp4');
        $this->withSession([ProfileContext::SESSION_KEY => $second->id])->get($url)->assertForbidden();
    }

    public function test_path_traversal_is_impossible(): void
    {
        [$profile, , $job] = $this->setupFile();
        $token = app(MediaSigner::class)->sign('hls', $job->id, $profile->user_id, $profile->id);
        $this->get("/m/{$token}/../../../../etc/passwd")->assertNotFound();
        $this->get("/m/{$token}/src/..%2F..%2Fmaster.m3u8")->assertNotFound();
        $this->get("/m/{$token}/.env")->assertNotFound();
    }

    public function test_xaccel_mode_delegates_to_internal_location(): void
    {
        [$profile, $file] = $this->setupFile();
        config(['movexa.serve_mode' => 'xaccel']);
        $url = app(MediaSigner::class)->url('file', $file->id, $profile->user_id, $profile->id, 'x.mp4');
        $r = $this->get($url)->assertOk();
        $this->assertStringStartsWith('/_protected/library/', $r->headers->get('X-Accel-Redirect'));
        $this->assertSame('', $r->getContent());
    }

    public function test_items_not_ready_are_never_served(): void
    {
        [$profile, $file] = $this->setupFile();
        $file->update(['status' => 'scanning']);
        $this->get(app(MediaSigner::class)->url('file', $file->id, $profile->user_id, $profile->id, 'x.mp4'))->assertNotFound();
    }
}
