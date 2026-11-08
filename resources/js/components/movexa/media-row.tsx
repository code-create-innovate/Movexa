import { Link } from '@inertiajs/react';
import { Film, Play } from 'lucide-react';
import { Carousel, CarouselItem } from '@/components/ui/carousel';
import { Progress } from '@/components/ui/progress';
import { cn } from '@/lib/utils';
import type { Card } from '@/types';

export function Poster({ item, className }: { item: Card; className?: string }) {
    return item.poster ? (
        <img src={item.poster} alt="" loading="lazy" className={cn('aspect-[2/3] w-full rounded-md bg-muted object-cover', className)} />
    ) : (
        <div className={cn('flex aspect-[2/3] w-full flex-col items-center justify-center gap-2 rounded-md bg-linear-to-br from-muted to-secondary p-2 text-center', className)}>
            <Film className="size-8 text-muted-foreground" />
            <span dir="auto" className="line-clamp-3 text-sm font-semibold">{item.title}</span>
        </div>
    );
}

export function MediaCard({ item }: { item: Card }) {
    const href = item.file_id && item.progress !== undefined ? `/watch/${item.file_id}` : item.file_id && item.episode ? `/watch/${item.file_id}` : `/title/${item.id}`;
    return (
        <Link href={href} className="group block rounded-md transition-transform focus-visible:scale-105 md:hover:scale-105" aria-label={item.title}>
            <div className="relative">
                <Poster item={item} />
                {item.file_id && (
                    <span className="absolute inset-0 flex items-center justify-center opacity-0 transition group-hover:opacity-100 group-focus-visible:opacity-100">
                        <Play className="size-10 rounded-full bg-black/60 p-2 text-white" />
                    </span>
                )}
            </div>
            {item.progress !== undefined && <Progress value={item.progress * 100} className="mt-1 h-1" />}
            <div className="mt-1 px-0.5">
                <div dir="auto" className="truncate text-sm font-medium">{item.title}</div>
                <div className="text-xs text-muted-foreground">
                    {item.episode ? `S${item.episode.season} · E${item.episode.number}` : item.year}
                </div>
            </div>
        </Link>
    );
}

export function MediaRow({ title, items }: { title: string; items: Card[] }) {
    return (
        <section className="my-6">
            <h2 dir="auto" className="mb-2 px-4 text-lg font-bold md:px-10 md:text-xl">{title}</h2>
            <Carousel label={title}>
                {items.map((i) => (
                    <CarouselItem key={`${i.id}-${i.file_id ?? ''}`} className="basis-[38%] sm:basis-[24%] md:basis-[18%] lg:basis-[14%] xl:basis-[12%]">
                        <MediaCard item={i} />
                    </CarouselItem>
                ))}
            </Carousel>
        </section>
    );
}
