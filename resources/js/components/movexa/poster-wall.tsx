import { useEffect, useMemo, useRef, useState } from 'react';
import { heavyVisualsOk, reducedMotion } from '@/lib/device-class';
import { cn } from '@/lib/utils';

/**
 * Background wall of small posters drifting down (transform-only), each column at its own speed.
 * Layers: posters → dark layer tinted with the current title (70–85 %) + slight blur → page content.
 * Static on TVs / weak devices / reduced motion / when disabled; paused when the tab is hidden.
 */
export function PosterWall({ posters, enabled = true, className }: { posters: { src: string }[]; enabled?: boolean; className?: string }) {
    const [hidden, setHidden] = useState(false);
    const ref = useRef<HTMLDivElement>(null);
    const animated = enabled && heavyVisualsOk() && !reducedMotion();
    const cols = useMemo(() => {
        if (!posters.length) return [];
        // Column width ≈ 160 px whatever the library size; small libraries are repeated to fill the columns.
        const n = Math.min(12, Math.max(3, Math.round((typeof window !== 'undefined' ? window.innerWidth : 1280) / 160)));
        const list = posters.slice(0, 40);
        const out: { src: string }[][] = Array.from({ length: n }, () => []);
        for (let i = 0; i < Math.max(list.length, n * 5); i++) out[i % n].push(list[(i * 7 + Math.floor(i / n)) % list.length]);
        return out;
    }, [posters]);
    useEffect(() => {
        const on = () => setHidden(document.hidden);
        document.addEventListener('visibilitychange', on);
        return () => document.removeEventListener('visibilitychange', on);
    }, []);
    if (!enabled || !cols.length) return null;
    return (
        <div ref={ref} aria-hidden data-testid="poster-wall" data-animated={animated} className={cn('pointer-events-none fixed inset-0 -z-10 overflow-hidden', hidden && 'mx-wall-paused', className)}>
            <div className="absolute inset-0 flex gap-3 px-3 opacity-60">
                {cols.map((c, i) => (
                    <div key={i} className="flex-1 overflow-hidden">
                        <div className={cn('flex flex-col gap-3', animated && 'mx-wall-col')} style={animated ? { animationDuration: `${70 + ((i * 37) % 60)}s`, animationDelay: `-${(i * 13) % 40}s` } : undefined}>
                            {[...c, ...c].map((p, k) => <img key={k} src={p.src} alt="" loading="lazy" decoding="async" className="aspect-[2/3] w-full rounded-md object-cover" />)}
                        </div>
                    </div>
                ))}
            </div>
            <div className="absolute inset-0 backdrop-blur-[3px]" style={{ background: 'color-mix(in srgb, var(--page-tint) 22%, rgb(8 8 12 / .80))' }} />
        </div>
    );
}
