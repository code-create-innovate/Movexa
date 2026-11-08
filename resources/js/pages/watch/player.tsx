import { Head, Link, router, usePage } from '@inertiajs/react';
import Hls from 'hls.js';
import {
    ArrowLeft, ArrowRight, Captions, Check, Maximize, MessageSquare, Minimize, Pause, PictureInPicture2, Play, RotateCcw, RotateCw, Settings, SkipForward, Upload, Users, Volume2, VolumeX,
} from 'lucide-react';
import { type FormEvent, useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { acceptRemoteCommand, detectCaps, isApple, loadThumbnails, type RemoteCmd, type ThumbCue } from '@/components/movexa/player-utils';
import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuLabel, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Sheet, SheetContent, SheetTitle } from '@/components/ui/sheet';
import { Slider } from '@/components/ui/slider';
import { getEcho } from '@/lib/echo';
import { api, csrf, formatTime, useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { Card, SharedData, SubPrefs } from '@/types';

type Sub = { id: number; label: string; language: string | null; url: string };
type Start = { keep?: boolean; session: string; method: 'direct' | 'remux' | 'transcode'; type: 'file' | 'hls'; src: string; ready: boolean; job?: number; progress?: number; position: number; subtitles: Sub[]; thumbnails: string | null };
type Props = {
    file: { id: number; duration: number; height: number | null; intro: { start: number; end: number } | null; audio: { language: string | null; title: string | null; codec: string }[]; qualities: number[] };
    item: Card; episode: { season: number; number: number; title: string | null } | null;
    next: { file_id: number; season: number; number: number; title: string | null } | null;
    prefs: SubPrefs; preferredSubtitle: string | null; party: string | null;
};
type PartyState = { uuid: string; playing: boolean; position: number; server_time: number; by?: string; action?: string };
type Msg = { id: number; name: string; body: string };

const SPEEDS = [0.5, 0.75, 1, 1.25, 1.5, 2];

export default function Player({ file, item, episode, next, prefs: initialPrefs, preferredSubtitle, party }: Props) {
    const { t, dir } = useT();
    const { reverb, profile } = usePage<SharedData>().props;
    const video = useRef<HTMLVideoElement>(null);
    const wrap = useRef<HTMLDivElement>(null);
    const hls = useRef<Hls | null>(null);
    const clientId = useMemo(() => Math.random().toString(36).slice(2), []);
    const applyingRemote = useRef(false);

    const [start, setStart] = useState<Start | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [preparing, setPreparing] = useState<number | null>(null);
    const [quality, setQuality] = useState<string>('auto');      // server-side choice: auto | 1080 | 720 | 480
    const [hlsLevels, setHlsLevels] = useState<{ height: number }[]>([]);
    const [hlsLevel, setHlsLevel] = useState(-1);
    const [audioIndex, setAudioIndex] = useState(0);
    const [subId, setSubId] = useState<number | null>(null);
    const [prefs, setPrefs] = useState<SubPrefs>(Object.assign({ size: 100, color: '#ffffff', background: 60, position: 8 }, initialPrefs));
    const [playing, setPlaying] = useState(false);
    const [time, setTime] = useState(0);
    const [buffered, setBuffered] = useState(0);
    const [duration, setDuration] = useState(file.duration || 0);
    const [volume, setVolume] = useState(1);
    const [muted, setMuted] = useState(false);
    const [speed, setSpeed] = useState(1);
    const [fs, setFs] = useState(false);
    const [controls, setControls] = useState(true);
    const [hover, setHover] = useState<{ x: number; time: number } | null>(null);
    const [thumbs, setThumbs] = useState<ThumbCue[]>([]);
    const [countdown, setCountdown] = useState<number | null>(null);
    const [flash, setFlash] = useState<string | null>(null);
    const [chatOpen, setChatOpen] = useState(false);
    const [messages, setMessages] = useState<Msg[]>([]);
    const [members, setMembers] = useState<{ id: number; name: string }[]>([]);
    const [draft, setDraft] = useState('');
    const [subStyleOpen, setSubStyleOpen] = useState(false);

    // ---- start / restart playback --------------------------------------------------
    const begin = useCallback(async (opts: { quality?: string; audio?: number; keepTime?: boolean } = {}) => {
        const at = opts.keepTime ? video.current?.currentTime ?? 0 : null;
        setError(null);
        const r = await api<Start & { message?: string }>(`/playback/${file.id}/start`, 'POST', {
            caps: detectCaps(), audio: opts.audio ?? audioIndex, quality: opts.quality ?? quality, session: start?.session,
        });
        if (!r.ok) return setError(r.status === 429 ? t.player.limit : t.player.error);
        const s = r.data;
        if (at !== null) { s.position = at; s.keep = true; }
        if (s.type === 'hls' && !s.ready && s.job) {
            setPreparing(s.progress ?? 0);
            for (;;) {
                await new Promise((res) => setTimeout(res, 2000));
                const st = await api<{ ready: boolean; progress: number; status: string }>(`/playback/job/${s.job}`);
                if (!st.ok || st.data.status === 'failed') return setError(t.player.error);
                setPreparing(st.data.progress);
                if (st.data.ready) break;
            }
        }
        setPreparing(null);
        setStart(s);
    }, [file.id, audioIndex, quality, start?.session, t]);

    useEffect(() => { begin(); }, []); // eslint-disable-line react-hooks/exhaustive-deps

    // ---- attach source --------------------------------------------------------------
    useEffect(() => {
        const v = video.current;
        if (!v || !start) return;
        hls.current?.destroy();
        hls.current = null;
        // Resume rule (skip tiny/near-end positions) applies to fresh starts; quality/audio switches keep the exact time.
        const seekTo = start.keep ? start.position : start.position > 5 && start.position < (file.duration || Infinity) - 10 ? start.position : 0;
        const onMeta = () => { if (seekTo) v.currentTime = seekTo; v.play().catch(() => {}); };
        v.addEventListener('loadedmetadata', onMeta, { once: true });
        if (start.type === 'hls' && Hls.isSupported() && !isApple()) {
            const h = new Hls({ xhrSetup: (x) => { x.withCredentials = true; }, startPosition: seekTo || -1, capLevelToPlayerSize: true });
            h.loadSource(start.src);
            h.attachMedia(v);
            h.on(Hls.Events.MANIFEST_PARSED, () => setHlsLevels(h.levels.map((l) => ({ height: l.height }))));
            h.on(Hls.Events.LEVEL_SWITCHED, () => setHlsLevel(h.autoLevelEnabled ? -1 : h.currentLevel));
            h.on(Hls.Events.ERROR, (_, d) => {
                if (!d.fatal) return;
                if (d.type === Hls.ErrorTypes.NETWORK_ERROR) h.startLoad();
                else if (d.type === Hls.ErrorTypes.MEDIA_ERROR) h.recoverMediaError();
                else setError(t.player.error);
            });
            hls.current = h;
        } else {
            v.src = start.src; // Direct Play, or native HLS (Safari / iOS)
        }
        if (start.thumbnails) loadThumbnails(start.thumbnails).then(setThumbs);
        const pref = preferredSubtitle && start.subtitles.find((s) => s.language?.startsWith(preferredSubtitle));
        if (pref) setSubId(pref.id);
        return () => { v.removeEventListener('loadedmetadata', onMeta); };
    }, [start]); // eslint-disable-line react-hooks/exhaustive-deps

    // Stop the playback session only when leaving the player (a quality/audio switch keeps the same session).
    const sessionRef = useRef<string | null>(null);
    sessionRef.current = start?.session ?? sessionRef.current;
    useEffect(() => () => {
        hls.current?.destroy();
        if (sessionRef.current) fetch(`/playback/session/${sessionRef.current}/stop`, { method: 'POST', keepalive: true, credentials: 'same-origin', headers: { 'X-XSRF-TOKEN': csrf(), Accept: 'application/json' } });
    }, []);

    // ---- subtitles: <track> elements, show the selected one ---------------------------
    useEffect(() => {
        const v = video.current;
        if (!v) return;
        Array.from(v.textTracks).forEach((tr) => { tr.mode = tr.id === `sub-${subId}` ? 'showing' : 'disabled'; });
    }, [subId, start]);

    // ---- progress heartbeat (every 5s) -------------------------------------------------
    useEffect(() => {
        if (!start) return;
        const id = setInterval(() => {
            const v = video.current;
            if (v && !v.paused && v.currentTime > 0) api(`/playback/session/${start.session}/progress`, 'POST', { position: v.currentTime, duration: v.duration || file.duration });
        }, 5000);
        return () => clearInterval(id);
    }, [start, file.duration]);

    // ---- watch party -------------------------------------------------------------------
    const sendParty = useCallback((action: 'play' | 'pause' | 'seek') => {
        if (!party || applyingRemote.current || !video.current) return;
        api(`/party/${party}/state`, 'POST', { action, position: video.current.currentTime, client: clientId });
    }, [party, clientId]);

    const applyParty = useCallback((s: PartyState) => {
        const v = video.current;
        if (!v || s.by === clientId) return;
        applyingRemote.current = true;
        const target = s.position + (s.playing ? Math.max(0, Date.now() - s.server_time) / 1000 : 0);
        if (Math.abs(v.currentTime - target) > 1.5) v.currentTime = target;
        if (s.playing && v.paused) v.play().catch(() => {});
        if (!s.playing && !v.paused) v.pause();
        setTimeout(() => (applyingRemote.current = false), 300);
    }, [clientId]);

    useEffect(() => {
        if (!party || !start) return;
        api<PartyState & { messages: Msg[] }>(`/party/${party}/state`).then((r) => { if (r.ok) { applyParty(r.data); setMessages(r.data.messages); } });
        const echo = getEcho(reverb);
        if (echo) {
            const ch = echo.join(`party.${party}`)
                .here((m: { id: number; name: string }[]) => setMembers(m))
                .joining((m: { id: number; name: string }) => setMembers((x) => [...x.filter((y) => y.id !== m.id), m]))
                .leaving((m: { id: number }) => setMembers((x) => x.filter((y) => y.id !== m.id)))
                .listen('.state', applyParty)
                .listen('.message', (m: Msg) => setMessages((x) => [...x, m]));
            return () => { ch && echo.leave(`party.${party}`); };
        }
        // Fallback without Reverb: poll the server state.
        const id = setInterval(() => api<PartyState & { messages: Msg[] }>(`/party/${party}/state`).then((r) => { if (r.ok) { applyParty(r.data); setMessages(r.data.messages); } }), 3000);
        return () => clearInterval(id);
    }, [party, start, reverb, applyParty]);

    // ---- remote control target: report state, apply server-relayed commands -------------------
    const remoteSeq = useRef(0);
    const [remoteId, setRemoteId] = useState<string | null>(null);
    const live = useRef({ audioIndex, subId, start });
    live.current = { audioIndex, subId, start };
    useEffect(() => {
        if (party) return; // watch party has its own sync
        (async () => {
            const r = await api<{ uuid: string; last_seq: number }>('/remote/register', 'POST', { uuid: localStorage.getItem('mx-remote-uuid') || undefined });
            if (!r.ok) return;
            localStorage.setItem('mx-remote-uuid', r.data.uuid);
            remoteSeq.current = r.data.last_seq;
            setRemoteId(r.data.uuid);
        })();
    }, [party]);
    useEffect(() => {
        if (!remoteId) return;
        const id = setInterval(() => {
            const v = video.current;
            if (!v) return;
            api(`/remote/${remoteId}/state`, 'POST', { state: {
                title: item.title, subtitle: episode ? `S${episode.season} · E${episode.number}` : null, file_id: file.id, position: Math.round(v.currentTime), duration: Math.round(v.duration || file.duration),
                paused: v.paused, audio: file.audio.map((a, i) => a.title || a.language || `#${i + 1}`), audio_index: live.current.audioIndex,
                subtitles: live.current.start?.subtitles.map((s) => ({ id: s.id, label: s.label })) ?? [], subtitle_id: live.current.subId, has_next: !!next,
            } });
        }, 2000);
        const echo = getEcho(reverb);
        if (echo) {
            echo.private(`remote.${remoteId}`).listen('.command', (c: RemoteCmd) => {
                if (!acceptRemoteCommand(remoteSeq.current, c)) return; // stale or duplicate
                remoteSeq.current = c.seq;
                const v = video.current;
                if (!v) return;
                switch (c.action) {
                    case 'play': v.play().catch(() => {}); break;
                    case 'pause': v.pause(); break;
                    case 'seek': v.currentTime = Number(c.value) || 0; break;
                    case 'skip': v.currentTime = Math.max(0, v.currentTime + (Number(c.value) || 0)); break;
                    case 'subtitle': setSubId(c.value == null ? null : Number(c.value)); break;
                    case 'audio': setAudioIndex(Number(c.value) || 0); if (hls.current && hls.current.audioTracks.length > 1) hls.current.audioTrack = Number(c.value) || 0; else begin({ audio: Number(c.value) || 0, keepTime: true }); break;
                    case 'next': if (next) router.visit(`/watch/${next.file_id}`); break;
                    case 'stop': v.pause(); router.visit(`/title/${item.id}`); break;
                }
            });
        }
        return () => { clearInterval(id); echo?.leave(`remote.${remoteId}`); };
    }, [remoteId]); // eslint-disable-line react-hooks/exhaustive-deps

    // ---- access revoked (custom block added while watching) → stop and leave --------------
    useEffect(() => {
        const echo = getEcho(reverb);
        if (!echo || !profile) return;
        const name = `profile.${profile.id}`;
        echo.private(name).listen('.access.revoked', async () => {
            const r = await fetch(`/playback/${file.id}/check`, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
            if (r.status === 404) {
                hls.current?.destroy();
                video.current?.pause();
                video.current?.removeAttribute('src');
                router.visit('/home');
            }
        });
        return () => echo.leave(name);
    }, [reverb, profile, file.id]);

    // ---- controls ----------------------------------------------------------------------
    const v = video.current;
    const toggle = () => (v?.paused ? v.play() : v?.pause());
    const seek = (to: number) => { if (v) { v.currentTime = Math.max(0, Math.min(to, duration || to)); sendParty('seek'); } };
    const skip = (d: number) => { seek((v?.currentTime ?? 0) + d); setFlash(d > 0 ? '+10' : '−10'); setTimeout(() => setFlash(null), 500); };
    const toggleFs = () => (document.fullscreenElement ? document.exitFullscreen() : wrap.current?.requestFullscreen?.() ?? (v as any)?.webkitEnterFullscreen?.());
    const pip = () => (document.pictureInPictureElement ? document.exitPictureInPicture() : v?.requestPictureInPicture?.());

    useEffect(() => {
        const onKey = (e: KeyboardEvent) => {
            if ((e.target as HTMLElement)?.closest('input,textarea,[role=menu]')) return;
            const k = e.key.toLowerCase();
            const map: Record<string, () => void> = {
                ' ': toggle, k: toggle, arrowright: () => skip(10), l: () => skip(10), arrowleft: () => skip(-10), j: () => skip(-10),
                f: toggleFs, m: () => v && (v.muted = !v.muted), arrowup: () => v && (v.volume = Math.min(1, v.volume + 0.1)), arrowdown: () => v && (v.volume = Math.max(0, v.volume - 0.1)),
                c: () => setSubId((id) => (id === null ? start?.subtitles[0]?.id ?? null : null)), escape: () => document.fullscreenElement && document.exitFullscreen(),
            };
            if (map[k]) { e.preventDefault(); map[k](); poke(); }
        };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    });

    const hideTimer = useRef<ReturnType<typeof setTimeout>>(undefined);
    const poke = () => {
        setControls(true);
        clearTimeout(hideTimer.current);
        hideTimer.current = setTimeout(() => !video.current?.paused && setControls(false), 3000);
    };
    useEffect(() => {
        const onFs = () => setFs(!!document.fullscreenElement);
        document.addEventListener('fullscreenchange', onFs);
        return () => document.removeEventListener('fullscreenchange', onFs);
    }, []);

    // Double tap on touch: left third = −10s, right third = +10s (physical sides, independent of RTL).
    const lastTap = useRef(0);
    const onTap = (e: React.PointerEvent) => {
        if (e.pointerType !== 'touch') return toggle();
        const now = Date.now();
        const rect = (e.currentTarget as HTMLElement).getBoundingClientRect();
        const x = (e.clientX - rect.left) / rect.width;
        if (now - lastTap.current < 300) { if (x < 0.35) skip(-10); else if (x > 0.65) skip(10); else toggleFs(); }
        else poke();
        lastTap.current = now;
    };

    // Next episode countdown in the last 20 seconds.
    useEffect(() => {
        if (!next || !duration || countdown !== null) return;
        if (duration - time < 20 && time > 0) setCountdown(10);
    }, [time, duration, next, countdown]);
    useEffect(() => {
        if (countdown === null || countdown < 0) return;
        if (countdown === 0) { router.visit(`/watch/${next!.file_id}`); return; }
        const id = setTimeout(() => setCountdown((c) => (c === null ? null : c - 1)), 1000);
        return () => clearTimeout(id);
    }, [countdown, next]);

    const savePrefs = (p: SubPrefs) => { setPrefs(p); api('/profile/preferences', 'PUT', { subtitle_prefs: p }); };
    const uploadSub = async (f: File) => {
        const fd = new FormData();
        fd.append('subtitle', f);
        const r = await fetch(`/watch/${file.id}/subtitles`, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-XSRF-TOKEN': csrf(), Accept: 'application/json' } });
        if (r.ok) begin({ keepTime: true });
    };
    const sendChat = async (e: FormEvent) => {
        e.preventDefault();
        if (!draft.trim() || !party) return;
        const r = await api<Msg>(`/party/${party}/messages`, 'POST', { body: draft });
        setDraft('');
        if (r.ok && !reverb) setMessages((m) => [...m, r.data]);
    };

    const hoverThumb = hover && thumbs.find((c) => hover.time >= c.start && hover.time < c.end);
    const inIntro = file.intro && time >= file.intro.start && time < file.intro.end;
    const Back = dir === 'rtl' ? ArrowRight : ArrowLeft;
    const pct = duration ? (time / duration) * 100 : 0;

    return (
        <div ref={wrap} className={cn('relative h-dvh w-full overflow-hidden bg-black text-white', !controls && 'cursor-none')} onPointerMove={poke}
            style={{ ['--sub-size' as string]: prefs.size, ['--sub-color' as string]: prefs.color, ['--sub-bg' as string]: prefs.background / 100 }}>
            <Head title={item.title} />
            <video
                ref={video} className="size-full" playsInline crossOrigin="use-credentials" preload="metadata"
                onPlay={() => { setPlaying(true); sendParty('play'); poke(); }} onPause={() => { setPlaying(false); sendParty('pause'); setControls(true); }}
                onTimeUpdate={(e) => { const el = e.currentTarget; setTime(el.currentTime); if (el.buffered.length) setBuffered(el.buffered.end(el.buffered.length - 1)); }}
                onDurationChange={(e) => isFinite(e.currentTarget.duration) && setDuration(e.currentTarget.duration)}
                onVolumeChange={(e) => { setVolume(e.currentTarget.volume); setMuted(e.currentTarget.muted); }}
                onEnded={() => start && api(`/playback/session/${start.session}/progress`, 'POST', { position: duration, duration })}
            >
                {start?.subtitles.map((s) => <track key={s.id} id={`sub-${s.id}`} kind="subtitles" src={s.url} srcLang={s.language ?? 'und'} label={s.label} />)}
            </video>
            <style>{`video::cue{line:${100 - prefs.position}%}`}</style>
            <div className="absolute inset-0" onPointerUp={onTap} />

            {(preparing !== null || (!start && !error)) && (
                <div className="absolute inset-0 flex flex-col items-center justify-center gap-3 bg-black/70">
                    <div className="size-12 animate-spin rounded-full border-4 border-white/20 border-t-primary" />
                    <p>{t.player.preparing} {preparing !== null && `${preparing}%`}</p>
                </div>
            )}
            {error && (
                <div className="absolute inset-0 flex flex-col items-center justify-center gap-4 bg-black/80">
                    <p className="text-lg">{error}</p>
                    <Button onClick={() => begin()}>{t.player.playNow}</Button>
                </div>
            )}
            {flash && <div className="pointer-events-none absolute inset-0 flex items-center justify-center text-5xl font-bold drop-shadow">{flash}</div>}

            {inIntro && (
                <Button className="absolute end-6 bottom-28 z-20" variant="secondary" size="lg" onClick={() => seek(file.intro!.end)}><SkipForward /> {t.player.skipIntro}</Button>
            )}
            {countdown !== null && countdown >= 0 && next && (
                <div className="absolute end-6 bottom-28 z-20 w-72 space-y-3 rounded-lg bg-black/85 p-4">
                    <div className="text-sm text-white/70">{t.player.nextIn} {countdown}</div>
                    <div dir="auto" className="font-semibold">S{next.season} · E{next.number} {next.title}</div>
                    <div className="flex gap-2">
                        <Button size="sm" onClick={() => router.visit(`/watch/${next.file_id}`)}>{t.player.playNow}</Button>
                        <Button size="sm" variant="ghost" onClick={() => setCountdown(-1)}>{t.player.cancel}</Button>
                    </div>
                </div>
            )}

            {/* Top bar */}
            <div className={cn('absolute inset-x-0 top-0 z-10 flex items-center gap-3 bg-linear-to-b from-black/80 to-transparent p-4 transition-opacity', controls ? 'opacity-100' : 'pointer-events-none opacity-0')}>
                <Button asChild variant="ghost" size="icon" className="text-white"><Link href={`/title/${item.id}`} aria-label={t.player.back}><Back /></Link></Button>
                <div className="min-w-0">
                    <div dir="auto" className="truncate text-lg font-semibold">{item.title}</div>
                    {episode && <div dir="auto" className="text-sm text-white/70">S{episode.season} · E{episode.number} {episode.title}</div>}
                </div>
                <div className="ms-auto flex items-center gap-2 text-xs text-white/60">
                    {start && <span className="rounded bg-white/10 px-2 py-1">{t.player.method[start.method]}</span>}
                    {party && <Button variant="ghost" size="sm" className="text-white" onClick={() => setChatOpen(true)}><Users /> {members.length || ''} <MessageSquare /></Button>}
                    {party && <Button variant="ghost" size="sm" className="text-white" onClick={() => navigator.clipboard?.writeText(location.href)}>{t.copyLink}</Button>}
                </div>
            </div>

            {/* Bottom controls */}
            <div className={cn('absolute inset-x-0 bottom-0 z-10 space-y-2 bg-linear-to-t from-black/90 to-transparent px-4 pt-10 pb-4 transition-opacity', controls ? 'opacity-100' : 'pointer-events-none opacity-0')}>
                {/* Video time always runs left→right, even in RTL */}
                <div dir="ltr" className="group relative h-5 cursor-pointer" role="slider" tabIndex={0} aria-valuemin={0} aria-valuemax={duration} aria-valuenow={time} aria-label="seek"
                    onPointerDown={(e) => { const r = e.currentTarget.getBoundingClientRect(); seek(((e.clientX - r.left) / r.width) * duration); }}
                    onPointerMove={(e) => { const r = e.currentTarget.getBoundingClientRect(); const x = Math.max(0, Math.min(e.clientX - r.left, r.width)); setHover({ x, time: (x / r.width) * duration }); }}
                    onPointerLeave={() => setHover(null)}>
                    <div className="absolute top-2 h-1 w-full rounded bg-white/25 group-hover:h-1.5">
                        <div className="absolute h-full rounded bg-white/40" style={{ width: `${duration ? (buffered / duration) * 100 : 0}%` }} />
                        <div className="absolute h-full rounded bg-primary" style={{ width: `${pct}%` }} />
                    </div>
                    <div className="absolute top-0.5 size-4 -translate-x-1/2 rounded-full bg-primary opacity-0 group-hover:opacity-100" style={{ left: `${pct}%` }} />
                    {hover && (
                        <div className="pointer-events-none absolute bottom-6 -translate-x-1/2 text-center" style={{ left: hover.x }}>
                            {hoverThumb && <div className="mb-1 rounded border border-white/30" style={{ width: hoverThumb.w, height: hoverThumb.h, background: `url(${hoverThumb.url}) -${hoverThumb.x}px -${hoverThumb.y}px` }} />}
                            <span className="rounded bg-black/80 px-1.5 py-0.5 text-xs">{formatTime(hover.time)}</span>
                        </div>
                    )}
                </div>
                <div className="flex items-center gap-1 md:gap-2">
                    <Button variant="ghost" size="icon" className="text-white" onClick={toggle} aria-label="play">{playing ? <Pause className="fill-current" /> : <Play className="fill-current" />}</Button>
                    <Button variant="ghost" size="icon" className="text-white" onClick={() => skip(-10)} aria-label="-10"><RotateCcw /></Button>
                    <Button variant="ghost" size="icon" className="text-white" onClick={() => skip(10)} aria-label="+10"><RotateCw /></Button>
                    <div className="hidden items-center gap-2 sm:flex">
                        <Button variant="ghost" size="icon" className="text-white" onClick={() => v && (v.muted = !v.muted)} aria-label="mute">{muted || volume === 0 ? <VolumeX /> : <Volume2 />}</Button>
                        <Slider dir="ltr" className="w-24" min={0} max={1} step={0.05} value={[muted ? 0 : volume]} onValueChange={([x]) => { if (v) { v.volume = x; v.muted = x === 0; } }} />
                    </div>
                    <span dir="ltr" className="text-sm tabular-nums text-white/80">{formatTime(time)} / {formatTime(duration)}</span>
                    <div className="ms-auto flex items-center gap-1">
                        {next && <Button variant="ghost" size="icon" className="text-white" onClick={() => router.visit(`/watch/${next.file_id}`)} aria-label="next"><SkipForward /></Button>}
                        <DropdownMenu>
                            <DropdownMenuTrigger asChild><Button variant="ghost" size="icon" className="text-white" aria-label={t.subtitles}><Captions /></Button></DropdownMenuTrigger>
                            <DropdownMenuContent align="end" className="w-60">
                                <DropdownMenuLabel>{t.subtitles}</DropdownMenuLabel>
                                <DropdownMenuItem onClick={() => setSubId(null)}>{subId === null && <Check />} {t.off}</DropdownMenuItem>
                                {start?.subtitles.map((s) => <DropdownMenuItem key={s.id} onClick={() => setSubId(s.id)}>{subId === s.id && <Check />} <span dir="auto">{s.label}</span></DropdownMenuItem>)}
                                <DropdownMenuSeparator />
                                <DropdownMenuItem onClick={() => setSubStyleOpen(true)}><Settings /> {t.player.subStyle}</DropdownMenuItem>
                                <DropdownMenuItem asChild>
                                    <label className="cursor-pointer"><Upload /> {t.player.uploadSub}<input type="file" accept=".srt,.vtt,.ass,.ssa" className="hidden" onChange={(e) => e.target.files?.[0] && uploadSub(e.target.files[0])} /></label>
                                </DropdownMenuItem>
                            </DropdownMenuContent>
                        </DropdownMenu>
                        <DropdownMenu>
                            <DropdownMenuTrigger asChild><Button variant="ghost" size="icon" className="text-white" aria-label={t.settings}><Settings /></Button></DropdownMenuTrigger>
                            <DropdownMenuContent align="end" className="max-h-[70vh] w-60 overflow-y-auto">
                                <DropdownMenuLabel>{t.player.quality}</DropdownMenuLabel>
                                {hlsLevels.length > 1 ? (
                                    <>
                                        <DropdownMenuItem onClick={() => { hls.current!.currentLevel = -1; setHlsLevel(-1); }}>{hlsLevel === -1 && <Check />} {t.player.auto}</DropdownMenuItem>
                                        {hlsLevels.map((l, i) => <DropdownMenuItem key={i} onClick={() => { hls.current!.currentLevel = i; setHlsLevel(i); }}>{hlsLevel === i && <Check />} {l.height}p</DropdownMenuItem>)}
                                    </>
                                ) : (
                                    <>
                                        <DropdownMenuItem onClick={() => { setQuality('auto'); begin({ quality: 'auto', keepTime: true }); }}>{quality === 'auto' && <Check />} {t.player.auto} / {t.player.original}</DropdownMenuItem>
                                        {file.qualities.map((q) => <DropdownMenuItem key={q} onClick={() => { setQuality(String(q)); begin({ quality: String(q), keepTime: true }); }}>{quality === String(q) && <Check />} {q}p</DropdownMenuItem>)}
                                    </>
                                )}
                                {file.audio.length > 1 && (
                                    <>
                                        <DropdownMenuSeparator />
                                        <DropdownMenuLabel>{t.audio}</DropdownMenuLabel>
                                        {file.audio.map((a, i) => (
                                            <DropdownMenuItem key={i} onClick={() => {
                                                setAudioIndex(i);
                                                if (hls.current && hls.current.audioTracks.length > 1) hls.current.audioTrack = i;
                                                else begin({ audio: i, keepTime: true });
                                            }}>{audioIndex === i && <Check />} <span dir="auto">{a.title || a.language || `#${i + 1}`}</span> <span className="ms-auto text-xs opacity-60">{a.codec}</span></DropdownMenuItem>
                                        ))}
                                    </>
                                )}
                                <DropdownMenuSeparator />
                                <DropdownMenuLabel>{t.player.speed}</DropdownMenuLabel>
                                {SPEEDS.map((s) => <DropdownMenuItem key={s} onClick={() => { if (v) v.playbackRate = s; setSpeed(s); }}>{speed === s && <Check />} {s}×</DropdownMenuItem>)}
                            </DropdownMenuContent>
                        </DropdownMenu>
                        {'pictureInPictureEnabled' in document && <Button variant="ghost" size="icon" className="hidden text-white sm:inline-flex" onClick={pip} aria-label={t.player.pip}><PictureInPicture2 /></Button>}
                        <Button variant="ghost" size="icon" className="text-white" onClick={toggleFs} aria-label={t.player.fullscreen}>{fs ? <Minimize /> : <Maximize />}</Button>
                    </div>
                </div>
            </div>

            <Sheet open={subStyleOpen} onOpenChange={setSubStyleOpen}>
                <SheetContent side="bottom" className="space-y-5 p-6">
                    <SheetTitle>{t.player.subStyle}</SheetTitle>
                    <label className="block space-y-2 text-sm">{t.player.size} ({prefs.size}%)<Slider dir="ltr" min={50} max={250} step={10} value={[prefs.size]} onValueChange={([x]) => setPrefs({ ...prefs, size: x })} onValueCommit={([x]) => savePrefs({ ...prefs, size: x })} /></label>
                    <label className="block space-y-2 text-sm">{t.player.background} ({prefs.background}%)<Slider dir="ltr" min={0} max={100} step={5} value={[prefs.background]} onValueChange={([x]) => setPrefs({ ...prefs, background: x })} onValueCommit={([x]) => savePrefs({ ...prefs, background: x })} /></label>
                    <label className="block space-y-2 text-sm">{t.player.position} ({prefs.position}%)<Slider dir="ltr" min={0} max={40} step={2} value={[prefs.position]} onValueChange={([x]) => setPrefs({ ...prefs, position: x })} onValueCommit={([x]) => savePrefs({ ...prefs, position: x })} /></label>
                    <div className="flex items-center gap-3 text-sm">{t.player.color}
                        {['#ffffff', '#ffff00', '#00ffff', '#00ff00'].map((c) => <button key={c} onClick={() => savePrefs({ ...prefs, color: c })} className="size-8 rounded-full ring-offset-2 ring-offset-background data-[on=true]:ring-2" data-on={prefs.color === c} style={{ background: c }} aria-label={c} />)}
                    </div>
                </SheetContent>
            </Sheet>

            <Sheet open={chatOpen} onOpenChange={setChatOpen}>
                <SheetContent side={dir === 'rtl' ? 'left' : 'right'} className="flex w-80 flex-col p-4">
                    <SheetTitle>{t.watchParty}</SheetTitle>
                    {members.length > 0 && <div className="text-xs text-muted-foreground">{t.members}: {members.map((m) => m.name).join('، ')}</div>}
                    <div className="flex-1 space-y-2 overflow-y-auto">
                        {messages.map((m) => <div key={m.id} className="text-sm"><b dir="auto">{m.name}</b>: <span dir="auto">{m.body}</span></div>)}
                    </div>
                    <form onSubmit={sendChat} className="flex gap-2"><Input dir="auto" value={draft} onChange={(e) => setDraft(e.target.value)} maxLength={500} /><Button type="submit">{t.send}</Button></form>
                </SheetContent>
            </Sheet>
        </div>
    );
}
