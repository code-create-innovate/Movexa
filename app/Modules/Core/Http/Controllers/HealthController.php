<?php

namespace App\Modules\Core\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

/** GET /api/health — status only (ok/fail per component). No versions, paths, counts or secrets. */
class HealthController extends Controller
{
    public function __invoke()
    {
        $checks = Cache::remember('movexa.health', 10, function () {
            $c = [];
            $c['database'] = $this->try(fn () => DB::select('select 1'));
            $c['redis'] = config('database.redis.client') && in_array('redis', [config('queue.default'), config('cache.default')], true)
                ? $this->try(fn () => Redis::connection()->ping()) : 'skipped';
            $c['storage'] = is_dir(config('movexa.paths.library')) && is_writable(config('movexa.paths.cache')) ? 'ok' : 'fail';
            $c['ffmpeg'] = is_executable(config('movexa.binaries.ffmpeg')) ? 'ok' : 'fail';

            return $c;
        });
        $critical = $checks['database'] === 'ok' && in_array($checks['redis'], ['ok', 'skipped'], true);
        $status = ! $critical ? 'down' : (in_array('fail', $checks, true) ? 'degraded' : 'ok');

        return response()->json(['status' => $status, 'service' => 'movexa', 'checks' => $checks], $critical ? 200 : 503)
            ->header('Cache-Control', 'no-store');
    }

    private function try(callable $fn): string
    {
        try {
            $fn();

            return 'ok';
        } catch (Throwable) {
            return 'fail';
        }
    }
}
