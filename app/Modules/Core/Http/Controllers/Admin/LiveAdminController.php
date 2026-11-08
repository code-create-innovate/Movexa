<?php

namespace App\Modules\Core\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\LiveTV\Models\Channel;
use App\Modules\LiveTV\Models\LiveSource;
use App\Modules\LiveTV\Models\LiveStream;
use App\Modules\LiveTV\Models\Recording;
use App\Modules\LiveTV\Services\LiveSourceImporter;
use Illuminate\Http\Request;
use Inertia\Inertia;

class LiveAdminController extends Controller
{
    public function index()
    {
        return Inertia::render('admin/live', [
            'sources' => LiveSource::withCount('channels')->get()->map(fn ($s) => $s->only('id', 'name', 'type', 'max_streams', 'refreshed_at', 'channels_count')),
            'channels' => Channel::orderBy('sort')->get(['id', 'live_source_id', 'name', 'number', 'category', 'epg_id', 'age_rating', 'sort', 'enabled']),
            'streams' => LiveStream::with('channel:id,name')->where('status', '!=', 'stopped')->get(),
            'recordings' => Recording::with('channel:id,name')->latest('starts_at')->limit(50)->get(),
        ]);
    }

    public function storeSource(Request $request, LiveSourceImporter $importer)
    {
        $d = $request->validate([
            'name' => ['required', 'max:80'], 'type' => ['required', 'in:m3u,hdhomerun,dvb'],
            'url' => ['required', 'max:2000'], 'epg_url' => ['nullable', 'max:2000'], 'max_streams' => ['required', 'integer', 'between:1,16'],
            'legal_ack' => ['accepted'], // the user confirms the source is licensed / legally owned
        ]);
        abort_unless(preg_match('#^(https?|udp|rtp|rtsp)://#i', $d['url']), 422, 'unsupported url');
        $source = LiveSource::create($d + ['legal_ack' => true]);
        $importer->refresh($source);

        return back();
    }

    public function refresh(LiveSource $source, LiveSourceImporter $importer)
    {
        $importer->refresh($source);

        return back();
    }

    public function destroySource(LiveSource $source)
    {
        $source->delete();

        return back();
    }

    public function updateChannel(Request $request, Channel $channel)
    {
        $channel->update($request->validate(['name' => ['sometimes', 'max:120'], 'category' => ['nullable', 'max:32'], 'age_rating' => ['nullable', 'integer', 'between:0,18'], 'sort' => ['integer'], 'enabled' => ['boolean'], 'epg_id' => ['nullable', 'max:190']]));

        return back();
    }

    public function record(Request $request, Channel $channel)
    {
        $d = $request->validate(['title' => ['required', 'max:200'], 'starts_at' => ['required', 'date'], 'ends_at' => ['required', 'date', 'after:starts_at']]);
        Recording::create($d + ['channel_id' => $channel->id, 'user_id' => $request->user()->id]);

        return back();
    }

    public function cancelRecording(Recording $recording)
    {
        $recording->update(['status' => 'cancelled']);

        return back();
    }
}
