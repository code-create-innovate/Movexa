import { useEffect, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import MovexaLayout from '@/layouts/movexa-layout';
import { api, useT } from '@/lib/i18n';

/** Phone side of TV sign-in: shows device name + code, then needs an explicit "Approve". */
export default function Link({ code: initial }: { code: string }) {
    const { t } = useT();
    const [code, setCode] = useState(initial);
    const [info, setInfo] = useState<{ code: string; device_name: string } | null>(null);
    const [msg, setMsg] = useState<string | null>(null);
    const [done, setDone] = useState(false);
    const lookup = async (c: string) => {
        setMsg(null);
        const r = await api<{ code: string; device_name: string }>(`/device-login/lookup?code=${encodeURIComponent(c)}`);
        r.ok ? setInfo(r.data) : (setInfo(null), setMsg(t.devices.notFound));
    };
    useEffect(() => { if (initial.length === 6) lookup(initial); }, [initial]); // eslint-disable-line react-hooks/exhaustive-deps
    const approve = async () => {
        const r = await api('/device-login/approve', 'POST', { code: info!.code });
        r.ok ? setDone(true) : setMsg(t.devices.notFound);
    };
    return (
        <MovexaLayout title={t.devices.tvTitle}>
            <div className="mx-auto max-w-md space-y-6 p-6">
                <h1 className="text-2xl font-bold">{t.devices.tvTitle}</h1>
                {done ? <p className="text-emerald-400">{t.devices.approved}</p> : info ? (
                    <div className="space-y-4 rounded-lg border bg-card p-4">
                        <p>{t.devices.approveQ}</p>
                        <p className="text-xl font-bold" dir="auto">{info.device_name}</p>
                        <p dir="ltr" className="text-center font-mono text-4xl font-black tracking-[0.3em]">{info.code}</p>
                        <Button size="lg" className="w-full" onClick={approve}>{t.devices.approve}</Button>
                    </div>
                ) : (
                    <form className="space-y-3" onSubmit={(e) => { e.preventDefault(); lookup(code); }}>
                        <label className="block text-sm">{t.devices.enterCode}</label>
                        <Input dir="ltr" autoFocus maxLength={6} className="text-center font-mono text-2xl tracking-[0.3em] uppercase" value={code} onChange={(e) => setCode(e.target.value.toUpperCase())} />
                        <Button type="submit" disabled={code.length !== 6}>{t.enter}</Button>
                    </form>
                )}
                {msg && <p className="text-destructive">{msg}</p>}
            </div>
        </MovexaLayout>
    );
}
