import { Link } from '@inertiajs/react';
import { Info, Play } from 'lucide-react';
import { MediaRow } from '@/components/movexa/media-row';
import { Button } from '@/components/ui/button';
import MovexaLayout from '@/layouts/movexa-layout';
import { useT } from '@/lib/i18n';
import type { Card } from '@/types';

type Row = { key: 'continue' | 'next_episode' | 'my_list' | 'recent' | 'genre'; label?: string; items: Card[] };

export default function Home({ hero, rows }: { hero: (Card & { overview?: string }) | null; rows: Row[] }) {
    const { t } = useT();
    return (
        <MovexaLayout title={t.home} transparent={!!hero}>
            {hero ? (
                <section className="relative h-[70vh] min-h-96 w-full">
                    {hero.backdrop && <img src={hero.backdrop} alt="" className="absolute inset-0 size-full object-cover" />}
                    <div className="absolute inset-0 bg-linear-to-t from-background via-background/40 to-transparent" />
                    <div className="absolute inset-0 bg-linear-to-r from-background/80 to-transparent rtl:bg-linear-to-l" />
                    <div className="absolute bottom-[12%] start-4 max-w-xl space-y-4 md:start-10">
                        <h1 dir="auto" className="text-4xl font-black md:text-6xl">{hero.title}</h1>
                        {hero.overview && <p dir="auto" className="line-clamp-3 text-base text-foreground/85 md:text-lg">{hero.overview}</p>}
                        <div className="flex gap-3">
                            <Button asChild size="lg"><Link href={`/title/${hero.id}`}><Play className="fill-current" /> {t.play}</Link></Button>
                            <Button asChild size="lg" variant="secondary"><Link href={`/title/${hero.id}`}><Info /> {t.moreInfo}</Link></Button>
                        </div>
                    </div>
                </section>
            ) : null}
            <div className={hero ? '-mt-16 relative z-10' : 'pt-4'}>
                {rows.length === 0 && <p className="p-10 text-center text-muted-foreground">{t.emptyLibrary}</p>}
                {rows.map((r, i) => <MediaRow key={i} title={r.key === 'genre' ? r.label! : t.rows[r.key]} items={r.items} />)}
            </div>
        </MovexaLayout>
    );
}
