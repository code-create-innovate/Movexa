import { Head, router, useForm } from '@inertiajs/react';
import { PosterWall } from '@/components/movexa/poster-wall';
import { Lock, Pencil, Plus, Trash2 } from 'lucide-react';
import { type FormEvent, useState } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { ProfileAvatar, switchLanguage } from '@/layouts/movexa-layout';
import { useT } from '@/lib/i18n';
import type { Profile } from '@/types';

const COLORS = ['#e50914', '#2563eb', '#16a34a', '#f59e0b', '#9333ea', '#db2777', '#0891b2'];

export default function SelectProfile({ profiles, currentId, lockedInKid, wall = [] }: { profiles: Profile[]; currentId: number | null; lockedInKid: boolean; wall?: { src: string }[] }) {
    const { t, locale } = useT();
    const [target, setTarget] = useState<Profile | null>(null);
    const [manage, setManage] = useState(false);
    const [editing, setEditing] = useState<Profile | 'new' | null>(null);
    const enter = useForm({ pin: '', parent_pin: '' });

    const choose = (p: Profile) => {
        const needsParent = lockedInKid && p.id !== currentId;
        if (!p.has_pin && !needsParent) return router.post(`/profiles/${p.id}/enter`);
        enter.reset();
        enter.clearErrors();
        setTarget(p);
    };
    const submit = (e: FormEvent) => {
        e.preventDefault();
        enter.post(`/profiles/${target!.id}/enter`, { onSuccess: () => setTarget(null) });
    };

    return (
        <div className="flex min-h-dvh flex-col items-center justify-center gap-10 p-6">
            <Head title={t.whoIsWatching} />
            <PosterWall posters={wall} />
            <button onClick={() => switchLanguage(locale)} className="absolute end-4 top-4 text-sm text-muted-foreground hover:text-foreground">{t.language}</button>
            <h1 className="text-3xl font-bold md:text-5xl">{t.whoIsWatching}</h1>
            {lockedInKid && <p className="text-sm text-muted-foreground">{t.lockedKid}</p>}
            <div className="flex flex-wrap justify-center gap-6">
                {profiles.map((p) => (
                    <div key={p.id} className="flex flex-col items-center gap-2">
                        <button onClick={() => (manage ? setEditing(p) : choose(p))} className="group relative rounded-lg focus-visible:scale-105" aria-label={p.name}>
                            <ProfileAvatar name={p.name} color={p.avatar_color} className="size-24 text-4xl transition group-hover:ring-4 group-hover:ring-foreground md:size-32 md:text-5xl" />
                            {manage && <Pencil className="absolute inset-0 m-auto size-8 text-white drop-shadow" />}
                            {p.has_pin && !manage && <Lock className="absolute bottom-1 end-1 size-4 text-white" />}
                        </button>
                        <span dir="auto" className="text-lg text-muted-foreground">{p.name}</span>
                        {p.is_kid && <span className="rounded bg-secondary px-2 text-xs">{t.kid}</span>}
                    </div>
                ))}
                {manage && profiles.length < 8 && (
                    <button onClick={() => setEditing('new')} className="flex size-24 items-center justify-center rounded-lg border-2 border-dashed md:size-32" aria-label={t.addProfile}><Plus className="size-10" /></button>
                )}
            </div>
            {!lockedInKid && (
                <Button variant="outline" size="lg" onClick={() => setManage(!manage)}>{manage ? t.done : t.manageProfiles}</Button>
            )}

            <Dialog open={!!target} onOpenChange={(o) => !o && setTarget(null)}>
                <DialogContent>
                    <DialogHeader><DialogTitle dir="auto">{target?.name}</DialogTitle></DialogHeader>
                    <form onSubmit={submit} className="space-y-4">
                        {lockedInKid && target?.id !== currentId && (
                            <div className="space-y-2">
                                <Label htmlFor="parent_pin">{t.parentPin}</Label>
                                <Input id="parent_pin" type="password" autoFocus value={enter.data.parent_pin} onChange={(e) => enter.setData('parent_pin', e.target.value)} />
                                <InputError message={enter.errors.parent_pin} />
                            </div>
                        )}
                        {target?.has_pin && (
                            <div className="space-y-2">
                                <Label htmlFor="pin">{t.enterPin}</Label>
                                <Input id="pin" type="password" inputMode="numeric" autoFocus={!lockedInKid} value={enter.data.pin} onChange={(e) => enter.setData('pin', e.target.value)} />
                                <InputError message={enter.errors.pin} />
                            </div>
                        )}
                        <DialogFooter><Button type="submit" disabled={enter.processing}>{t.enter}</Button></DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            {editing && <ProfileEditor profile={editing === 'new' ? null : editing} onClose={() => setEditing(null)} canDelete={profiles.length > 1} />}
        </div>
    );
}

