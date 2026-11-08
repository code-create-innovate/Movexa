import { Link, router } from '@inertiajs/react';
import { Laptop, QrCode, Tv } from 'lucide-react';
import { Button } from '@/components/ui/button';
import MovexaLayout from '@/layouts/movexa-layout';
import { useT } from '@/lib/i18n';

type D = { id: number; name: string; kind: string; last_seen_at: string | null; current: boolean };

export default function Devices({ devices }: { devices: D[] }) {
    const { t, locale } = useT();
    return (
        <MovexaLayout title={t.devices.myDevices}>
            <div className="mx-auto max-w-2xl space-y-4 p-6">
                <div className="flex items-center gap-2">
                    <h1 className="text-2xl font-bold">{t.devices.myDevices}</h1>
                    <Button asChild variant="secondary" className="ms-auto"><Link href="/link"><QrCode /> {t.devices.signInTv}</Link></Button>
                </div>
                {devices.map((d) => (
                    <div key={d.id} className="flex items-center gap-3 rounded-lg border bg-card p-3">
                        {d.kind === 'tv' ? <Tv /> : <Laptop />}
                        <div className="flex-1">
                            <div dir="auto" className="font-semibold">{d.name} {d.current && <span className="text-xs text-primary">({t.devices.thisDevice})</span>}</div>
                            <div className="text-xs text-muted-foreground">{t.devices.lastSeen}: {d.last_seen_at ? new Date(d.last_seen_at).toLocaleString(locale === 'ar' ? 'ar' : 'en') : '—'}</div>
                        </div>
                        {!d.current && <Button variant="destructive" size="sm" onClick={() => router.delete(`/devices/${d.id}`, { preserveScroll: true })}>{t.devices.revoke}</Button>}
                    </div>
                ))}
            </div>
        </MovexaLayout>
    );
}
