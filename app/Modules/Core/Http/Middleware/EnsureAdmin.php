<?php

namespace App\Modules\Core\Http\Middleware;

use App\Modules\Core\Services\ProfileContext;
use Closure;
use Illuminate\Http\Request;

class EnsureAdmin
{
    public function __construct(private ProfileContext $profiles) {}

    public function handle(Request $request, Closure $next)
    {
        // Admin pages are never reachable from a kid profile, even on an admin account.
        abort_unless($request->user()?->is_admin && ! $this->profiles->current()?->is_kid, 403);

        return $next($request);
    }
}
