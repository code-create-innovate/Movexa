import { router, useForm } from '@inertiajs/react';
import { AlertTriangle, RefreshCw, Trash2 } from 'lucide-react';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import AdminLayout, { Section } from '@/layouts/admin-layout';
import { useT } from '@/lib/i18n';

type Source = { id: number; name: string; type: string; max_streams: number; refreshed_at: string | null; channels_count: number };
type Channel = { id: number; live_source_id: number; name: string; number: string | null; category: string | null; epg_id: string | null; age_rating: number | null; sort: number; enabled: boolean };
type Rec = { id: number; title: string; starts_at: string; ends_at: string; status: string; error: string | null; channel: { name: string } };

export default function LiveAdmin({ sources, channels, streams, recordings }: { sources: Source[]; channels: Channel[]; streams: { id: number; status: string; restarts: number; channel: { name: string } }[]; recordings: Rec[] }) {
    const { t } = useT();
    const form = useForm({ name: '', type: 'm3u', url: '', epg_url: '', max_streams: 1, legal_ack: false as boolean });
    const rec = useForm({ channel: '', title: '', starts_at: '', ends_at: '' });
    const patch = (c: Channel, d: Partial<Channel>) => router.patch(`/admin/live/channels/${c.id}`, d, { preserveScroll: true });
    return (
        <AdminLayout title={t.adminUi.liveSources}>
            <Section title={t.adminUi.liveSources}>
                <Alert className="mb-4"><AlertTriangle /><AlertDescription>{t.adminUi.legalNote}</AlertDescription></Alert>
                <ul className="mb-4 space-y-2 text-sm">
                    {sources.map((s) => (
                        <li key={s.id} className="flex items-center gap-2 border-t pt-2">
                            <span className="flex-1" dir="auto"><b>{s.name}</b> <span className="text-muted-foreground">({s.type} · {s.channels_count} · tuners {s.max_streams})</span></span>
                            <Button size="sm" variant="secondary" onClick={() => router.post(`/admin/live/sources/${s.id}/refresh`, {}, { preserveScroll: true })}><RefreshCw /> {t.adminUi.refresh}</Button>
                            <Button size="icon" variant="ghost" onClick={() => confirm('?') && router.delete(`/admin/live/sources/${s.id}`)}><Trash2 /></Button>
                        </li>
                    ))}
                </ul>
                <form className="space-y-2" onSubmit={(e) => { e.preventDefault(); form.post('/admin/live/sources', { preserveScroll: true, onSuccess: () => form.reset() }); }}>
                    <div className="flex flex-wrap gap-2">
                        <Input className="w-40" placeholder={t.name} value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} required />
                        <select className="h-9 rounded-md border bg-background px-2 text-sm" value={form.data.type} onChange={(e) => form.setData('type', e.target.value)}>
                            <option value="m3u">M3U / M3U8 (IPTV)</option><option value="hdhomerun">HDHomeRun</option>
                        </select>
                        <Input dir="ltr" className="min-w-64 flex-1" placeholder={form.data.type === 'hdhomerun' ? 'http://192.168.1.50' : 'https://provider/playlist.m3u'} value={form.data.url} onChange={(e) => form.setData('url', e.target.value)} required />
                        <Input dir="ltr" className="min-w-64 flex-1" placeholder="XMLTV EPG URL (optional)" value={form.data.epg_url} onChange={(e) => form.setData('epg_url', e.target.value)} />
                        <Input type="number" min={1} max={16} className="w-20" value={form.data.max_streams} onChange={(e) => form.setData('max_streams', Number(e.target.value))} title="tuners" />
                    </div>
                    <label className="flex items-center gap-2 text-sm"><Checkbox checked={form.data.legal_ack} onCheckedChange={(v) => form.setData('legal_ack', !!v)} /> {t.adminUi.legalAck}</label>
                    {Object.values(form.errors).map((e) => <p key={e} className="text-sm text-destructive">{e}</p>)}
                    <Button type="submit" disabled={!form.data.legal_ack || form.processing}>{t.save}</Button>
                </form>
            </Section>

            <Section title={t.live}>
                <div className="overflow-x-auto"><table className="w-full text-sm"><tbody>
                    {channels.map((c) => (
                        <tr key={c.id} className="border-t">
                            <td className="py-1"><input type="checkbox" checked={c.enabled} onChange={(e) => patch(c, { enabled: e.target.checked })} /></td>
                            <td><input type="number" className="w-14 rounded border bg-background px-1" defaultValue={c.sort} onBlur={(e) => patch(c, { sort: Number(e.target.value) })} /></td>
                            <td dir="auto">{c.number} {c.name}</td>
                            <td><select className="rounded border bg-background px-1" value={c.category ?? ''} onChange={(e) => patch(c, { category: e.target.value || null })}>
                                <option value="">—</option>{Object.entries(t.liveTv.categories).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
                            </select></td>
                            <td><input className="w-12 rounded border bg-background px-1" placeholder="age" defaultValue={c.age_rating ?? ''} onBlur={(e) => patch(c, { age_rating: e.target.value === '' ? null : Number(e.target.value) })} /></td>
                            <td dir="ltr" className="text-xs text-muted-foreground">{c.epg_id}</td>
                        </tr>
                    ))}
                </tbody></table></div>
            </Section>

            <Section title={t.adminUi.recordings}>
                <form className="mb-3 flex flex-wrap gap-2" onSubmit={(e) => { e.preventDefault(); rec.transform(({ channel, ...d }) => d); rec.post(`/admin/live/channels/${rec.data.channel}/record`, { preserveScroll: true, onSuccess: () => rec.reset() }); }}>
                    <select className="h-9 rounded-md border bg-background px-2 text-sm" value={rec.data.channel} onChange={(e) => rec.setData('channel', e.target.value)} required>
                        <option value="">{t.live}</option>{channels.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                    </select>
                    <Input className="w-48" placeholder={t.name} value={rec.data.title} onChange={(e) => rec.setData('title', e.target.value)} required />
                    <Input type="datetime-local" className="w-56" value={rec.data.starts_at} onChange={(e) => rec.setData('starts_at', e.target.value)} required />
                    <Input type="datetime-local" className="w-56" value={rec.data.ends_at} onChange={(e) => rec.setData('ends_at', e.target.value)} required />
                    <Button type="submit">{t.adminUi.record}</Button>
                </form>
                <ul className="space-y-1 text-sm">{recordings.map((r) => (
                    <li key={r.id} className="flex gap-2"><span dir="auto" className="flex-1">{r.channel.name} · {r.title}</span><span className="text-muted-foreground">{new Date(r.starts_at).toLocaleString()}</span><span>{r.status}</span>
                        {r.status === 'scheduled' && <Button size="sm" variant="ghost" onClick={() => router.delete(`/admin/live/recordings/${r.id}`, { preserveScroll: true })}>{t.cancel}</Button>}</li>
                ))}</ul>
                {streams.length > 0 && <p className="mt-3 text-xs text-muted-foreground">{streams.map((s) => `${s.channel.name}: ${s.status} (${s.restarts})`).join(' · ')}</p>}
            </Section>
        </AdminLayout>
    );
}
