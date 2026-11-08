<?php

namespace App\Modules\Core\Services;

use App\Modules\Core\Models\Profile;
use Illuminate\Http\Request;

/** Resolves the active profile from the session; always scoped to the authenticated user. */
class ProfileContext
{
    public const SESSION_KEY = 'movexa.profile_id';

    public function __construct(private Request $request) {}

    public function current(): ?Profile
    {
        $user = $this->request->user();
        $id = $this->request->hasSession() ? $this->request->session()->get(self::SESSION_KEY) : null;
        if (! $user || ! $id) {
            return null;
        }

        // Admin "view as" (read-only): browse as any profile.
        $va = $this->request->session()->get('view_as');
        if ($va && $user->is_admin) {
            return Profile::find($va['profile']);
        }

        return $user->profiles()->whereKey($id)->first();
    }

    public function viewingAs(): bool
    {
        return $this->request->hasSession() && $this->request->session()->has('view_as') && (bool) $this->request->user()?->is_admin;
    }

    public function set(Profile $profile): void
    {
        $this->request->session()->put(self::SESSION_KEY, $profile->id);
        $this->request->session()->regenerate();
    }

    public function clear(): void
    {
        $this->request->session()->forget(self::SESSION_KEY);
    }
}
