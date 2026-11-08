<?php

use App\Modules\Core\Services\ProfileContext;
use App\Modules\Playback\Models\WatchParty;
use Illuminate\Support\Facades\Broadcast;

// Per-profile control channel (access revoked → stop playback). Only the logged-in owner of that profile.
Broadcast::channel('profile.{id}', fn ($user, int $id) => $user->profiles()->whereKey($id)->exists());

Broadcast::channel('admin.transcodes', fn ($user) => (bool) $user->is_admin);

// Presence channel: members = profiles allowed to watch the party's title.
Broadcast::channel('party.{uuid}', function ($user, string $uuid) {
    $profile = app(ProfileContext::class)->current();
    $party = WatchParty::with('file.item')->where('uuid', $uuid)->first();
    if (! $profile || ! $party || ! $party->file->viewableBy($profile)) {
        return false;
    }

    return ['id' => $profile->id, 'name' => $profile->name, 'color' => $profile->avatar_color];
});
