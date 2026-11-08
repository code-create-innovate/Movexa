import { router, useForm } from '@inertiajs/react';
import { RefreshCw, Trash2 } from 'lucide-react';
import { useEffect, useState } from 'react';
import { Uploader } from '@/components/movexa/uploader';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Progress } from '@/components/ui/progress';
import AdminLayout, { bytes, Section } from '@/layouts/admin-layout';
import { getEcho } from '@/lib/echo';
import { useT } from '@/lib/i18n';
import type { SharedData } from '@/types';
import { usePage } from '@inertiajs/react';

type Props = {
    stats: { items: number; files: number; library_bytes: number; cache_bytes: number; disk: { free: number; total: number } | null; cpu_load: number[]; cpus: number; encoder: string; encoders: string[]; scanner: boolean; quarantined: number };
    sessions: { user: string; method: string; client: string; title: string }[];
    transcodes: { id: number; title: string; mode: string; profile: string; status: string; progress: number; encoder: string | null; size: number; pinned: boolean; error: string | null }[];
    top: { title: string; plays: number }[];
    libraries: { id: number; name: string; type: string; path: string; source: string; items_count: number; last_scanned_at: string | null }[];
    users: { id: number; name: string; email: string; is_admin: boolean; max_concurrent_streams: number | null; profiles_count: number; downloads_enabled: boolean; downloads_quota_mb: number; downloads_max: number }[];
    limits: { streams_per_user: number; max_transcodes: number };
};

