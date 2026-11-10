import { router } from '@inertiajs/react';
import { Trash2 } from 'lucide-react';
import { useMemo, useState } from 'react';
import { BlockDialog, type BlockTarget } from '@/components/movexa/block-dialog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import MovexaLayout from '@/layouts/movexa-layout';
import { useT } from '@/lib/i18n';

type Block = { id: number; target_type: string; target: string; subject_type: string; subject: string; subject_id: number; expires_at: string | null; active: boolean };
type Props = {
    blocks: Block[]; users: { id: number; name: string }[]; isAdmin: boolean; canViewAs: boolean; audit: { id: number; action: string; details: unknown; created_at: string }[];
    targets: { genres: string[]; tags: string[]; libraries: { id: number; name: string }[]; collections: { id: number; name: string }[]; categories: string[] };
};

export default function Blocks({ blocks, isAdmin, audit, targets }: Props) {
    const { t, locale } = useT();
    const [sel, setSel] = useState<number[]>([]);
    const [secret, setSecret] = useState('');
    const fmt = (d: string | null) => (d ? new Date(d).toLocaleString(locale === 'ar' ? 'ar' : 'en') : t.blocks.forever);
    const byPerson = useMemo(() => groupBy(blocks, (b) => `${b.subject_type}:${b.subject}`), [blocks]);
    const byContent = useMemo(() => groupBy(blocks, (b) => `${t.blocks.types[b.target_type as keyof typeof t.blocks.types]}: ${b.target}`), [blocks, t]);
    const generic: BlockTarget[] = [
        ...targets.genres.map((g) => ({ type: 'genre', value: g, label: `${t.blocks.types.genre}: ${g}` })),
        ...targets.tags.map((g) => ({ type: 'tag', value: g, label: `${t.blocks.types.tag}: ${g}` })),
        ...targets.libraries.map((l) => ({ type: 'library', id: l.id, label: `${t.blocks.types.library}: ${l.name}` })),
        ...targets.collections.map((c) => ({ type: 'collection', id: c.id, label: `${t.blocks.types.collection}: ${c.name}` })),
        ...targets.categories.map((c) => ({ type: 'channel_category', value: c, label: `${t.blocks.types.channel_category}: ${c}` })),
    ];
    const list = (groups: Record<string, Block[]>) => Object.entries(groups).map(([k, items]) => (
        <section key={k} className="mb-4 rounded-lg border bg-card p-3">
            <h3 dir="auto" className="mb-2 font-semibold">{k}</h3>
            {items.map((b) => (
                <label key={b.id} className="flex items-center gap-3 border-t py-2 text-sm">
                    <input type="checkbox" checked={sel.includes(b.id)} onChange={() => setSel((s) => (s.includes(b.id) ? s.filter((x) => x !== b.id) : [...s, b.id]))} />
                    <span className="flex-1" dir="auto">{t.blocks.types[b.target_type as keyof typeof t.blocks.types]}: {b.target} ← {b.subject}</span>
                    <span className={b.active ? 'text-muted-foreground' : 'text-emerald-400'}>{t.blocks.expires}: {fmt(b.expires_at)}</span>
                </label>
            ))}
        </section>
    ));
    return (
        <MovexaLayout title={t.blocks.title}>
            <div className="mx-auto max-w-5xl space-y-6 py-2">
                <div className="flex flex-wrap items-center gap-3">
                    <h1 className="text-2xl font-bold">{t.blocks.title}</h1>
                    {generic.length > 0 && <div className="ms-auto"><BlockDialog targets={generic} /></div>}
                </div>
                {blocks.length === 0 ? <p className="text-muted-foreground">{t.blocks.empty}</p> : (
                    <Tabs defaultValue="person">
                        <TabsList><TabsTrigger value="person">{t.blocks.byPerson}</TabsTrigger><TabsTrigger value="content">{t.blocks.byContent}</TabsTrigger></TabsList>
                        <TabsContent value="person">{list(byPerson)}</TabsContent>
                        <TabsContent value="content">{list(byContent)}</TabsContent>
                    </Tabs>
                )}
                {sel.length > 0 && (
                    <div className="sticky bottom-4 flex flex-wrap items-center gap-2 rounded-lg border bg-card p-3 shadow-lg">
                        <Input type="password" className="w-56" placeholder={t.blocks.secret} value={secret} onChange={(e) => setSecret(e.target.value)} />
                        <Button variant="destructive" disabled={!secret} onClick={() => router.delete('/blocks', { data: { ids: sel, secret }, preserveScroll: true, onSuccess: () => { setSel([]); setSecret(''); } })}>
                            <Trash2 /> {t.blocks.removeSelected} ({sel.length})
                        </Button>
                    </div>
                )}
                {isAdmin && audit.length > 0 && (
                    <section className="rounded-lg border bg-card p-3 text-xs">
                        <h2 className="mb-2 text-sm font-semibold">{t.blocks.audit}</h2>
                        {audit.map((a) => <div key={a.id} dir="ltr" className="border-t py-1 font-mono">{a.created_at} · {a.action} · {JSON.stringify(a.details)}</div>)}
                    </section>
                )}
            </div>
        </MovexaLayout>
    );
}

function groupBy<T>(xs: T[], key: (x: T) => string) {
    return xs.reduce<Record<string, T[]>>((acc, x) => ((acc[key(x)] ??= []).push(x), acc), {});
}
