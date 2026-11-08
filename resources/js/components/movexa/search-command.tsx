import { router } from '@inertiajs/react';
import { Film, Tv } from 'lucide-react';
import { useEffect, useState } from 'react';
import { Command, CommandEmpty, CommandGroup, CommandInput, CommandItem, CommandList } from '@/components/ui/command';
import { Dialog, DialogContent, DialogTitle } from '@/components/ui/dialog';
import { api, useT } from '@/lib/i18n';
import type { Card } from '@/types';

/** Instant Arabic/English search (shadcn Command). */
export function SearchCommand({ open, onOpenChange }: { open: boolean; onOpenChange: (o: boolean) => void }) {
    const { t } = useT();
    const [q, setQ] = useState('');
    const [results, setResults] = useState<Card[]>([]);
    useEffect(() => {
        if (q.trim().length < 1) return setResults([]);
        const id = setTimeout(async () => {
            const r = await api<{ results: Card[] }>(`/search?q=${encodeURIComponent(q)}`);
            if (r.ok) setResults(r.data.results.slice(0, 12));
        }, 150);
        return () => clearTimeout(id);
    }, [q]);
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="overflow-hidden p-0 sm:max-w-xl">
                <DialogTitle className="sr-only">{t.search}</DialogTitle>
                <Command shouldFilter={false}>
                    <CommandInput value={q} onValueChange={setQ} placeholder={t.searchPlaceholder} autoFocus />
                    <CommandList>
                        {q && <CommandEmpty>{t.noResults}</CommandEmpty>}
                        {results.length > 0 && (
                            <CommandGroup heading={t.search}>
                                {results.map((r) => (
                                    <CommandItem key={r.id} value={String(r.id)} onSelect={() => { onOpenChange(false); router.visit(`/title/${r.id}`); }}>
                                        {r.poster ? <img src={r.poster} alt="" className="h-12 w-8 rounded object-cover" /> : r.type === 'show' ? <Tv className="size-5" /> : <Film className="size-5" />}
                                        <span dir="auto" className="font-medium">{r.title}</span>
                                        <span className="ms-auto text-xs text-muted-foreground">{r.year}</span>
                                    </CommandItem>
                                ))}
                            </CommandGroup>
                        )}
                    </CommandList>
                </Command>
            </DialogContent>
        </Dialog>
    );
}
