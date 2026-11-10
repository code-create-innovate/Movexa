import { useState } from 'react';
import { ContinueList, GlassRow, RecentList, SidePanel } from '@/components/movexa/glass-panels';
import { type Hero, HeroBanner } from '@/components/movexa/hero-banner';
import { MediaRow } from '@/components/movexa/media-row';
import { PosterWall } from '@/components/movexa/poster-wall';
import MovexaLayout from '@/layouts/movexa-layout';
import { useT } from '@/lib/i18n';
import type { Card } from '@/types';

type Row = { key: 'continue' | 'next_episode' | 'my_list' | 'recent' | 'genre' | 'top_movies' | 'top_shows'; label?: string; items: Card[]; top?: boolean };

export default function Home({ banner, wall, rows }: { banner: Hero[]; wall: { src: string }[]; rows: Row[] }) {
    const { t } = useT();
    const [cur, setCur] = useState<Hero | null>(banner[0] ?? null);
    const label = (r: Row) => (r.key === 'genre' ? r.label! : r.key === 'top_movies' ? t.top.movies : r.key === 'top_shows' ? t.top.shows : t.rows[r.key]);
    const seeAll = (r: Row) => (r.key === 'genre' ? `/search?genre=${encodeURIComponent(r.label!)}` : r.key === 'recent' ? '/search' : undefined);
    const recent = rows.find((r) => r.key === 'recent');
    const cont = rows.find((r) => r.key === 'continue');
    const main = rows.filter((r) => r !== recent && r !== cont);
    const side = !!(recent || cont);

    return (
        <MovexaLayout title={t.home} backdrop={cur?.backdrop ?? recent?.items.find((i) => i.backdrop)?.backdrop} background={<PosterWall posters={wall} />}>
            {rows.length === 0 && banner.length === 0 && <p className="p-10 text-center text-muted-foreground">{t.emptyLibrary}</p>}
            <div className={side ? 'grid gap-5 lg:grid-cols-[17rem_minmax(0,1fr)] xl:grid-cols-[19rem_minmax(0,1fr)]' : 'grid gap-5'}>
                {banner.length > 0 && <div className="min-w-0 lg:col-start-2 lg:row-start-1">
                    <HeroBanner items={banner} onChange={setCur} />
                </div>}
                {side && (
                    <aside className="flex min-w-0 flex-col gap-5 lg:col-start-1 lg:row-span-2 lg:row-start-1">
                        {cont && <SidePanel title={t.rows.continue} href="/history" testId="panel-continue"><ContinueList items={cont.items.slice(0, 6)} /></SidePanel>}
                        {recent && <SidePanel title={t.rows.recent} href="/search" testId="panel-recent"><RecentList items={recent.items.slice(0, 3)} /></SidePanel>}
                    </aside>
                )}
                <div className={side ? 'min-w-0 space-y-4 lg:col-start-2 lg:row-start-2' : 'min-w-0 space-y-4'}>
                    {main.map((r, i) => (r.top
                        ? <MediaRow key={i} title={label(r)} items={r.items} top />
                        : <GlassRow key={i} title={label(r)} items={r.items} href={seeAll(r)} />))}
                </div>
            </div>
        </MovexaLayout>
    );
}
