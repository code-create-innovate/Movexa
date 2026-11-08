<?php

namespace Tests\Feature\Core;

use App\Models\User;
use App\Modules\Core\Services\MediaSigner;
use App\Modules\Core\Services\ProfileContext;
use App\Modules\Library\Models\Subtitle;
use App\Modules\Playback\Models\TranscodeJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\Support\MediaFixtures;
use Tests\TestCase;

/** A kid profile must not reach 18+ (or unrated) content through ANY route. */
class ParentalControlTest extends TestCase
{
    use RefreshDatabase;

    public function test_kid_cannot_reach_adult_content_by_any_path(): void
    {
        $user = User::factory()->create();
        $kid = $user->profiles()->create(['name' => 'Kid', 'is_kid' => true, 'max_age_rating' => 7]);
        $adultFile = MediaFixtures::readyMovie($this->dataRoot.'/library', ['title' => 'Adult Film', 'age_rating' => 18, 'poster_path' => 'p.webp']);
        $unrated = MediaFixtures::readyMovie($this->dataRoot.'/library', ['title' => 'Unrated Film', 'age_rating' => null]);
        $kidsFile = MediaFixtures::readyMovie($this->dataRoot.'/library', ['title' => 'Cartoon', 'age_rating' => 0]);
        file_put_contents($this->dataRoot.'/images/p.webp', 'x');
        file_put_contents($this->dataRoot.'/library/a.vtt', "WEBVTT\n");
        $sub = Subtitle::create(['media_file_id' => $adultFile->id, 'label' => 'x', 'path' => $this->dataRoot.'/library/a.vtt', 'source' => 'upload']);
        $job = TranscodeJob::create(['media_file_id' => $adultFile->id, 'mode' => 'remux', 'profile_key' => 'remux', 'status' => 'done', 'output_dir' => $this->dataRoot.'/cache']);
        file_put_contents($this->dataRoot.'/cache/master.m3u8', '#EXTM3U');

        $this->actingAs($user)->withSession([ProfileContext::SESSION_KEY => $kid->id]);

        $this->get('/home')->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->component('browse/home')
            ->where('rows', fn ($rows) => collect($rows)->pluck('items')->flatten(1)->pluck('title')->diff(['Cartoon'])->isEmpty()));
        $this->getJson('/search?q=Film')->assertOk()->assertJsonCount(0, 'results');
        $this->getJson('/search?q=Cartoon')->assertJsonCount(1, 'results');

        foreach ([$adultFile, $unrated] as $f) {
            $this->get("/title/{$f->media_item_id}")->assertNotFound();
            $this->get("/watch/{$f->id}")->assertNotFound();
            $this->postJson("/playback/{$f->id}/start", [])->assertNotFound();
            $this->post("/title/{$f->media_item_id}/list")->assertNotFound();
            $this->post("/party/{$f->id}")->assertNotFound();
        }
        $s = app(MediaSigner::class);
        // Even with a correctly signed token, the media gateway re-checks the profile's age limit.
        $this->get($s->url('file', $adultFile->id, $user->id, $kid->id, 'x.mp4'))->assertNotFound();
        $this->get($s->url('hls', $job->id, $user->id, $kid->id, 'master.m3u8'))->assertNotFound();
        $this->get($s->url('sub', $sub->id, $user->id, $kid->id, 'x.vtt'))->assertNotFound();
        $this->get($s->url('img', $adultFile->media_item_id, $user->id, $kid->id, 'poster'))->assertNotFound();
        $this->get($s->url('thumb', $adultFile->id, $user->id, $kid->id, 'thumbnails.vtt'))->assertNotFound();

        // The allowed title works.
        $this->get("/title/{$kidsFile->media_item_id}")->assertOk();
        $this->get($s->url('file', $kidsFile->id, $user->id, $kid->id, 'x.mp4'))->assertOk();
    }
}
