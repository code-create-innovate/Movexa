<?php

namespace App\Modules\Playback\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Services\MediaSigner;
use App\Modules\Library\Http\Controllers\BrowseController;
use App\Modules\Library\Models\MediaFile;
use App\Modules\Library\Services\MediaPresenter;
use App\Modules\Library\Services\Security\SubtitleSanitizer;
use App\Modules\Library\Services\Security\VirusScanner;
use App\Modules\Media\Jobs\TranscodeMediaJob;
use App\Modules\Media\Services\HlsPackager;
use App\Modules\Media\Services\PlaybackDecider;
use App\Modules\Playback\Models\PlaybackSession;
use App\Modules\Playback\Models\TranscodeJob;
use App\Modules\Playback\Models\WatchProgress;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;

class PlaybackController extends Controller
{
    public function __construct(private MediaSigner $signer) {}

    private function file(Request $request, int $id): MediaFile
    {
        $f = MediaFile::with('item', 'streams', 'subtitles', 'episode.season')->find($id);
        abort_unless($f && $f->viewableBy($request->attributes->get('profile')), 404);

        return $f;
    }

    public function page(Request $request, int $file, MediaPresenter $present)
    {
        $f = $this->file($request, $file);
        $profile = $request->attributes->get('profile');
        $next = $f->episode ? BrowseController::episodeAfter($f->episode) : null;
        if ($next && (! $next->file || ! $next->file->viewableBy($profile))) {
            $next = null;
        }

        return Inertia::render('watch/player', [
            'file' => [
                'id' => $f->id, 'duration' => $f->duration, 'height' => $f->height,
                'intro' => $f->intro_end ? ['start' => $f->intro_start ?? 0, 'end' => $f->intro_end] : null,
                'audio' => $f->streams->where('type', 'audio')->map(fn ($s) => ['language' => $s->language, 'title' => $s->title, 'codec' => $s->codec])->values(),
                'qualities' => array_values(app(HlsPackager::class)->ladderFor($f)),
            ],
            'item' => $present->card($f->item, $profile) + [
                'overview' => app()->getLocale() === 'ar' ? ($f->item->overview_ar ?: $f->item->overview) : ($f->item->overview ?: $f->item->overview_ar),
                'cast' => array_slice(array_column($f->item->cast ?? [], 'name'), 0, 4),
            ],
            'recap' => $this->recap($f, $profile),
            'settings' => $profile->allSettings(),
            'episode' => $f->episode ? ['season' => $f->episode->season->number, 'number' => $f->episode->number, 'title' => $f->episode->title] : null,
            'next' => $next?->file ? ['file_id' => $next->file->id, 'season' => $next->season->number, 'number' => $next->number, 'title' => $next->title] : null,
            'prefs' => $profile->subtitle_prefs ?? ['size' => 100, 'color' => '#ffffff', 'background' => 60, 'position' => 8],
            'preferredSubtitle' => $profile->preferred_subtitle_lang,
            'party' => $request->query('party'),
        ]);
    }

    /**
     * "وين وقفنا؟" — returning to a show after N days: summary of the last episode THIS profile watched
     * (stored locally, Arabic first). Never anything about upcoming episodes. No summary → no card.
     */
    private function recap(MediaFile $f, $profile): ?array
    {
        if (! $f->episode_id || ! $profile->setting('recap')) {
            return null;
        }
        $last = WatchProgress::with('episode.season')->where('profile_id', $profile->id)->where('media_item_id', $f->media_item_id)
            ->where('media_file_id', '!=', $f->id)->whereNotNull('episode_id')->latest('updated_at')->first();
        if (! $last || $last->updated_at->gt(now()->subDays(config('movexa.discovery.recap_after_days'))) || ! $last->episode) {
            return null;
        }
        $ar = app()->getLocale() === 'ar';
        $summary = $ar ? ($last->episode->overview_ar ?: $last->episode->overview) : ($last->episode->overview ?: $last->episode->overview_ar);
        if (! $summary) {
            return null;
        }

        return ['season' => $last->episode->season->number, 'number' => $last->episode->number,
            'title' => $ar ? ($last->episode->title_ar ?: $last->episode->title) : $last->episode->title, 'summary' => $summary, 'days' => (int) $last->updated_at->diffInDays(now())];
    }

