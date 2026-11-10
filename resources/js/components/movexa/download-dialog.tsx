import { router } from '@inertiajs/react';
import { Download } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { api, useT } from '@/lib/i18n';

type F = { id: number; audio: { language: string | null; title: string | null }[]; subtitles: { id: number; label: string }[] };

export function DownloadDialog({ file, defaultOpen = false }: { file: F; defaultOpen?: boolean }) {
    const { t } = useT();
    const [quality, setQuality] = useState(480);
    const [audio, setAudio] = useState(0);
    const [subs, setSubs] = useState<number[]>(file.subtitles.map((s) => s.id));
    const [err, setErr] = useState<string | null>(null);
    const go = async () => {
        const r = await api<{ message?: string }>('/downloads', 'POST', { file_id: file.id, quality, audio_index: audio, subtitle_ids: subs });
        if (r.ok) router.visit('/downloads');
        else setErr(r.data?.message === 'count_limit' ? t.devices.countLimit : r.data?.message === 'quota' ? t.devices.quota : t.devices.disabled);
    };
    return (
        <Dialog defaultOpen={defaultOpen}>
            <DialogTrigger asChild><Button size="lg" variant="secondary"><Download /> {t.devices.download}</Button></DialogTrigger>
            <DialogContent>
                <DialogHeader><DialogTitle>{t.devices.download}</DialogTitle></DialogHeader>
                <div className="space-y-4 text-sm">
                    <div className="flex gap-2">{[480, 720].map((q) => <Button key={q} variant={quality === q ? 'default' : 'secondary'} onClick={() => setQuality(q)}>{q}p</Button>)}</div>
                    {file.audio.length > 1 && <div className="flex flex-wrap gap-2">{file.audio.map((a, i) => <Button key={i} size="sm" variant={audio === i ? 'default' : 'secondary'} onClick={() => setAudio(i)}>{a.title || a.language || `#${i + 1}`}</Button>)}</div>}
                    {file.subtitles.map((s) => (
                        <label key={s.id} className="flex items-center gap-2"><Checkbox checked={subs.includes(s.id)} onCheckedChange={() => setSubs((x) => (x.includes(s.id) ? x.filter((y) => y !== s.id) : [...x, s.id]))} /> <span dir="auto">{s.label}</span></label>
                    ))}
                    <p className="text-xs text-muted-foreground">{t.devices.offlineNote}</p>
                    {err && <p className="text-destructive">{err}</p>}
                </div>
                <DialogFooter><Button onClick={go}><Download /> {t.devices.download}</Button></DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
