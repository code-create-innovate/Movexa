<?php

namespace App\Modules\Devices\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Core\Models\AuditLog;
use App\Modules\Core\Services\ProfileContext;
use App\Modules\Devices\Models\Device;
use App\Modules\Devices\Models\DeviceLogin;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Inertia\Inertia;

/**
 * TV sign-in with a QR code / 6-character code (one-time, 5 minutes). Works locally even if HomeCore is down.
 * Scanning alone does nothing: a logged-in phone must explicitly press "Approve". The TV then lands on the
 * profile picker — profile PINs and parental limits still apply.
 */
class DeviceLoginController extends Controller
{
    public function tv()
    {
        return Inertia::render('devices/tv-login');
    }

    public function start(Request $request)
    {
        $plain = Str::random(48);
        $login = DeviceLogin::create([
            'code' => DeviceLogin::newCode(),
            'poll_hash' => hash('sha256', $plain),
            'device_name' => mb_substr($request->input('name') ?: Device::nameFromAgent($request->userAgent()), 0, 120),
            'ip' => $request->ip(),
            'expires_at' => now()->addMinutes(5),
        ]);

        return [
            'code' => $login->code,
            'poll_token' => $plain,
            'url' => rtrim(config('app.url'), '/').'/link?code='.$login->code, // includes :8088 via APP_URL
            'expires_at' => $login->expires_at->toIso8601String(),
        ];
    }

    public function poll(Request $request)
    {
        $login = DeviceLogin::where('poll_hash', hash('sha256', (string) $request->input('poll_token')))->first();
        if (! $login) {
            return response()->json(['status' => 'invalid'], 404);
        }
        if ($login->status === 'consumed' || $login->expires_at->isPast()) {
            $login->status === 'consumed' || $login->update(['status' => 'expired']);

            return response()->json(['status' => 'expired'], 410);
        }
        if ($login->status !== 'approved') {
            return response()->json(['status' => 'pending'], 202);
        }
        $login->update(['status' => 'consumed']); // one-time
        $request->session()->put('device_kind', 'tv');
        Auth::login(User::findOrFail($login->user_id), true);
        $request->session()->regenerate();
        $device = Device::create(['user_id' => $login->user_id, 'name' => $login->device_name, 'kind' => 'tv', 'session_id' => $request->session()->getId(), 'last_seen_at' => now()]);
        $request->session()->put('device_id', $device->id);
        AuditLog::record('device.login', ['device' => $device->id, 'name' => $login->device_name], $login->user_id);

        return ['status' => 'approved', 'redirect' => '/home'];
    }

    /** Phone side. */
    public function link(Request $request)
    {
        return Inertia::render('devices/link', ['code' => strtoupper((string) $request->query('code', ''))]);
    }

    public function lookup(Request $request)
    {
        $login = DeviceLogin::where('code', strtoupper((string) $request->query('code')))->first();
        abort_unless($login && $login->status === 'pending' && $login->expires_at->isFuture(), 404);

        return ['code' => $login->code, 'device_name' => $login->device_name, 'expires_at' => $login->expires_at->toIso8601String()];
    }

    public function approve(Request $request, ProfileContext $ctx)
    {
        abort_if($ctx->viewingAs(), 403);
        $login = DeviceLogin::where('code', strtoupper((string) $request->input('code')))->first();
        abort_unless($login && $login->status === 'pending' && $login->expires_at->isFuture(), 404);
        $login->update(['status' => 'approved', 'user_id' => $request->user()->id]);
        AuditLog::record('device.approved', ['code_id' => $login->id, 'name' => $login->device_name]);

        return ['status' => 'approved', 'device_name' => $login->device_name];
    }

    public function devices(Request $request)
    {
        $current = $request->session()->get('device_id');

        return Inertia::render('devices/index', [
            'devices' => Device::where('user_id', $request->user()->id)->whereNull('revoked_at')->latest('last_seen_at')->get()
                ->map(fn ($d) => $d->only('id', 'name', 'kind', 'last_seen_at', 'created_at') + ['current' => $d->id === $current]),
        ]);
    }

    public function revoke(Request $request, Device $device)
    {
        abort_unless($device->user_id === $request->user()->id, 404);
        $device->update(['revoked_at' => now()]);
        if ($device->session_id) {
            rescue(fn () => Session::getHandler()->destroy($device->session_id), report: false);
        }
        AuditLog::record('device.revoked', ['device' => $device->id]);

        return back();
    }
}
