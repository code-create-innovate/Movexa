<?php

namespace App\Modules\Core\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Library\Jobs\ProcessIngestJob;
use App\Modules\Library\Jobs\ScanLibraryJob;
use App\Modules\Library\Models\IngestJob;
use App\Modules\Library\Models\Library;
use App\Modules\Library\Models\MediaFile;
use App\Modules\Library\Models\MediaItem;
use App\Modules\Library\Models\SecurityEvent;
use App\Modules\Library\Models\Upload;
use App\Modules\Library\Services\IngestPipeline;
use App\Modules\Library\Services\LibraryIndexer;
use App\Modules\Library\Services\Security\VirusScanner;
use App\Modules\Media\Jobs\TranscodeMediaJob;
use App\Modules\Media\Services\Encoders;
use App\Modules\Media\Services\HlsPackager;
use App\Modules\Playback\Models\PlaybackSession;
use App\Modules\Playback\Models\TranscodeJob;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;

class AdminController extends Controller
{
    public function dashboard(Encoders $encoders, VirusScanner $scanner)
    {
        $disk = fn ($p) => is_dir($p) ? ['free' => @disk_free_space($p), 'total' => @disk_total_space($p)] : null;
        $load = function_exists('sys_getloadavg') ? sys_getloadavg() : [0, 0, 0];

        return Inertia::render('admin/dashboard', [
            'stats' => [
                'items' => MediaItem::count(), 'files' => MediaFile::where('status', 'ready')->count(),
                'library_bytes' => (int) MediaFile::where('status', 'ready')->where('linked', false)->sum('size'),
                'cache_bytes' => (int) TranscodeJob::sum('output_size'),
                'disk' => $disk(config('movexa.paths.library')),
                'cpu_load' => $load, 'cpus' => (int) (@shell_exec('nproc') ?: 1),
                'encoder' => $encoders->select(), 'encoders' => array_keys($encoders->available()),
                'scanner' => $scanner->available(),
                'quarantined' => IngestJob::whereIn('status', ['quarantined', 'rejected', 'failed'])->whereNotNull('quarantine_path')->count(),
            ],
            'sessions' => PlaybackSession::active()->with('user:id,name')->get()->map(fn ($s) => [
                'user' => $s->user->name, 'method' => $s->method, 'client' => Str::limit($s->client, 60),
                'title' => $s->media_file_id ? MediaFile::find($s->media_file_id)?->item?->title : ('Live #'.$s->channel_id),
            ]),
            'transcodes' => TranscodeJob::with('file.item:id,title')->latest('updated_at')->limit(20)->get()->map(fn ($j) => [
                'id' => $j->id, 'title' => $j->file?->item?->title, 'mode' => $j->mode, 'profile' => $j->profile_key, 'status' => $j->status,
                'progress' => $j->progress, 'encoder' => $j->encoder, 'size' => $j->output_size, 'pinned' => $j->pinned, 'error' => $j->error,
            ]),
            'top' => DB::table('watch_history')->select('media_item_id', DB::raw('count(*) as plays'))->groupBy('media_item_id')->orderByDesc('plays')->limit(10)->get()
                ->map(fn ($r) => ['title' => MediaItem::find($r->media_item_id)?->title, 'plays' => $r->plays]),
            'libraries' => Library::withCount('items')->get(),
            'users' => User::withCount('profiles')->get(['id', 'name', 'email', 'is_admin', 'is_emergency_admin', 'max_concurrent_streams', 'downloads_enabled', 'downloads_quota_mb', 'downloads_max']),
            'limits' => ['streams_per_user' => config('movexa.limits.streams_per_user'), 'max_transcodes' => config('movexa.transcode.max_concurrent')],
        ]);
    }

    public function storeLibrary(Request $request)
    {
        $data = $request->validate(['name' => ['required', 'max:80'], 'type' => ['required', 'in:movies,shows'], 'path' => ['required', 'max:1024'], 'source' => ['required', 'in:local,cloudsync'], 'watch' => ['boolean']]);
        $real = realpath($data['path']);
        $allowed = array_filter([realpath(config('movexa.data_root')), realpath((string) config('movexa.cloudsync.root'))]);
        abort_unless($real && is_dir($real) && collect($allowed)->contains(fn ($r) => $real === $r || str_starts_with($real, $r.'/')), 422, 'path must be inside the data root or the CloudSync root');
        $lib = Library::create(['path' => $real] + $data);
        ScanLibraryJob::dispatch($lib->id);

        return back();
    }

