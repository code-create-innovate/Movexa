<?php

namespace Tests\Feature\Discovery;

use App\Modules\Core\Services\MediaSigner;
use App\Modules\Core\Services\ProfileContext;
use App\Modules\Library\Models\Episode;
use App\Modules\Library\Models\MediaFile;
use App\Modules\Library\Models\MediaItem;
use App\Modules\Library\Models\Season;
use App\Modules\Media\Services\ColorService;
use App\Modules\Playback\Models\WatchProgress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\MediaFixtures;
use Tests\TestCase;

class VisualsDataTest extends TestCase
{
    use RefreshDatabase;

    private function poster(MediaItem $item, array $rgb): void
    {
        @mkdir($this->dataRoot."/images/items/{$item->id}", 0755, true);
        $im = imagecreatetruecolor(100, 150);
        imagefill($im, 0, 0, imagecolorallocate($im, ...$rgb));
        imagejpeg($im, $this->dataRoot."/images/items/{$item->id}/poster.jpg");
        $item->update(['poster_path' => "items/{$item->id}/poster.jpg", 'backdrop_path' => "items/{$item->id}/poster.jpg"]);
        app(ColorService::class)->refreshItem($item);
    }

    public function test_dominant_color_and_small_poster(): void
    {
        $f = MediaFixtures::readyMovie($this->dataRoot.'/library', ['age_rating' => 0]);
        $this->poster($f->item, [200, 20, 30]);
        $c = $f->item->fresh()->dominant_color;
        $this->assertMatchesRegularExpression('/^#[0-9a-f]{6}$/', $c);
        [$r, $g, $b] = sscanf($c, '#%02x%02x%02x');
        $this->assertTrue($r > 150 && $g < 60 && $b < 70, $c);
        $this->assertFileExists($this->dataRoot."/images/items/{$f->item->id}/poster_small.webp");
        $this->assertSame(200, getimagesize($this->dataRoot."/images/items/{$f->item->id}/poster_small.webp")[0]);
    }

    public function test_color_timeline_covers_the_whole_title_and_needs_a_session(): void
    {
        $profile = $this->actingAsProfile();
        $f = MediaFixtures::readyMovie($this->dataRoot.'/library', ['age_rating' => 0]);
        MediaFixtures::make($f->path, 'mp4', 18);
        $f->update(['duration' => 18.0]);
        $path = app(ColorService::class)->timeline($f->fresh());
        $json = json_decode(file_get_contents($path), true);
        $this->assertGreaterThanOrEqual($f->duration, count($json['colors']) * $json['step']);

        $start = $this->postJson("/playback/{$f->id}/start", ['caps' => ['video' => ['h264'], 'audio' => ['aac'], 'containers' => ['mp4']]])->json();
        $this->assertNotNull($start['colors']);
        $res = $this->get($start['colors'])->assertOk()->assertHeader('Content-Type', 'application/json');
        $this->assertArrayHasKey('colors', json_decode(file_get_contents($res->baseResponse->getFile()->getPathname()), true));
        auth()->logout();
        $this->flushSession();
        $this->get($start['colors'])->assertForbidden();
    }

    public function test_banner_and_wall_respect_the_kid_limit_and_profile_picker_only_shows_all_ages(): void
    {
        $profile = $this->actingAsProfile();
        $kid = $profile->user->profiles()->create(['name' => 'Kid', 'is_kid' => true, 'max_age_rating' => 7]);
        $lib = $this->dataRoot.'/library';
        foreach ([0, 0, 7, 13, 16, 18, 18] as $i => $age) {
            $f = MediaFixtures::readyMovie($lib, ['title' => "T{$i}", 'age_rating' => $age]);
            $this->poster($f->item, [20 * $i, 100, 100]);
        }
        $this->withSession([ProfileContext::SESSION_KEY => $kid->id]);
        $page = $this->get('/home')->assertOk()->viewData('page')['props'];
        $this->assertNotEmpty($page['banner']);
        $this->assertLessThanOrEqual(8, count($page['banner']));
        foreach ($page['banner'] as $b) {
            $this->assertLessThanOrEqual(7, $b['age_rating']);
        }
        foreach ($page['wall'] as $w) { // every poster URL in the wall must also be fetchable by the kid → never above the limit
            $this->get($w['src'])->assertOk();
        }
        $this->assertCount(3, $page['wall']);

        // Profile picker (no profile yet): only age 0 posters.
        $this->flushSession();
        $props = $this->actingAs($profile->user)->get('/profiles')->viewData('page')['props'];
        $this->assertCount(2, $props['wall']);
        foreach ($props['wall'] as $w) {
            $this->get($w['src'])->assertOk();
        }
        $adult = MediaItem::where('age_rating', 18)->first();
        $this->get(app(MediaSigner::class)->url('img', $adult->id, $profile->user_id, null, 'poster_small'))->assertNotFound();
    }

