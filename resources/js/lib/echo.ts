import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
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
        });
    }
    return echo;
}
