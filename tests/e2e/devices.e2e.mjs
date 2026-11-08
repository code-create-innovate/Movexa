// E2E: QR TV sign-in (two devices), offline download playback (network off), phone remote (needs Reverb: REMOTE=1).
import { createRequire } from 'node:module';
const require = createRequire(import.meta.url);
const { chromium } = require('playwright');

const BASE = process.env.BASE ?? 'http://127.0.0.1:8765';
const FILE = process.env.FILE ?? '1';
const step = (m) => console.log('✓', m);
const fail = (m) => { console.error('✗', m); process.exit(1); };
const opts = process.env.NGINX ? { args: ['--host-resolver-rules=MAP movexa.lan 127.0.0.1', '--no-proxy-server', '--ignore-certificate-errors'] } : {};
const browser = await chromium.launch(opts);
const newCtx = (o = {}) => browser.newContext({ viewport: { width: 1280, height: 720 }, ignoreHTTPSErrors: !!process.env.NGINX, ...o });

async function login(ctx) {
    const p = await ctx.newPage();
    p.on('pageerror', (e) => console.log('  pageerror(phone):', e.message));
    await p.goto(`${BASE}/login`);
    await p.fill('#email', 'admin@e2e.lan');
    await p.fill('#password', 'password-e2e');
    await Promise.all([p.waitForURL('**/profiles'), p.click('button[type=submit]')]);
    await Promise.all([p.waitForURL('**/home'), p.click('button[aria-label="Othman"]')]);
    return p;
}
const xsrf = `decodeURIComponent(document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN=')).split('=')[1])`;