export default function Dashboard(p: Props) {
    const { t } = useT();
    const { reverb } = usePage<SharedData>().props;
    const [progress, setProgress] = useState<Record<number, number>>({});
    const user = useForm({ name: '', email: '', password: '', is_admin: false as boolean });
    const lib = useForm({ name: '', type: 'movies', path: '/www/data/movexa/library/', source: 'local', watch: true });

    useEffect(() => {
        const echo = getEcho(reverb);
        if (echo) {
            echo.private('admin.transcodes').listen('.transcode.progress', (e: { jobId: number; progress: number }) => setProgress((x) => ({ ...x, [e.jobId]: e.progress })));
            return () => echo.leave('admin.transcodes');
        }
        const id = setInterval(() => router.reload({ only: ['transcodes', 'sessions', 'stats'] }), 5000);
        return () => clearInterval(id);
    }, [reverb]);

    const tiles = [
        [t.adminUi.libraries, `${p.stats.items} · ${p.stats.files}`],
        [t.adminUi.storage, `${bytes(p.stats.library_bytes)} + cache ${bytes(p.stats.cache_bytes)}${p.stats.disk ? ` · free ${bytes(p.stats.disk.free)}` : ''}`],
        [t.adminUi.cpu, `${p.stats.cpu_load.map((x) => x.toFixed(2)).join(' / ')} (${p.stats.cpus} CPU)`],
        [t.adminUi.encoder, `${p.stats.encoder} [${p.stats.encoders.join(', ')}]`],
        [t.adminUi.scanner, p.stats.scanner ? `ClamAV ${t.adminUi.available}` : `ClamAV ${t.adminUi.unavailable}`],
        [t.adminUi.quarantine, String(p.stats.quarantined)],
    ];

    return (
        <AdminLayout title={t.admin}>
            <div className="mb-8 grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6">
                {tiles.map(([k, v]) => <div key={k} className="rounded-lg border bg-card p-3"><div className="text-xs text-muted-foreground">{k}</div><div className="mt-1 text-sm font-semibold"><span dir="ltr" className="inline-block">{v}</span></div></div>)}
            </div>

            <Section title={t.adminUi.sessions}>
                {p.sessions.length === 0 ? <p className="text-sm text-muted-foreground">—</p> : (
                    <table className="w-full text-sm"><tbody>{p.sessions.map((s, i) => <tr key={i} className="border-t"><td className="py-2">{s.user}</td><td dir="auto">{s.title}</td><td>{t.player.method[s.method as 'direct'] ?? s.method}</td><td className="text-muted-foreground">{s.client}</td></tr>)}</tbody></table>
                )}
            </Section>

            <Section title={t.adminUi.transcodes}>
                <p className="mb-2 text-xs text-muted-foreground">max {p.limits.max_transcodes}</p>
                <div className="space-y-2">
                    {p.transcodes.map((j) => (
                        <div key={j.id} className="grid grid-cols-[1fr_auto] items-center gap-2 text-sm">
                            <div>
                                <div dir="auto">{j.title} <span className="text-xs text-muted-foreground">{j.mode} · {j.profile} · {j.encoder} · {bytes(j.size)} {j.pinned && `· ${t.adminUi.pinned}`}</span></div>
                                <Progress value={progress[j.id] ?? j.progress} />
                                {j.error && <div className="text-xs text-destructive" dir="ltr">{j.error}</div>}
                            </div>
                            <div className="flex items-center gap-2"><span className="text-xs">{j.status}</span><Button size="icon" variant="ghost" onClick={() => router.delete(`/admin/transcodes/${j.id}`, { preserveScroll: true })}><Trash2 /></Button></div>
                        </div>
                    ))}
                </div>
            </Section>

            <Section title={t.adminUi.libraries}>
                <ul className="mb-4 space-y-2 text-sm">
                    {p.libraries.map((l) => (
                        <li key={l.id} className="flex items-center gap-2 border-t pt-2">
                            <div className="min-w-0 flex-1"><b dir="auto">{l.name}</b> <span className="text-muted-foreground">({l.type} · {l.source} · {l.items_count})</span><div dir="ltr" className="truncate text-xs text-muted-foreground">{l.path}</div></div>
                            <Button size="sm" variant="secondary" onClick={() => router.post(`/admin/libraries/${l.id}/scan`, {}, { preserveScroll: true })}><RefreshCw /> {t.adminUi.scan}</Button>
                            <Button size="icon" variant="ghost" onClick={() => confirm('?') && router.delete(`/admin/libraries/${l.id}`)}><Trash2 /></Button>
                        </li>
                    ))}
                </ul>
                <form onSubmit={(e) => { e.preventDefault(); lib.post('/admin/libraries', { preserveScroll: true, onSuccess: () => lib.reset() }); }} className="flex flex-wrap gap-2">
                    <Input className="w-40" placeholder={t.name} value={lib.data.name} onChange={(e) => lib.setData('name', e.target.value)} required />
                    <select className="h-9 rounded-md border bg-background px-2 text-sm" value={lib.data.type} onChange={(e) => lib.setData('type', e.target.value)}><option value="movies">{t.movies}</option><option value="shows">{t.shows}</option></select>
                    <select className="h-9 rounded-md border bg-background px-2 text-sm" value={lib.data.source} onChange={(e) => lib.setData('source', e.target.value)}><option value="local">local</option><option value="cloudsync">CloudSync</option></select>
                    <Input dir="ltr" className="min-w-64 flex-1" placeholder={t.adminUi.path} value={lib.data.path} onChange={(e) => lib.setData('path', e.target.value)} required />
                    <Button type="submit" disabled={lib.processing}>{t.adminUi.addLibrary}</Button>
                </form>
                {Object.values(lib.errors).map((e) => <p key={e} className="text-sm text-destructive">{e}</p>)}
            </Section>

            {p.libraries.length > 0 && <Section title={t.adminUi.upload}><Uploader libraries={p.libraries} /></Section>}

            <Section title={t.adminUi.users}>
                <table className="w-full text-sm"><tbody>
                    {p.users.map((u) => (
                        <tr key={u.id} className="border-t">
                            <td className="py-2" dir="auto">{u.name}</td><td dir="ltr" className="text-muted-foreground">{u.email}</td><td>{u.profiles_count}</td>
                            <td><label className="flex items-center gap-1"><input type="checkbox" checked={u.is_admin} onChange={(e) => router.patch(`/admin/users/${u.id}`, { is_admin: e.target.checked }, { preserveScroll: true })} /> {t.adminUi.admin}</label></td>
                            <td><label className="flex items-center gap-1">{t.adminUi.maxStreams}
                                <input type="number" min={1} max={20} className="w-14 rounded border bg-background px-1" defaultValue={u.max_concurrent_streams ?? p.limits.streams_per_user}
                                    onBlur={(e) => router.patch(`/admin/users/${u.id}`, { max_concurrent_streams: Number(e.target.value) }, { preserveScroll: true })} /></label></td>
                            <td><label className="flex items-center gap-1"><input type="checkbox" checked={u.downloads_enabled} onChange={(e) => router.patch(`/admin/users/${u.id}`, { downloads_enabled: e.target.checked }, { preserveScroll: true })} /> {t.devices.downloads}</label></td>
                            <td><label className="flex items-center gap-1">MB <input type="number" min={100} className="w-20 rounded border bg-background px-1" defaultValue={u.downloads_quota_mb} onBlur={(e) => router.patch(`/admin/users/${u.id}`, { downloads_quota_mb: Number(e.target.value) }, { preserveScroll: true })} /></label></td>
                            <td><label className="flex items-center gap-1"># <input type="number" min={1} className="w-14 rounded border bg-background px-1" defaultValue={u.downloads_max} onBlur={(e) => router.patch(`/admin/users/${u.id}`, { downloads_max: Number(e.target.value) }, { preserveScroll: true })} /></label></td>
                        </tr>
                    ))}
                </tbody></table>
                <form className="mt-3 flex flex-wrap gap-2" onSubmit={(e) => { e.preventDefault(); user.post('/admin/users', { preserveScroll: true, onSuccess: () => user.reset() }); }}>
                    <Input className="w-40" dir="auto" placeholder={t.name} value={user.data.name} onChange={(e) => user.setData('name', e.target.value)} required />
                    <Input className="w-56" dir="ltr" type="email" placeholder="email" value={user.data.email} onChange={(e) => user.setData('email', e.target.value)} required />
                    <Input className="w-44" type="password" placeholder="password (10+)" value={user.data.password} onChange={(e) => user.setData('password', e.target.value)} required />
                    <label className="flex items-center gap-1 text-sm"><input type="checkbox" checked={user.data.is_admin} onChange={(e) => user.setData('is_admin', e.target.checked)} /> {t.adminUi.admin}</label>
                    <Button type="submit">+</Button>
                </form>
                {Object.values(user.errors).map((e) => <p key={e} className="text-sm text-destructive">{e}</p>)}
            </Section>

            <Section title={t.adminUi.topWatched}>
                <ol className="list-inside list-decimal text-sm">{p.top.map((x, i) => <li key={i} dir="auto">{x.title} — {x.plays}</li>)}</ol>
            </Section>
        </AdminLayout>
    );
}
