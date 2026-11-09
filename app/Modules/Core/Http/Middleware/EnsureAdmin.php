<?php

namespace App\Modules\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Per-user permissions: ->middleware('perm:library') or 'perm:library,upload' (any of them).
 * Disabled accounts have no permission at all.
 */
class EnsureAdmin
{
    public function handle(Request $request, Closure $next, string ...$permissions)
    {
        $user = $request->user();
        $permissions = $permissions ?: ['users'];
        abort_unless($user && collect($permissions)->contains(fn ($p) => $user->hasPermission($p)), 403);

        return $next($request);
    }
}