function ProfileEditor({ profile, onClose, canDelete }: { profile: Profile | null; onClose: () => void; canDelete: boolean }) {
    const { t } = useT();
    const form = useForm({
        name: profile?.name ?? '', avatar_color: profile?.avatar_color ?? COLORS[0], is_kid: profile?.is_kid ?? false,
        max_age_rating: String(profile?.max_age_rating ?? 7), pin: '',
    });
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.transform((d) => ({ ...d, max_age_rating: d.is_kid ? Number(d.max_age_rating) : null, ...(d.pin === '' && profile ? {} : { pin: d.pin || null }) }));
        const opts = { onSuccess: onClose, preserveScroll: true };
        profile ? form.patch(`/profiles/${profile.id}`, opts) : form.post('/profiles', opts);
    };
    return (
        <Dialog open onOpenChange={(o) => !o && onClose()}>
            <DialogContent>
                <DialogHeader><DialogTitle>{profile ? t.edit : t.addProfile}</DialogTitle></DialogHeader>
                <form onSubmit={submit} className="space-y-4">
                    <div className="space-y-2">
                        <Label htmlFor="name">{t.name}</Label>
                        <Input id="name" dir="auto" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} required maxLength={60} />
                        <InputError message={form.errors.name} />
                    </div>
                    <div className="flex gap-2">
                        {COLORS.map((c) => (
                            <button type="button" key={c} onClick={() => form.setData('avatar_color', c)} className="size-8 rounded-md ring-offset-2 ring-offset-background data-[on=true]:ring-2" data-on={form.data.avatar_color === c} style={{ background: c }} aria-label={c} />
                        ))}
                    </div>
                    <label className="flex items-center gap-2"><Checkbox checked={form.data.is_kid} onCheckedChange={(v) => form.setData('is_kid', !!v)} /> {t.kid}</label>
                    {form.data.is_kid && (
                        <div className="space-y-2">
                            <Label>{t.maxAge}</Label>
                            <Select value={form.data.max_age_rating} onValueChange={(v) => form.setData('max_age_rating', v)}>
                                <SelectTrigger><SelectValue /></SelectTrigger>
                                <SelectContent>{['0', '7', '12', '13', '16'].map((a) => <SelectItem key={a} value={a}>{a}+</SelectItem>)}</SelectContent>
                            </Select>
                        </div>
                    )}
                    <div className="space-y-2">
                        <Label htmlFor="pin">{t.pin} ({t.optional})</Label>
                        <Input id="pin" type="password" inputMode="numeric" placeholder="4–8" value={form.data.pin} onChange={(e) => form.setData('pin', e.target.value)} />
                        <InputError message={form.errors.pin} />
                    </div>
                    <DialogFooter className="gap-2">
                        {profile && canDelete && (
                            <Button type="button" variant="destructive" onClick={() => router.delete(`/profiles/${profile.id}`, { onSuccess: onClose })}><Trash2 /> {t.delete}</Button>
                        )}
                        <Button type="submit" disabled={form.processing}>{t.save}</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
