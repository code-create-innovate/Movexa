// On-device storage for downloads: video in OPFS (fallback: Cache API), metadata/subtitles/poster in IndexedDB.
// public/offline.js reads the same structures to play without any server.
export type LocalDownload = {
    uuid: string; title: string; episode: { season: number | null; number: number } | null; duration: number | null; expires_at: string;
    position: number; storage: 'opfs' | 'cache'; size: number; poster?: Blob | null; subtitles: { label: string; language: string | null; text: string }[];
};
const DB = 'movexa-offline';
const CACHE = 'movexa-downloads';

function db(): Promise<IDBDatabase> {
    return new Promise((res, rej) => {
        const r = indexedDB.open(DB, 1);
        r.onupgradeneeded = () => r.result.createObjectStore('downloads', { keyPath: 'uuid' });
        r.onsuccess = () => res(r.result);
        r.onerror = () => rej(r.error);
    });
}
async function tx<T>(mode: IDBTransactionMode, fn: (s: IDBObjectStore) => IDBRequest<T>): Promise<T> {
    const d = await db();
    return new Promise((res, rej) => {
        const r = fn(d.transaction('downloads', mode).objectStore('downloads'));
        r.onsuccess = () => res(r.result);
        r.onerror = () => rej(r.error);
    });
}
export const listLocal = () => tx<LocalDownload[]>('readonly', (s) => s.getAll() as IDBRequest<LocalDownload[]>);
export const getLocal = (uuid: string) => tx<LocalDownload | undefined>('readonly', (s) => s.get(uuid) as IDBRequest<LocalDownload | undefined>);
export const putLocal = (d: LocalDownload) => tx('readwrite', (s) => s.put(d));

export async function removeLocal(uuid: string) {
    await tx('readwrite', (s) => s.delete(uuid));
    try { await (await navigator.storage.getDirectory()).removeEntry(`dl-${uuid}.mp4`); } catch { /* not in OPFS */ }
    try { await (await caches.open(CACHE)).delete(`/offline-video/${uuid}`); } catch { /* not cached */ }
}

/** Streams the signed video URL straight to disk with progress. */
export async function saveVideo(uuid: string, url: string, onProgress: (pct: number) => void): Promise<{ storage: 'opfs' | 'cache'; size: number }> {
    const r = await fetch(url, { credentials: 'same-origin' });
    if (!r.ok || !r.body) throw new Error(`download failed ${r.status}`);
    const total = Number(r.headers.get('Content-Length')) || 0;
    let got = 0;
    const counter = new TransformStream<Uint8Array, Uint8Array>({ transform(chunk, c) { got += chunk.byteLength; if (total) onProgress((got / total) * 100); c.enqueue(chunk); } });
    const body = r.body.pipeThrough(counter);
    try {
        const dir = await navigator.storage.getDirectory();
        const fh = await dir.getFileHandle(`dl-${uuid}.mp4`, { create: true });
        const w = await (fh as any).createWritable();
        await body.pipeTo(w);
        return { storage: 'opfs', size: got };
    } catch {
        // Safari without OPFS writable streams → Cache API
        const res = await fetch(url, { credentials: 'same-origin' });
        const blob = await res.blob();
        await (await caches.open(CACHE)).put(`/offline-video/${uuid}`, new Response(blob, { headers: { 'Content-Type': 'video/mp4' } }));
        onProgress(100);
        return { storage: 'cache', size: blob.size };
    }
}

export async function storageEstimate(): Promise<{ usage: number; quota: number } | null> {
    try { const e = await navigator.storage.estimate(); await navigator.storage.persist?.(); return { usage: e.usage ?? 0, quota: e.quota ?? 0 }; } catch { return null; }
}
