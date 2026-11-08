<?php

namespace Tests\Feature\Core;

use App\Models\User;
use App\Modules\Core\Events\AccessRevoked;
use App\Modules\Core\Models\AccessBlock;
use App\Modules\Core\Models\ApiToken;
use App\Modules\Core\Models\AuditLog;
use App\Modules\Core\Services\MediaSigner;
use App\Modules\Core\Services\ProfileContext;
use App\Modules\Library\Models\Episode;
use App\Modules\Library\Models\MediaFile;
use App\Modules\Library\Models\MediaItem;
use App\Modules\Library\Models\Season;
use App\Modules\LiveTV\Models\Channel;
use App\Modules\LiveTV\Models\LiveSource;
use App\Modules\Playback\Models\WatchProgress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Tests\Support\MediaFixtures;
use Tests\TestCase;

class AccessBlockTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private $a;

    private $b;

    private MediaFile $movie;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create(['password' => 'secret-pass', 'oidc_subject' => 'hc-b']);
        $this->b = $this->owner->profiles()->create(['name' => 'B']); // first non-kid profile → used by HomeCore API
        $this->a = $this->owner->profiles()->create(['name' => 'A']);
        $this->movie = MediaFixtures::readyMovie($this->dataRoot.'/library', ['title' => 'Horror Night', 'age_rating' => 16, 'genres' => ['Horror'], 'poster_path' => 'p.webp']);
        file_put_contents($this->dataRoot.'/images/p.webp', 'x');
    }

    private function as($profile): void
    {
        $this->actingAs($profile->user)->withSession([ProfileContext::SESSION_KEY => $profile->id]);
    }

    private function block(string $type, $id, $value = null, $subject = null, $expires = null): AccessBlock
    {
        $subject ??= $this->b;

        return AccessBlock::create(['target_type' => $type, 'target_id' => $id, 'target_value' => $value, 'subject_type' => 'profile', 'subject_id' => $subject->id, 'expires_at' => $expires]);
    }

    private function assertHidden($profile, MediaFile $file): void
    {
        $this->as($profile);
        $s = app(MediaSigner::class);
        $item = $file->media_item_id;
        $this->getJson('/search?q='.urlencode(MediaItem::find($item)->title))->assertJsonCount(0, 'results');
        $this->get('/home')->assertOk()->assertInertia(fn ($p) => $p->where('rows', fn ($rows) => collect($rows)->pluck('items')->flatten(1)->pluck('id')->doesntContain($item)));
        $this->get("/title/{$item}")->assertNotFound();
        $this->get("/watch/{$file->id}")->assertNotFound();
        $this->postJson("/playback/{$file->id}/start", [])->assertNotFound();
        $this->post("/party/{$file->id}")->assertNotFound();
        $this->post("/title/{$item}/list")->assertNotFound();
        $this->get($s->url('file', $file->id, $profile->user_id, $profile->id, 'x.mp4'))->assertNotFound();
        $this->get($s->url('img', $item, $profile->user_id, $profile->id, 'poster'))->assertNotFound();
        $this->get($s->url('thumb', $file->id, $profile->user_id, $profile->id, 'thumbnails.vtt'))->assertNotFound();
    }

    public function test_item_block_hides_title_everywhere_for_that_profile_only(): void
    {
        WatchProgress::create(['profile_id' => $this->b->id, 'media_file_id' => $this->movie->id, 'media_item_id' => $this->movie->media_item_id, 'position' => 100, 'duration' => 400]);
        $this->block('item', $this->movie->media_item_id);
        $this->assertHidden($this->b, $this->movie);

        // HomeCore API (uses the account's first adult profile = B)
        [, $token] = ApiToken::issue('hc', ['homecore:read']);
        $h = ['Authorization' => "Bearer {$token}"];
        $this->getJson('/api/homecore/continue-watching?user=hc-b', $h)->assertJsonCount(0, 'items');
        $this->getJson('/api/homecore/search?user=hc-b&q=Horror', $h)->assertJsonCount(0, 'results');
        $this->get("/api/homecore/poster/{$this->movie->media_item_id}?user=hc-b", $h)->assertNotFound();

        // Profile A is unaffected.
        $this->as($this->a);
        $this->get("/title/{$this->movie->media_item_id}")->assertOk();
        $this->getJson('/search?q=Horror')->assertJsonCount(1, 'results');
    }

    public function test_genre_block_applies_to_titles_added_later(): void
    {
        $this->block('genre', null, 'horror');
        $later = MediaFixtures::readyMovie($this->dataRoot.'/library', ['title' => 'New Scream', 'age_rating' => 13, 'genres' => ['Horror', 'Comedy']]);
        $this->assertHidden($this->b, $later);
        $this->assertHidden($this->b, $this->movie);
    }

    public function test_show_block_covers_seasons_and_episodes_and_season_block_is_partial(): void
    {
        $show = MediaItem::create(['library_id' => $this->movie->library_id, 'type' => 'show', 'title' => 'Saga', 'age_rating' => 0, 'match_key' => 'saga']);
        $files = [];
        foreach ([1, 2] as $sn) {
            $season = Season::create(['media_item_id' => $show->id, 'number' => $sn]);
            $ep = Episode::create(['season_id' => $season->id, 'media_item_id' => $show->id, 'number' => 1]);
            $files[$sn] = MediaFile::create(['media_item_id' => $show->id, 'episode_id' => $ep->id, 'library_id' => $show->library_id, 'path' => $this->movie->path, 'original_name' => "s{$sn}.mp4", 'status' => 'ready', 'container' => 'mp4', 'video_codec' => 'h264', 'duration' => 4]);
        }
        $seasonBlock = $this->block('season', Season::where('number', 1)->value('id'));
        $this->as($this->b);
        $this->get("/watch/{$files[1]->id}")->assertNotFound();
        $this->get("/watch/{$files[2]->id}")->assertOk();
        $this->get("/title/{$show->id}")->assertOk()->assertInertia(fn ($p) => $p->has('seasons', 1)->where('seasons.0.number', 2));

        $seasonBlock->delete();
        $this->block('item', $show->id);
        $this->assertHidden($this->b, $files[1]);
        $this->assertHidden($this->b, $files[2]);
    }

    public function test_account_block_covers_every_profile_of_the_account(): void
    {
        AccessBlock::create(['target_type' => 'item', 'target_id' => $this->movie->media_item_id, 'subject_type' => 'user', 'subject_id' => $this->owner->id]);
        $this->assertHidden($this->a, $this->movie);
        $this->assertHidden($this->b, $this->movie);
    }

    public function test_temporary_block_expires_on_time(): void
    {
        $this->block('item', $this->movie->media_item_id, null, null, now()->addHour());
        $this->as($this->b);
        $this->get("/title/{$this->movie->media_item_id}")->assertNotFound();
        $this->travel(61)->minutes();
        $this->get("/title/{$this->movie->media_item_id}")->assertOk();
    }

    public function test_block_during_playback_stops_media_and_broadcasts_stop(): void
    {
        Event::fake([AccessRevoked::class]);
        $this->as($this->b);
        $url = app(MediaSigner::class)->url('file', $this->movie->id, $this->owner->id, $this->b->id, 'x.mp4');
        $this->get($url)->assertOk();
        $this->post('/blocks', ['target_type' => 'item', 'target_id' => $this->movie->media_item_id, 'profiles' => [$this->b->id], 'secret' => 'secret-pass'])->assertRedirect();
        $this->get($url)->assertNotFound();          // same still-valid token: refused immediately
        $this->postJson("/playback/{$this->movie->id}/start", [])->assertNotFound(); // no new/renewed URLs
        Event::assertDispatched(AccessRevoked::class, fn ($e) => in_array($this->b->id, $e->profileIds, true));
        $this->assertTrue(AuditLog::where('action', 'block.created')->exists());
    }

    public function test_channels_and_channel_categories(): void
    {
        $src = LiveSource::create(['name' => 'S', 'type' => 'm3u', 'url' => 'http://x.invalid', 'max_streams' => 1, 'legal_ack' => true]);
        $news = Channel::create(['live_source_id' => $src->id, 'name' => 'News 1', 'category' => 'news', 'stream_url' => 'http://x.invalid/1']);
        $sport = Channel::create(['live_source_id' => $src->id, 'name' => 'Sport 1', 'category' => 'sports', 'stream_url' => 'http://x.invalid/2']);
        $this->block('channel', $news->id);
        $this->block('channel_category', null, 'Sports');
        $this->as($this->b);
        $this->get('/live')->assertInertia(fn ($p) => $p->has('channels', 0));
        $this->postJson("/live/{$news->id}/watch")->assertNotFound();
        $this->postJson("/live/{$sport->id}/watch")->assertNotFound();
        $this->as($this->a);
        $this->get('/live')->assertInertia(fn ($p) => $p->has('channels', 2));
    }

    public function test_owner_permissions_secret_and_visibility_of_the_list(): void
    {
        $stranger = User::factory()->create();
        $strangerProfile = $stranger->profiles()->create(['name' => 'X']);
        $this->as($this->a);
        $base = ['target_type' => 'item', 'target_id' => $this->movie->media_item_id];
        $this->post('/blocks', $base + ['profiles' => [$this->b->id], 'secret' => 'wrong'])->assertSessionHasErrors('secret');
        $this->post('/blocks', $base + ['profiles' => [$strangerProfile->id], 'secret' => 'secret-pass'])->assertStatus(422); // not your account
        $this->post('/blocks', $base + ['profiles' => [$this->b->id], 'secret' => 'secret-pass'])->assertRedirect();
        $this->assertSame(1, AccessBlock::count());

        // The blocked profile does not see the block in the list.
        $this->as($this->b);
        $this->get('/blocks')->assertInertia(fn ($p) => $p->has('blocks', 0));
        $this->as($this->a);
        $this->get('/blocks')->assertInertia(fn ($p) => $p->has('blocks', 1)->where('warning_no_pin', true));

        // Kid profiles can't open or change blocks.
        $kid = $this->owner->profiles()->create(['name' => 'Kid', 'is_kid' => true]);
        $this->as($kid);
        $this->get('/blocks')->assertForbidden();
        $this->delete('/blocks', ['ids' => [AccessBlock::first()->id], 'secret' => 'secret-pass'])->assertForbidden();

        $this->as($this->a);
        $this->delete('/blocks', ['ids' => [AccessBlock::first()->id], 'secret' => 'secret-pass'])->assertRedirect();
        $this->assertSame(0, AccessBlock::count());
        $this->assertTrue(AuditLog::where('action', 'block.removed')->exists());
    }

    public function test_admin_view_as_is_read_only_and_shows_what_the_profile_sees(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $ap = $admin->profiles()->create(['name' => 'Admin']);
        $this->block('item', $this->movie->media_item_id);
        $this->actingAs($admin)->withSession([ProfileContext::SESSION_KEY => $ap->id]);
        $this->post("/view-as/{$this->b->id}")->assertRedirect('/home');
        $this->get("/title/{$this->movie->media_item_id}")->assertNotFound(); // sees exactly what B sees
        $this->post("/title/{$this->movie->media_item_id}/list")->assertForbidden(); // read-only
        $this->post('/view-as/exit')->assertRedirect('/admin/blocks');
        $this->get("/title/{$this->movie->media_item_id}")->assertOk();
    }

    public function test_policy_fails_closed(): void
    {
        $this->as($this->a);
        $this->get("/title/{$this->movie->media_item_id}")->assertOk();
        Schema::drop('access_blocks');
        $this->get("/title/{$this->movie->media_item_id}")->assertNotFound();
        $this->getJson('/search?q=Horror')->assertJsonCount(0, 'results');
    }
}
