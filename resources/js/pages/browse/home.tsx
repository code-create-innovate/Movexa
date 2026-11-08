import { HeroBanner } from '@/components/movexa/hero-banner';
import { MediaRow } from '@/components/movexa/media-row';
import { PosterWall } from '@/components/movexa/poster-wall';
import MovexaLayout from '@/layouts/movexa-layout';
import { useT } from '@/lib/i18n';
import type { Card } from '@/types';

type Row = { key: 'continue' | 'next_episode' | 'my_list' | 'recent' | 'genre' | 'top_movies' | 'top_shows'; label?: string; items: Card[]; top?: boolean };
type Hero = Card & { overview?: string | null; preview?: string | null; in_list?: boolean };

export default function Home({ banner, wall, rows }: { banner: Hero[]; wall: { src: string }[]; rows: Row[] }) {
    const { t } = useT();
    const label = (r: Row) => (r.key === 'genre' ? r.label! : r.key === 'top_movies' ? t.top.movies : r.key === 'top_shows' ? t.top.shows : t.rows[r.key]);
    return (
        <MovexaLayout title={t.home} transparent={banner.length > 0}>
            <PosterWall posters={wall} />
            {banner.length > 0 && <HeroBanner items={banner} />}
            <div className={banner.length ? 'relative z-10 -mt-16' : 'pt-4'}>
                {rows.length === 0 && <p className="p-10 text-center text-muted-foreground">{t.emptyLibrary}</p>}
                {rows.map((r, i) => <MediaRow key={i} title={label(r)} items={r.items} top={!!r.top} />)}
            </div>
        </MovexaLayout>
    );
}
