<?php

namespace App\Modules\Library\Services;

use App\Modules\Library\Jobs\ProcessIngestJob;
use App\Modules\Library\Models\IngestJob;
use App\Modules\Library\Models\Library;
use App\Modules\Library\Models\MediaFile;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Library folders are scanned in "verify in place" mode (link_only): type check + ffprobe + ClamAV.
 * Files carrying extra payload (attachments, data streams) are rebuilt by CDR into the managed library.
 * Inbox drop folders (Samba / external disk) are ingested in copy mode with full CDR.
 */
class LibraryScanner
{
    public function scan(Library $library): int
    {
        $known = MediaFile::where('library_id', $library->id)->pluck('original_name', 'path')->all();
        $pending = IngestJob::whereIn('status', ['queued', 'checking', 'scanning', 'sanitizing', 'verifying'])->pluck('inbox_path')->flip()->all();
        $done = IngestJob::where('library_id', $library->id)->where('link_only', true)->pluck('inbox_path')->flip()->all();
        $count = 0;

        foreach ($this->files($library->path) as $path) {
            if (isset($known[$path]) || isset($pending[$path]) || isset($done[$path])) {
                continue;
            }
            $job = IngestPipeline::enqueue($path, $this->relativeName($library->path, $path), $library, 'scan', null, true);
            ProcessIngestJob::dispatch($job->id);
            $count++;
        }

        // Files removed from disk disappear from the library.
        MediaFile::where('library_id', $library->id)->where('linked', true)->get()
            ->each(fn ($f) => is_file($f->path) ?: $f->delete());

        $library->update(['last_scanned_at' => now()]);

        return $count;
    }

    /** Copy-mode intake for the inbox drop folder (Samba share, external disk import). */
    public function scanInbox(): int
    {
        $count = 0;
        foreach (['movies', 'shows'] as $type) {
            $dir = config('movexa.paths.inbox')."/drop/{$type}";
            if (! is_dir($dir)) {
                continue;
            }
            $library = Library::where('type', $type)->where('source', 'local')->first();
            foreach ($this->files($dir, minAge: 60) as $path) {
                if (IngestJob::where('inbox_path', $path)->exists()) {
                    continue;
                }
                ProcessIngestJob::dispatch(IngestPipeline::enqueue($path, $this->relativeName($dir, $path), $library, 'samba')->id);
                $count++;
            }
        }

        return $count;
    }

    /** @return \Generator<string> */
    public function files(string $root, int $minAge = 0): \Generator
    {
        if (! is_dir($root)) {
            return;
        }
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        $exts = array_merge(...array_values(array_intersect_key(config('movexa.security.allowed_extensions'), ['video' => 1, 'audio' => 1])));
        foreach ($it as $f) {
            /** @var \SplFileInfo $f */
            if (! $f->isFile() || str_contains($f->getPathname(), '/.') || $f->isLink()) {
                continue;
            }
            if ($minAge && time() - $f->getMTime() < $minAge) {
                continue; // still being copied
            }
            $ext = strtolower($f->getExtension());
            // Unknown types in a library folder are still sent to the inspector so they get logged/rejected.
            if (in_array($ext, $exts, true) || ! in_array($ext, ['nfo', 'txt', 'srt', 'vtt', 'ass', 'ssa', 'jpg', 'jpeg', 'png', 'webp', 'sub', 'idx'], true)) {
                yield $f->getPathname();
            }
        }
    }

    private function relativeName(string $root, string $path): string
    {
        return ltrim(substr($path, strlen(rtrim($root, '/'))), '/');
    }
}
