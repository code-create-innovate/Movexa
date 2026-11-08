<?php

namespace App\Modules\Playback\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Library\Models\MediaFile;
use App\Modules\Playback\Events\PartyMessagePosted;
use App\Modules\Playback\Events\PartyStateChanged;
use App\Modules\Playback\Models\WatchParty;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** Server is the source of truth for party state; Reverb broadcasts every change to all members. */
class WatchPartyController extends Controller
{
    public function create(Request $request, int $file)
    {
        $profile = $request->attributes->get('profile');
        $f = MediaFile::with('item')->find($file);
        abort_unless($f && $f->viewableBy($profile), 404);
        $party = WatchParty::create(['uuid' => (string) Str::uuid(), 'host_profile_id' => $profile->id, 'media_file_id' => $f->id, 'state_at' => now()]);

        return redirect('/watch/'.$f->id.'?party='.$party->uuid);
    }

    public function state(Request $request, string $uuid)
    {
        $party = $this->party($request, $uuid);

        return $this->payload($party) + ['messages' => $party->messages()->latest('id')->limit(50)->get()->reverse()->values()
            ->map(fn ($m) => $this->format($m))];
    }

    public function update(Request $request, string $uuid)
    {
        $party = $this->party($request, $uuid);
        $data = $request->validate(['action' => ['required', 'in:play,pause,seek'], 'position' => ['required', 'numeric', 'min:0']]);
        $party->update(['position' => $data['position'], 'playing' => $data['action'] === 'play' || ($data['action'] === 'seek' && $party->playing), 'state_at' => now()]);
        $payload = $this->payload($party) + ['action' => $data['action'], 'by' => mb_substr((string) $request->input('client', ''), 0, 40)];
        rescue(fn () => broadcast(new PartyStateChanged($party->uuid, $payload)), report: false);

        return $payload;
    }

    private function format(\App\Modules\Playback\Models\WatchPartyMessage $m): array
    {
        return ['id' => $m->id, 'profile_id' => $m->profile_id, 'name' => \App\Modules\Core\Models\Profile::find($m->profile_id)?->name, 'body' => $m->body, 'at' => $m->created_at];
    }

    public function message(Request $request, string $uuid)
    {
        $party = $this->party($request, $uuid);
        $body = trim(strip_tags($request->validate(['body' => ['required', 'string', 'max:500']])['body']));
        abort_if($body === '', 422);
        $m = $party->messages()->create(['profile_id' => $request->attributes->get('profile')->id, 'body' => $body]);
        $out = $this->format($m->fresh());
        rescue(fn () => broadcast(new PartyMessagePosted($party->uuid, $out)), report: false);

        return $out;
    }

    private function party(Request $request, string $uuid): WatchParty
    {
        $party = WatchParty::with('file.item')->where('uuid', $uuid)->where('created_at', '>=', now()->subDay())->firstOrFail();
        abort_unless($party->file->viewableBy($request->attributes->get('profile')), 404);

        return $party;
    }

    private function payload(WatchParty $p): array
    {
        return ['uuid' => $p->uuid, 'file_id' => $p->media_file_id, 'playing' => $p->playing, 'position' => round($p->currentPosition(), 2), 'server_time' => now()->getTimestampMs(), 'host' => $p->host_profile_id];
    }
}