try {
    // ---------- QR sign-in ----------
    const tvCtx = await newCtx({ userAgent: 'Mozilla/5.0 (SMART-TV; Linux; Tizen 7.0) AppleWebKit/537.36 Chrome/120 TV Safari/537.36' });
    const tv = await tvCtx.newPage();
    tv.on('pageerror', (e) => console.log('  pageerror(tv):', e.message));
    await tv.goto(`${BASE}/tv`);
    const code = (await tv.getByTestId('tv-code').textContent()).trim();
    if (!/^[A-Z2-9]{6}$/.test(code)) fail(`bad code ${code}`);
    if ((await tv.locator('img[alt="QR"]').count()) !== 1) fail('no QR');
    step(`TV shows QR + code ${code}`);
    await tv.waitForTimeout(3000);
    if (!tv.url().endsWith('/tv')) fail('TV signed in before approval');
    step('no session before approval');

    const phoneCtx = await newCtx({ viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true });
    const phone = await login(phoneCtx);
    await phone.goto(`${BASE}/link?code=${code}`);
    await phone.getByText(code).first().waitFor();
    await phone.getByText('TV').first().waitFor();
    await phone.getByRole('button', { name: 'موافقة' }).click();
    await phone.getByText('تمت الموافقة').waitFor();
    await tv.waitForURL('**/profiles', { timeout: 15000 });
    step('phone approved → TV on profile picker');
    await Promise.all([tv.waitForURL('**/home'), tv.click('button[aria-label="Othman"]')]);
    await phone.goto(`${BASE}/devices`);
    await phone.getByText('TV').first().waitFor();
    step('TV listed in "my devices"');

    // ---------- Remote (needs Reverb) ----------
    if (process.env.REMOTE) {
        await tv.goto(`${BASE}/watch/${FILE}`);
        await tv.waitForFunction(() => { const v = document.querySelector('video'); return v && v.currentTime > 1 && !v.paused; }, null, { timeout: 30000 });
        await phone.goto(`${BASE}/remote`);
        await phone.getByRole('button', { name: /TV/ }).first().click({ timeout: 15000 });
        await phone.getByRole('button', { name: 'play' }).waitFor({ timeout: 15000 });
        await phone.getByRole('button', { name: 'play' }).click(); // currently playing → sends pause
        await tv.waitForFunction(() => document.querySelector('video').paused, null, { timeout: 10000 });
        step('remote: pause applied on the TV');
        const t0 = await tv.evaluate(() => document.querySelector('video').currentTime);
        await phone.getByRole('button', { name: '+10' }).click();
        await tv.waitForFunction((t) => document.querySelector('video').currentTime >= t + 9, t0, { timeout: 10000 });
        step('remote: +10s applied on the TV');

        // Watch party over Reverb: phone creates it, TV joins; pause / seek on one device follows on the other.
        await phone.goto(`${BASE}/title/${process.env.ITEM ?? '1'}`);
        await Promise.all([phone.waitForURL('**/watch/**party=**'), phone.getByRole('button', { name: 'مشاهدة جماعية' }).click()]);
        const url = phone.url();
        await tv.goto(url);
        for (const p of [phone, tv]) await p.waitForFunction(() => { const v = document.querySelector('video'); return v && v.readyState >= 1; }, null, { timeout: 30000 });
        if (await phone.evaluate(() => document.querySelector('video').paused)) await phone.getByRole('button', { name: 'play' }).first().click({ force: true });
        await tv.waitForFunction(() => !document.querySelector('video').paused, null, { timeout: 10000 });
        await phone.waitForTimeout(1500);
        await phone.keyboard.press('k'); // player shortcut: toggle → pause
        await tv.waitForFunction(() => document.querySelector('video').paused, null, { timeout: 10000 });
        step('watch party: play and pause synced between two devices over Reverb');
    }

    // ---------- Offline download ----------
    const r = await phone.evaluate(async ([file, x]) => {
        const res = await fetch('/downloads', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': eval(x) }, body: JSON.stringify({ file_id: Number(file), quality: 480 }) });
        return { status: res.status, body: await res.json() };
    }, [FILE, xsrf]);
    if (r.status !== 201) fail(`download create ${r.status} ${JSON.stringify(r.body)}`);
    const uuid = r.body.uuid;
    await phone.goto(`${BASE}/downloads`);
    await phone.evaluate(() => navigator.serviceWorker.ready);
    await phone.getByRole('button', { name: 'حفظ على الجهاز' }).click({ timeout: 60000 });
    await phone.getByText('محفوظ على الجهاز').waitFor({ timeout: 60000 });
    step('download prepared on server and saved on device');

    await phoneCtx.setOffline(true);
    await phone.goto(`${BASE}/offline.html`);
    await phone.locator(`button[data-uuid="${uuid}"]`).click(); // a real tap (mobile autoplay needs a gesture)
    await phone.waitForFunction(() => { const v = document.querySelector('video'); return v && v.currentTime > 1; }, null, { timeout: 20000 });
    step('plays with the network OFF (OPFS + service worker)');
    await phone.goto(`${BASE}/home`).catch(() => {});
    await phone.getByText('بدون اتصال').first().waitFor({ timeout: 10000 });
    step('offline navigation falls back to the offline page');
    await phone.goto(`${BASE}/offline.html`);
    await phone.locator(`button[data-uuid="${uuid}"]`).click(); // a real tap (mobile autoplay needs a gesture)
    await phone.waitForFunction(() => document.querySelector('video')?.currentTime > 7, null, { timeout: 20000 }); // position saved every 5s
    await phoneCtx.setOffline(false);
    await phone.goto(`${BASE}/downloads`); // sync on reconnect
    await phone.waitForTimeout(2000);
    const pos = await phone.evaluate(async ([file, x]) => {
        const res = await fetch(`/playback/${file}/start`, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': eval(x) }, body: JSON.stringify({ caps: { video: ['h264'], audio: ['aac'], containers: ['mp4'] } }) });
        return (await res.json()).position;
    }, [FILE, xsrf]);
    if (!(pos > 4)) fail(`offline position not synced (${pos})`);
    step(`offline position synced back to the server (${pos.toFixed(1)}s)`);
    step('done');
} catch (e) {
    fail(e.message);
} finally {
    await browser.close();
}