    public function destroyLibrary(Library $library)
    {
        $library->delete();

        return back();
    }

    public function scan(Library $library)
    {
        ScanLibraryJob::dispatch($library->id);

        return back();
    }

    public function storeUser(Request $request)
    {
        $d = $request->validate(['name' => ['required', 'max:120'], 'email' => ['required', 'email', 'unique:users,email'], 'password' => ['required', 'min:10'], 'is_admin' => ['boolean']]);
        $user = User::create($d);
        $user->profiles()->create(['name' => $user->name]);

        return back();
    }

    public function updateUser(Request $request, User $user)
    {
        $data = $request->validate(['is_admin' => ['boolean'], 'max_concurrent_streams' => ['nullable', 'integer', 'between:1,20'],
            'downloads_enabled' => ['boolean'], 'downloads_quota_mb' => ['integer', 'between:100,1000000'], 'downloads_max' => ['integer', 'between:1,200']]);
        abort_if($user->id === $request->user()->id && array_key_exists('is_admin', $data) && ! $data['is_admin'], 422);
        $user->forceFill($data)->save();

        return back();
    }

    // ---- Library corrections ------------------------------------------------------

    public function matches(Request $request)
    {
        return Inertia::render('admin/matches', [
            'files' => MediaFile::with('item:id,title,year,type,tmdb_id,age_rating', 'episode.season')->where('status', 'ready')->latest()->paginate(50)
                ->through(fn ($f) => ['id' => $f->id, 'name' => $f->original_name, 'item' => $f->item, 'season' => $f->episode?->season?->number, 'episode' => $f->episode?->number, 'sanitized' => $f->sanitized, 'linked' => $f->linked]),
        ]);
    }

    public function rematch(Request $request, MediaFile $file, LibraryIndexer $indexer)
    {
        $d = $request->validate(['type' => ['required', 'in:movie,show'], 'title' => ['required', 'max:200'], 'year' => ['nullable', 'integer'], 'season' => ['nullable', 'integer'], 'episode' => ['nullable', 'integer'], 'tmdb_id' => ['nullable', 'integer']]);
        $item = $indexer->rematch($file, $d['type'], $d['title'], $d['year'] ?? null, $d['season'] ?? null, $d['episode'] ?? null);
        if (! empty($d['tmdb_id'])) {
            \App\Modules\Library\Jobs\FetchMetadataJob::dispatch($item->id, $d['tmdb_id']);
        }

        return back();
    }

    public function updateItem(Request $request, MediaItem $item)
    {
        $item->update($request->validate([
            'title' => ['required', 'max:250'], 'title_ar' => ['nullable', 'max:250'], 'overview' => ['nullable', 'max:5000'], 'overview_ar' => ['nullable', 'max:5000'],
            'year' => ['nullable', 'integer'], 'age_rating' => ['nullable', 'integer', 'between:0,18'], 'genres' => ['nullable', 'array'],
        ]) + ['match_locked' => true]);

        return back();
    }

    public function setIntro(Request $request, MediaFile $file)
    {
        $file->update($request->validate(['intro_start' => ['nullable', 'numeric', 'min:0'], 'intro_end' => ['nullable', 'numeric', 'gt:intro_start']]));

        return back();
    }

    // ---- Quarantine ---------------------------------------------------------------

    public function quarantine()
    {
        return Inertia::render('admin/quarantine', [
            'jobs' => IngestJob::whereIn('status', ['rejected', 'quarantined', 'failed'])->latest()->limit(200)->get()
                ->map(fn ($j) => $j->only('id', 'original_name', 'source', 'status', 'reason', 'threat', 'detected_mime', 'sanitized', 'purge_after', 'created_at') + ['can_retry' => (bool) $j->quarantine_path && $j->status !== 'rejected']),
            'recent' => IngestJob::latest()->limit(30)->get(['id', 'original_name', 'status', 'reason', 'threat', 'sanitized', 'created_at']),
            'events' => SecurityEvent::latest('id')->limit(100)->get(),
        ]);
    }

