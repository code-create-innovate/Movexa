import { router, useForm, usePage } from '@inertiajs/react';
import { RefreshCw, Trash2 } from 'lucide-react';
import { Uploader } from '@/components/movexa/uploader';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AdminLayout, { Section } from '@/layouts/admin-layout';
import { useT } from '@/lib/i18n';
import type { SharedData } from '@/types';

type Lib = { id: number; name: string; type: string; path: string; source: string; items_count: number; last_scanned_at: string | null };

export default function LibraryAdmin({ libraries }: { libraries: Lib[] }) {
    const { t } = useT();
    const { auth } = usePage<SharedData>().props;
    const lib = useForm({ name: '', type: 'movies', path: '/www/data/movexa/library/', source: 'local', watch: true });
    return (
        <AdminLayout title={t.accounts.library}>
            {auth.user?.can.library && (
                <Section title={t.adminUi.libraries}>
                    <ul className="mb-4 space-y-2 text-sm">
                        {libraries.map((l) => (
                            <li key={l.id} className="flex items-center gap-2 border-t pt-2">
                                <div className="min-w-0 flex-1"><b dir="auto">{l.name}</b> <span className="text-muted-foreground">({l.type} · {l.source} · {l.items_count})</span><div dir="ltr" className="truncate text-xs text-muted-foreground">{l.path}</div></div>
                                <Button size="sm" variant="secondary" onClick={() => router.post(`/admin/libraries/${l.id}/scan`, {}, { preserveScroll: true })}><RefreshCw /> {t.adminUi.scan}</Button>
                                <Button size="icon" variant="ghost" onClick={() => confirm('?') && router.delete(`/admin/libraries/${l.id}`)}><Trash2 /></Button>
                            </li>
                        ))}
                    </ul>
                    <form onSubmit={(e) => { e.preventDefault(); lib.post('/admin/libraries', { preserveScroll: true, onSuccess: () => lib.reset() }); }} className="flex flex-wrap gap-2">
                        <Input className="w-40" placeholder={t.name} value={lib.data.name} onChange={(e) => lib.setData('name', e.target.value)} required />
                        <select className="h-9 rounded-md border bg-background px-2 text-sm" value={lib.data.type} onChange={(e) => lib.setData('type', e.target.value)}><option value="movies">{t.movies}</option><option value="shows">{t.shows}</option></select>
                        <select className="h-9 rounded-md border bg-background px-2 text-sm" value={lib.data.source} onChange={(e) => lib.setData('source', e.target.value)}><option value="local">local</option><option value="cloudsync">CloudSync</option></select>
                        <Input dir="ltr" className="min-w-64 flex-1" placeholder={t.adminUi.path} value={lib.data.path} onChange={(e) => lib.setData('path', e.target.value)} required />
                        <Button type="submit" disabled={lib.processing}>{t.adminUi.addLibrary}</Button>
                    </form>
                    {Object.values(lib.errors).map((e) => <p key={e} className="text-sm text-destructive">{e}</p>)}
                </Section>
            )}
            {auth.user?.can.upload && libraries.length > 0 && <Section title={t.adminUi.upload}><Uploader libraries={libraries} /></Section>}
        </AdminLayout>
    );
}
