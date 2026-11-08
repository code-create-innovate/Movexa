import { router, usePage } from '@inertiajs/react';
import type { SharedData } from '@/types';
import Hls from 'hls.js';
import { Radio, Star, WifiOff } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { BlockDialog } from '@/components/movexa/block-dialog';
import { isApple } from '@/components/movexa/player-utils';
import { Button } from '@/components/ui/button';
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import MovexaLayout from '@/layouts/movexa-layout';
import { api, csrf, useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';

type Program = { id: number; title: string; description: string | null; starts_at: string; ends_at: string };
type Channel = { id: number; name: string; number: string | null; category: string | null; favorite: boolean; now: Program | null; next: Program | null; schedule: Program[] };
type Status = { status: string; viewers: number; restarts: number; latency: number | null; quality: string | null };

export default function Live({ channels }: { channels: Channel[] }) {
    const { t, locale } = useT();
    const { profile, viewAs } = usePage<SharedData>().props;
    const [filter, setFilter] = useState('all');
    const [active, setActive] = useState<Channel | null>(null);
    const [stream, setStream] = useState<{ id: number; src: string } | null>(null);
    const [status, setStatus] = useState<Status | null>(null);
    const [err, setErr] = useState<string | null>(null);
    const video = useRef<HTMLVideoElement>(null);
    const hls = useRef<Hls | null>(null);
    const fmt = (s: string) => new Date(s).toLocaleTimeString(locale === 'ar' ? 'ar' : 'en', { hour: '2-digit', minute: '2-digit' });

    const cats = useMemo(() => [...new Set(channels.map((c) => c.category).filter(Boolean))] as string[], [channels]);
    const list = channels.filter((c) => filter === 'all' || (filter === 'fav' ? c.favorite : c.category === filter));

    const leave = async () => {
        hls.current?.destroy();
        if (stream) await api(`/live/stream/${stream.id}/leave`, 'POST');
        setStream(null);
    };
    const tune = async (c: Channel) => {
        await leave();
        setActive(c);
        setErr(null);
        const r = await api<{ stream: number; src: string; status: Status; message?: string }>(`/live/${c.id}/watch`, 'POST');
        if (!r.ok) return setErr(r.data?.message === 'tuner_limit' ? t.liveTv.tunerLimit : r.status === 429 ? t.player.limit : t.player.error);
        setStream({ id: r.data.stream, src: r.data.src });
        setStatus(r.data.status);
    };

    useEffect(() => {
        const v = video.current;
        if (!v || !stream) return;
        let tries = 0;
        const attach = () => {
            if (Hls.isSupported() && !isApple()) {
                const h = new Hls({ liveSyncDurationCount: 3, xhrSetup: (x) => { x.withCredentials = true; }, manifestLoadingMaxRetry: 20, manifestLoadingRetryDelay: 1000 });
                h.loadSource(stream.src);
                h.attachMedia(v);
                h.on(Hls.Events.MANIFEST_PARSED, () => v.play().catch(() => {}));
                h.on(Hls.Events.ERROR, (_, d) => { if (d.fatal) { h.destroy(); if (tries++ < 10) setTimeout(attach, 2000); } });
                hls.current = h;
            } else {
                v.src = stream.src;
                v.play().catch(() => {});
            }
        };
        attach();
        // Heartbeat keeps the shared ffmpeg process alive; status shows latency/reconnect.
        const id = setInterval(async () => { const r = await api<Status>(`/live/stream/${stream.id}/status`); if (r.ok) setStatus(r.data); }, 10000);
        return () => { clearInterval(id); hls.current?.destroy(); };
    }, [stream]);

    useEffect(() => {
        const onUnload = () => stream && fetch(`/live/stream/${stream.id}/leave`, { method: 'POST', keepalive: true, credentials: 'same-origin', headers: { 'X-XSRF-TOKEN': csrf(), Accept: 'application/json' } });
        window.addEventListener('pagehide', onUnload);
        return () => { window.removeEventListener('pagehide', onUnload); onUnload(); };
    }, [stream]);

    return (
        <MovexaLayout title={t.live}>
            <div className="grid gap-4 p-4 md:grid-cols-[1fr_380px] md:p-6">
                <div className="space-y-3">
                    <div className="relative aspect-video overflow-hidden rounded-lg bg-black">
                        <video ref={video} className="size-full" controls playsInline crossOrigin="use-credentials" />
                        {!stream && !err && <div className="absolute inset-0 flex items-center justify-center text-muted-foreground"><Radio className="size-12" /></div>}
                        {err && <div className="absolute inset-0 flex items-center justify-center bg-black/80 p-6 text-center">{err}</div>}
                        {status?.status === 'reconnecting' && (
                            <div className="absolute start-3 top-3 flex items-center gap-2 rounded bg-amber-500/90 px-2 py-1 text-sm text-black"><WifiOff className="size-4" /> {t.liveTv.reconnecting}</div>
                        )}
                    </div>
                    {active && (
                        <div className="space-y-1">
                            <div className="flex flex-wrap items-center gap-3">
                                <h1 dir="auto" className="text-xl font-bold">{active.name}</h1>
                                <span className="flex items-center gap-1 rounded bg-primary px-2 text-xs font-bold text-primary-foreground">● LIVE</span>
                                {!profile?.is_kid && !viewAs && (
                                    <BlockDialog trigger={<Button size="sm" variant="ghost">{t.blocks.blockFrom}</Button>} targets={[
                                        { type: 'channel', id: active.id, label: `${t.blocks.thisChannel}: ${active.name}` },
                                        ...(active.category ? [{ type: 'channel_category', value: active.category, label: `${t.blocks.thisCategory}: ${active.category}` }] : []),
                                    ]} />
                                )}
                                {status && (
                                    <span className="text-xs text-muted-foreground">
                                        {status.latency !== null && `${t.liveTv.latency} ≈ ${status.latency}s · `}{status.viewers} {t.liveTv.viewers}{status.quality && ` · ${status.quality}`}
                                    </span>
                                )}
                            </div>
                            {active.now && <p dir="auto" className="text-sm"><b>{t.liveTv.now}:</b> {active.now.title} ({fmt(active.now.starts_at)}–{fmt(active.now.ends_at)})</p>}
                            {active.next && <p dir="auto" className="text-sm text-muted-foreground"><b>{t.liveTv.next}:</b> {active.next.title} ({fmt(active.next.starts_at)})</p>}
                            {active.schedule.length > 2 && (
                                <details className="text-sm"><summary className="cursor-pointer text-muted-foreground">{t.liveTv.schedule}</summary>
                                    <ul className="mt-2 space-y-1">{active.schedule.map((p) => <li key={p.id} dir="auto"><span className="tabular-nums text-muted-foreground">{fmt(p.starts_at)}</span> {p.title}</li>)}</ul>
                                </details>
                            )}
                        </div>
                    )}
                </div>
                <aside className="space-y-3">
                    <Tabs value={filter} onValueChange={setFilter}>
                        <TabsList className="flex max-w-full justify-start overflow-x-auto scrollbar-none">
                            <TabsTrigger value="all">{t.liveTv.all}</TabsTrigger>
                            <TabsTrigger value="fav">{t.liveTv.favorites}</TabsTrigger>
                            {cats.map((c) => <TabsTrigger key={c} value={c}>{(t.liveTv.categories as Record<string, string>)[c] ?? c}</TabsTrigger>)}
                        </TabsList>
                    </Tabs>
                    {channels.length === 0 && <p className="text-sm text-muted-foreground">{t.liveTv.noChannels}</p>}
                    <ul className="max-h-[70vh] space-y-1 overflow-y-auto">
                        {list.map((c) => (
                            <li key={c.id} className={cn('flex items-center gap-2 rounded-lg p-2 hover:bg-accent', active?.id === c.id && 'bg-accent')}>
                                <button className="min-w-0 flex-1 text-start" onClick={() => tune(c)}>
                                    <div className="flex items-center gap-2"><span className="w-8 text-xs text-muted-foreground tabular-nums">{c.number}</span><span dir="auto" className="truncate font-medium">{c.name}</span></div>
                                    {c.now && <div dir="auto" className="truncate ps-10 text-xs text-muted-foreground">{c.now.title}</div>}
                                </button>
                                <Button variant="ghost" size="icon" onClick={() => router.post(`/live/${c.id}/favorite`, {}, { preserveScroll: true, preserveState: true })} aria-label={t.liveTv.favorites}>
                                    <Star className={cn(c.favorite && 'fill-yellow-400 text-yellow-400')} />
                                </Button>
                            </li>
                        ))}
                    </ul>
                </aside>
            </div>
        </MovexaLayout>
    );
}
