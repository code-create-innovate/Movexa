<?php

namespace App\Modules\Devices\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Devices\Events\RemoteCommand;
use App\Modules\Devices\Models\RemoteDevice;
use App\Support\Realtime;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;

/**
 * Phone as a remote. Commands go phone → server (authenticated, validated) → Reverb private channel of the player.
 * No client-to-client events. Each command carries a server sequence number and a 10 s expiry; duplicates (same nonce)
 * and stale commands are refused here, and the player ignores anything older than the last sequence it applied.
 */
class RemoteController extends Controller
{
    public const ACTIONS = ['play', 'pause', 'seek', 'skip', 'audio', 'subtitle', 'next', 'stop'];

    public function page()
    {
        return Inertia::render('devices/remote');
    }

    public function register(Request $request)
    {
        $d = $request->validate(['uuid' => ['nullable', 'uuid'], 'name' => ['nullable', 'string', 'max:120']]);
        $device = ! empty($d['uuid']) ? RemoteDevice::where('uuid', $d['uuid'])->where('user_id', $request->user()->id)->first() : null;
        $device ??= new RemoteDevice(['uuid' => (string) Str::uuid(), 'user_id' => $request->user()->id]);
        $device->fill(['name' => ($d['name'] ?? null) ?: \App\Modules\Devices\Models\Device::nameFromAgent($request->userAgent()), 'profile_id' => $request->attributes->get('profile')->id])->save();

        return ['uuid' => $device->uuid, 'last_seq' => $device->last_seq];
    }

    public function state(Request $request, string $uuid)
    {
        $device = $this->own($request, $uuid);
        $state = $request->validate(['state' => ['required', 'array']])['state'];
        $device->update(['state' => array_intersect_key($state, array_flip(['title', 'subtitle', 'file_id', 'position', 'duration', 'paused', 'audio', 'audio_index', 'subtitles', 'subtitle_id', 'has_next'])), 'state_at' => now()]);

        return ['ok' => true];
    }

    public function devices(Request $request)
    {
        return ['devices' => RemoteDevice::where('user_id', $request->user()->id)->where('uuid', '!=', (string) $request->query('self'))->latest('state_at')->get()
            ->filter(fn ($d) => $d->online())->map(fn ($d) => ['uuid' => $d->uuid, 'name' => $d->name, 'state' => $d->state])->values()];
    }

    public function command(Request $request, string $uuid)
    {
        $device = $this->own($request, $uuid);
        $d = $request->validate([
            'action' => ['required', 'in:'.implode(',', self::ACTIONS)], 'value' => ['nullable'],
            'nonce' => ['required', 'string', 'max:64'], 'sent_at' => ['required', 'integer'],
        ]);
        if (abs(now()->getTimestampMs() - $d['sent_at']) > 10_000) {
            return response()->json(['message' => 'stale'], 409);
        }
        if (! Cache::add('remote-nonce:'.$request->user()->id.':'.$d['nonce'], 1, 120)) {
            return response()->json(['message' => 'duplicate'], 409);
        }
        if (! $device->online()) {
            return response()->json(['message' => 'device_offline'], 409);
        }
        $seq = DB::transaction(function () use ($device) {
            $locked = RemoteDevice::whereKey($device->id)->lockForUpdate()->first();
            $locked->increment('last_seq');

            return $locked->last_seq;
        });
        $cmd = ['seq' => $seq, 'action' => $d['action'], 'value' => $d['value'] ?? null, 'expires_at' => now()->addSeconds(10)->getTimestampMs()];
        if (! Realtime::send(new RemoteCommand($device->uuid, $cmd))) {
            return response()->json(['message' => 'realtime_unavailable'], 503); // phone shows the remote as unavailable
        }

        return ['ok' => true, 'seq' => $seq];
    }

    private function own(Request $request, string $uuid): RemoteDevice
    {
        $device = RemoteDevice::where('uuid', $uuid)->first();
        abort_unless($device && $device->user_id === $request->user()->id, 404); // other accounts: indistinguishable from "not found"

        return $device;
    }
}
