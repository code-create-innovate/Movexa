<?php

namespace App\Modules\Core\Console;

use App\Models\User;
use App\Modules\Core\Models\ApiToken;
use App\Modules\Library\Jobs\ScanLibraryJob;
use App\Modules\Library\Models\IngestJob;
use App\Modules\Library\Models\Library;
use App\Modules\Library\Services\LibraryScanner;
use App\Modules\LiveTV\Jobs\RecordProgramJob;
use App\Modules\LiveTV\Models\Recording;
use App\Modules\LiveTV\Services\LiveStreamManager;
use App\Modules\Playback\Models\TranscodeJob;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

/** Registered from routes/console.php */
class MovexaCommands
{
    public static function register(): void
    {
        Artisan::command('movexa:create-admin {login} {--name=Admin} {--email=} {--password=} {--emergency}', function (string $login) {
            // {login}: a username (or an email). --password for non-interactive installs; otherwise prompted.
            $password = $this->option('password') ?: $this->secret('Password (min 12 chars)');
            if (strlen((string) $password) < 12) {
                return $this->error('Password too short (min 12)') ?? 1;
            }
            $isEmail = str_contains($login, '@');
            $username = $isEmail ? strstr($login, '@', true) : $login;
            $email = $this->option('email') ?: ($isEmail ? $login : $login.'@movexa.local');
            $user = User::where('username', $username)->orWhere('email', $email)->first() ?? new User;
            $user->fill(['name' => $this->option('name'), 'username' => $username, 'email' => $email, 'password' => $password, 'is_admin' => true,
                'is_emergency_admin' => (bool) $this->option('emergency'), 'disabled_at' => null])->save();
            $this->info("Admin \"{$username}\" ready.");
        })->purpose('Create/update a local admin (use --emergency for the HomeCore-independent account)');

        Artisan::command('movexa:token {name} {abilities*}', function (string $name, array $abilities) {
            $allowed = ['homecore:read', 'cloudsync:ingest'];
            if (array_diff($abilities, $allowed)) {
                return $this->error('Allowed abilities: '.implode(', ', $allowed)) ?? 1;
            }
            [, $plain] = ApiToken::issue($name, $abilities);
            $this->line($plain);
            $this->warn('Shown once. Store it in HomeCore / CloudSync settings.');
        })->purpose('Issue a scoped service token');

        Artisan::command('movexa:scan {--library=} {--inbox}', function () {
            if ($this->option('inbox')) {
                $this->info('inbox: '.app(LibraryScanner::class)->scanInbox().' queued');
            }
            $libs = $this->option('library') ? Library::whereKey($this->option('library'))->get() : Library::all();
            foreach ($libs as $lib) {
                $this->info("{$lib->name}: ".app(LibraryScanner::class)->scan($lib).' new file(s) queued');
            }
        })->purpose('Scan libraries (and the inbox drop folder)');

        Artisan::command('movexa:watch', function () {
            $bin = config('movexa.binaries.inotifywait');
            if (! is_executable($bin)) {
                return $this->error('inotifywait missing: apt install inotify-tools') ?? 1;
            }
            $map = Library::where('watch', true)->get()->mapWithKeys(fn ($l) => [$l->path => $l->id])->all();
            $drop = config('movexa.paths.inbox').'/drop';
            $paths = array_merge(array_keys($map), is_dir($drop) ? [$drop] : []);
            if (! $paths) {
                return $this->warn('nothing to watch') ?? 0;
            }
            $p = new Process(array_merge([$bin, '-m', '-r', '-q', '-e', 'close_write,moved_to', '--format', '%w%f'], $paths));
            $p->setTimeout(null);
            $pending = [];
            $p->start();
            while ($p->isRunning()) {
                foreach (array_filter(explode("\n", $p->getIncrementalOutput())) as $path) {
                    foreach ($map as $root => $id) {
                        if (str_starts_with($path, rtrim($root, '/').'/')) {
                            $pending['lib:'.$id] = time();
                        }
                    }
                    if (str_starts_with($path, $drop.'/')) {
                        $pending['inbox'] = time();
                    }
                }
                foreach ($pending as $key => $at) {
                    if (time() - $at >= 30) { // debounce: wait until copying settles
                        $key === 'inbox' ? app(LibraryScanner::class)->scanInbox() : ScanLibraryJob::dispatch((int) substr($key, 4));
                        unset($pending[$key]);
                    }
                }
                sleep(2);
            }
        })->purpose('Watch library folders with inotify (run under Supervisor)');

        Artisan::command('movexa:live-reaper {--loop}', function () {
            do {
                app(LiveStreamManager::class)->reap();
                $this->option('loop') && sleep(5);
            } while ($this->option('loop'));
        })->purpose('Stop idle live channels / restart dropped ones');

        Artisan::command('movexa:recordings', function () {
            Recording::where('status', 'scheduled')->where('starts_at', '<=', now()->addMinute())->where('ends_at', '>', now())
                ->each(fn ($r) => RecordProgramJob::dispatch($r->id));
            Recording::where('status', 'scheduled')->where('ends_at', '<=', now())->update(['status' => 'failed', 'error' => 'missed']);
        })->purpose('Start due DVR recordings');

        Artisan::command('movexa:cache-prune', function () {
            $max = (float) config('movexa.transcode.cache_max_gb') * 1024 ** 3;
            $total = (int) TranscodeJob::sum('output_size');
            foreach (TranscodeJob::where('pinned', false)->where('status', 'done')->orderBy('last_accessed_at')->get() as $job) {
                if ($total <= $max) {
                    break;
                }
                if ($job->output_dir && is_dir($job->output_dir)) {
                    File::deleteDirectory($job->output_dir);
                }
                $total -= $job->output_size;
                $job->delete();
            }
            // Orphans: failed jobs older than a day.
            TranscodeJob::where('status', 'failed')->where('updated_at', '<', now()->subDay())->get()
                ->each(function ($j) { $j->output_dir && File::deleteDirectory($j->output_dir); $j->delete(); });
            $this->info('cache: '.round($total / 1024 ** 3, 2).' GB');
        })->purpose('LRU cleanup of the HLS cache (pinned pre-transcodes are kept)');

        Artisan::command('movexa:quarantine-purge', function () {
            $n = 0;
            IngestJob::whereNotNull('quarantine_path')->where('purge_after', '<', now())->each(function ($j) use (&$n) {
                @unlink($j->quarantine_path);
                $j->update(['quarantine_path' => null, 'reason' => trim(($j->reason ?? '').' purged')]);
                $n++;
            });
            $this->info("purged {$n}");
        })->purpose('Delete quarantined files older than the retention period');

        Artisan::command('movexa:colors', function () {
            $svc = app(\App\Modules\Media\Services\ColorService::class);
            \App\Modules\Library\Models\MediaItem::each(fn ($i) => $svc->refreshItem($i));
            \App\Modules\Library\Models\MediaFile::where('status', 'ready')->whereNotNull('video_codec')->each(fn ($f) => $svc->timeline($f));
            $this->info('colors refreshed');
        })->purpose('Backfill dominant colors, small posters and ambient color timelines');

        Artisan::command('movexa:security-versions', function () {
            $ff = new Process([config('movexa.binaries.ffmpeg'), '-version']);
            $ff->run();
            $this->line(strtok($ff->getOutput(), "\n"));
            $clam = app(\App\Modules\Library\Services\Security\ClamdScanner::class)->version();
            $this->line('clamd: '.($clam ?? 'unreachable'));
            $apt = new Process(['bash', '-c', 'apt list --upgradable 2>/dev/null | grep -E "^(ffmpeg|clamav|libav)" || true']);
            $apt->run();
            $out = trim($apt->getOutput());
            \App\Modules\Core\Models\AppSetting::put('security_updates', $out ? explode("\n", $out) : []);
            $out ? $this->warn("Updates available:\n".$out) : $this->info('ffmpeg/clamav up to date');
        })->purpose('Record ffmpeg/ClamAV versions and pending security updates (admin alert)');
    }
}
