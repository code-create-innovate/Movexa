<?php

namespace App\Modules\Core\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Models\Profile;
use App\Modules\Core\Services\ProfileContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class ProfileController extends Controller
{
    public function __construct(private ProfileContext $context) {}

    public function select(Request $request)
    {
        $current = $this->context->current();

        return Inertia::render('profiles/select', [
            'profiles' => $request->user()->profiles()->orderBy('id')->get(),
            'currentId' => $current?->id,
            'lockedInKid' => (bool) $current?->is_kid,
            'wall' => app(\App\Modules\Library\Services\Discovery::class)->wallBeforeProfile($request->user()->id),
        ]);
    }

    public function enter(Request $request, Profile $profile)
    {
        abort_unless($profile->user_id === $request->user()->id, 404);
        $current = $this->context->current();
        $pin = $request->input('pin');
        $this->throttle($request);

        // Leaving a kid profile needs the parental PIN (or account password when none is set).
        if ($current?->is_kid && $current->id !== $profile->id && ! $this->parentalOk($request, $request->input('parent_pin'))) {
            throw ValidationException::withMessages(['parent_pin' => __('movexa.pin_invalid')]);
        }
        if (! $profile->checkPin($pin)) {
            throw ValidationException::withMessages(['pin' => __('movexa.pin_invalid')]);
        }
        RateLimiter::clear($this->key($request));
        $this->context->set($profile);

        return redirect()->route('home');
    }

    public function store(Request $request)
    {
        $this->authorizeManage($request);
        $data = $this->validated($request);
        abort_if($request->user()->profiles()->count() >= 8, 422, 'max profiles');
        $profile = $request->user()->profiles()->make($data);
        $profile->setPin($request->input('pin'));
        $profile->save();

        return back();
    }

    public function update(Request $request, Profile $profile)
    {
        $this->authorizeManage($request);
        abort_unless($profile->user_id === $request->user()->id, 404);
        $profile->fill($this->validated($request));
        if ($request->has('pin')) {
            $profile->setPin($request->input('pin'));
        }
        $profile->save();

        return back();
    }

    public function destroy(Request $request, Profile $profile)
    {
        $this->authorizeManage($request);
        abort_unless($profile->user_id === $request->user()->id, 404);
        abort_if($request->user()->profiles()->count() <= 1, 422);
        $profile->delete();
        if ($this->context->current()?->id === $profile->id) {
            $this->context->clear();
        }

        return back();
    }

    public function parentalPin(Request $request)
    {
        $this->authorizeManage($request);
        $request->validate(['password' => ['required', 'current_password'], 'pin' => ['nullable', 'digits_between:4,8']]);
        $request->user()->forceFill(['parental_pin_hash' => $request->filled('pin') ? Hash::make($request->input('pin')) : null])->save();

        return back();
    }

    public function preferences(Request $request)
    {
        $profile = $this->context->current();
        abort_unless($profile, 409);
        $data = $request->validate([
            'locale' => ['nullable', 'in:ar,en'],
            'subtitle_prefs' => ['nullable', 'array'],
            'subtitle_prefs.size' => ['nullable', 'integer', 'between:50,250'],
            'subtitle_prefs.color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'subtitle_prefs.background' => ['nullable', 'integer', 'between:0,100'],
            'subtitle_prefs.position' => ['nullable', 'integer', 'between:0,40'],
            'preferred_audio_lang' => ['nullable', 'string', 'max:8'],
            'preferred_subtitle_lang' => ['nullable', 'string', 'max:8'],
            'settings' => ['nullable', 'array'],
            'settings.intro' => ['boolean'], 'settings.pause_screen' => ['boolean'], 'settings.poster_wall' => ['boolean'], 'settings.recap' => ['boolean'],
            'settings.ambient' => ['in:off,soft,strong'],
        ]);
        if (isset($data['settings'])) {
            $data['settings'] = array_merge($profile->settings ?? [], array_intersect_key($data['settings'], \App\Modules\Core\Models\Profile::DEFAULT_SETTINGS));
        }
        $profile->fill($data)->save();
        if (isset($data['locale']) && ! $profile->is_kid) {
            $request->user()->forceFill(['locale' => $data['locale']])->save();
        }

        return $request->expectsJson() ? response()->json(['ok' => true, 'settings' => $profile->allSettings()]) : back();
    }

    public function playbackSettings()
    {
        abort_unless($this->context->current(), 409);

        return Inertia::render('settings/playback', ['settings' => $this->context->current()->allSettings()]);
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'avatar_color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'is_kid' => ['boolean'],
            'max_age_rating' => ['nullable', 'integer', 'in:0,7,12,13,16,18'],
            'locale' => ['nullable', 'in:ar,en'],
            'pin' => ['nullable', 'digits_between:4,8'],
        ]);
        unset($data['pin']);

        return $data;
    }

    /** Profile management is never possible from inside a kid profile. */
    private function authorizeManage(Request $request): void
    {
        abort_if($this->context->current()?->is_kid, 403);
    }

    private function parentalOk(Request $request, ?string $pin): bool
    {
        $user = $request->user();
        if ($user->parental_pin_hash) {
            return $pin !== null && Hash::check($pin, $user->parental_pin_hash);
        }

        return $pin !== null && Hash::check($pin, $user->password);
    }

    private function throttle(Request $request): void
    {
        if (RateLimiter::tooManyAttempts($this->key($request), 5)) {
            throw ValidationException::withMessages(['pin' => __('movexa.too_many_attempts')]);
        }
        RateLimiter::hit($this->key($request), 300);
    }

    private function key(Request $request): string
    {
        return 'profile-pin:'.$request->user()->id;
    }
}