    /** Lightweight re-check used by players after an "access revoked" signal. */
    public function check(Request $request, int $file)
    {
        $this->file($request, $file);

        return response()->noContent();
    }

    public function start(Request $request, int $file, PlaybackDecider $decider, HlsPackager $packager)
    {
        $f = $this->file($request, $file);
        $profile = $request->attributes->get('profile');
        $user = $request->user();
        $data = $request->validate([
            'caps' => ['array'], 'caps.video' => ['array'], 'caps.audio' => ['array'], 'caps.containers' => ['array'], 'caps.maxHeight' => ['nullable', 'integer'],
            'audio' => ['nullable', 'integer', 'min:0'], 'quality' => ['nullable', 'string'], 'session' => ['nullable', 'uuid'],
        ]);

        $limit = $user->max_concurrent_streams ?? config('movexa.limits.streams_per_user');
        $active = PlaybackSession::active()->where('user_id', $user->id)->when($data['session'] ?? null, fn ($q, $s) => $q->where('uuid', '!=', $s))->count();
        if ($active >= $limit) {
            return response()->json(['message' => 'stream_limit', 'limit' => $limit], 429);
        }

        $quality = $data['quality'] ?? 'auto';
        $decision = $decider->decide($f, $data['caps'] ?? [], (int) ($data['audio'] ?? 0), $quality !== 'auto' && $quality !== 'original');

        $session = PlaybackSession::updateOrCreate(['uuid' => $data['session'] ?? (string) Str::uuid()], [
            'user_id' => $user->id, 'profile_id' => $profile->id, 'media_file_id' => $f->id, 'method' => $decision['method'],
            'client' => mb_substr((string) $request->userAgent(), 0, 190), 'last_seen_at' => now(),
        ]);

        $resp = [
            'session' => $session->uuid, 'method' => $decision['method'], 'reason' => $decision['reason'],
            'position' => (float) (WatchProgress::where('profile_id', $profile->id)->where('media_file_id', $f->id)->where('completed', false)->value('position') ?? 0),
            'subtitles' => $f->subtitles->map(fn ($s) => ['id' => $s->id, 'label' => $s->label, 'language' => $s->language, 'url' => $this->signer->url('sub', $s->id, $user->id, $profile->id, 'subtitle.vtt')])->values(),
            'thumbnails' => $f->thumbnails_path ? $this->signer->url('thumb', $f->id, $user->id, $profile->id, 'thumbnails.vtt') : null,
            'colors' => $f->thumbnails_path && is_file($f->thumbnails_path.'/colors.json') ? $this->signer->url('thumb', $f->id, $user->id, $profile->id, 'colors.json') : null,
        ];

        if ($decision['method'] === 'direct') {
            return $resp + ['type' => 'file', 'src' => $this->signer->url('file', $f->id, $user->id, $profile->id, 'stream.'.($f->container === 'webm' ? 'webm' : 'mp4')), 'ready' => true];
        }

        $mode = $decision['method'];
        $heights = $mode === 'transcode' ? ($quality !== 'auto' && ctype_digit($quality) ? [(int) $quality] : $packager->ladderFor($f)) : null;
        $key = HlsPackager::profileKey($mode, $heights);
        $job = TranscodeJob::firstOrCreate(['media_file_id' => $f->id, 'profile_key' => $key], ['mode' => $mode, 'status' => 'queued']);
        if ($job->status === 'failed' || ($job->status === 'done' && (! $job->output_dir || ! is_file($job->output_dir.'/master.m3u8')))) {
            $job->update(['status' => 'queued', 'error' => null]);
        }
        if ($job->wasRecentlyCreated || $job->status === 'queued') {
            TranscodeMediaJob::dispatch($job->id);
            $job->refresh();
        }

        return $resp + [
            'type' => 'hls', 'job' => $job->id, 'ready' => HlsPackager::isPlayable($job), 'progress' => $job->progress, 'status' => $job->status,
            'src' => $this->signer->url('hls', $job->id, $user->id, $profile->id, 'master.m3u8'),
        ];
    }

