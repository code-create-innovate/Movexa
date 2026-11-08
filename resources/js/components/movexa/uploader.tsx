import { Upload } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Progress } from '@/components/ui/progress';
import { api, csrf, useT } from '@/lib/i18n';

/** Chunked, resumable upload: asks the server where to continue (offset), then PUTs 8 MB chunks. */
export function Uploader({ libraries }: { libraries: { id: number; name: string }[] }) {
    const { t } = useT();
    const [lib, setLib] = useState(libraries[0]?.id);
    const [jobs, setJobs] = useState<{ name: string; pct: number; state: string }[]>([]);

    const send = async (file: File, idx: number) => {
        const upd = (p: Partial<{ pct: number; state: string }>) => setJobs((j) => j.map((x, i) => (i === idx ? { ...x, ...p } : x)));
        const init = await api<{ uuid: string; received: number; chunk_size: number }>('/admin/uploads', 'POST', { library_id: lib, file_name: file.name, size: file.size });
        if (!init.ok) return upd({ state: 'error' });
        let offset = init.data.received;
        while (offset < file.size) {
            const chunk = file.slice(offset, offset + init.data.chunk_size);
            let r: Response;
            try {
                r = await fetch(`/admin/uploads/${init.data.uuid}`, { method: 'PUT', body: chunk, credentials: 'same-origin', headers: { 'X-Upload-Offset': String(offset), 'X-XSRF-TOKEN': csrf(), Accept: 'application/json', 'Content-Type': 'application/octet-stream' } });
            } catch {
                upd({ state: 'retry' });
                await new Promise((res) => setTimeout(res, 3000));
                continue; // network blip → resume from the same offset
            }
            const body = await r.json().catch(() => ({}));
            if (r.status === 409) { offset = body.received; continue; }
            if (!r.ok) return upd({ state: 'error' });
            offset = body.received;
            upd({ pct: (offset / file.size) * 100, state: body.completed ? 'scanning' : t.adminUi.uploading });
        }
    };
    const pick = (files: FileList | null) => {
        if (!files) return;
        const base = jobs.length;
        setJobs((j) => [...j, ...Array.from(files).map((f) => ({ name: f.name, pct: 0, state: 'queued' }))]);
        Array.from(files).reduce((p, f, i) => p.then(() => send(f, base + i)), Promise.resolve());
    };

    return (
        <div className="space-y-3">
            <div className="flex flex-wrap items-center gap-2">
                <select className="h-9 rounded-md border bg-background px-2 text-sm" value={lib} onChange={(e) => setLib(Number(e.target.value))}>
                    {libraries.map((l) => <option key={l.id} value={l.id}>{l.name}</option>)}
                </select>
                <Button asChild variant="secondary" disabled={!lib}>
                    <label className="cursor-pointer"><Upload /> {t.adminUi.upload}<input type="file" multiple className="hidden" accept="video/*,.mkv,.avi,.ts,.m2ts" onChange={(e) => pick(e.target.files)} /></label>
                </Button>
            </div>
            {jobs.map((j, i) => (
                <div key={i} className="text-sm">
                    <div className="flex justify-between"><span dir="auto" className="truncate">{j.name}</span><span className="text-muted-foreground">{j.state}</span></div>
                    <Progress value={j.pct} />
                </div>
            ))}
        </div>
    );
}
