import { Head } from '@inertiajs/react';
import QRCode from 'qrcode';
import { useCallback, useEffect, useRef, useState } from 'react';
import { api, useT } from '@/lib/i18n';

type Start = { code: string; poll_token: string; url: string; expires_at: string };

/** TV sign-in: QR + 6-char code, one-time, 5 minutes, auto-renewed. No posters (no session yet). */
export default function TvLogin() {
    const { t } = useT();
    const [s, setS] = useState<Start | null>(null);
    const [qr, setQr] = useState('');
    const [left, setLeft] = useState(300);
    const timer = useRef<ReturnType<typeof setInterval>>(undefined);

    const start = useCallback(async () => {
        const r = await api<Start>('/device-login/start', 'POST', { name: navigator.userAgent.match(/Tizen|Web0S|webOS|BRAVIA|Android TV/i) ? 'TV' : undefined });
        if (!r.ok) return setTimeout(start, 10000);
        setS(r.data);
        setQr(await QRCode.toDataURL(r.data.url, { width: 360, margin: 1, color: { dark: '#000000', light: '#ffffff' } }));
    }, []);
    useEffect(() => { start(); }, [start]);

    useEffect(() => {
        if (!s) return;
        clearInterval(timer.current);
        timer.current = setInterval(async () => {
            const sec = Math.max(0, Math.round((new Date(s.expires_at).getTime() - Date.now()) / 1000));
            setLeft(sec);
            if (sec <= 0) return start(); // auto-renew
            const r = await api<{ status: string; redirect?: string }>('/device-login/poll', 'POST', { poll_token: s.poll_token });
            if (r.status === 200 && r.data.redirect) window.location.href = r.data.redirect;
            else if (r.status === 410 || r.status === 404) start();
        }, 2500);
        return () => clearInterval(timer.current);
    }, [s, start]);

    return (
        <div className="flex min-h-dvh flex-col items-center justify-center gap-8 bg-linear-to-br from-[#14060a] via-background to-[#0b0b1a] p-8 text-center">
            <Head title={t.devices.tvTitle} />
            <h1 className="text-4xl font-black text-primary">Movexa</h1>
            <h2 className="text-2xl font-bold">{t.devices.tvTitle}</h2>
            {s && (
                <div className="flex flex-col items-center gap-6 md:flex-row md:gap-12">
                    {qr && <img src={qr} alt="QR" className="size-72 rounded-xl bg-white p-3" />}
                    <div className="space-y-3">
                        <div className="text-sm text-muted-foreground">{t.devices.codeLabel}</div>
                        <div dir="ltr" data-testid="tv-code" className="font-mono text-6xl font-black tracking-[0.3em]">{s.code}</div>
                        <div className="text-muted-foreground">{t.devices.expiresIn} {Math.floor(left / 60)}:{String(left % 60).padStart(2, '0')}</div>
                    </div>
                </div>
            )}
            <p className="max-w-xl text-lg text-muted-foreground">{t.devices.tvHint}</p>
        </div>
    );
}
