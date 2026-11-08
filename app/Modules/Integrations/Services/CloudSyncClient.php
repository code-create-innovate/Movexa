<?php

namespace App\Modules\Integrations\Services;

use Illuminate\Support\Facades\Http;

/**
 * Movexa → CloudSync calls (contract in docs/integration/cloudsync.md).
 * Movexa keeps working when CloudSync is down: every call is optional and fails soft.
 */
class CloudSyncClient
{
    public function configured(): bool
    {
        return (bool) (config('movexa.cloudsync.url') && config('movexa.cloudsync.token'));
    }

    private function http()
    {
        return Http::baseUrl(rtrim(config('movexa.cloudsync.url'), '/'))->withToken(config('movexa.cloudsync.token'))->timeout(8)->acceptJson();
    }

    public function healthy(): bool
    {
        if (! config('movexa.cloudsync.url')) {
            return false;
        }

        return (bool) rescue(fn () => Http::timeout(3)->get(rtrim(config('movexa.cloudsync.url'), '/').'/api/health')->json('status') === 'ok', false, false);
    }

    /** Folders the given HomeCore user may read in CloudSync. */
    public function folders(string $oidcSubject, string $parent = '/'): array
    {
        if (! $this->configured()) {
            return [];
        }

        return rescue(fn () => $this->http()->get('/api/integrations/movexa/folders', ['user' => $oidcSubject, 'parent' => $parent])->json('folders') ?? [], [], false);
    }

    /** Asks CloudSync to confirm the user can read the file and to resolve its on-disk path. */
    public function resolve(string $oidcSubject, string $fileId): ?array
    {
        if (! $this->configured()) {
            return null;
        }
        $r = rescue(fn () => $this->http()->get('/api/integrations/movexa/files/'.rawurlencode($fileId), ['user' => $oidcSubject]), null, false);

        return $r?->successful() ? $r->json() : null; // {id, name, path, size, mime, can_read: true}
    }

    /** Path must stay inside the CloudSync storage root (no traversal, no symlink escape). */
    public static function safePath(?string $path): ?string
    {
        $root = realpath((string) config('movexa.cloudsync.root'));
        $real = $path ? realpath($path) : false;

        return $root && $real && str_starts_with($real, $root.'/') && is_file($real) ? $real : null;
    }
}