    public function progress(Request $request, string $session)
    {
        $s = PlaybackSession::where('uuid', $session)->where('user_id', $request->user()->id)->firstOrFail();
        $profile = $request->attributes->get('profile');
        abort_unless($s->profile_id === $profile->id, 403);
        $data = $request->validate(['position' => ['required', 'numeric', 'min:0'], 'duration' => ['nullable', 'numeric', 'min:0']]);
        $s->update(['last_seen_at' => now()]);

        $file = MediaFile::find($s->media_file_id);
        $duration = $data['duration'] ?: $file->duration;
        $completed = $duration && $data['position'] >= $duration * 0.92;
        if (! $s->counted && $duration && $data['position'] >= min(600, $duration * 0.2)) {
            DB::table('view_events')->insert(['profile_id' => $profile->id, 'media_item_id' => $file->media_item_id, 'counted_at' => now()]);
            $s->update(['counted' => true]);
        }
        $row = WatchProgress::firstOrNew(['profile_id' => $profile->id, 'media_file_id' => $file->id]);
        $wasCompleted = $row->exists && $row->completed;
        $row->fill(['media_item_id' => $file->media_item_id, 'episode_id' => $file->episode_id, 'position' => $completed ? 0 : $data['position'], 'duration' => $duration, 'completed' => $completed])->save();
        if ($completed && ! $wasCompleted) {
            DB::table('watch_history')->insert(['profile_id' => $profile->id, 'media_item_id' => $file->media_item_id, 'episode_id' => $file->episode_id, 'watched_at' => now()]);
        }

        return ['ok' => true, 'completed' => $completed];
    }

    public function status(Request $request, int $job)
    {
        $j = TranscodeJob::findOrFail($job);
        abort_unless($j->file && $j->file->viewableBy($request->attributes->get('profile')), 404);

        return ['ready' => HlsPackager::isPlayable($j), 'progress' => $j->progress, 'status' => $j->status];
    }

    public function stop(Request $request, string $session)
    {
        PlaybackSession::where('uuid', $session)->where('user_id', $request->user()->id)->delete();

        return ['ok' => true];
    }

    /** Manual subtitle upload: scanned, decoded (Windows-1256 etc.), sanitized to WebVTT. */
    public function uploadSubtitle(Request $request, int $file, SubtitleSanitizer $sanitizer, VirusScanner $scanner)
    {
        $f = $this->file($request, $file);
        abort_if($request->attributes->get('profile')->is_kid, 403);
        $data = $request->validate(['subtitle' => ['required', 'file', 'max:5120'], 'language' => ['nullable', 'string', 'max:8'], 'label' => ['nullable', 'string', 'max:60']]);
        $upload = $data['subtitle'];
        $ext = strtolower($upload->getClientOriginalExtension());
        abort_unless(in_array($ext, ['srt', 'vtt', 'ass', 'ssa'], true), 422, 'unsupported subtitle type');
        $scan = $scanner->scan($upload->getRealPath());
        if (! $scan->clean && ! (! config('movexa.security.require_scanner') && $scan->failed())) {
            return response()->json(['message' => 'rejected'], 422);
        }
        $raw = (string) file_get_contents($upload->getRealPath());
        abort_if(preg_match('/[\x00-\x08\x0E-\x1A]/', substr($raw, 0, 4096)) && ! str_starts_with($raw, "\xFF\xFE") && ! str_starts_with($raw, "\xFE\xFF"), 422, 'binary content');
        $clean = $sanitizer->sanitize($raw, $ext);
        abort_if($clean['cues'] === 0, 422, 'no cues');
        $dir = config('movexa.paths.library').'/.subtitles/'.$f->id;
        @mkdir($dir, 0755, true);
        $path = $dir.'/upload-'.Str::random(10).'.vtt';
        file_put_contents($path, $clean['vtt']);
        $sub = $f->subtitles()->create(['language' => $data['language'] ?? null, 'label' => $data['label'] ?? strtoupper($data['language'] ?? 'Upload'), 'path' => $path, 'source' => 'upload', 'source_encoding' => $clean['encoding']]);

        return ['id' => $sub->id, 'encoding' => $clean['encoding'], 'cues' => $clean['cues']];
    }
}
