<?php

namespace App\Modules\Core\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Services\ProfileContext;
use Illuminate\Http\Request;
use Inertia\Inertia;

/** Viewing preferences of the signed-in account (no profile picker, no PINs: one account per person). */
class ProfileController extends Controller
{
    public function __construct(private ProfileContext $context) {}

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
        if (isset($data['locale'])) {
            $request->user()->forceFill(['locale' => $data['locale']])->save();
        }

        return $request->expectsJson() ? response()->json(['ok' => true, 'settings' => $profile->allSettings()]) : back();
    }

    public function playbackSettings()
    {
        abort_unless($this->context->current(), 409);

        return Inertia::render('settings/playback', ['settings' => $this->context->current()->allSettings()]);
    }

}
