<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;
use Inertia\Inertia;
use Inertia\Response;

class RegisteredUserController extends Controller
{
    /**
     * Show the registration page.
     */
    /** Self-registration only for first-run setup, or when explicitly enabled. */
    public static function open(): bool
    {
        return User::count() === 0 || (bool) config('movexa.allow_registration');
    }

    public function create(): Response
    {
        abort_unless(self::open(), 403);
        return Inertia::render('auth/register');
    }

    /**
     * Handle an incoming registration request.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        abort_unless(self::open(), 403);
        $first = User::count() === 0;
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|lowercase|email|max:255|unique:'.User::class,
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
        ]);
        // The very first account becomes the (local, HomeCore-independent) admin.
        $user->forceFill(['is_admin' => $first, 'is_emergency_admin' => $first])->save();
        $user->profiles()->create(['name' => $user->name]);

        event(new Registered($user));

        Auth::login($user);

        return to_route('profiles.select');
    }
}
