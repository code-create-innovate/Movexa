<?php

namespace App\Modules\Core\Http\Middleware;

use App\Modules\Core\Services\ProfileContext;
use Closure;
use Illuminate\Http\Request;

class SetLocale
{
    public function __construct(private ProfileContext $profiles) {}

    public function handle(Request $request, Closure $next)
    {
        $locale = $this->profiles->current()?->locale ?? $request->user()?->locale ?? $request->session()->get('locale', 'ar');
        app()->setLocale(in_array($locale, ['ar', 'en'], true) ? $locale : 'ar');

        return $next($request);
    }
}
