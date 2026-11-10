import { Link } from '@inertiajs/react';
import { Film, Play } from 'lucide-react';
import type { ReactNode } from 'react';
import { Carousel, CarouselItem } from '@/components/ui/carousel';
import { setTint } from '@/lib/device-class';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { Card } from '@/types';

const hrefOf = (i: Card) => (i.file_id ? `/watch/${i.file_id}` : `/title/${i.id}`);
const subOf = (i: Card) => (i.episode ? `S${i.episode.season} · E${i.episode.number}` : [i.year, i.vote ? `★ ${i.vote.toFixed(1)}` : null].filter(Boolean).join(' · '));

function Art({ item, wide, className }: { item: Card; wide?: boolean; className?: string }) {
    const src = (wide ? item.backdrop ?? item.poster : item.poster ?? item.backdrop) ?? null;
    return src ? (
        <img src={src} alt="" loading="lazy" decoding="async" className={cn('size-full object-cover', className)} />
    ) : (
        <div className={cn('flex size-full items-center justify-center bg-linear-to-br from-white/15 to-white/5', className)}><Film className="size-6 text-white/60" /></div>
    );
}

/** Frosted side card with a title and an optional "see all" link. */
export function SidePanel({ title, href, children, testId }: { title: string; href?: string; children: ReactNode; testId?: string }) {
    const { t } = useT();
    return (
        <section className="mx-glass-soft rounded-3xl p-4" data-testid={testId}>
            <div className="mb-3 flex items-center justify-between gap-2">
                <h2 dir="auto" className="text-base font-bold">{title}</h2>
                {href && <Link href={href} className="text-xs text-foreground/60 hover:text-foreground">{t.glass.seeAll}</Link>}
            </div>
            {children}
        </section>
    );
}

/** Wide thumbnails with the title on the image (left column, top). */
export function RecentList({ items }: { items: Card[] }) {
    return (
        <div className="flex flex-col gap-3">
            {items.map((i) => (
                <Link key={i.id} href={`/title/${i.id}`} onPointerEnter={() => setTint(i.color)} onFocus={() => setTint(i.color)} aria-label={i.title}
                    className="group relative block aspect-video overflow-hidden rounded-2xl">
                    <Art item={i} wide className="transition duration-500 group-hover:scale-105" />
                    <div className="absolute inset-0 bg-linear-to-t from-black/80 via-black/10 to-transparent" />
                    <div className="absolute inset-x-3 bottom-2.5 flex items-end gap-2 text-white">
                        <span dir="auto" className="line-clamp-2 flex-1 text-sm leading-snug font-semibold">{i.title}</span>
                        <span className="mx-play size-8 shrink-0"><Play className="size-3.5 fill-current" /></span>
                    </div>
                </Link>
            ))}
        </div>
    );
}

/** Small poster + title + episode/progress, play button straight into the player (left column, bottom). */
export function ContinueList({ items }: { items: Card[] }) {
    return (
        <div className="flex flex-col gap-2">
            {items.map((i) => (
                <Link key={`${i.id}-${i.file_id}`} href={hrefOf(i)} onPointerEnter={() => setTint(i.color)} onFocus={() => setTint(i.color)} aria-label={i.title}
                    className="group flex items-center gap-3 rounded-2xl p-1.5 transition hover:bg-white/10">
                    <div className="h-14 w-11 shrink-0 overflow-hidden rounded-lg"><Art item={i} /></div>
                    <div className="min-w-0 flex-1">
                        <div dir="auto" className="truncate text-sm font-semibold">{i.title}</div>
                        <div className="truncate text-xs text-foreground/60">{subOf(i)}</div>
                        {i.progress !== undefined && (
                            <div className="mt-1.5 h-1 overflow-hidden rounded-full bg-white/15"><div className="h-full rounded-full bg-primary" style={{ width: `${Math.round(i.progress * 100)}%` }} /></div>
                        )}
                    </div>
                    <span className="mx-play size-8 shrink-0 opacity-80 group-hover:opacity-100"><Play className="size-3.5 fill-current" /></span>
                </Link>
            ))}
        </div>
    );
}

/** Tall card: picture, genre tag, title, year/rating, white play button. */
export function GlassCard({ item }: { item: Card }) {
    return (
        <Link href={hrefOf(item)} onPointerEnter={() => setTint(item.color)} onFocus={() => setTint(item.color)} aria-label={item.title}
            className="group relative block aspect-[3/4] overflow-hidden rounded-3xl shadow-lg transition-transform focus-visible:scale-[1.03] md:hover:-translate-y-1">
            <Art item={item} className="transition duration-500 group-hover:scale-105" />
            <div className="absolute inset-0 bg-linear-to-t from-black/90 via-black/20 to-transparent" />
            <div className="absolute inset-x-3 bottom-3 text-white">
                {item.genres[0] && <span dir="auto" className="mb-2 inline-block rounded-full bg-white/20 px-2.5 py-0.5 text-[11px] backdrop-blur-md">{item.genres[0]}</span>}
                <div className="flex items-end gap-2">
                    <div className="min-w-0 flex-1">
                        <div dir="auto" className="line-clamp-2 text-sm leading-snug font-bold md:text-base">{item.title}</div>
                        <div className="truncate text-xs text-white/65">{subOf(item)}</div>
                    </div>
                    <span className="mx-play size-10 shrink-0"><Play className="size-4 fill-current" /></span>
                </div>
            </div>
            {item.progress !== undefined && <div className="absolute inset-x-0 top-0 h-1 bg-black/30"><div className="h-full bg-primary" style={{ width: `${Math.round(item.progress * 100)}%` }} /></div>}
        </Link>
    );
}

export function GlassRow({ title, items, href }: { title: string; items: Card[]; href?: string }) {
    const { t } = useT();
    return (
        <section className="my-2" data-testid="glass-row">
            <div className="mb-1 flex items-center justify-between gap-3">
                <h2 dir="auto" className="text-lg font-bold md:text-xl">{title}</h2>
                {href && <Link href={href} className="mx-glass-soft rounded-full px-3 py-1 text-xs text-foreground/75 hover:text-foreground">{t.glass.seeAll}</Link>}
            </div>
            <Carousel label={title}>
                {items.map((i) => (
                    <CarouselItem key={`${i.id}-${i.file_id ?? ''}`} className="basis-[46%] sm:basis-[31%] md:basis-[24%] xl:basis-[19%] 2xl:basis-[16%]">
                        <GlassCard item={i} />
                    </CarouselItem>
                ))}
            </Carousel>
        </section>
    );
}
