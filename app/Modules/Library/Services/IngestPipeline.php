<?php

namespace App\Modules\Library\Services;

use App\Modules\Library\Jobs\FetchMetadataJob;
use App\Modules\Library\Jobs\GenerateThumbnailsJob;
use App\Modules\Library\Models\IngestJob;
use App\Modules\Library\Models\Library;
use App\Modules\Library\Models\MediaFile;
use App\Modules\Library\Models\SecurityEvent;
use App\Modules\Library\Services\Security\FileTypeInspector;
use App\Modules\Library\Services\Security\MediaSanitizer;
use App\Modules\Library\Services\Security\Quarantine;
use App\Modules\Library\Services\Security\SubtitleSanitizer;
use App\Modules\Library\Services\Security\VirusScanner;
use App\Modules\Media\Services\MediaProbe;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Every new file (upload, scan, Samba, CloudSync, external disk) passes through here
 * BEFORE it can appear in the library:
 *   inbox → real-type check → ffprobe (sandbox) → ClamAV → CDR rebuild (sandbox) → verify + rescan → library
 * Any failure ends in quarantine (encrypted, never playable). No "allow infected file" path exists.
 */
class IngestPipeline
{
    public function __construct(
        private FileTypeInspector $inspector,
        private MediaProbe $probe,
        private VirusScanner $scanner,
        private MediaSanitizer $sanitizer,
        private SubtitleSanitizer $subtitles,
        private Quarantine $quarantine,
        private LibraryIndexer $indexer,
    ) {}

    public static function enqueue(string $path, string $originalName, ?Library $library, string $source, ?int $userId = null, bool $linkOnly = false): IngestJob
    {
        return IngestJob::create([
            'library_id' => $library?->id, 'user_id' => $userId, 'source' => $source,
            'original_name' => $originalName, 'inbox_path' => $path, 'status' => 'queued', 'link_only' => $linkOnly,
        ]);
    }

