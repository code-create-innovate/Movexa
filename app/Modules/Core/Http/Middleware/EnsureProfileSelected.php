<?php

namespace App\Modules\Core\Http\Middleware;

use App\Modules\Core\Services\ProfileContext;
use Closure;
use Illuminate\Http\Request;

class EnsureProfileSelected
{
    public function __construct(private ProfileContext $profiles) {}

    public function handle(Request $request, Closure $next)
    {
        $profile = $this->profiles->current();
        if (! $profile) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'profile_required'], 409);
            }

            return redirect()->route('profiles.select');
        }
        $request->attributes->set('profile', $profile);

        return $next($request);
    }
}
