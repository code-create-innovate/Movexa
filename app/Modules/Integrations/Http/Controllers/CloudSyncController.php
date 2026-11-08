<?php

namespace App\Modules\Integrations\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Integrations\Services\CloudSyncClient;
use App\Modules\Library\Jobs\ProcessIngestJob;
use App\Modules\Library\Models\Library;
use App\Modules\Library\Services\IngestPipeline;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * CloudSync → Movexa (service token with ability "cloudsync:ingest").
 *  POST /api/cloudsync/link  : "Add to Movexa" — link the file in place (no copy), verify-only pipeline.
 *  POST /api/cloudsync/send  : "Send to library" from a device — CloudSync copies into the Movexa inbox, full CDR.
 * Movexa re-checks the user's permission with CloudSync before accepting (never trusts the path blindly).
 */
class CloudSyncController extends Controller
{
    public function __construct(private CloudSyncClient $client) {}

    public function link(Request $request)
    {
        return $this->accept($request, true);
    }

    public function send(Request $request)
    {
        return $this->accept($request, false);
    }

    public function status(Request $request, int $id)
    {
        $job = \App\Modules\Library\Models\IngestJob::where('source', 'cloudsync')->findOrFail($id);

        return ['id' => $job->id, 'status' => $job->status, 'reason' => $job->reason, 'media_file_id' => $job->media_file_id];
    }

    private function accept(Request $request, bool $link)
    {
        $data = $request->validate([
            'user' => ['required', 'string', 'max:191'],      // HomeCore OIDC subject
            'file_id' => ['required', 'string', 'max:191'],
            'library' => ['required', 'in:movies,shows'],
        ]);
        $user = User::where('oidc_subject', $data['user'])->first();
        if (! $user) {
            return response()->json(['message' => 'unknown_user'], 422);
        }
        $library = Library::where('type', $data['library'])->orderByRaw("source = 'cloudsync' desc")->first();
        if (! $library) {
            return response()->json(['message' => 'no_library'], 422);
        }

        $file = $this->client->resolve($data['user'], $data['file_id']);
        if (! $file || empty($file['can_read'])) {
            return response()->json(['message' => 'forbidden'], 403);
        }
        $path = CloudSyncClient::safePath($file['path'] ?? null);
        if (! $path) {
            return response()->json(['message' => 'path_outside_cloudsync_root'], 422);
        }

        if (! $link) {
            $dest = config('movexa.paths.inbox').'/cloudsync/'.Str::uuid().'-'.Str::limit(basename($path), 120, '');
            @mkdir(dirname($dest), 0750, true);
            if (! @copy($path, $dest)) {
                return response()->json(['message' => 'copy_failed'], 500);
            }
            $path = $dest;
        }
        $job = IngestPipeline::enqueue($path, basename($file['name'] ?? $path), $library, 'cloudsync', $user->id, $link);
        ProcessIngestJob::dispatch($job->id);

        return response()->json(['id' => $job->id, 'status' => $job->fresh()->status], 202);
    }
}
