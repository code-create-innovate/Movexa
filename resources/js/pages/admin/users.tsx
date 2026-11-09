import { router, useForm, usePage } from '@inertiajs/react';
import { Eye, Save, UserPlus } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Switch } from '@/components/ui/switch';
import AdminLayout, { Section } from '@/layouts/admin-layout';
import { useT } from '@/lib/i18n';
import type { Permission, SharedData } from '@/types';

type U = {
    id: number; name: string; username: string | null; email: string; is_admin: boolean; disabled: boolean; permissions: Permission[]; max_age_rating: number | null;
    allowed_library_ids: number[] | null; max_concurrent_streams: number | null; downloads_quota_mb: number; downloads_max: number; has_2fa: boolean; last_seen: string | null;
};
type Lib = { id: number; name: string; type: string };
const WATCH: Permission[] = ['live', 'party', 'download'];
const MANAGE: Permission[] = ['upload', 'library', 'quarantine', 'live_manage', 'blocks', 'stats', 'users'];
const AGES = [null, 0, 7, 12, 13, 16, 18];

export default function Users({ users, libraries, defaults }: { users: U[]; permissions: Permission[]; libraries: Lib[]; defaults: { streams_per_user: number } }) {
    const { t } = useT();
    const form = useForm({ name: '', username: '', email: '', password: '', permissions: ['live', 'party'] as Permission[], max_age_rating: null as number | null, allowed_library_ids: null as number[] | null });
    return (
        <AdminLayout title={t.accounts.title}>
            <Section title={t.accounts.add}>
                <form className="space-y-3" onSubmit={(e) => { e.preventDefault(); form.post('/admin/users', { preserveScroll: true, onSuccess: () => form.reset() }); }}>
                    <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
                        <Input dir="auto" placeholder={t.accounts.name} value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} required />
                        <Input dir="ltr" placeholder={t.accounts.username} value={form.data.username} onChange={(e) => form.setData('username', e.target.value)} required />
                        <Input dir="ltr" type="email" placeholder={t.accounts.email} value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} />
                        <Input type="password" placeholder={t.accounts.password} value={form.data.password} onChange={(e) => form.setData('password', e.target.value)} required minLength={8} />
                    </div>
                    <PermissionEditor value={form.data} onChange={(v) => form.setData({ ...form.data, ...v })} libraries={libraries} />
                    {Object.values(form.errors).map((e) => <p key={e} className="text-sm text-destructive">{e}</p>)}
                    <Button type="submit" disabled={form.processing}><UserPlus /> {t.accounts.add}</Button>
                </form>
            </Section>
            {users.map((u) => <UserCard key={u.id} u={u} libraries={libraries} defaults={defaults} />)}
        </AdminLayout>
    );
}

type Editable = { permissions: Permission[]; max_age_rating: number | null; allowed_library_ids: number[] | null; is_admin?: boolean };

function PermissionEditor({ value, onChange, libraries, isAdmin }: { value: Editable; onChange: (v: Partial<Editable>) => void; libraries: Lib[]; isAdmin?: boolean }) {
    const { t } = useT();
    const toggle = (p: Permission) => onChange({ permissions: value.permissions.includes(p) ? value.permissions.filter((x) => x !== p) : [...value.permissions, p] });
    const libs = value.allowed_library_ids;
    return (
        <div className="grid gap-4 md:grid-cols-3">
            {[[t.accounts.watching, WATCH], [t.accounts.managing, MANAGE]].map(([title, list]) => (
                <div key={title as string} className="space-y-1.5">
                    <div className="text-xs font-semibold text-muted-foreground">{title as string}</div>
                    {(list as Permission[]).map((p) => (
                        <label key={p} className="flex items-center gap-2 text-sm">
                            <Checkbox checked={isAdmin || value.permissions.includes(p)} disabled={isAdmin} onCheckedChange={() => toggle(p)} data-perm={p} /> {t.accounts.perms[p]}
                        </label>
                    ))}
                </div>
            ))}
            <div className="space-y-3">
                <div className="space-y-1.5">
                    <div className="text-xs font-semibold text-muted-foreground">{t.accounts.ageLimit}</div>
                    <div className="flex flex-wrap gap-1">
                        {AGES.map((a) => (
                            <button type="button" key={String(a)} onClick={() => onChange({ max_age_rating: a })}
                                className={`rounded-md px-2 py-1 text-xs ${value.max_age_rating === a ? 'bg-primary text-primary-foreground' : 'bg-secondary'}`}>{a === null ? t.accounts.noLimit : `${a}+`}</button>
                        ))}
                    </div>
                </div>
                <div className="space-y-1.5">
                    <div className="text-xs font-semibold text-muted-foreground">{t.accounts.libraries}</div>
                    <label className="flex items-center gap-2 text-sm"><Checkbox checked={libs === null} onCheckedChange={(v) => onChange({ allowed_library_ids: v ? null : [] })} /> {t.accounts.allLibraries}</label>
                    {libs !== null && libraries.map((l) => (
                        <label key={l.id} className="flex items-center gap-2 text-sm">
                            <Checkbox checked={libs.includes(l.id)} onCheckedChange={() => onChange({ allowed_library_ids: libs.includes(l.id) ? libs.filter((x) => x !== l.id) : [...libs, l.id] })} /> <span dir="auto">{l.name}</span>
                        </label>
                    ))}
                </div>
            </div>
        </div>
    );
}

