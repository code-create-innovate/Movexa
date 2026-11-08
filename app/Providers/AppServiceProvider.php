<?php

namespace App\Providers;

use App\Modules\Library\Services\Security\ClamdScanner;
use App\Modules\Library\Services\Security\MultiScanner;
use App\Modules\Library\Services\Security\VirusScanner;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Add a second engine by appending another VirusScanner implementation here.
        $this->app->bind(VirusScanner::class, fn () => new MultiScanner(array_values(array_filter([
            config('movexa.security.scanner') === 'clamd' ? new ClamdScanner : null,
        ]))));
    }

    public function boot(): void {}
}
