import { Link, router, usePage } from '@inertiajs/react';
import { Check, ChevronLeft, ChevronRight, Download, Flame, Info, MoreHorizontal, Play, Plus } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { heavyVisualsOk, reducedMotion, setTint } from '@/lib/device-class';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { Card, SharedData } from '@/types';

export type Hero = Card & { overview?: string | null; preview?: string | null; in_list?: boolean; color?: string | null };

/**
 * Featured card: 5–8 library titles (no ads), auto-rotating every 8 s, local 20 s preview after 2.5 s.
 * Pauses on hover / focus / touch. Reports the current title so the page can blur its backdrop behind the glass.
 */
export function HeroBanner({ items, onChange }: { items: Hero[]; onChange?: (cur: Hero) => void }) {
    const { t, locale } = useT();
    const { auth, viewAs } = usePage<SharedData>().props;
    const [i, setI] = useState(0);
    const [hold, setHold] = useState(false);
    const [showPreview, setShowPreview] = useState(false);
    const box = useRef<HTMLElement>(null);
    const cur = items[i];
    useEffect(() => {
        if (!cur) return;
        setTint(cur.color);
        onChange?.(cur);
        setShowPreview(false);
        const id = setTimeout(() => setShowPreview(true), 2500);
        return () => clearTimeout(id);
    }, [cur]); // eslint-disable-line react-hooks/exhaustive-deps
    useEffect(() => {
        if (hold || items.length < 2) return;
        const id = setTimeout(() => setI((x) => (x + 1) % items.length), 8000);
        return () => clearTimeout(id);
    }, [i, hold, items.length]);
    if (!cur) return null;
    const previewOk = !!cur.preview && showPreview && heavyVisualsOk() && !reducedMotion();
    const go = (d: number) => setI((x) => (x + d + items.length) % items.length);
    const rtl = locale === 'ar';
    const watch = cur.file_id ? `/watch/${cur.file_id}` : `/title/${cur.id}`;
    return (
        <section ref={box} className="relative isolate flex h-[clamp(22rem,56vh,36rem)] w-full flex-col justify-end overflow-hidden rounded-3xl bg-black/40 text-white shadow-2xl"
            data-testid="hero" data-index={i}
            onPointerEnter={() => setHold(true)} onPointerLeave={() => setHold(false)} onTouchStart={() => setHold(true)}
            onFocusCapture={() => setHold(true)} onBlurCapture={(e) => !box.current?.contains(e.relatedTarget as Node) && setHold(false)}>
            {items.map((it, k) => it.backdrop && (
                <img key={it.id} src={it.backdrop} alt="" className={cn('absolute inset-0 -z-10 size-full object-cover transition-opacity duration-1000', k === i ? 'opacity-100' : 'opacity-0')} />
            ))}
            {previewOk && <video key={cur.id} src={cur.preview!} muted autoPlay playsInline loop className="absolute inset-0 -z-10 size-full object-cover" />}
            <div className="absolute inset-0 -z-10 bg-linear-to-t from-black/85 via-black/25 to-black/10" />
            <div className="absolute inset-0 -z-10 bg-linear-to-r from-black/60 via-transparent to-transparent rtl:bg-linear-to-l" />

            <span className="absolute start-5 top-5 inline-flex items-center gap-1.5 rounded-full bg-black/35 px-3 py-1.5 text-xs font-medium backdrop-blur-md">
                <Flame className="size-3.5 text-orange-400" /> {t.glass.featured}
            </span>

            <div className="max-w-xl space-y-3 p-5 md:p-8">
                {cur.genres.length > 0 && (
                    <div className="flex flex-wrap gap-2">
                        {cur.genres.slice(0, 3).map((g) => <span key={g} dir="auto" className="rounded-full bg-white/15 px-3 py-1 text-xs backdrop-blur-md">{g}</span>)}
                    </div>
                )}
                <h1 dir="auto" className="text-3xl leading-tight font-black drop-shadow md:text-5xl">{cur.title}</h1>
                <div className="flex gap-3 text-sm text-white/75">
                    {cur.year && <span>{cur.year}</span>}
                    {cur.age_rating !== null && <span className="rounded border border-white/40 px-1.5">{cur.age_rating}+</span>}
                    {cur.vote && <span>★ {cur.vote.toFixed(1)}</span>}
                </div>
                {cur.overview && <p dir="auto" className="line-clamp-3 text-sm text-white/80 md:text-base">{cur.overview}</p>}
                <div className="flex flex-wrap items-center gap-2 pt-1 md:gap-3">
                    <Link href={watch} className="inline-flex h-11 items-center gap-2 rounded-full bg-white px-5 font-semibold md:h-12 md:px-7 text-black shadow-lg transition hover:bg-white/90">
                        <Play className="size-4 fill-current" /> {t.glass.watch}
                    </Link>
                    {auth.user?.can.download && !viewAs && cur.file_id && (
                        <Link href={`/title/${cur.id}?download=1`} className="inline-flex h-11 items-center gap-2 rounded-full border border-white/45 px-4 font-medium md:h-12 md:px-6 backdrop-blur-md transition hover:bg-white/15">
                            <Download className="size-4" /> {t.devices.download}
                        </Link>
                    )}
                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <button type="button" aria-label={t.glass.more} className="inline-flex size-11 items-center justify-center rounded-full border border-white/45 md:size-12 backdrop-blur-md transition hover:bg-white/15">
                                <MoreHorizontal className="size-5" />
                            </button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="start">
                            <DropdownMenuItem asChild><Link href={`/title/${cur.id}`}><Info /> {t.moreInfo}</Link></DropdownMenuItem>
                            <DropdownMenuItem onClick={() => router.post(`/title/${cur.id}/list`, {}, { preserveScroll: true, preserveState: true })}>
                                {cur.in_list ? <Check /> : <Plus />} {cur.in_list ? t.inList : t.addToList}
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                </div>
            </div>

            {items.length > 1 && (
                <div className="absolute end-5 top-5 flex items-center gap-3 md:end-8 md:top-auto md:bottom-8">
                    <div className="hidden gap-1.5 sm:flex">
                        {items.map((_, k) => <button key={k} aria-label={`${k + 1}`} onClick={() => setI(k)} className={cn('h-1.5 rounded-full bg-white/40 transition-all', k === i ? 'w-6 bg-white' : 'w-2.5')} />)}
                    </div>
                    <button type="button" onClick={() => go(-1)} aria-label={t.glass.prev} className="inline-flex size-10 items-center justify-center rounded-full bg-white/15 backdrop-blur-md transition hover:bg-white/30">
                        {rtl ? <ChevronRight className="size-5" /> : <ChevronLeft className="size-5" />}
                    </button>
                    <button type="button" onClick={() => go(1)} aria-label={t.glass.next} className="inline-flex size-10 items-center justify-center rounded-full bg-white/15 backdrop-blur-md transition hover:bg-white/30">
                        {rtl ? <ChevronLeft className="size-5" /> : <ChevronRight className="size-5" />}
                    </button>
                </div>
            )}
        </section>
    );
}
