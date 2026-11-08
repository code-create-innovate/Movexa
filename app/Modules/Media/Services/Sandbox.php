<?php

namespace App\Modules\Media\Services;

use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Runs untrusted-input processors (ffmpeg, ffprobe) isolated from the network and the system.
 *
 * Drivers:
 *  - systemd : sudo /usr/local/sbin/movexa-sandbox (systemd-run with ProtectSystem=strict,
 *              PrivateNetwork=yes, NoNewPrivileges=yes, MemoryMax, CPUQuota, dedicated user). Production.
 *  - bwrap   : bubblewrap, all namespaces unshared, read-only system, only given paths writable.
 *  - unshare : network namespace only (CI / containers).
 *  - none    : no isolation (NEVER in production; refused when APP_ENV=production).
 */
class Sandbox
{
    public function __construct(private ?string $driver = null) {}

    public function driver(): string
    {
        return $this->driver ?? config('movexa.sandbox.driver');
    }

    /** @param list<string> $command  @param list<string> $writable directories the process may write to */
    public function process(array $command, array $writable = [], ?int $timeout = null, array $readable = []): Process
    {
        $driver = $this->driver();
        if ($driver === 'none' && app()->environment('production')) {
            throw new RuntimeException('Sandbox driver "none" is not allowed in production.');
        }

        $wrapped = match ($driver) {
            'systemd' => $this->systemd($command, $writable, $timeout),
            'bwrap' => $this->bwrap($command, $writable, $readable),
            'unshare' => array_merge(['unshare', '--net', '--'], $command),
            'none' => $command,
            default => throw new RuntimeException("Unknown sandbox driver {$driver}"),
        };

        $process = new Process($wrapped, null, ['LC_ALL' => 'C.UTF-8', 'HOME' => sys_get_temp_dir()]);
        $process->setTimeout($timeout ?? config('movexa.sandbox.timeout'));

        return $process;
    }

    public function run(array $command, array $writable = [], ?int $timeout = null, array $readable = []): Process
    {
        $p = $this->process($command, $writable, $timeout, $readable);
        $p->run();

        return $p;
    }

    private function systemd(array $command, array $writable, ?int $timeout): array
    {
        $args = ['sudo', '-n', '/usr/local/sbin/movexa-sandbox', '--timeout', (string) ($timeout ?? config('movexa.sandbox.timeout'))];
        foreach ($writable as $dir) {
            $args[] = '--rw';
            $args[] = $dir;
        }

        return array_merge($args, ['--'], $command);
    }

    private function bwrap(array $command, array $writable, array $readable): array
    {
        $args = ['bwrap', '--unshare-all', '--die-with-parent', '--new-session',
            '--ro-bind', '/usr', '/usr', '--symlink', 'usr/lib', '/lib', '--symlink', 'usr/lib64', '/lib64',
            '--symlink', 'usr/bin', '/bin', '--ro-bind-try', '/etc/alternatives', '/etc/alternatives',
            '--ro-bind-try', '/etc/ld.so.cache', '/etc/ld.so.cache', '--ro-bind-try', '/etc/fonts', '/etc/fonts',
            '--proc', '/proc', '--dev', '/dev', '--tmpfs', '/tmp'];
        if (is_dir('/dev/dri')) {
            $args = array_merge($args, ['--dev-bind', '/dev/dri', '/dev/dri']); // VAAPI/QSV
        }
        foreach ($readable as $p) {
            $args = array_merge($args, ['--ro-bind', $p, $p]);
        }
        foreach ($writable as $dir) {
            $args = array_merge($args, ['--bind', $dir, $dir]);
        }

        return array_merge($args, ['--'], $command);
    }
}
