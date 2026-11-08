import { router } from '@inertiajs/react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AdminLayout, { Section } from '@/layouts/admin-layout';
import { useT } from '@/lib/i18n';

type Row = { id: number; name: string; item: { id: number; title: string; year: number | null; type: string; tmdb_id: number | null; age_rating: number | null; featured?: boolean } | null; season: number | null; episode: number | null; sanitized: boolean; linked: boolean };

export default function Matches({ files }: { files: { data: Row[]; links: { url: string | null; label: string; active: boolean }[] } }) {
    const { t } = useT();
    return (
        <AdminLayout title={t.adminUi.matches}>
            <Section title={t.adminUi.matches}>
                <div className="space-y-3">{files.data.map((f) => <MatchRow key={f.id} f={f} />)}</div>
                <div className="mt-4 flex flex-wrap gap-1">
                    {files.links.map((l, i) => l.url && <Button key={i} size="sm" variant={l.active ? 'default' : 'ghost'} onClick={() => router.visit(l.url!)} dangerouslySetInnerHTML={{ __html: l.label }} />)}
                </div>
            </Section>
        </AdminLayout>
    );
}

function MatchRow({ f }: { f: Row }) {
    const { t } = useT();
    const [d, setD] = useState({ type: f.item?.type ?? 'movie', title: f.item?.title ?? '', year: f.item?.year ?? '', season: f.season ?? '', episode: f.episode ?? '', tmdb_id: f.item?.tmdb_id ?? '' });
    const [age, setAge] = useState(f.item?.age_rating ?? '');
    const [intro, setIntro] = useState({ intro_start: '', intro_end: '' });
    const num = (v: unknown) => (v === '' || v === null ? null : Number(v));
    return (
        <div className="space-y-2 border-t pt-3 text-sm">
            <div dir="ltr" className="truncate text-xs text-muted-foreground">{f.name} {f.sanitized && '· sanitized'} {f.linked && '· linked'}</div>
            <div className="flex flex-wrap gap-2">
                <select className="h-9 rounded-md border bg-background px-2" value={d.type} onChange={(e) => setD({ ...d, type: e.target.value })}><option value="movie">{t.movies}</option><option value="show">{t.shows}</option></select>
                <Input dir="auto" className="w-56" value={d.title} onChange={(e) => setD({ ...d, title: e.target.value })} />
                <Input className="w-20" placeholder={t.year} value={d.year} onChange={(e) => setD({ ...d, year: e.target.value })} />
                {d.type === 'show' && <><Input className="w-16" placeholder="S" value={d.season} onChange={(e) => setD({ ...d, season: e.target.value })} /><Input className="w-16" placeholder="E" value={d.episode} onChange={(e) => setD({ ...d, episode: e.target.value })} /></>}
                <Input className="w-28" placeholder="TMDB id" value={d.tmdb_id} onChange={(e) => setD({ ...d, tmdb_id: e.target.value })} />
                <Button size="sm" onClick={() => router.post(`/admin/files/${f.id}/rematch`, { ...d, year: num(d.year), season: num(d.season), episode: num(d.episode), tmdb_id: num(d.tmdb_id) }, { preserveScroll: true })}>{t.adminUi.rematch}</Button>
                {f.item && <>
                    <Input className="w-20" placeholder="age" value={age} onChange={(e) => setAge(e.target.value)} />
                    <Button size="sm" variant="secondary" onClick={() => router.patch(`/admin/items/${f.item!.id}`, { title: d.title, year: num(d.year), age_rating: num(age) }, { preserveScroll: true })}>{t.maxAge}</Button>
                </>}
            </div>
            <div className="flex flex-wrap items-center gap-2">
                <span className="text-xs text-muted-foreground">{t.adminUi.intro}</span>
                <Input className="w-20" placeholder="0" value={intro.intro_start} onChange={(e) => setIntro({ ...intro, intro_start: e.target.value })} />
                <Input className="w-20" placeholder="90" value={intro.intro_end} onChange={(e) => setIntro({ ...intro, intro_end: e.target.value })} />
                <Button size="sm" variant="ghost" onClick={() => router.post(`/admin/files/${f.id}/intro`, { intro_start: num(intro.intro_start) ?? 0, intro_end: num(intro.intro_end) }, { preserveScroll: true })}>{t.save}</Button>
                <Button size="sm" variant="ghost" onClick={() => router.post(`/admin/files/${f.id}/pretranscode`, {}, { preserveScroll: true })}>{t.adminUi.pretranscode}</Button>
                {f.item && <Button size="sm" variant={f.item.featured ? 'default' : 'ghost'} onClick={() => router.post(`/admin/items/${f.item!.id}/feature`, {}, { preserveScroll: true })}>★ {t.adminUi.feature}</Button>}
            </div>
        </div>
    );
}
