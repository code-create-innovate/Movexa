import { Link } from '@inertiajs/react';
import { Dices, Info, Play, Shuffle } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { api, useT } from '@/lib/i18n';
import type { Card } from '@/types';

/** "شن نتفرج؟" — server picks (age, blocks, ready-only, not fully watched, filters, weighted by taste). */
export function PickDialog() {
    const { t } = useT();
    const [f, setF] = useState({ type: '', genre: '', max_minutes: '', min_vote: '', lang: '' });
    const [res, setRes] = useState<(Card & { file_id?: number }) | null | undefined>(undefined);
    const pick = async () => {
        const q = new URLSearchParams(Object.entries(f).filter(([, v]) => v !== ''));
        const r = await api<{ item: (Card & { file_id?: number }) | null }>(`/pick?${q}`);
        setRes(r.ok ? r.data.item : null);
    };
    return (
        <Dialog onOpenChange={(o) => o && setRes(undefined)}>
            <DialogTrigger asChild><Button variant="ghost" size="sm" className="gap-1"><Dices className="size-5" /> <span className="hidden lg:inline">{t.pick.button}</span></Button></DialogTrigger>
            <DialogContent className="sm:max-w-lg">
                <DialogHeader><DialogTitle>{t.pick.title}</DialogTitle></DialogHeader>
                {res === undefined ? (
                    <div className="grid grid-cols-2 gap-2 text-sm">
                        <select className="h-10 rounded-md border bg-background px-2" value={f.type} onChange={(e) => setF({ ...f, type: e.target.value })}>
                            <option value="">{t.pick.anyType}</option><option value="movie">{t.movies}</option><option value="show">{t.shows}</option>
                        </select>
                        <Input placeholder={t.genre} value={f.genre} onChange={(e) => setF({ ...f, genre: e.target.value })} />
                        <Input type="number" placeholder={t.pick.minutes} value={f.max_minutes} onChange={(e) => setF({ ...f, max_minutes: e.target.value })} />
                        <Input type="number" step="0.5" placeholder={t.minRating} value={f.min_vote} onChange={(e) => setF({ ...f, min_vote: e.target.value })} />
                        <select className="col-span-2 h-10 rounded-md border bg-background px-2" value={f.lang} onChange={(e) => setF({ ...f, lang: e.target.value })}>
                            <option value="">{t.pick.lang}</option><option value="ar">العربية</option><option value="en">English</option>
                        </select>
                        <Button className="col-span-2" size="lg" onClick={pick}><Shuffle /> {t.pick.go}</Button>
                    </div>
                ) : res === null ? (
                    <div className="space-y-3"><p className="text-muted-foreground">{t.pick.none}</p><Button variant="secondary" onClick={() => setRes(undefined)}>{t.filters}</Button></div>
                ) : (
                    <div className="flex gap-4" data-testid="pick-result">
                        {res.poster && <img src={res.poster} alt="" className="w-28 rounded-md object-cover" />}
                        <div className="flex-1 space-y-2">
                            <div dir="auto" className="text-xl font-bold">{res.title}</div>
                            <div className="text-xs text-muted-foreground">{res.year} {res.age_rating !== null && `· ${res.age_rating}+`} {res.genres.join(' · ')}</div>
                            {res.overview && <p dir="auto" className="line-clamp-4 text-sm">{res.overview}</p>}
                            <div className="flex flex-wrap gap-2">
                                {res.file_id && <Button asChild><Link href={`/watch/${res.file_id}`}><Play className="fill-current" /> {t.play}</Link></Button>}
                                <Button asChild variant="secondary"><Link href={`/title/${res.id}`}><Info /> {t.pick.details}</Link></Button>
                                <Button variant="ghost" onClick={pick}><Shuffle /> {t.pick.another}</Button>
                            </div>
                        </div>
                    </div>
                )}
            </DialogContent>
        </Dialog>
    );
}