function UserCard({ u, libraries, defaults }: { u: U; libraries: Lib[]; defaults: { streams_per_user: number } }) {
    const { t, locale } = useT();
    const { auth } = usePage<SharedData>().props;
    const me = auth.user?.id === u.id;
    const [d, setD] = useState({ name: u.name, username: u.username ?? '', is_admin: u.is_admin, disabled: u.disabled, permissions: u.permissions, max_age_rating: u.max_age_rating,
        allowed_library_ids: u.allowed_library_ids, max_concurrent_streams: u.max_concurrent_streams ?? defaults.streams_per_user, downloads_quota_mb: u.downloads_quota_mb, downloads_max: u.downloads_max, password: '' });
    const [dirty, setDirty] = useState(false);
    const set = (v: Partial<typeof d>) => { setD({ ...d, ...v }); setDirty(true); };
    const save = () => router.patch(`/admin/users/${u.id}`, { ...d, password: d.password || null, ...(auth.user?.is_admin ? {} : { is_admin: undefined }) }, { preserveScroll: true, onSuccess: () => { setDirty(false); setD((x) => ({ ...x, password: '' })); } });
    return (
        <section className="mb-4 rounded-lg border bg-card p-4" data-testid={`user-${u.username}`}>
            <div className="mb-3 flex flex-wrap items-center gap-3">
                <Input dir="auto" className="w-44 font-semibold" value={d.name} onChange={(e) => set({ name: e.target.value })} />
                <Input dir="ltr" className="w-36" value={d.username} onChange={(e) => set({ username: e.target.value })} />
                <span dir="ltr" className="text-xs text-muted-foreground">{u.email}</span>
                {u.has_2fa && <span className="rounded bg-emerald-500/15 px-1.5 text-xs text-emerald-400">{t.accounts.twoFa}</span>}
                <span className="text-xs text-muted-foreground">{t.accounts.lastSeen}: {u.last_seen ? new Date(u.last_seen).toLocaleString(locale === 'ar' ? 'ar' : 'en') : '—'}</span>
                <div className="ms-auto flex items-center gap-3">
                    {auth.user?.is_admin && <label className="flex items-center gap-2 text-sm"><Switch checked={d.is_admin} disabled={me} onCheckedChange={(v) => set({ is_admin: v })} /> {t.accounts.admin}</label>}
                    <label className="flex items-center gap-2 text-sm"><Switch checked={!d.disabled} disabled={me} onCheckedChange={(v) => set({ disabled: !v })} /> {d.disabled ? t.accounts.disabled : t.accounts.active}</label>
                    {!me && <Button size="sm" variant="ghost" onClick={() => router.post(`/view-as/${u.id}`)}><Eye /> {t.accounts.viewAs}</Button>}
                </div>
            </div>
            <PermissionEditor value={d} isAdmin={d.is_admin} onChange={set} libraries={libraries} />
            <div className="mt-3 flex flex-wrap items-center gap-3 text-sm">
                <label className="flex items-center gap-1">{t.accounts.streams} <Input type="number" min={1} max={20} className="w-16" value={d.max_concurrent_streams} onChange={(e) => set({ max_concurrent_streams: Number(e.target.value) })} /></label>
                <label className="flex items-center gap-1">{t.devices.downloads} MB <Input type="number" min={100} className="w-24" value={d.downloads_quota_mb} onChange={(e) => set({ downloads_quota_mb: Number(e.target.value) })} /></label>
                <label className="flex items-center gap-1"># <Input type="number" min={1} className="w-16" value={d.downloads_max} onChange={(e) => set({ downloads_max: Number(e.target.value) })} /></label>
                <Input type="password" className="w-44" placeholder={t.accounts.newPassword} value={d.password} onChange={(e) => set({ password: e.target.value })} />
                <Button size="sm" className="ms-auto" disabled={!dirty} onClick={save}><Save /> {t.accounts.save}</Button>
            </div>
        </section>
    );
}
