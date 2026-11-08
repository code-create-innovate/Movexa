<?php

namespace App\Modules\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/** While an admin uses "view as", nothing can be changed (and nothing can be played). */
class ViewAsReadOnly
{
    public function handle(Request $request, Closure $next)
    {
        if ($request->hasSession() && $request->session()->has('view_as') && ! $request->isMethodSafe()
            && ! $request->is('view-as/exit', 'logout', 'locale/*')) {
            abort(403, 'view-as is read-only');
        }

        return $next($request);
    }
}
