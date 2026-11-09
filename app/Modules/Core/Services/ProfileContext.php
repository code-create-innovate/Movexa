<?php

namespace App\Modules\Core\Services;

use App\Models\User;
use App\Modules\Core\Models\Profile;
use Illuminate\Http\Request;

/**
 * There is no profile picker any more: each person signs in with their own account and gets its single
 * internal viewing profile. An admin with the "users" permission can "view as" another account (read-only).
 */
class ProfileContext
{
    public const SESSION_KEY = 'movexa.profile_id';

    public function __construct(private Request $request) {}

    public function current(): ?Profile
    {
        $user = $this->request->user();
        if (! $user) {
            return null;
        }
        $cached = $this->request->attributes->get('movexa.profile');
        if ($cached instanceof Profile && $cached->user_id === ($this->viewAsUserId() ?? $user->id)) {
            return $cached;
        }
        $target = ($id = $this->viewAsUserId()) ? User::find($id) : $user;
        $profile = $target?->primaryProfile();
        $profile?->setRelation('user', $target);
        $this->request->attributes->set('movexa.profile', $profile);

        return $profile;
    }

    private function viewAsUserId(): ?int
    {
        $va = $this->request->hasSession() ? $this->request->session()->get('view_as') : null;

        return $va && $this->request->user()?->hasPermission('users') ? (int) $va['user'] : null;
    }

    public function viewingAs(): bool
    {
        return $this->viewAsUserId() !== null;
    }
}
