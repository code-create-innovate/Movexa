<?php

namespace App\Modules\Devices\Http\Middleware;

use App\Modules\Devices\Models\Device;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/** Every logged-in session is a "device". A revoked device is logged out on its next request. */
class DeviceSessionGuard
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        if ($user && $request->hasSession()) {
            $id = $request->session()->get('device_id');
            $device = $id ? Device::find($id) : null;
            if ($device && $device->user_id !== $user->id) {
                $device = null; // session switched account → register a device for the new user
            }
            if (($device && $device->revoked_at) || $user->disabled_at) {
                Auth::guard('web')->logout();
                $request->session()->invalidate();

                return $request->expectsJson() ? response()->json(['message' => 'device_revoked'], 401) : redirect('/login');
            }
            if (! $device) {
                $device = Device::create(['user_id' => $user->id, 'name' => Device::nameFromAgent($request->userAgent()), 'kind' => $request->session()->pull('device_kind', 'browser')]);
                $request->session()->put('device_id', $device->id);
            }
            if ($device->session_id !== $request->session()->getId() || ! $device->last_seen_at || $device->last_seen_at->lt(now()->subMinute())) {
                $device->forceFill(['session_id' => $request->session()->getId(), 'last_seen_at' => now()])->saveQuietly();
            }
        }

        return $next($request);
    }
}
