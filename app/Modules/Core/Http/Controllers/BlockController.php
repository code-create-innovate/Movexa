<?php

namespace App\Modules\Core\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Core\Events\AccessRevoked;
use App\Modules\Core\Models\AccessBlock;
use App\Modules\Core\Models\AuditLog;
use App\Modules\Core\Models\Profile;
use App\Modules\Core\Services\ProfileContext;
use App\Modules\Library\Models\Collection;
use App\Modules\Library\Models\Episode;
use App\Modules\Library\Models\Library;
use App\Modules\Library\Models\MediaItem;
use App\Modules\Library\Models\Season;
use App\Modules\LiveTV\Models\Channel;
use App\Modules\Playback\Models\PlaybackSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Custom blocking per account. Allowed for admins and accounts with the "blocks" permission (route middleware).
 * Every change needs the user's password. Nobody (except admins) sees blocks that target their own account.
 */
class BlockController extends Controller
{
    public function __construct(private ProfileContext $ctx) {}

    private function guard(Request $request): void
    {
        abort_if($request->session()->has('view_as'), 403);
    }

    /** Subjects: accounts (never the caller's own account unless admin). */
    private function allowedSubjects(Request $request): array
    {
        $user = $request->user();
        $users = User::orderBy('name')->get(['id', 'name', 'username'])->when(! $user->is_admin, fn ($c) => $c->where('id', '!=', $user->id))->values();

        return [collect(), $users];
    }

    public function index(Request $request)
    {
        $this->guard($request);
        $user = $request->user();
        [$profiles, $users] = $this->allowedSubjects($request);
        $me = $this->ctx->current();

        $q = AccessBlock::query()->latest();
        if (! $user->is_admin) {
            $q->where(fn ($w) => $w->where('subject_type', '!=', 'user')->orWhere('subject_id', '!=', $user->id))
                ->where(fn ($w) => $w->where('subject_type', '!=', 'profile')->orWhere('subject_id', '!=', $user->primaryProfile()->id));
        }
        $blocks = $q->get()->map(fn ($b) => $this->present($b, $profiles, $users));

        return Inertia::render('blocks/index', [
            'blocks' => $blocks,
            'users' => $users,
            'isAdmin' => (bool) $user->is_admin,
            'canViewAs' => $user->hasPermission('users'),
            'targets' => [
                'genres' => MediaItem::pluck('genres')->flatten()->filter()->unique()->sort()->values(),
                'tags' => MediaItem::pluck('tags')->flatten()->filter()->unique()->sort()->values(),
                'libraries' => Library::get(['id', 'name']),
                'collections' => Collection::get(['id', 'name']),
                'categories' => Channel::whereNotNull('category')->distinct()->pluck('category'),
            ],
            'audit' => $user->is_admin ? AuditLog::latest('id')->limit(50)->get() : [],
        ]);
    }

    /** Options for the "Block from…" dialog on a title / channel page. */
    public function options(Request $request)
    {
        $this->guard($request);
        [$profiles, $users] = $this->allowedSubjects($request);

        return ['users' => $users->values()];
    }

    public function store(Request $request)
    {
        $this->guard($request);
        $d = $request->validate([
            'target_type' => ['required', 'in:item,season,episode,genre,collection,tag,library,channel,channel_category'],
            'target_id' => ['nullable', 'integer'],
            'target_value' => ['nullable', 'string', 'max:190'],
            'users' => ['array'], 'users.*' => ['integer'],
            'expires_at' => ['nullable', 'date', 'after:now'],
            'secret' => ['required', 'string'],
        ]);
        $this->verifySecret($request, $d['secret']);
        $this->validateTarget($d);
        [$profiles, $users] = $this->allowedSubjects($request);
        $profileIds = [];
        $userIds = array_values(array_intersect($d['users'] ?? [], collect($users)->pluck('id')->all()));
        abort_if(! $profileIds && ! $userIds, 422, 'no subjects');

        $created = [];
        foreach ([['profile', $profileIds], ['user', $userIds]] as [$type, $ids]) {
            foreach ($ids as $id) {
                $created[] = AccessBlock::create([
                    'target_type' => $d['target_type'], 'target_id' => $d['target_id'] ?? null, 'target_value' => $d['target_value'] ?? null,
                    'subject_type' => $type, 'subject_id' => $id, 'expires_at' => $d['expires_at'] ?? null, 'created_by' => $request->user()->id,
                ])->id;
            }
        }
        AuditLog::record('block.created', ['ids' => $created, 'target' => $d['target_type'], 'target_id' => $d['target_id'] ?? null, 'target_value' => $d['target_value'] ?? null, 'profiles' => $profileIds, 'users' => $userIds, 'expires_at' => $d['expires_at'] ?? null]);
        $this->revokePlayback($profileIds, $userIds);

        return back();
    }

