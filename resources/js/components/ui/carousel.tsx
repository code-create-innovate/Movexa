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
            <div ref={ref} className="-mx-1 overflow-hidden px-1 py-2">
                <div className="flex gap-3 md:gap-4">{children}</div>
            </div>
            {can.prev && (
                <button type="button" tabIndex={-1} aria-hidden onClick={() => api?.scrollPrev()} className="mx-glass absolute top-1/2 start-1 z-10 hidden size-10 -translate-y-1/2 items-center justify-center rounded-full opacity-0 transition group-hover/carousel:opacity-100 md:flex">
                    <Prev className="size-5" />
                </button>
            )}
            {can.next && (
                <button type="button" tabIndex={-1} aria-hidden onClick={() => api?.scrollNext()} className="mx-glass absolute top-1/2 end-1 z-10 hidden size-10 -translate-y-1/2 items-center justify-center rounded-full opacity-0 transition group-hover/carousel:opacity-100 md:flex">
                    <Next className="size-5" />
                </button>
            )}
        </div>
    );
}
export function CarouselItem({ children, className }: { children: React.ReactNode; className?: string }) {
    return <div className={cn('min-w-0 shrink-0 grow-0', className)}>{children}</div>;
}
