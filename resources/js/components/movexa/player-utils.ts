export type Caps = { video: string[]; audio: string[]; containers: string[] };

/** What this browser can decode natively (sent to the server to choose direct/remux/transcode). */
export function detectCaps(): Caps {
    const v = document.createElement('video');
    const can = (t: string) => v.canPlayType(t) !== '' || (typeof MediaSource !== 'undefined' && MediaSource.isTypeSupported?.(t));
    const video = [
        ['h264', 'video/mp4; codecs="avc1.640028"'], ['hevc', 'video/mp4; codecs="hvc1.1.6.L120.90"'],
        ['av1', 'video/mp4; codecs="av01.0.08M.08"'], ['vp9', 'video/webm; codecs="vp9"'],
    ].filter(([, t]) => can(t)).map(([k]) => k);
    const audio = [
        ['aac', 'audio/mp4; codecs="mp4a.40.2"'], ['mp3', 'audio/mpeg'], ['ac3', 'audio/mp4; codecs="ac-3"'],
        ['eac3', 'audio/mp4; codecs="ec-3"'], ['opus', 'audio/webm; codecs="opus"'], ['flac', 'audio/flac'],
    ].filter(([, t]) => can(t)).map(([k]) => k);
    const containers = ['mp4', ...(v.canPlayType('video/webm') ? ['webm'] : [])];
    return { video: video.length ? video : ['h264'], audio: audio.length ? audio : ['aac'], containers };
}

export const isApple = () => /iPhone|iPad|iPod|Macintosh/.test(navigator.userAgent) && 'ontouchend' in document || /^((?!chrome|android).)*safari/i.test(navigator.userAgent);

export type ThumbCue = { start: number; end: number; url: string; x: number; y: number; w: number; h: number };

export async function loadThumbnails(vttUrl: string): Promise<ThumbCue[]> {
    const r = await fetch(vttUrl, { credentials: 'same-origin' });
    if (!r.ok) return [];
    const text = await r.text();
    const base = vttUrl.slice(0, vttUrl.lastIndexOf('/') + 1);
    const ts = (s: string) => s.split(':').reduce((a, p) => a * 60 + parseFloat(p), 0);
    const cues: ThumbCue[] = [];
    for (const block of text.split(/\n\n+/)) {
        const m = block.match(/([\d:.]+)\s+-->\s+([\d:.]+)\n(.+)#xywh=(\d+),(\d+),(\d+),(\d+)/);
        if (m) cues.push({ start: ts(m[1]), end: ts(m[2]), url: base + m[3], x: +m[4], y: +m[5], w: +m[6], h: +m[7] });
    }
    return cues;
}

export type RemoteCmd = { seq: number; action: string; value: unknown; expires_at: number };

/** Remote commands: apply only if newer than the last applied sequence and not expired (stale/duplicate → ignored). */
export function acceptRemoteCommand(lastSeq: number, cmd: RemoteCmd, now = Date.now()): boolean {
    return Number.isFinite(cmd.seq) && cmd.seq > lastSeq && cmd.expires_at > now;
}
