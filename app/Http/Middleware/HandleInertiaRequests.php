<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $profile = app(\App\Modules\Core\Services\ProfileContext::class)->current();
        $user = $request->user();

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'locale' => app()->getLocale(),
            'auth' => [
                'user' => $user?->only('id', 'name', 'email', 'is_admin', 'locale'),
            ],
            'profile' => $profile?->only('id', 'name', 'avatar_color', 'is_kid', 'max_age_rating', 'locale', 'subtitle_prefs'),
            'homecore' => ['url' => config('movexa.homecore.url'), 'sso' => (bool) config('movexa.homecore.oidc.enabled'), 'theme' => config('movexa.homecore.theme_css')],
            'reverb' => config('broadcasting.default') === 'reverb' ? [
                'key' => config('broadcasting.connections.reverb.key'),
                'host' => env('REVERB_PUBLIC_HOST', $request->getHost()),
                'port' => (int) env('REVERB_PUBLIC_PORT', $request->getPort()),
                'scheme' => env('REVERB_PUBLIC_SCHEME', $request->getScheme()),
            ] : null,
            'flash' => ['status' => $request->hasSession() ? $request->session()->get('status') : null],
        ];
    }
}
