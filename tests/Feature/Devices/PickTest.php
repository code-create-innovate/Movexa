<?php

namespace Tests\Feature\Devices;

use App\Modules\Core\Models\AccessBlock;
use App\Modules\Core\Services\ProfileContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\MediaFixtures;
use Tests\TestCase;

class PickTest extends TestCase
{
    use RefreshDatabase;

    public function test_pick_respects_age_scan_state_blocks_history_and_filters(): void
    {
        $profile = $this->actingAsProfile();
        $kid = $this->account(['max_age_rating' => 7])->primaryProfile();
        $lib = $this->dataRoot.'/library';
        $kids = MediaFixtures::readyMovie($lib, ['title' => 'Cartoon', 'age_rating' => 0, 'genres' => ['Animation'], 'vote_average' => 8]);
        $adult = MediaFixtures::readyMovie($lib, ['title' => 'Adult', 'age_rating' => 18, 'genres' => ['Drama'], 'vote_average' => 6]);
        $scanning = MediaFixtures::readyMovie($lib, ['title' => 'Scanning', 'age_rating' => 0]);
        $scanning->update(['status' => 'scanning']);
        $seen = MediaFixtures::readyMovie($lib, ['title' => 'Seen', 'age_rating' => 0]);
        DB::table('watch_history')->insert(['profile_id' => $profile->id, 'media_item_id' => $seen->media_item_id, 'watched_at' => now()]);
        $blocked = MediaFixtures::readyMovie($lib, ['title' => 'Blocked', 'age_rating' => 0]);
        AccessBlock::create(['target_type' => 'item', 'target_id' => $blocked->media_item_id, 'subject_type' => 'user', 'subject_id' => $profile->user_id]);

        $titles = [];
        for ($i = 0; $i < 20; $i++) {
            $titles[] = $this->getJson('/pick')->json('item.title');
        }
        $this->assertEqualsCanonicalizing(['Cartoon', 'Adult'], array_values(array_unique(array_filter($titles))));

        $fresh = fn () => $this->withSession(['pick_recent' => []]);
        $fresh();
        $this->getJson('/pick?genre=Drama')->assertJsonPath('item.title', 'Adult');
        $fresh();
        $this->getJson('/pick?min_vote=7.5')->assertJsonPath('item.title', 'Cartoon');
        $fresh();
        $this->getJson('/pick?type=show')->assertJsonPath('item', null);
        $fresh();
        $this->getJson('/pick?max_minutes=1')->assertJsonPath('item.title', fn ($t) => in_array($t, ['Cartoon', 'Adult'], true)); // 4 s fixtures

        $this->actingAs($kid->user)->withSession(['pick_recent' => []]);
        for ($i = 0; $i < 10; $i++) {
            $this->withSession(['pick_recent' => []]);
            $this->assertContains($this->getJson('/pick')->json('item.title'), ['Cartoon', 'Seen', 'Blocked']);
        }
    }

    public function test_no_repeat_of_recent_picks_and_empty_message(): void
    {
        $this->actingAsProfile();
        MediaFixtures::readyMovie($this->dataRoot.'/library', ['title' => 'Only One', 'age_rating' => 0]);
        $this->getJson('/pick')->assertJsonPath('item.title', 'Only One');
        $this->getJson('/pick')->assertJsonPath('item', null); // not repeated in this session
    }
}
