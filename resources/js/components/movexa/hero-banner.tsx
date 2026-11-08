import { Link, router } from '@inertiajs/react';
import { Check, Info, Play, Plus } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import { heavyVisualsOk, reducedMotion, setTint } from '@/lib/device-class';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { Card } from '@/types';

type Hero = Card & { overview?: string | null; preview?: string | null; in_list?: boolean; color?: string | null };

/** 5–8 library titles (no ads), auto-rotating every 8 s; pauses on hover / focus / touch. */
export function HeroBanner({ items }: { items: Hero[] }) {
    const { t } = useT();
    const [i, setI] = useState(0);
    const [hold, setHold] = useState(false);
    const [showPreview, setShowPreview] = useState(false);
    const box = useRef<HTMLElement>(null);
    const cur = items[i];
    useEffect(() => { setTint(cur?.color); setShowPreview(false); const id = setTimeout(() => setShowPreview(true), 2500); return () => clearTimeout(id); }, [cur]);
    useEffect(() => {
        if (hold || items.length < 2) return;
        const id = setTimeout(() => setI((x) => (x + 1) % items.length), 8000);
        return () => clearTimeout(id);
    }, [i, hold, items.length]);
    if (!cur) return null;
    const previewOk = !!cur.preview && showPreview && heavyVisualsOk() && !reducedMotion();
    return (
        <section ref={box} className="relative h-[72vh] min-h-96 w-full overflow-hidden" data-testid="hero" data-index={i}
            onPointerEnter={() => setHold(true)} onPointerLeave={() => setHold(false)} onTouchStart={() => setHold(true)}
            onFocusCapture={() => setHold(true)} onBlurCapture={(e) => !box.current?.contains(e.relatedTarget as Node) && setHold(false)}>
            {items.map((it, k) => it.backdrop && (
                <img key={it.id} src={it.backdrop} alt="" className={cn('absolute inset-0 size-full object-cover transition-opacity duration-1000', k === i ? 'opacity-100' : 'opacity-0')} />
            ))}
            {previewOk && <video key={cur.id} src={cur.preview!} muted autoPlay playsInline loop className="absolute inset-0 size-full object-cover" />}
            <div className="absolute inset-0 bg-linear-to-t from-background via-background/40 to-transparent" />
            <div className="absolute inset-0 bg-linear-to-r from-background/85 to-transparent rtl:bg-linear-to-l" />
            <div className="absolute bottom-[14%] start-4 max-w-xl space-y-4 md:start-10">
                <h1 dir="auto" className="text-4xl font-black drop-shadow md:text-6xl">{cur.title}</h1>
                <div className="flex gap-3 text-sm text-foreground/80">
                    {cur.year && <span>{cur.year}</span>}
                    {cur.age_rating !== null && <span className="rounded border px-1.5">{cur.age_rating}+</span>}
                    {cur.vote && <span>★ {cur.vote.toFixed(1)}</span>}
                </div>
                {cur.overview && <p dir="auto" className="line-clamp-3 text-base text-foreground/85 md:text-lg">{cur.overview}</p>}
                <div className="flex flex-wrap gap-3">
                    <Button asChild size="lg"><Link href={`/title/${cur.id}`}><Play className="fill-current" /> {t.play}</Link></Button>
                    <Button asChild size="lg" variant="secondary"><Link href={`/title/${cur.id}`}><Info /> {t.moreInfo}</Link></Button>
                    <Button size="lg" variant="ghost" onClick={() => router.post(`/title/${cur.id}/list`, {}, { preserveScroll: true, preserveState: true })}>
                        {cur.in_list ? <Check /> : <Plus />} {cur.in_list ? t.inList : t.addToList}
                    </Button>
                </div>
            </div>
            {items.length > 1 && (
                <div className="absolute end-4 bottom-[14%] flex gap-1.5 md:end-10">
                    {items.map((_, k) => <button key={k} aria-label={`${k + 1}`} onClick={() => setI(k)} className={cn('h-1.5 rounded-full bg-white/40 transition-all', k === i ? 'w-6 bg-white' : 'w-3')} />)}
                </div>
            )}
        </section>
    );
}