    public function test_top10_counts_only_real_views_respects_age_and_hides_when_sparse(): void
    {
        $profile = $this->actingAsProfile();
        $lib = $this->dataRoot.'/library';
        $files = [];
        foreach (range(0, 5) as $i) {
            $files[$i] = MediaFixtures::readyMovie($lib, ['title' => "M{$i}", 'age_rating' => $i === 5 ? 18 : 0]);
        }
        $caps = ['caps' => ['video' => ['h264'], 'audio' => ['aac'], 'containers' => ['mp4']]];
        $watch = function (MediaFile $f, float $pos) use ($caps) {
            $s = $this->postJson("/playback/{$f->id}/start", $caps)->json('session');
            $this->postJson("/playback/session/{$s}/progress", ['position' => $pos, 'duration' => 100])->assertOk();
            $this->postJson("/playback/session/{$s}/progress", ['position' => $pos + 0.5, 'duration' => 100])->assertOk(); // same session → counted once
            $this->postJson("/playback/session/{$s}/stop");
        };
        $watch($files[0], 19); // below 20 % → not counted
        $this->assertSame(0, DB::table('view_events')->count());
        foreach ([[0, 3], [1, 5], [2, 1], [3, 2], [4, 4], [5, 6]] as [$i, $n]) {
            for ($k = 0; $k < $n; $k++) {
                $watch($files[$i], 25);
            }
        }
        $this->assertSame(21, DB::table('view_events')->count());

        $rows = collect($this->get('/home')->viewData('page')['props']['rows']);
        $top = $rows->firstWhere('key', 'top_movies');
        $this->assertSame(['M5', 'M1', 'M4', 'M0', 'M3', 'M2'], array_column($top['items'], 'title'));
        $this->assertSame(1, $top['items'][0]['rank']);

        // Kid: M5 (18+) excluded → 5 titles left → still shown; then old views expire → hidden.
        $kid = $profile->user->profiles()->create(['name' => 'Kid', 'is_kid' => true, 'max_age_rating' => 7]);
        $this->withSession([ProfileContext::SESSION_KEY => $kid->id]);
        $kidTop = collect($this->get('/home')->viewData('page')['props']['rows'])->firstWhere('key', 'top_movies');
        $this->assertSame(['M1', 'M4', 'M0', 'M3', 'M2'], array_column($kidTop['items'], 'title'));
        DB::table('view_events')->whereIn('media_item_id', [$files[2]->media_item_id])->delete();
        $this->assertNull(collect($this->get('/home')->viewData('page')['props']['rows'])->firstWhere('key', 'top_movies'));
        $this->travel(31)->days();
        $this->withSession([ProfileContext::SESSION_KEY => $profile->id]);
        $this->assertNull(collect($this->get('/home')->viewData('page')['props']['rows'])->firstWhere('key', 'top_movies'));
    }

    public function test_recap_after_absence_shows_last_watched_episode_only(): void
    {
        $profile = $this->actingAsProfile();
        $base = MediaFixtures::readyMovie($this->dataRoot.'/library', ['age_rating' => 0]);
        $show = MediaItem::create(['library_id' => $base->library_id, 'type' => 'show', 'title' => 'Saga', 'age_rating' => 0, 'match_key' => 'saga']);
        $season = Season::create(['media_item_id' => $show->id, 'number' => 1]);
        $files = [];
        foreach ([1, 2, 3] as $n) {
            $ep = Episode::create(['season_id' => $season->id, 'media_item_id' => $show->id, 'number' => $n, 'overview' => "Summary {$n}", 'overview_ar' => $n === 1 ? 'ملخص الحلقة الأولى' : null]);
            $files[$n] = MediaFile::create(['media_item_id' => $show->id, 'episode_id' => $ep->id, 'library_id' => $show->library_id, 'path' => $base->path, 'original_name' => "e{$n}.mp4", 'status' => 'ready', 'container' => 'mp4', 'video_codec' => 'h264', 'duration' => 4]);
        }
        WatchProgress::create(['profile_id' => $profile->id, 'media_file_id' => $files[1]->id, 'media_item_id' => $show->id, 'episode_id' => $files[1]->episode_id, 'position' => 0, 'completed' => true]);

        $this->get("/watch/{$files[2]->id}")->assertInertia(fn ($p) => $p->where('recap', null)); // watched recently
        $this->travel(15)->days();
        $this->get("/watch/{$files[2]->id}")->assertInertia(fn ($p) => $p->where('recap.number', 1)->where('recap.summary', 'ملخص الحلقة الأولى'));

        // No summary for the last watched episode → no card. Nothing about later episodes ever leaks.
        WatchProgress::create(['profile_id' => $profile->id, 'media_file_id' => $files[2]->id, 'media_item_id' => $show->id, 'episode_id' => $files[2]->episode_id, 'position' => 1, 'updated_at' => now()->subDays(20)]);
        Episode::find($files[2]->episode_id)->update(['overview' => null]);
        WatchProgress::where('media_file_id', $files[1]->id)->update(['updated_at' => now()->subDays(30)]);
        $this->get("/watch/{$files[3]->id}")->assertInertia(fn ($p) => $p->where('recap', null));

        // Setting off → never shown.
        $profile->update(['settings' => ['recap' => false]]);
        $this->get("/watch/{$files[2]->id}")->assertInertia(fn ($p) => $p->where('recap', null));
    }
}
