import { router } from '@inertiajs/react';
import { RotateCw, ShieldAlert, Trash2 } from 'lucide-react';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import AdminLayout, { Section } from '@/layouts/admin-layout';
import { useT } from '@/lib/i18n';

type Job = { id: number; original_name: string; source: string; status: string; reason: string | null; threat: string | null; detected_mime: string | null; sanitized: boolean; purge_after: string | null; created_at: string; can_retry: boolean };
type Event = { id: number; file_name: string; sha256: string | null; stage: string; result: string; detail: string | null; created_at: string };

export default function Quarantine({ jobs, recent, events }: { jobs: Job[]; recent: Job[]; events: Event[] }) {
    const { t } = useT();
    return (
        <AdminLayout title={t.adminUi.quarantine}>
            <Alert className="mb-6"><ShieldAlert /><AlertDescription>{t.adminUi.securityNote}</AlertDescription></Alert>
            <Section title={t.adminUi.quarantine}>
                {jobs.length === 0 ? <p className="text-sm text-muted-foreground">—</p> : (
                    <div className="overflow-x-auto"><table className="w-full text-sm">
                        <thead className="text-start text-xs text-muted-foreground"><tr><th className="text-start">{t.name}</th><th className="text-start">{t.adminUi.status}</th><th className="text-start">{t.adminUi.reason}</th><th className="text-start">{t.adminUi.threat}</th><th /></tr></thead>
                        <tbody>{jobs.map((j) => (
                            <tr key={j.id} className="border-t align-top">
                                <td className="py-2" dir="auto">{j.original_name}<div className="text-xs text-muted-foreground" dir="ltr">{j.source} · {j.detected_mime} · {new Date(j.created_at).toLocaleString()}</div></td>
                                <td>{j.status}</td><td dir="ltr" className="text-xs">{j.reason}</td><td className="text-destructive">{j.threat}</td>
                                <td className="flex gap-1 py-2">
                                    {j.can_retry && <Button size="sm" variant="secondary" onClick={() => router.post(`/admin/quarantine/${j.id}/retry`, {}, { preserveScroll: true })}><RotateCw /> {t.adminUi.retry}</Button>}
                                    <Button size="sm" variant="destructive" onClick={() => confirm(t.adminUi.purge + '?') && router.delete(`/admin/quarantine/${j.id}`, { preserveScroll: true })}><Trash2 /> {t.adminUi.purge}</Button>
                                </td>
                            </tr>
                        ))}</tbody>
                    </table></div>
                )}
            </Section>
            <Section title={t.adminUi.recent}>
                <ul className="space-y-1 text-sm">{recent.map((j) => <li key={j.id} className="flex gap-2"><span dir="auto" className="flex-1 truncate">{j.original_name}</span><span>{j.status}</span>{j.sanitized && <span className="text-emerald-400">{t.sanitized}</span>}{j.threat && <span className="text-destructive">{j.threat}</span>}</li>)}</ul>
            </Section>
            <Section title={t.adminUi.events}>
                <div className="overflow-x-auto"><table className="w-full text-xs" dir="ltr"><tbody>
                    {events.map((e) => <tr key={e.id} className="border-t"><td className="py-1 whitespace-nowrap">{new Date(e.created_at).toLocaleString()}</td><td dir="auto">{e.file_name}</td><td>{e.stage}</td><td className={e.result === 'infected' || e.result === 'rejected' ? 'text-destructive' : ''}>{e.result}</td><td>{e.detail}</td><td className="font-mono text-muted-foreground">{e.sha256?.slice(0, 12)}</td></tr>)}
                </tbody></table></div>
            </Section>
        </AdminLayout>
    );
}