    public function process(IngestJob $job): IngestJob
    {
        $path = $job->inbox_path;
        $name = $job->original_name;
        $sha = is_file($path) ? hash_file('sha256', $path) : null;
        $log = fn (string $stage, string $result, ?string $detail = null) => SecurityEvent::create([
            'ingest_job_id' => $job->id, 'file_name' => mb_substr($name, 0, 250), 'sha256' => $sha,
            'stage' => $stage, 'result' => $result, 'detail' => $detail ? mb_substr($detail, 0, 250) : null,
        ]);

        try {
            if (! $path || ! is_file($path)) {
                return $this->finish($job, 'failed', 'file_missing');
            }

            // 1-2. Real type, not extension
            $job->update(['status' => 'checking']);
            $type = $this->inspector->inspect($path, $name);
            $job->update(['detected_mime' => $type['mime']]);
            if (! $type['ok']) {
                $log('type', 'rejected', $type['reason']);

                return $this->isolate($job, 'rejected', $type['reason']);
            }
            $log('type', 'ok', $type['category'].' '.$type['mime']);

            if (! in_array($type['category'], ['video', 'audio'], true)) {
                $log('type', 'rejected', 'standalone_'.$type['category'].'_not_ingestable_here');

                return $this->isolate($job, 'rejected', 'not_a_media_container');
            }

            try {
                $info = $this->probe->analyze($path);
            } catch (Throwable $e) {
                $log('probe', 'rejected', $e->getMessage());

                return $this->isolate($job, 'rejected', 'probe_failed');
            }
            if (! $info['video'] && ! $info['audio']) {
                $log('probe', 'rejected', 'no_av_streams');

                return $this->isolate($job, 'rejected', 'no_av_streams');
            }

            // 3. Antivirus
            $job->update(['status' => 'scanning']);
            $scan = $this->scanner->scan($path);
            if ($scan->failed()) {
                $log('antivirus', 'error', $scan->error);
                if (config('movexa.security.require_scanner')) {
                    return $this->isolate($job, 'quarantined', 'scanner_unavailable');
                }
            }
            $infected = ! $scan->clean && ! $scan->failed();
            $log('antivirus', $infected ? 'infected' : 'clean', $scan->threat);
            if ($infected) {
                $job->update(['threat' => $scan->threat]);
            }

            // Link mode (CloudSync): clean files without extra payload are referenced, not copied.
            $suspicious = $this->hasPayload($info);
            if ($job->link_only && ! $infected && ! $suspicious) {
                $file = $this->register($job, $path, $info, false, true);
                $log('link', 'ok');

                return $this->finish($job, 'done', null, $file);
            }

            // 4. CDR rebuild — always (copy mode) or when needed (link mode)
            $job->update(['status' => 'sanitizing']);
            $work = config('movexa.paths.inbox').'/.work';
            @mkdir($work, 0750, true);
            try {
                $rebuilt = $this->sanitizer->rebuild($path, $work.'/'.Str::uuid(), $info);
            } catch (Throwable $e) {
                $log('sanitize', 'failed', $e->getMessage());

                return $this->isolate($job, 'quarantined', $infected ? 'infected_sanitize_failed' : 'sanitize_failed');
            }
            $log('sanitize', $rebuilt['mode'], $rebuilt['removed'] ? 'removed '.implode(',', array_slice($rebuilt['removed'], 0, 8)) : 'nothing_removed');

            // 5. Verify: duration/streams checked inside rebuild(); now rescan the NEW file.
            $job->update(['status' => 'verifying']);
            $rescan = $this->scanner->scan($rebuilt['path']);
            if (! $rescan->clean && ! ($rescan->failed() && ! config('movexa.security.require_scanner'))) {
                @unlink($rebuilt['path']);
                $log('rescan', $rescan->failed() ? 'error' : 'infected', $rescan->threat ?? $rescan->error);

                return $this->isolate($job, 'quarantined', 'rescan_failed');
            }
            $log('rescan', 'clean');

            $dest = $this->libraryDestination($job, $rebuilt['path']);
            rename($rebuilt['path'], $dest);
            @chmod($dest, 0644);
            $file = $this->register($job, $dest, $rebuilt['after'], true, false);
            $this->extractSubtitles($file, $path, $rebuilt['text_subtitles']);

            // 6. Original: quarantined if infected, otherwise removed (link mode: left untouched).
            if ($infected) {
                $job->update(['quarantine_path' => $this->quarantine->put($path), 'purge_after' => now()->addDays(config('movexa.security.quarantine_days'))]);
            } elseif (! $job->link_only) {
                @unlink($path);
            }
            $job->update(['sanitized' => true]);

            return $this->finish($job, 'done', $infected ? 'sanitized_after_detection' : null, $file);
        } catch (Throwable $e) {
            Log::error('ingest failed', ['job' => $job->id, 'error' => $e->getMessage()]);
            $log('pipeline', 'error', $e->getMessage());

            return $this->isolate($job, 'failed', 'internal_error');
        }
    }

    /** Re-run CDR on a quarantined original (admin "retry"). Never releases the original as-is. */
    public function retry(IngestJob $job): IngestJob
    {
        if (! $job->quarantine_path || ! is_file($job->quarantine_path)) {
            return $job;
        }
        $tmp = config('movexa.paths.inbox').'/.work/retry-'.Str::uuid().'-'.basename($job->original_name);
        @mkdir(dirname($tmp), 0750, true);
        $this->quarantine->restoreTo($job->quarantine_path, $tmp);
        @unlink($job->quarantine_path);
        $job->update(['inbox_path' => $tmp, 'quarantine_path' => null, 'status' => 'queued', 'reason' => null, 'link_only' => false]);

        return $this->process($job->fresh());
    }

    private function hasPayload(array $info): bool
    {
        foreach ($info['streams'] as $s) {
            if (! in_array($s['type'], ['video', 'audio', 'subtitle'], true) || $s['attached_pic']) {
                return true;
            }
        }

        return false;
    }