    public function retry(IngestJob $job, IngestPipeline $pipeline)
    {
        abort_if($job->status === 'rejected', 422); // non-media files are never retried
        $pipeline->retry($job);

        return back();
    }

    public function purge(IngestJob $job)
    {
        if ($job->quarantine_path) {
            @unlink($job->quarantine_path);
        }
        $job->update(['quarantine_path' => null, 'status' => $job->status === 'rejected' ? 'rejected' : 'failed', 'reason' => trim(($job->reason ?? '').' purged')]);

        return back();
    }

    // ---- Transcode cache ----------------------------------------------------------

    public function pretranscode(Request $request, MediaFile $file, HlsPackager $packager)
    {
        $key = HlsPackager::profileKey('transcode', $packager->ladderFor($file));
        $job = TranscodeJob::firstOrCreate(['media_file_id' => $file->id, 'profile_key' => $key], ['mode' => 'transcode']);
        $job->update(['pinned' => true]);
        if (in_array($job->status, ['queued', 'failed'], true)) {
            $job->update(['status' => 'queued']);
            TranscodeMediaJob::dispatch($job->id);
        }

        return back();
    }

    public function deleteTranscode(TranscodeJob $job)
    {
        if ($job->output_dir && is_dir($job->output_dir)) {
            \Illuminate\Support\Facades\File::deleteDirectory($job->output_dir);
        }
        $job->delete();

        return back();
    }

    // ---- Chunked, resumable upload ------------------------------------------------

    public function uploadInit(Request $request)
    {
        $d = $request->validate(['library_id' => ['required', 'exists:libraries,id'], 'file_name' => ['required', 'max:250'], 'size' => ['required', 'integer', 'min:1']]);
        $up = Upload::where('user_id', $request->user()->id)->where('file_name', $d['file_name'])->where('size', $d['size'])->where('completed', false)->first()
            ?? Upload::create($d + ['user_id' => $request->user()->id, 'uuid' => (string) Str::uuid(), 'chunk_size' => 8 * 1024 * 1024]);
        @mkdir(dirname($up->partPath()), 0750, true);
        $received = is_file($up->partPath()) ? filesize($up->partPath()) : 0;
        $up->update(['received' => $received]);

        return ['uuid' => $up->uuid, 'received' => $received, 'chunk_size' => $up->chunk_size];
    }

    public function uploadChunk(Request $request, string $uuid)
    {
        $up = Upload::where('uuid', $uuid)->where('user_id', $request->user()->id)->where('completed', false)->firstOrFail();
        $offset = (int) $request->header('X-Upload-Offset', '-1');
        $current = is_file($up->partPath()) ? filesize($up->partPath()) : 0;
        if ($offset !== $current) {
            return response()->json(['received' => $current], 409); // client resumes from here
        }
        $in = fopen('php://input', 'rb');
        $out = fopen($up->partPath(), 'ab');
        $written = stream_copy_to_stream($in, $out, $up->chunk_size + 1);
        fclose($in);
        fclose($out);
        if ($written > $up->chunk_size || $current + $written > $up->size) {
            ftruncate(fopen($up->partPath(), 'r+'), $current);

            return response()->json(['message' => 'chunk too large'], 413);
        }
        $received = $current + $written;
        $up->update(['received' => $received]);
        if ($received === (int) $up->size) {
            $inbox = config('movexa.paths.inbox').'/uploads';
            @mkdir($inbox, 0750, true);
            $dest = $inbox.'/'.$up->uuid.'.bin';
            rename($up->partPath(), $dest);
            $up->update(['completed' => true]);
            $job = IngestPipeline::enqueue($dest, $up->file_name, Library::find($up->library_id), 'upload', $up->user_id);
            ProcessIngestJob::dispatch($job->id);

            return ['received' => $received, 'completed' => true, 'ingest_id' => $job->id];
        }

        return ['received' => $received, 'completed' => false];
    }
}
