<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Modules\Core\Http\Middleware\ApiTokenAuth;
use App\Modules\Core\Http\Middleware\EnsureAdmin;
use App\Modules\Core\Http\Middleware\EnsureProfileSelected;
use App\Modules\Core\Http\Middleware\SecurityHeaders;
use App\Modules\Core\Http\Middleware\SetLocale;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withBroadcasting(__DIR__.'/../routes/channels.php', ['middleware' => ['web', 'auth']])
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->trustProxies(at: ['127.0.0.1', '::1']);
        $middleware->web(append: [
            SetLocale::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            SecurityHeaders::class,
        ]);
        $middleware->api(append: [SecurityHeaders::class]);
        $middleware->alias([
            'profile' => EnsureProfileSelected::class,
            'admin' => EnsureAdmin::class,
            'api.token' => ApiTokenAuth::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
