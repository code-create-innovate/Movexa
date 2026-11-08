<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Fire-and-forget realtime events. Reverb being down must never break playback or the API.
 * (Note: `rescue(fn () => broadcast(...))` is NOT enough — PendingBroadcast sends in its destructor,
 * i.e. after rescue() has returned, so the exception escapes.)
 */
class Realtime
{
    public static function send(object $event): bool
    {
        try {
            event($event);

            return true;
        } catch (Throwable $e) {
            Log::warning('realtime unavailable', ['event' => $event::class, 'error' => mb_substr($e->getMessage(), 0, 200)]);

            return false;
        }
    }
}
