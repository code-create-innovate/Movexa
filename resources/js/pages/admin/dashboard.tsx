import { router } from '@inertiajs/react';
import { Trash2 } from 'lucide-react';
import { useEffect, useState } from 'react';
import { Button } from '@/components/ui/button';
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
    limits: { streams_per_user: number; max_transcodes: number };
};

export default function Dashboard(p: Props) {
    const { t } = useT();
    const { reverb } = usePage<SharedData>().props;
    const [progress, setProgress] = useState<Record<number, number>>({});

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


            <Section title={t.adminUi.topWatched}>
                <ol className="list-inside list-decimal text-sm">{p.top.map((x, i) => <li key={i} dir="auto">{x.title} — {x.plays}</li>)}</ol>
            </Section>
        </AdminLayout>
    );
}
