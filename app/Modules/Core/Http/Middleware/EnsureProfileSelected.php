<?php

namespace App\Modules\Core\Http\Middleware;

use App\Modules\Core\Services\ProfileContext;
use Closure;
use Illuminate\Http\Request;

/** Puts the account's viewing profile on the request (kept under the old "profile" alias). */
class EnsureProfileSelected
{
    public function __construct(private ProfileContext $profiles) {}

    public function handle(Request $request, Closure $next)
    {
        $profile = $this->profiles->current();
        abort_unless($profile, 403);
        $request->attributes->set('profile', $profile);

        return $next($request);
    }
}
