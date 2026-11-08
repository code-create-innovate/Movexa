import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { MediaCard } from '@/components/movexa/media-row';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import MovexaLayout from '@/layouts/movexa-layout';
import { useT } from '@/lib/i18n';
import type { Card } from '@/types';

type Filters = { q?: string; genre?: string; year?: number; min_vote?: number; type?: string };

export default function Search({ results, filters, genres }: { results: Card[]; filters: Filters; genres: string[] }) {
    const { t } = useT();
    const [f, setF] = useState<Filters>(filters);
    useEffect(() => {
        const id = setTimeout(() => {
            const clean = Object.fromEntries(Object.entries(f).filter(([, v]) => v !== undefined && v !== '' && v !== 'all'));
            router.get('/search', clean, { preserveState: true, replace: true, preserveScroll: true });
        }, 250);
        return () => clearTimeout(id);
    }, [f]);
    const years = Array.from({ length: 60 }, (_, i) => new Date().getFullYear() - i);
    return (
        <MovexaLayout title={t.search}>
            <div className="space-y-6 px-4 py-6 md:px-10">
                <Input dir="auto" autoFocus className="h-14 text-lg" placeholder={t.searchPlaceholder} value={f.q ?? ''} onChange={(e) => setF({ ...f, q: e.target.value })} />
                <div className="flex flex-wrap gap-2">
                    <Select value={f.type ?? 'all'} onValueChange={(v) => setF({ ...f, type: v })}>
                        <SelectTrigger className="w-36"><SelectValue /></SelectTrigger>
                        <SelectContent><SelectItem value="all">{t.allTypes}</SelectItem><SelectItem value="movie">{t.movies}</SelectItem><SelectItem value="show">{t.shows}</SelectItem></SelectContent>
                    </Select>
                    <Select value={f.genre ?? 'all'} onValueChange={(v) => setF({ ...f, genre: v })}>
                        <SelectTrigger className="w-44"><SelectValue placeholder={t.genre} /></SelectTrigger>
                        <SelectContent><SelectItem value="all">{t.genre}: {t.allTypes}</SelectItem>{genres.map((g) => <SelectItem key={g} value={g}>{g}</SelectItem>)}</SelectContent>
                    </Select>
                    <Select value={f.year ? String(f.year) : 'all'} onValueChange={(v) => setF({ ...f, year: v === 'all' ? undefined : Number(v) })}>
                        <SelectTrigger className="w-32"><SelectValue placeholder={t.year} /></SelectTrigger>
                        <SelectContent><SelectItem value="all">{t.year}</SelectItem>{years.map((y) => <SelectItem key={y} value={String(y)}>{y}</SelectItem>)}</SelectContent>
                    </Select>
                    <Select value={f.min_vote ? String(f.min_vote) : 'all'} onValueChange={(v) => setF({ ...f, min_vote: v === 'all' ? undefined : Number(v) })}>
                        <SelectTrigger className="w-36"><SelectValue placeholder={t.minRating} /></SelectTrigger>
                        <SelectContent><SelectItem value="all">{t.minRating}</SelectItem>{[5, 6, 7, 8, 9].map((v) => <SelectItem key={v} value={String(v)}>★ {v}+</SelectItem>)}</SelectContent>
                    </Select>
                </div>
                {results.length === 0 ? <p className="py-10 text-center text-muted-foreground">{t.noResults}</p> : (
                    <div className="grid grid-cols-3 gap-3 sm:grid-cols-4 md:grid-cols-6 xl:grid-cols-8">{results.map((r) => <MediaCard key={r.id} item={r} />)}</div>
                )}
            </div>
        </MovexaLayout>
    );
}
