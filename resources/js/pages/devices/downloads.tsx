import { router } from '@inertiajs/react';
import { HardDriveDownload, Play, Trash2, WifiOff } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Progress } from '@/components/ui/progress';
import MovexaLayout from '@/layouts/movexa-layout';
import { bytes } from '@/layouts/admin-layout';
import { api, useT } from '@/lib/i18n';
import { getLocal, listLocal, putLocal, removeLocal, saveVideo, storageEstimate, type LocalDownload } from '@/lib/offline-store';

type Srv = { uuid: string; status: string; progress: number; size: number; quality: number; title: string; episode: { season: number; number: number } | null; duration: number; expires_at: string };
type Manifest = Srv & { video: string; poster: string | null; subtitles: { label: string; language: string | null; url: string }[] };

export default function Downloads({ enabled, quota, downloads }: { enabled: boolean; quota: { max: number; mb: number; count: number; bytes: number }; downloads: Srv[] }) {
    const { t, locale } = useT();
    const [local, setLocal] = useState<Record<string, LocalDownload>>({});
    const [saving, setSaving] = useState<Record<string, number>>({});
    const [est, setEst] = useState<{ usage: number; quota: number } | null>(null);

    const refreshLocal = useCallback(async () => setLocal(Object.fromEntries((await listLocal()).map((d) => [d.uuid, d]))), []);

    // On every visit while online: sync positions, renew, delete revoked/expired/blocked copies.
    useEffect(() => {
        (async () => {
            const items = await listLocal();
            if (items.length) {
                const r = await api<{ items: Record<string, { action: string; expires_at?: string }> }>('/downloads/sync', 'POST', { items: items.map((d) => ({ uuid: d.uuid, position: d.position })) });
                if (r.ok) {
                    for (const d of items) {
                        const a = r.data.items[d.uuid];
                        if (!a || a.action === 'delete') await removeLocal(d.uuid);
                        else if (a.expires_at) await putLocal({ ...d, expires_at: a.expires_at });
                    }
                }
            }
            await refreshLocal();
            setEst(await storageEstimate());
        })();
    }, [refreshLocal]);

    // Poll server-side preparation progress.
    useEffect(() => {
        if (!downloads.some((d) => d.status === 'queued' || d.status === 'processing')) return;
        const id = setInterval(() => router.reload({ only: ['downloads', 'quota'] }), 3000);
        return () => clearInterval(id);
    }, [downloads]);

    const save = async (uuid: string) => {
        setSaving((s) => ({ ...s, [uuid]: 0 }));
        const m = await api<Manifest>(`/downloads/${uuid}/manifest`);
        if (!m.ok) return;
        const { storage, size } = await saveVideo(uuid, m.data.video, (p) => setSaving((s) => ({ ...s, [uuid]: p })));
        const subtitles = await Promise.all(m.data.subtitles.map(async (s) => ({ label: s.label, language: s.language, text: await (await fetch(s.url, { credentials: 'same-origin' })).text() })));
        const poster = m.data.poster ? await (await fetch(m.data.poster, { credentials: 'same-origin' })).blob() : null;
        const prev = await getLocal(uuid);
        await putLocal({ uuid, title: m.data.title, episode: m.data.episode, duration: m.data.duration, expires_at: m.data.expires_at, position: prev?.position ?? 0, storage, size, poster, subtitles });
        setSaving(({ [uuid]: _, ...rest }) => rest);
        refreshLocal();
    };
    const remove = async (uuid: string) => {
        await removeLocal(uuid);
        await api(`/downloads/${uuid}`, 'DELETE');
        router.reload();
    };

    return (
        <MovexaLayout title={t.devices.downloads}>
            <div className="mx-auto max-w-3xl space-y-5 p-6">
                <div className="flex flex-wrap items-center gap-3">
                    <h1 className="text-2xl font-bold">{t.devices.downloads}</h1>
                    <Button asChild variant="secondary" className="ms-auto"><a href="/offline.html"><WifiOff /> {t.devices.offline}</a></Button>
                </div>
                {!enabled && <p className="rounded-md bg-muted p-3 text-sm">{t.devices.disabled}</p>}
                <div className="grid grid-cols-2 gap-3 text-sm">
                    <div className="rounded-lg border bg-card p-3">{t.devices.downloads}: {quota.count} / {quota.max} · {bytes(quota.bytes)} / {bytes(quota.mb * 1024 * 1024)}</div>
                    {est && <div className="rounded-lg border bg-card p-3">{t.devices.space}: {bytes(est.usage)} {t.devices.used} · {bytes(est.quota - est.usage)} {t.devices.remaining}</div>}
                </div>
                <p className="text-xs text-muted-foreground">{t.devices.offlineNote}</p>
                {downloads.map((d) => {
                    const l = local[d.uuid];
                    return (
                        <div key={d.uuid} className="space-y-2 rounded-lg border bg-card p-3" data-testid={`dl-${d.uuid}`}>
                            <div className="flex items-center gap-2">
                                <div className="flex-1">
                                    <div dir="auto" className="font-semibold">{d.title} {d.episode && `· S${d.episode.season}E${d.episode.number}`}</div>
                                    <div className="text-xs text-muted-foreground">{d.quality}p · {bytes(d.size)} · {t.devices.expires}: {new Date(l?.expires_at ?? d.expires_at).toLocaleDateString(locale === 'ar' ? 'ar' : 'en')}</div>
                                </div>
                                {d.status === 'ready' && !l && saving[d.uuid] === undefined && <Button size="sm" onClick={() => save(d.uuid)}><HardDriveDownload /> {t.devices.save}</Button>}
                                {l && <Button asChild size="sm" variant="secondary"><a href={`/offline.html#${d.uuid}`}><Play /> {t.devices.playOffline}</a></Button>}
                                <Button size="icon" variant="ghost" onClick={() => remove(d.uuid)} aria-label={t.delete}><Trash2 /></Button>
                            </div>
                            {(d.status === 'queued' || d.status === 'processing') && <div className="text-xs">{t.devices.preparing} {d.progress}%<Progress value={d.progress} /></div>}
                            {saving[d.uuid] !== undefined && <div className="text-xs">{t.devices.saving} {Math.round(saving[d.uuid])}%<Progress value={saving[d.uuid]} /></div>}
                            {l && <div className="text-xs text-emerald-400">{t.devices.saved}</div>}
                            {d.status === 'failed' && <div className="text-xs text-destructive">✗</div>}
                        </div>
                    );
                })}
            </div>
        </MovexaLayout>
    );
}
