<?php

namespace App\Modules\Core\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Core\Services\Totp;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class TwoFactorController extends Controller
{
    public function show(Request $request)
    {
        $user = $request->user();
        $pending = $request->session()->get('2fa.setup_secret');

        return Inertia::render('settings/two-factor', [
            'enabled' => $user->hasTwoFactor(),
            'setup' => $pending ? ['secret' => $pending, 'uri' => Totp::uri($pending, $user->email)] : null,
        ]);
    }

    public function start(Request $request)
    {
        $request->validate(['password' => ['required', 'current_password']]);
        $request->session()->put('2fa.setup_secret', Totp::generateSecret());

        return back();
    }

    public function confirm(Request $request)
    {
        $request->validate(['code' => ['required', 'string']]);
        $secret = $request->session()->get('2fa.setup_secret');
        if (! $secret || ! Totp::verify($secret, $request->input('code'))) {
            throw ValidationException::withMessages(['code' => __('movexa.code_invalid')]);
        }
        $request->user()->forceFill(['two_factor_secret' => $secret, 'two_factor_confirmed_at' => now()])->save();
        $request->session()->forget('2fa.setup_secret');

        return back();
    }

    public function disable(Request $request)
    {
        $request->validate(['password' => ['required', 'current_password']]);
        $request->user()->forceFill(['two_factor_secret' => null, 'two_factor_confirmed_at' => null])->save();

        return back();
    }

    public function challenge(Request $request)
    {
        abort_unless($request->session()->has('2fa.user_id'), 404);

        return Inertia::render('auth/two-factor-challenge');
    }

    public function verify(Request $request)
    {
        $request->validate(['code' => ['required', 'string']]);
        $id = $request->session()->get('2fa.user_id');
        $user = $id ? User::find($id) : null;
        abort_unless($user, 404);
        $key = '2fa:'.$user->id;
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['code' => __('movexa.too_many_attempts')]);
        }
        if (! Totp::verify($user->two_factor_secret, $request->input('code'))) {
            RateLimiter::hit($key, 300);
            throw ValidationException::withMessages(['code' => __('movexa.code_invalid')]);
        }
        RateLimiter::clear($key);
        Auth::login($user, (bool) $request->session()->pull('2fa.remember'));
        $request->session()->forget('2fa.user_id');
        $request->session()->regenerate();

        return redirect()->intended(route('home'));
    }
}
