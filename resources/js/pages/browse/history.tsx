import { MediaCard } from '@/components/movexa/media-row';
import MovexaLayout from '@/layouts/movexa-layout';
import { useT } from '@/lib/i18n';
import type { Card } from '@/types';

export default function History({ entries }: { entries: (Card & { watched_at: string })[] }) {
    const { t, locale } = useT();
    return (
        <MovexaLayout title={t.history}>
            <div className="px-4 py-6 md:px-10">
                <h1 className="mb-6 text-2xl font-bold">{t.history}</h1>
                {entries.length === 0 && <p className="text-muted-foreground">{t.noResults}</p>}
                <div className="grid grid-cols-3 gap-3 sm:grid-cols-4 md:grid-cols-6 xl:grid-cols-8">
                    {entries.map((e, i) => (
                        <div key={i}>
                            <MediaCard item={e} />
                            <div className="text-xs text-muted-foreground">{new Date(e.watched_at).toLocaleDateString(locale === 'ar' ? 'ar' : 'en')}</div>
                        </div>
                    ))}
                </div>
            </div>
        </MovexaLayout>
    );
}
