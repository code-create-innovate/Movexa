import { router } from '@inertiajs/react';
import { Ban, TriangleAlert } from 'lucide-react';
import { useEffect, useState } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { api, useT } from '@/lib/i18n';

export type BlockTarget = { type: string; id?: number | null; value?: string | null; label: string };
type Options = { profiles: { id: number; name: string; account: string; has_pin: boolean }[]; users: { id: number; name: string }[]; warning_no_pin: boolean };

/** "Block from…" — choose what (title / season / genre / channel …) and from whom, optional end date, confirm with password/PIN. */
export function BlockDialog({ targets, trigger }: { targets: BlockTarget[]; trigger?: React.ReactNode }) {
    const { t } = useT();
    const [open, setOpen] = useState(false);
    const [opts, setOpts] = useState<Options | null>(null);
    const [target, setTarget] = useState(0);
    const [profiles, setProfiles] = useState<number[]>([]);
    const [users, setUsers] = useState<number[]>([]);
    const [until, setUntil] = useState('');
    const [secret, setSecret] = useState('');
    const [errors, setErrors] = useState<Record<string, string>>({});
    useEffect(() => { if (open && !opts) api<Options>('/blocks/options').then((r) => r.ok && setOpts(r.data)); }, [open, opts]);
    const toggle = (list: number[], set: (x: number[]) => void, id: number) => set(list.includes(id) ? list.filter((x) => x !== id) : [...list, id]);
    const submit = () => {
        const tg = targets[target];
        router.post('/blocks', { target_type: tg.type, target_id: tg.id ?? null, target_value: tg.value ?? null, profiles, users, expires_at: until || null, secret }, {
            preserveScroll: true, onError: (e) => setErrors(e), onSuccess: () => { setOpen(false); setSecret(''); },
        });
    };
    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>{trigger ?? <Button variant="ghost" size="lg"><Ban /> {t.blocks.blockFrom}</Button>}</DialogTrigger>
            <DialogContent className="max-h-[90vh] overflow-y-auto">
                <DialogHeader><DialogTitle>{t.blocks.blockFrom}</DialogTitle></DialogHeader>
                {!opts ? <p className="text-sm text-muted-foreground">…</p> : (
                    <div className="space-y-4">
                        <div className="space-y-2">
                            <Label>{t.blocks.target}</Label>
                            {targets.map((tg, i) => (
                                <label key={i} className="flex items-center gap-2 text-sm"><input type="radio" checked={target === i} onChange={() => setTarget(i)} /> <span dir="auto">{tg.label}</span></label>
                            ))}
                        </div>
                        <div className="space-y-2">
                            <Label>{t.blocks.profiles}</Label>
                            {opts.profiles.map((p) => (
                                <label key={p.id} className="flex items-center gap-2 text-sm">
                                    <Checkbox checked={profiles.includes(p.id)} onCheckedChange={() => toggle(profiles, setProfiles, p.id)} />
                                    <span dir="auto">{p.name}</span> <span className="text-xs text-muted-foreground" dir="auto">({p.account})</span>
                                    {!p.has_pin && <TriangleAlert className="size-3 text-amber-400" />}
                                </label>
                            ))}
                        </div>
                        <div className="space-y-2">
                            <Label>{t.blocks.accounts}</Label>
                            {opts.users.map((u) => (
                                <label key={u.id} className="flex items-center gap-2 text-sm"><Checkbox checked={users.includes(u.id)} onCheckedChange={() => toggle(users, setUsers, u.id)} /> <span dir="auto">{u.name}</span></label>
                            ))}
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="until">{t.blocks.until} ({t.optional} — {t.blocks.forever})</Label>
                            <Input id="until" type="datetime-local" value={until} onChange={(e) => setUntil(e.target.value)} />
                            <InputError message={errors.expires_at} />
                        </div>
                        {opts.warning_no_pin && <p className="flex gap-2 rounded-md bg-amber-500/10 p-2 text-xs text-amber-300"><TriangleAlert className="size-4 shrink-0" /> {t.blocks.noPinWarning}</p>}
                        <div className="space-y-2">
                            <Label htmlFor="secret">{t.blocks.secret}</Label>
                            <Input id="secret" type="password" value={secret} onChange={(e) => setSecret(e.target.value)} />
                            <InputError message={errors.secret} />
                        </div>
                    </div>
                )}
                <DialogFooter><Button variant="destructive" disabled={!secret || (!profiles.length && !users.length)} onClick={submit}><Ban /> {t.blocks.add}</Button></DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