    private function register(IngestJob $job, string $path, array $info, bool $sanitized, bool $linked): MediaFile
    {
        $file = MediaFile::create([
            'library_id' => $job->library_id,
            'path' => $path,
            'original_name' => $job->original_name,
            'sha256' => hash_file('sha256', $path),
            'size' => filesize($path),
            'container' => MediaProbe::containerFromFormat($info['format_name'], $path),
            'video_codec' => $info['video']['codec'] ?? null,
            'audio_codec' => $info['audio']['codec'] ?? null,
            'width' => $info['video']['width'] ?? null,
            'height' => $info['video']['height'] ?? null,
            'duration' => $info['duration'],
            'bitrate' => $info['bitrate'],
            'probe' => ['streams' => $info['streams'], 'format' => $info['format_name']],
            'sanitized' => $sanitized,
            'linked' => $linked,
            'status' => 'pending',
        ]);
        foreach ($info['streams'] as $s) {
            $file->streams()->create([
                'index' => $s['index'], 'type' => $s['type'], 'codec' => $s['codec'], 'language' => $s['language'] ? substr($s['language'], 0, 8) : null,
                'title' => $s['title'] ? mb_substr($s['title'], 0, 120) : null, 'channels' => $s['channels'], 'is_default' => $s['is_default'],
                'is_text' => in_array($s['codec'], ['subrip', 'ass', 'ssa', 'webvtt', 'mov_text', 'text'], true),
            ]);
        }

        $this->indexer->index($file, $job->original_name, $job->library);
        $file->update(['status' => 'ready']); // only now visible
        if (config('movexa.tmdb.key') && $file->media_item_id) {
            FetchMetadataJob::dispatch($file->media_item_id);
        }
        if ($file->video_codec) {
            GenerateThumbnailsJob::dispatch($file->id);
        }

        return $file;
    }

    private function extractSubtitles(MediaFile $file, string $original, array $streams): void
    {
        $dir = config('movexa.paths.library').'/.subtitles/'.$file->id;
        @mkdir($dir, 0755, true);
        foreach ($streams as $s) {
            $raw = $dir.'/'.$s['index'].'.srt';
            if (! $this->sanitizer->extractSubtitle($original, $s['index'], $raw)) {
                continue;
            }
            $clean = $this->subtitles->sanitize((string) file_get_contents($raw), 'srt');
            @unlink($raw);
            if ($clean['cues'] === 0) {
                continue;
            }
            $vtt = $dir.'/'.$s['index'].'.vtt';
            file_put_contents($vtt, $clean['vtt']);
            $file->subtitles()->create([
                'language' => $s['language'] ? substr($s['language'], 0, 8) : null,
                'label' => $s['title'] ?: strtoupper($s['language'] ?? 'und'),
                'path' => $vtt, 'source' => 'embedded', 'source_encoding' => $clean['encoding'],
            ]);
        }
    }

    private function libraryDestination(IngestJob $job, string $rebuilt): string
    {
        $parsed = app(FilenameParser::class)->parse($job->original_name);
        $base = $job->library?->path ?: config('movexa.paths.library');
        $folder = $parsed['type'] === 'episode'
            ? $this->safe($parsed['title']).'/Season '.str_pad((string) $parsed['season'], 2, '0', STR_PAD_LEFT)
            : $this->safe($parsed['title'].($parsed['year'] ? " ({$parsed['year']})" : ''));
        $dir = rtrim($base, '/').'/'.$folder;
        @mkdir($dir, 0755, true);
        $stem = $this->safe(pathinfo($job->original_name, PATHINFO_FILENAME));
        $ext = pathinfo($rebuilt, PATHINFO_EXTENSION);
        $dest = "{$dir}/{$stem}.{$ext}";
        $i = 1;
        while (file_exists($dest)) {
            $dest = "{$dir}/{$stem} ({$i}).{$ext}";
            $i++;
        }

        return $dest;
    }

    private function safe(string $s): string
    {
        $s = preg_replace('/[^\p{L}\p{N} ._()\-\[\]]/u', '', $s);

        return trim(mb_substr($s, 0, 120), ' .') ?: 'untitled';
    }

    private function isolate(IngestJob $job, string $status, string $reason): IngestJob
    {
        $update = ['status' => $status, 'reason' => $reason];
        if ($job->inbox_path && is_file($job->inbox_path) && ! $job->link_only) {
            $update['quarantine_path'] = $this->quarantine->put($job->inbox_path);
            $update['purge_after'] = now()->addDays(config('movexa.security.quarantine_days'));
        }
        $job->update($update);
        Log::warning('movexa.ingest.'.$status, ['job' => $job->id, 'reason' => $reason]);

        return $job;
    }

    private function finish(IngestJob $job, string $status, ?string $reason, ?MediaFile $file = null): IngestJob
    {
        $job->update(['status' => $status, 'reason' => $reason, 'media_file_id' => $file?->id]);

        return $job;
    }
}
