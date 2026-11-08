// Standalone offline player (no server, no bundler). Reads what resources/js/lib/offline-store.ts saved.
(function () {
  const open = () => new Promise((res, rej) => { const r = indexedDB.open('movexa-offline', 1); r.onupgradeneeded = () => r.result.createObjectStore('downloads', { keyPath: 'uuid' }); r.onsuccess = () => res(r.result); r.onerror = () => rej(r.error); });
  const store = async (mode) => (await open()).transaction('downloads', mode).objectStore('downloads');
  const req = (r) => new Promise((res, rej) => { r.onsuccess = () => res(r.result); r.onerror = () => rej(r.error); });
  const all = async () => req((await store('readonly')).getAll());
  const put = async (d) => req((await store('readwrite')).put(d));
  const del = async (uuid) => {
    await req((await store('readwrite')).delete(uuid));
    try { await (await navigator.storage.getDirectory()).removeEntry('dl-' + uuid + '.mp4'); } catch (e) {}
    try { await (await caches.open('movexa-downloads')).delete('/offline-video/' + uuid); } catch (e) {}
  };
  async function videoBlob(d) {
    if (d.storage === 'opfs') return (await (await (await navigator.storage.getDirectory()).getFileHandle('dl-' + d.uuid + '.mp4')).getFile());
    const r = await caches.match('/offline-video/' + d.uuid); return r ? r.blob() : null;
  }
  const el = (t, a, ...c) => { const e = document.createElement(t); Object.assign(e, a || {}); c.forEach((x) => e.append(x)); return e; };
  let timer = null;

  async function play(d) {
    const v = document.getElementById('v');
    const blob = await videoBlob(d);
    if (!blob) { alert('الملف غير موجود'); return; }
    v.querySelectorAll('track').forEach((t) => t.remove());
    v.src = URL.createObjectURL(blob);
    (d.subtitles || []).forEach((s, i) => v.append(el('track', { kind: 'subtitles', label: s.label, srclang: s.language || 'und', src: URL.createObjectURL(new Blob([s.text], { type: 'text/vtt' })), default: i === 0 })));
    document.getElementById('player').hidden = false;
    document.getElementById('now').textContent = d.title;
    v.addEventListener('loadedmetadata', () => { if (d.position > 5 && d.position < (v.duration - 10)) v.currentTime = d.position; v.play().catch(() => {}); }, { once: true });
    clearInterval(timer);
    timer = setInterval(() => { if (!v.paused) { d.position = v.currentTime; put(d); } }, 5000); // synced to the server on next connection
    window.scrollTo(0, 0);
  }

  async function render() {
    const list = document.getElementById('list');
    list.textContent = '';
    const items = await all();
    for (const d of items) {
      if (new Date(d.expires_at) < new Date()) { await del(d.uuid); continue; } // expired offline → removed
      const img = el('img', { alt: '' });
      if (d.poster) img.src = URL.createObjectURL(d.poster);
      const btn = el('button', { textContent: 'تشغيل ▶', onclick: () => play(d) });
      btn.dataset.uuid = d.uuid;
      list.append(el('div', { className: 'item' }, img, el('div', { className: 't' }, el('div', { textContent: d.title, dir: 'auto' }), el('div', { className: 'muted', textContent: 'ينتهي ' + new Date(d.expires_at).toLocaleDateString('ar') })), btn));
    }
    if (!items.length) list.append(el('p', { className: 'muted', textContent: 'لا توجد تنزيلات محفوظة على هذا الجهاز.' }));
    const want = location.hash.slice(1);
    const d = items.find((x) => x.uuid === want);
    if (d) play(d);
  }
  render();
})();
