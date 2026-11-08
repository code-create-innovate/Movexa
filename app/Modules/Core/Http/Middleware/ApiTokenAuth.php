<?php

namespace App\Modules\Core\Http\Middleware;

use App\Modules\Core\Models\ApiToken;
use Closure;
use Illuminate\Http\Request;

/** Scoped service tokens (HomeCore, CloudSync). Usage: ->middleware('api.token:homecore:read') */
class ApiTokenAuth
{
    public function handle(Request $request, Closure $next, string $ability)
    {
        $plain = $request->bearerToken();
        $token = $plain ? ApiToken::findByPlain($plain) : null;
        if (! $token) {
            return response()->json(['message' => 'unauthenticated'], 401);
        }
        if (! $token->can($ability)) {
            return response()->json(['message' => 'forbidden'], 403);
        }
        $token->forceFill(['last_used_at' => now()])->saveQuietly();
        $request->attributes->set('api_token', $token);

        return $next($request);
    }
}
