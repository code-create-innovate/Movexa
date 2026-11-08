import { Link, router, usePage } from '@inertiajs/react';
import type { SharedData } from '@/types';
import { Check, Heart, Play, Plus, ShieldCheck, ThumbsDown, ThumbsUp, Users } from 'lucide-react';
import { BlockDialog } from '@/components/movexa/block-dialog';
import { Poster } from '@/components/movexa/media-row';
import { Button } from '@/components/ui/button';
import { Progress } from '@/components/ui/progress';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import MovexaLayout from '@/layouts/movexa-layout';
import { formatTime, useT } from '@/lib/i18n';
import type { Card } from '@/types';

type FileInfo = {
    id: number; duration: number; height: number | null; sanitized: boolean;
    audio: { language: string | null; title: string | null; codec: string; channels: number | null }[];
    subtitles: { id: number; language: string | null; label: string }[];
    progress: { position: number; completed: boolean } | null;
};
type Episode = { id: number; number: number; title: string | null; overview: string | null; file: FileInfo };
type Item = Card & { overview: string | null; original_title: string | null; certification: string | null; cast: { name: string; character: string | null }[]; in_list: boolean; rating: number | null };

export default function Title({ item, file, seasons }: { item: Item; file: FileInfo | null; seasons: { id: number; number: number; episodes: Episode[] }[] }) {
    const { t } = useT();
    const { profile, viewAs } = usePage<SharedData>().props;
    const first = file ?? seasons[0]?.episodes[0]?.file ?? null;
    const resumeEp = seasons.flatMap((s) => s.episodes).find((e) => e.file.progress && !e.file.progress.completed);
    const target = resumeEp?.file ?? first;
    const rate = (v: number | null) => router.post(`/title/${item.id}/rate`, { value: item.rating === v ? null : v }, { preserveScroll: true });
    const langs = (f: FileInfo | null) => f ? [...new Set(f.audio.map((a) => a.language ?? '—'))].join(' · ') : '';

    return (
        <MovexaLayout title={item.title} transparent>
            <section className="relative min-h-[60vh]">
                {item.backdrop && <img src={item.backdrop} alt="" className="absolute inset-0 size-full object-cover" />}
                <div className="absolute inset-0 bg-linear-to-t from-background via-background/70 to-background/20" />
                <div className="relative flex flex-col gap-6 px-4 pt-28 pb-8 md:flex-row md:px-10">
                    <div className="w-40 shrink-0 md:w-56"><Poster item={item} /></div>
                    <div className="max-w-3xl space-y-4">
                        <h1 dir="auto" className="text-3xl font-black md:text-5xl">{item.title}</h1>
                        <div className="flex flex-wrap items-center gap-3 text-sm text-muted-foreground">
                            {item.year && <span>{item.year}</span>}
                            {item.age_rating !== null && <span className="rounded border px-1.5">{item.age_rating}+</span>}
                            {item.certification && <span>{item.certification}</span>}
                            {first && <span>{formatTime(first.duration)}</span>}
                            {first?.height && <span className="rounded border px-1.5">{first.height >= 2000 ? '4K' : first.height >= 1000 ? 'HD' : 'SD'}</span>}
                            {item.vote && <span>★ {item.vote.toFixed(1)}</span>}
                            {first?.sanitized && <span className="inline-flex items-center gap-1 text-emerald-400"><ShieldCheck className="size-4" /> {t.sanitized}</span>}
                        </div>
                        {item.genres.length > 0 && <div className="text-sm" dir="auto">{item.genres.join(' · ')}</div>}
                        {item.overview && <p dir="auto" className="text-base leading-relaxed text-foreground/90">{item.overview}</p>}
                        <div className="flex flex-wrap gap-2">
                            {target && (
                                <Button asChild size="lg"><Link href={`/watch/${target.id}`}><Play className="fill-current" /> {target.progress && !target.progress.completed ? t.resume : t.play}</Link></Button>
                            )}
                            <Button size="lg" variant="secondary" onClick={() => router.post(`/title/${item.id}/list`, {}, { preserveScroll: true })}>
                                {item.in_list ? <Check /> : <Plus />} {item.in_list ? t.inList : t.addToList}
                            </Button>
                            {target && (
                                <Button size="lg" variant="outline" onClick={() => router.post(`/party/${target.id}`)}><Users /> {t.watchParty}</Button>
                            )}
                            {!profile?.is_kid && !viewAs && (
                                <BlockDialog targets={[
                                    { type: 'item', id: item.id, label: `${t.blocks.thisTitle}: ${item.title}` },
                                    ...seasons.map((s) => ({ type: 'season', id: s.id, label: `${t.blocks.thisSeason}: ${t.season} ${s.number}` })),
                                    ...item.genres.map((g) => ({ type: 'genre', value: g, label: `${t.blocks.types.genre}: ${g}` })),
                                ]} />
                            )}
                            <div className="flex gap-1">
                                <Button size="icon" variant={item.rating === -1 ? 'default' : 'ghost'} onClick={() => rate(-1)} aria-label={t.dislike}><ThumbsDown /></Button>
                                <Button size="icon" variant={item.rating === 1 ? 'default' : 'ghost'} onClick={() => rate(1)} aria-label={t.like}><ThumbsUp /></Button>
                                <Button size="icon" variant={item.rating === 2 ? 'default' : 'ghost'} onClick={() => rate(2)} aria-label={t.love}><Heart /></Button>
                            </div>
                        </div>
                        {file && (
                            <div className="grid gap-1 text-sm text-muted-foreground">
                                <div><span className="text-foreground">{t.audio}:</span> {langs(file)}</div>
                                {file.subtitles.length > 0 && <div><span className="text-foreground">{t.subtitles}:</span> {file.subtitles.map((s) => s.label).join(' · ')}</div>}
                            </div>
                        )}
                        {item.cast.length > 0 && (
                            <div className="text-sm text-muted-foreground" dir="auto"><span className="text-foreground">{t.cast}:</span> {item.cast.slice(0, 8).map((c) => c.name).join('، ')}</div>
                        )}
                    </div>
                </div>
            </section>
            {seasons.length > 0 && (
                <section className="px-4 pb-16 md:px-10">
                    <Tabs defaultValue={String(seasons[0].number)}>
                        <TabsList className="max-w-full overflow-x-auto scrollbar-none">
                            {seasons.map((s) => <TabsTrigger key={s.number} value={String(s.number)}>{t.season} {s.number}</TabsTrigger>)}
                        </TabsList>
                        {seasons.map((s) => (
                            <TabsContent key={s.number} value={String(s.number)} className="grid gap-2">
                                {s.episodes.map((e) => (
                                    <Link key={e.id} href={`/watch/${e.file.id}`} className="flex items-center gap-4 rounded-lg p-3 hover:bg-accent focus-visible:bg-accent">
                                        <span className="w-8 text-center text-2xl font-bold text-muted-foreground">{e.number}</span>
                                        <div className="min-w-0 flex-1">
                                            <div dir="auto" className="font-semibold">{e.title ?? `${t.episode} ${e.number}`}</div>
                                            {e.overview && <p dir="auto" className="line-clamp-2 text-sm text-muted-foreground">{e.overview}</p>}
                                            {e.file.progress && !e.file.progress.completed && <Progress className="mt-2 h-1" value={(e.file.progress.position / e.file.duration) * 100} />}
                                        </div>
                                        <span className="text-sm text-muted-foreground">{formatTime(e.file.duration)}</span>
                                        <Play className="size-5" />
                                    </Link>
                                ))}
                            </TabsContent>
                        ))}
                    </Tabs>
                </section>
            )}
        </MovexaLayout>
    );
}
