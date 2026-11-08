import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
import { csrf } from '@/lib/i18n';
import type { SharedData } from '@/types';

let echo: Echo<'reverb'> | null = null;

/** Reverb client (only when broadcasting is configured server-side). */
export function getEcho(cfg: SharedData['reverb']): Echo<'reverb'> | null {
    if (!cfg) return null;
    if (!echo) {
        (window as any).Pusher = Pusher;
        echo = new Echo({
            broadcaster: 'reverb', key: cfg.key, wsHost: cfg.host, wsPort: cfg.port, wssPort: cfg.port,
            forceTLS: cfg.scheme === 'https', enabledTransports: ['ws', 'wss'],
            // Private/presence channel auth (/broadcasting/auth) needs Laravel's CSRF token (from the XSRF cookie).
            authorizer: (channel: { name: string }): any => ({
                authorize: (socketId: string, cb: (err: Error | null, data: any) => void) => {
                    fetch('/broadcasting/auth', {
                        method: 'POST', credentials: 'same-origin',
                        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': csrf() },
                        body: JSON.stringify({ socket_id: socketId, channel_name: channel.name }),
                    }).then(async (r) => (r.ok ? cb(null, await r.json()) : cb(new Error(`auth ${r.status}`), null))).catch((e) => cb(e, null));
                },
            }),
        });
    }
    return echo;
}
