import { usePage } from '@inertiajs/react';
import ar from '@/i18n/ar';
import en from '@/i18n/en';

export type Dict = typeof ar;
const dicts = { ar, en };

export function useT() {
    const { locale } = usePage<{ locale: 'ar' | 'en' }>().props;
    const t = dicts[locale] ?? ar;
    return { t, locale, dir: locale === 'ar' ? 'rtl' : 'ltr' } as const;
}

export function formatTime(sec: number) {
    if (!isFinite(sec) || sec < 0) sec = 0;
    const h = Math.floor(sec / 3600), m = Math.floor((sec % 3600) / 60), s = Math.floor(sec % 60);
    return (h ? `${h}:${String(m).padStart(2, '0')}` : `${m}`) + `:${String(s).padStart(2, '0')}`;
}

export function csrf(): string {
    return decodeURIComponent(document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN='))?.split('=')[1] ?? '');
}

/** JSON fetch with Laravel CSRF + session cookie. */
export async function api<T = unknown>(url: string, method = 'GET', body?: unknown): Promise<{ ok: boolean; status: number; data: T }> {
    const r = await fetch(url, {
        method,
        credentials: 'same-origin',
        headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': csrf(), 'X-Requested-With': 'XMLHttpRequest' },
        body: body !== undefined ? JSON.stringify(body) : undefined,
    });
    let data: any = null;
    try { data = await r.json(); } catch { /* empty */ }
    return { ok: r.ok, status: r.status, data };
}
