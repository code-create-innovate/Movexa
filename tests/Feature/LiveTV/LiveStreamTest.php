<?php

namespace Tests\Feature\LiveTV;

use App\Modules\LiveTV\Models\Channel;
use App\Modules\LiveTV\Models\LiveSource;
use App\Modules\LiveTV\Models\LiveStream;
use App\Modules\LiveTV\Services\LiveStreamManager;
use App\Modules\LiveTV\Services\M3uParser;
use App\Modules\LiveTV\Services\XmltvImporter;
use App\Modules\LiveTV\Models\EpgProgram;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\MediaFixtures;
use Tests\TestCase;

class LiveStreamTest extends TestCase
{
    use RefreshDatabase;

    public function test_two_viewers_share_one_ffmpeg_process_which_stops_after_both_leave(): void
    {
        $src = MediaFixtures::make($this->dataRoot.'/inbox/channel.mp4', 'mp4', 20);
        $source = LiveSource::create(['name' => 'Test', 'type' => 'm3u', 'url' => 'http://example.invalid/list.m3u', 'max_streams' => 1, 'legal_ack' => true]);
        $channel = Channel::create(['live_source_id' => $source->id, 'name' => 'Test 1', 'stream_url' => 'file://'.$src]);
        $m = app(LiveStreamManager::class);

        $a = $m->acquire($channel, 'viewer-a');
        $b = $m->acquire($channel, 'viewer-b');
        $this->assertSame($a->id, $b->id);
        $this->assertSame($a->fresh()->pid, $b->fresh()->pid);
        $this->assertTrue($m->alive($a->fresh()));
        $this->assertSame(1, LiveStream::count());
        $this->assertSame(2, $m->viewerCount($a));

        // HLS is actually produced.
        for ($i = 0; $i < 80 && ! is_file($a->output_dir.'/index.m3u8'); $i++) {
            usleep(100_000);
        }
        $this->assertFileExists($a->output_dir.'/index.m3u8');
        $this->assertNotEmpty(glob($a->output_dir.'/seg_*.ts'));

        // Tuner limit: a second channel on the same 1-tuner source is refused.
        $channel2 = Channel::create(['live_source_id' => $source->id, 'name' => 'Test 2', 'stream_url' => 'file://'.$src]);
        try {
            $m->acquire($channel2, 'viewer-c');
            $this->fail('tuner limit not enforced');
        } catch (\RuntimeException $e) {
            $this->assertSame('tuner_limit', $e->getMessage());
        }

        $pid = $a->fresh()->pid;
        $m->release($a->fresh(), 'viewer-a');
        $this->assertTrue($m->alive($a->fresh()), 'still one viewer');
        $m->release($a->fresh(), 'viewer-b');
        $this->assertSame('stopped', $a->fresh()->status);
        usleep(300_000);
        $this->assertFalse(posix_kill($pid, 0) && str_contains((string) @file_get_contents("/proc/{$pid}/cmdline"), 'ffmpeg'));
    }

    public function test_reaper_stops_streams_without_heartbeat(): void
    {
        $src = MediaFixtures::make($this->dataRoot.'/inbox/c.mp4', 'mp4', 20);
        $source = LiveSource::create(['name' => 'T', 'type' => 'm3u', 'url' => 'http://x.invalid', 'max_streams' => 2, 'legal_ack' => true]);
        $channel = Channel::create(['live_source_id' => $source->id, 'name' => 'C', 'stream_url' => 'file://'.$src]);
        $m = app(LiveStreamManager::class);
        $s = $m->acquire($channel, 'v');
        $this->travel(10)->seconds();
        $m->reap();
        $this->assertSame('stopped', $s->fresh()->status);
    }

    public function test_m3u_and_xmltv_parsing(): void
    {
        $list = "#EXTM3U\n#EXTINF:-1 tvg-id=\"aljazeera.qa\" tvg-logo=\"http://l/x.png\" group-title=\"News\",Al Jazeera\nhttps://live.example/aj.m3u8\n#EXTINF:-1 group-title=\"أطفال\",Kids TV\nhttp://live.example/kids.ts\n#EXTINF:-1,Bad\nfile:///etc/passwd\n";
        $rows = (new M3uParser)->parse($list);
        $this->assertCount(2, $rows); // file:// entries are ignored
        $this->assertSame('aljazeera.qa', $rows[0]['epg_id']);
        $this->assertSame('news', M3uParser::category($rows[0]['group'], $rows[0]['name']));
        $this->assertSame('kids', M3uParser::category($rows[1]['group'], $rows[1]['name']));

        $now = now()->utc();
        $xml = $this->dataRoot.'/epg.xml';
        file_put_contents($xml, '<?xml version="1.0"?><tv><programme start="'.$now->copy()->subMinutes(10)->format('YmdHis').' +0000" stop="'.$now->copy()->addMinutes(20)->format('YmdHis').' +0000" channel="aljazeera.qa"><title>الحصاد</title><desc>نشرة</desc></programme>'
            .'<programme start="'.$now->copy()->addMinutes(20)->format('YmdHis').' +0000" stop="'.$now->copy()->addMinutes(50)->format('YmdHis').' +0000" channel="aljazeera.qa"><title>Next</title></programme></tv>');
        $this->assertSame(2, (new XmltvImporter)->import($xml));
        $this->assertSame('الحصاد', EpgProgram::where('starts_at', '<=', now())->where('ends_at', '>', now())->value('title'));
    }
}
