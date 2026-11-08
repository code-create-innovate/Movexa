// Movexa service worker.
// - Caches the static app shell (/build, /icons) and the standalone offline player (/offline.html, /offline.js).
// - Never caches media (/m/...), API calls or app pages (they need a live, authenticated session).
// - When a navigation fails (no network), shows /offline.html, which plays downloads saved on the device.
const CACHE = 'movexa-shell-v2';
const PRECACHE = ['/offline.html', '/offline.js', '/icons/icon.svg', '/icons/icon-192.png', '/manifest.webmanifest'];
self.addEventListener('install', (e) => { self.skipWaiting(); e.waitUntil(caches.open(CACHE).then((c) => c.addAll(PRECACHE))); });
self.addEventListener('activate', (e) => e.waitUntil(caches.keys().then((keys) => Promise.all(keys.filter((k) => k.startsWith('movexa-shell') && k !== CACHE).map((k) => caches.delete(k)))).then(() => self.clients.claim())));
self.addEventListener('fetch', (e) => {
    const url = new URL(e.request.url);
    if (e.request.method !== 'GET' || url.origin !== location.origin) return;
    if (url.pathname === '/offline.html' || url.pathname === '/offline.js') {
        e.respondWith(fetch(e.request).then((res) => { if (res.ok) caches.open(CACHE).then((c) => c.put(e.request, res.clone())); return res; }).catch(() => caches.match(url.pathname)));
        return;
    }
    if (url.pathname.startsWith('/build/') || url.pathname.startsWith('/icons/')) {
        e.respondWith(caches.match(e.request).then((hit) => hit || fetch(e.request).then((res) => { if (res.ok) caches.open(CACHE).then((c) => c.put(e.request, res.clone())); return res; })));
        return;
    }
    if (e.request.mode === 'navigate') {
        e.respondWith(fetch(e.request).catch(() => caches.match('/offline.html')));
    }
});
