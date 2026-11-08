import { usePage } from '@inertiajs/react';
import { Captions, Pause, Play, RotateCcw, RotateCw, SkipForward, Square, Tv } from 'lucide-react';
import { useEffect, useState } from 'react';
import { Button } from '@/components/ui/button';
import MovexaLayout from '@/layouts/movexa-layout';
import { api, formatTime, useT } from '@/lib/i18n';
import type { SharedData } from '@/types';

type State = { title?: string; subtitle?: string; position?: number; duration?: number; paused?: boolean; audio?: string[]; audio_index?: number; subtitles?: { id: number; label: string }[]; subtitle_id?: number | null; has_next?: boolean };
type Dev = { uuid: string; name: string; state: State };

/** Phone remote: commands go to the server (authenticated), which relays them over Reverb to the player. */
export default function Remote() {
    const { t } = useT();
    const { reverb } = usePage<SharedData>().props;
    const [devices, setDevices] = useState<Dev[]>([]);
    const [sel, setSel] = useState<string | null>(null);
    const [down, setDown] = useState(!reverb);
    useEffect(() => {
        const load = () => api<{ devices: Dev[] }>(`/remote/devices?self=${localStorage.getItem('mx-remote-uuid') ?? ''}`).then((r) => r.ok && setDevices(r.data.devices));
        load();
        const id = setInterval(load, 2000);
        return () => clearInterval(id);
    }, []);
    const dev = devices.find((d) => d.uuid === sel) ?? devices[0];
    const send = async (action: string, value?: unknown) => {
        if (!dev) return;
        const r = await api(`/remote/${dev.uuid}/command`, 'POST', { action, value, nonce: crypto.randomUUID?.() ?? String(Math.random()), sent_at: Date.now() });
        setDown(r.status === 503);
    };
    const s = dev?.state ?? {};
    return (
        <MovexaLayout title={t.devices.remote}>
            <div className="mx-auto max-w-md space-y-5 p-6">
                <h1 className="text-2xl font-bold">{t.devices.remote}</h1>
                {down && <p className="rounded-md bg-amber-500/10 p-3 text-sm text-amber-300">{t.devices.remoteUnavailable}</p>}
                {devices.length === 0 ? <p className="text-muted-foreground">{t.devices.noDevices}</p> : (
                    <>
                        <div className="flex flex-wrap gap-2">
                            {devices.map((d) => <Button key={d.uuid} variant={d.uuid === dev?.uuid ? 'default' : 'secondary'} size="sm" onClick={() => setSel(d.uuid)}><Tv /> {d.name}</Button>)}
                        </div>
                        <div className="rounded-xl border bg-card p-4 text-center">
                            <div dir="auto" className="text-lg font-bold">{s.title}</div>
                            {s.subtitle && <div dir="auto" className="text-sm text-muted-foreground">{s.subtitle}</div>}
                            <input dir="ltr" type="range" className="mt-4 w-full accent-[var(--primary)]" min={0} max={s.duration || 0} value={s.position || 0} disabled={down} onChange={(e) => send('seek', Number(e.target.value))} aria-label="seek" />
                            <div dir="ltr" className="text-xs text-muted-foreground">{formatTime(s.position ?? 0)} / {formatTime(s.duration ?? 0)}</div>
                            <div className="mt-4 flex items-center justify-center gap-3" dir="ltr">
                                <Button size="icon" variant="secondary" disabled={down} onClick={() => send('skip', -10)} aria-label="-10"><RotateCcw /></Button>
                                <Button size="icon" className="size-16 rounded-full" disabled={down} onClick={() => send(s.paused ? 'play' : 'pause')} aria-label="play">{s.paused ? <Play className="fill-current" /> : <Pause className="fill-current" />}</Button>
                                <Button size="icon" variant="secondary" disabled={down} onClick={() => send('skip', 10)} aria-label="+10"><RotateCw /></Button>
                                {s.has_next && <Button size="icon" variant="secondary" disabled={down} onClick={() => send('next')} aria-label="next"><SkipForward /></Button>}
                            </div>
                        </div>
                        {(s.audio?.length ?? 0) > 1 && (
                            <div className="space-y-2"><div className="text-sm">{t.audio}</div>
                                <div className="flex flex-wrap gap-2">{s.audio!.map((a, i) => <Button key={i} size="sm" variant={s.audio_index === i ? 'default' : 'secondary'} disabled={down} onClick={() => send('audio', i)}>{a}</Button>)}</div>
                            </div>
                        )}
                        {(s.subtitles?.length ?? 0) > 0 && (
                            <div className="space-y-2"><div className="flex items-center gap-1 text-sm"><Captions className="size-4" /> {t.subtitles}</div>
                                <div className="flex flex-wrap gap-2">
                                    <Button size="sm" variant={s.subtitle_id == null ? 'default' : 'secondary'} disabled={down} onClick={() => send('subtitle', null)}>{t.off}</Button>
                                    {s.subtitles!.map((x) => <Button key={x.id} size="sm" variant={s.subtitle_id === x.id ? 'default' : 'secondary'} disabled={down} onClick={() => send('subtitle', x.id)}>{x.label}</Button>)}
                                </div>
                            </div>
                        )}
                        <Button variant="destructive" className="w-full" disabled={down} onClick={() => send('stop')}><Square /> {t.devices.stop}</Button>
                    </>
                )}
            </div>
        </MovexaLayout>
    );
}
