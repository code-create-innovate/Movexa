import useEmblaCarousel from 'embla-carousel-react';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import * as React from 'react';
import { cn } from '@/lib/utils';

/** shadcn-style Carousel. direction follows the document (RTL rows scroll right→left). */
export function Carousel({ children, className, label }: { children: React.ReactNode; className?: string; label?: string }) {
    const dir = typeof document !== 'undefined' && document.documentElement.dir === 'rtl' ? 'rtl' : 'ltr';
    const [ref, api] = useEmblaCarousel({ direction: dir, align: 'start', dragFree: true, containScroll: 'trimSnaps' });
    const [can, setCan] = React.useState({ prev: false, next: false });
    React.useEffect(() => {
        if (!api) return;
        const update = () => setCan({ prev: api.canScrollPrev(), next: api.canScrollNext() });
        update();
        api.on('select', update).on('reInit', update).on('scroll', update);
    }, [api]);
    const Prev = dir === 'rtl' ? ChevronRight : ChevronLeft;
    const Next = dir === 'rtl' ? ChevronLeft : ChevronRight;
    return (
        <div className={cn('group/carousel relative', className)} role="region" aria-roledescription="carousel" aria-label={label}>
            <div ref={ref} className="overflow-hidden px-4 md:px-10">
                <div className="flex gap-2 md:gap-3">{children}</div>
            </div>
            {can.prev && (
                <button type="button" tabIndex={-1} aria-hidden onClick={() => api?.scrollPrev()} className="absolute inset-y-0 start-0 z-10 hidden w-10 items-center justify-center bg-linear-to-r rtl:bg-linear-to-l from-background/90 to-transparent opacity-0 transition group-hover/carousel:opacity-100 md:flex">
                    <Prev className="size-8" />
                </button>
            )}
            {can.next && (
                <button type="button" tabIndex={-1} aria-hidden onClick={() => api?.scrollNext()} className="absolute inset-y-0 end-0 z-10 hidden w-10 items-center justify-center bg-linear-to-l rtl:bg-linear-to-r from-background/90 to-transparent opacity-0 transition group-hover/carousel:opacity-100 md:flex">
                    <Next className="size-8" />
                </button>
            )}
        </div>
    );
}
export function CarouselItem({ children, className }: { children: React.ReactNode; className?: string }) {
    return <div className={cn('min-w-0 shrink-0 grow-0', className)}>{children}</div>;
}
