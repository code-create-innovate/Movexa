<?php

namespace App\Modules\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next)
    {
        Vite::useCspNonce();
        $response = $next($request);
        $nonce = Vite::cspNonce();
        $dev = app()->environment('local') && file_exists(public_path('hot'));
        $vite = $dev ? ' http://localhost:5173 ws://localhost:5173' : '';
        // WebSocket only to our own Reverb endpoint (https://movexa.lan:8088 → wss://movexa.lan:8088).
        $o = config('broadcasting.connections.reverb.options');
        $reverb = config('broadcasting.default') === 'reverb' && ! empty($o['host'])
            ? ' '.(($o['scheme'] ?? 'https') === 'https' ? 'wss' : 'ws').'://'.$o['host'].(! empty($o['port']) ? ':'.$o['port'] : '')
            : '';

        $csp = implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'nonce-{$nonce}'".$vite,
            "style-src 'self' 'unsafe-inline'".$vite, // Radix/inline style attributes
            "img-src 'self' data: blob:",
            "media-src 'self' blob:",
            "font-src 'self'",
            "connect-src 'self'".$reverb.$vite,
            "worker-src 'self' blob:",
            "object-src 'none'",
            "base-uri 'self'",
            "frame-ancestors 'self'",
            "form-action 'self'".(config('movexa.homecore.oidc.issuer') ? ' '.config('movexa.homecore.oidc.issuer') : ''),
        ]);

        $response->headers->set('Content-Security-Policy', $csp);
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'same-origin');
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');

        return $response;
    }
}
