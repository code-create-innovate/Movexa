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
 * Custom blocking. Admin: any profile/account. Account owner: only profiles of their own account.
 * Changes need the password (or the parental PIN). Blocked profiles never see this page (kid profiles are refused,
 * and blocks targeting the current profile are hidden from the list).
 */
class BlockController extends Controller
{
    public function __construct(private ProfileContext $ctx) {}

    private function guard(Request $request): void
    {
        abort_if(! $this->ctx->current() || $this->ctx->current()->is_kid || $request->session()->has('view_as'), 403);
    }

    /** Which subjects the current user may block for. */
    private function allowedSubjects(Request $request): array
    {
        $user = $request->user();
        $profiles = $user->is_admin ? Profile::with('user:id,name')->get() : $user->profiles()->with('user:id,name')->get();
        $users = $user->is_admin ? User::all(['id', 'name']) : collect([$user->only('id', 'name')]);

        return [$profiles, $users];
    }

    public function index(Request $request)
    {
        $this->guard($request);
        $user = $request->user();
        [$profiles, $users] = $this->allowedSubjects($request);
        $me = $this->ctx->current();

        $q = AccessBlock::query()->latest();
        if (! $user->is_admin) {
            $q->where(fn ($w) => $w->where(fn ($a) => $a->where('subject_type', 'profile')->whereIn('subject_id', $profiles->pluck('id')))
                ->orWhere(fn ($a) => $a->where('subject_type', 'user')->where('subject_id', $user->id)));
        }
        // Never show a profile the blocks that target itself (unless admin).
        if (! $user->is_admin) {
            $q->where(fn ($w) => $w->where('subject_type', '!=', 'profile')->orWhere('subject_id', '!=', $me->id));
        }
        $blocks = $q->get()->map(fn ($b) => $this->present($b, $profiles, $users));

        $noPin = $user->profiles()->whereNull('pin_hash')->where('is_kid', false)->count();

        return Inertia::render('blocks/index', [
            'blocks' => $blocks,
            'profiles' => $profiles->map(fn ($p) => ['id' => $p->id, 'name' => $p->name, 'account' => $p->user->name, 'user_id' => $p->user_id, 'has_pin' => $p->has_pin]),
            'users' => $users,
            'warning_no_pin' => $noPin > 0,
            'isAdmin' => (bool) $user->is_admin,
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

        return [
            'profiles' => $profiles->map(fn ($p) => ['id' => $p->id, 'name' => $p->name, 'account' => $p->user->name, 'has_pin' => $p->has_pin])->values(),
            'users' => $users->values(),
            'warning_no_pin' => $request->user()->profiles()->whereNull('pin_hash')->where('is_kid', false)->exists(),
        ];
    }

    public function store(Request $request)
    {
        $this->guard($request);
        $d = $request->validate([
            'target_type' => ['required', 'in:item,season,episode,genre,collection,tag,library,channel,channel_category'],
            'target_id' => ['nullable', 'integer'],
            'target_value' => ['nullable', 'string', 'max:190'],
            'profiles' => ['array'], 'profiles.*' => ['integer'],
            'users' => ['array'], 'users.*' => ['integer'],
            'expires_at' => ['nullable', 'date', 'after:now'],
            'secret' => ['required', 'string'],
        ]);
        $this->verifySecret($request, $d['secret']);
        $this->validateTarget($d);
        [$profiles, $users] = $this->allowedSubjects($request);
        $profileIds = array_values(array_intersect($d['profiles'] ?? [], $profiles->pluck('id')->all()));
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
        [$profiles] = $this->allowedSubjects($request);
        $q = AccessBlock::whereIn('id', $d['ids']);
        if (! $request->user()->is_admin) {
            $q->where(fn ($w) => $w->where(fn ($a) => $a->where('subject_type', 'profile')->whereIn('subject_id', $profiles->pluck('id')))
                ->orWhere(fn ($a) => $a->where('subject_type', 'user')->where('subject_id', $request->user()->id)));
        }
        $ids = $q->pluck('id')->all();
        AccessBlock::whereIn('id', $ids)->delete();
        AuditLog::record('block.removed', ['ids' => $ids]);

        return back();
    }

    /** Admin "view as": read-only browsing as another profile. */
    public function viewAs(Request $request, Profile $profile)
    {
        abort_unless($request->user()->is_admin && ! $this->ctx->current()?->is_kid, 403);
        $request->session()->put('view_as', ['profile' => $profile->id, 'return' => $this->ctx->current()?->id]);
        AuditLog::record('view_as.start', ['profile' => $profile->id]);

        return redirect('/home');
    }

    public function exitViewAs(Request $request)
    {
        $va = $request->session()->pull('view_as');
        if ($va) {
            AuditLog::record('view_as.end', ['profile' => $va['profile']]);
        }

        return redirect('/admin/blocks');
    }

    private function verifySecret(Request $request, string $secret): void
    {
        $u = $request->user();
        $ok = Hash::check($secret, $u->password) || ($u->parental_pin_hash && Hash::check($secret, $u->parental_pin_hash))
            || (($p = $this->ctx->current()) && $p->pin_hash && Hash::check($secret, $p->pin_hash));
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
        $subject = $b->subject_type === 'profile' ? $profiles->firstWhere('id', $b->subject_id)?->name : collect($users)->firstWhere('id', $b->subject_id)['name'] ?? null;

        return ['id' => $b->id, 'target_type' => $b->target_type, 'target' => $label ?? '#'.$b->target_id, 'subject_type' => $b->subject_type, 'subject' => $subject ?? '#'.$b->subject_id,
            'subject_id' => $b->subject_id, 'expires_at' => $b->expires_at?->toIso8601String(), 'active' => ! $b->expires_at || $b->expires_at->isFuture(), 'created_at' => $b->created_at->toIso8601String()];
    }
}