    public function destroy(Request $request)
    {
        $this->guard($request);
        $d = $request->validate(['ids' => ['required', 'array'], 'ids.*' => ['integer'], 'secret' => ['required', 'string']]);
        $this->verifySecret($request, $d['secret']);
        $q = AccessBlock::whereIn('id', $d['ids']);
        if (! $request->user()->is_admin) {
            $q->where(fn ($w) => $w->where('subject_type', '!=', 'user')->orWhere('subject_id', '!=', $request->user()->id));
        }
        $ids = $q->pluck('id')->all();
        AccessBlock::whereIn('id', $ids)->delete();
        AuditLog::record('block.removed', ['ids' => $ids]);

        return back();
    }

    /** "View as" another account (read-only) — "users" permission (route middleware). */
    public function viewAs(Request $request, User $user)
    {
        $request->session()->put('view_as', ['user' => $user->id]);
        AuditLog::record('view_as.start', ['user' => $user->id]);

        return redirect('/home');
    }

    public function exitViewAs(Request $request)
    {
        $va = $request->session()->pull('view_as');
        if ($va) {
            AuditLog::record('view_as.end', ['user' => $va['user'] ?? null]);
        }

        return redirect('/admin/users');
    }

    private function verifySecret(Request $request, string $secret): void
    {
        $u = $request->user();
        $ok = Hash::check($secret, $u->password);
        if (! $ok) {
            throw ValidationException::withMessages(['secret' => __('movexa.pin_invalid')]);
        }
    }

    private function validateTarget(array $d): void
    {
        $id = $d['target_id'] ?? null;
        $ok = match ($d['target_type']) {
            'item' => MediaItem::whereKey($id)->exists(),
            'season' => Season::whereKey($id)->exists(),
            'episode' => Episode::whereKey($id)->exists(),
            'library' => Library::whereKey($id)->exists(),
            'collection' => Collection::whereKey($id)->exists(),
            'channel' => Channel::whereKey($id)->exists(),
            'genre', 'tag', 'channel_category' => ! empty($d['target_value']),
        };
        abort_unless($ok, 422, 'invalid target');
    }

    /** Running players of affected profiles get a stop signal; segments/URLs are re-checked on every request anyway. */
    private function revokePlayback(array $profileIds, array $userIds): void
    {
        $ids = array_unique(array_merge($profileIds, Profile::whereIn('user_id', $userIds)->pluck('id')->all()));
        if ($ids) {
            \App\Support\Realtime::send(new AccessRevoked($ids));
        }
    }

    private function present(AccessBlock $b, $profiles, $users): array
    {
        $label = match ($b->target_type) {
            'item' => MediaItem::find($b->target_id)?->title,
            'season' => ($s = Season::with('show')->find($b->target_id)) ? $s->show->title.' · S'.$s->number : null,
            'episode' => ($e = Episode::with('show', 'season')->find($b->target_id)) ? $e->show->title.' · S'.$e->season->number.'E'.$e->number : null,
            'library' => Library::find($b->target_id)?->name,
            'collection' => Collection::find($b->target_id)?->name,
            'channel' => Channel::find($b->target_id)?->name,
            default => $b->target_value,
        };
        $subject = $b->subject_type === 'profile' ? Profile::with('user')->find($b->subject_id)?->user?->name : collect($users)->firstWhere('id', $b->subject_id)?->name ?? User::find($b->subject_id)?->name;

        return ['id' => $b->id, 'target_type' => $b->target_type, 'target' => $label ?? '#'.$b->target_id, 'subject_type' => $b->subject_type, 'subject' => $subject ?? '#'.$b->subject_id,
            'subject_id' => $b->subject_id, 'expires_at' => $b->expires_at?->toIso8601String(), 'active' => ! $b->expires_at || $b->expires_at->isFuture(), 'created_at' => $b->created_at->toIso8601String()];
    }
}
