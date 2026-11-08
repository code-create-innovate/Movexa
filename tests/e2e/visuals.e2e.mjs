// E2E: login page loads no protected image, intro once per session (skippable, can be disabled), ambient glow follows
// the scene, pause screen + "ends at" (with 2× speed), long-press 2× on touch (not in watch party), no pause screen on live.
import { createRequire } from 'node:module';
const require = createRequire(import.meta.url);
const { chromium } = require('playwright');

const BASE = process.env.BASE ?? 'http://127.0.0.1:8765';
const FILE = process.env.FILE ?? '1';
const ITEM = process.env.ITEM ?? '1';
const step = (m) => console.log('✓', m);
const fail = (m) => { console.error('✗', m); process.exit(1); };
const browser = await chromium.launch();

async function signIn(ctx, { expectIntro = true } = {}) {
    const p = await ctx.newPage();
    p.on('pageerror', (e) => console.log('  pageerror:', e.message));
    const protectedReqs = [];
    p.on('request', (r) => { if (new URL(r.url()).pathname.startsWith('/m/')) protectedReqs.push(r.url()); });
    await p.goto(`${BASE}/login`);
    await p.waitForLoadState('networkidle');
    if (protectedReqs.length || (await p.locator('img[src*="/m/"]').count())) fail('login page requested a protected image');
    await p.fill('#email', 'admin@e2e.lan');
    await p.fill('#password', 'password-e2e');
    await Promise.all([p.waitForURL('**/profiles'), p.click('button[type=submit]')]);
    await Promise.all([p.waitForURL('**/home'), p.click('button[aria-label="Othman"]')]);
    return p;
}
const xsrf = `decodeURIComponent(document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN=')).split('=')[1])`;
const put = (p, url, body) => p.evaluate(async ([u, b, x]) => (await fetch(u, { method: 'PUT', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': eval(x) }, body: JSON.stringify(b) })).status, [url, body, xsrf]);

try {
    // ---------- intro ----------
    const ctx = await browser.newContext({ viewport: { width: 1280, height: 720 } });
    const page = await signIn(ctx);
    step('login page: gradient only, no protected image requested');
    await page.getByTestId('intro').waitFor({ timeout: 3000 });
    await page.keyboard.press('Space');
    await page.getByTestId('intro').waitFor({ state: 'detached', timeout: 2000 });
    step('intro shown after choosing the profile and skipped with a key');
    await page.goto(`${BASE}/search`);
    await page.goto(`${BASE}/home`);
    await page.waitForTimeout(500);
    if (await page.getByTestId('intro').count()) fail('intro shown twice in one session');
    step('intro only once per session');
    const st = await put(page, '/profile/preferences', { settings: { intro: false } });
    if (st >= 400) fail(`could not disable intro (${st})`);
    const ctx2 = await browser.newContext();
    const p2 = await signIn(ctx2);
    await p2.waitForTimeout(800);
    if (await p2.getByTestId('intro').count()) fail('intro shown although disabled');
    step('intro not shown when disabled in the profile settings');
    await put(p2, '/profile/preferences', { settings: { intro: true } });
    await ctx2.close();

    // ---------- ambient glow ----------
    await page.goto(`${BASE}/watch/${FILE}`);
    await page.waitForFunction(() => { const v = document.querySelector('video'); return v && v.currentTime > 1 && !v.paused; }, null, { timeout: 30000 });
    await page.getByTestId('ambient').waitFor({ timeout: 10000 });
    const c1 = await page.getByTestId('ambient').getAttribute('data-color');
    await page.evaluate(() => { document.querySelector('video').currentTime = 31; });
    await page.waitForFunction((c) => document.querySelector('[data-testid=ambient]')?.getAttribute('data-color') !== c, c1, { timeout: 10000 });
    const c2 = await page.getByTestId('ambient').getAttribute('data-color');
    const rgb = (h) => [1, 3, 5].map((i) => parseInt(h.slice(i, i + 2), 16));
    const [r1, , b1] = rgb(c1), [r2, , b2] = rgb(c2);
    if (!(r1 > b1 && b2 > r2)) fail(`ambient colors not following the scene: ${c1} → ${c2}`);
    step(`ambient light follows the scene (${c1} red part → ${c2} blue part)`);

    // ---------- pause screen ----------
    await page.evaluate(() => { const v = document.querySelector('video'); v.currentTime = 10; v.playbackRate = 2; });
    await page.waitForTimeout(300);
    await page.keyboard.press('k');
    await page.waitForTimeout(4000);
    if (await page.getByTestId('pause-screen').count()) fail('pause screen too early');
    await page.getByTestId('pause-screen').waitFor({ timeout: 3000 });
    const shown = await page.getByTestId('ends-at').textContent();
    const expected = await page.evaluate(() => { const v = document.querySelector('video'); return new Date(Date.now() + ((v.duration - v.currentTime) / v.playbackRate) * 1000).toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' }); });
    if (!shown.includes(expected)) fail(`ends-at "${shown}" ≠ ${expected}`);
    step(`pause screen after 5 s with correct end time at 2× (${expected})`);
    await page.keyboard.press('k');
    await page.getByTestId('pause-screen').waitFor({ state: 'detached', timeout: 3000 });
    step('pause screen disappears on resume');

    // ---------- long press (touch) ----------
    const mctx = await browser.newContext({ viewport: { width: 844, height: 390 }, hasTouch: true, isMobile: true });
    await mctx.addCookies(await ctx.cookies());
    const m = await mctx.newPage();
    const longPress = async (pg) => pg.evaluate(async () => {
        const v = document.querySelector('video');
        const layer = document.querySelector('.touch-none');
        const r = layer.getBoundingClientRect();
        const opt = { pointerType: 'touch', pointerId: 7, isPrimary: true, bubbles: true, clientX: r.left + r.width / 2, clientY: r.top + r.height / 2 };
        layer.dispatchEvent(new PointerEvent('pointerdown', opt));
        await new Promise((res) => setTimeout(res, 800));
        const during = v.playbackRate;
        const indicator = !!document.querySelector('[data-testid=boost]');
        layer.dispatchEvent(new PointerEvent('pointerup', opt));
        await new Promise((res) => setTimeout(res, 100));
        return { during, after: v.playbackRate, indicator };
    });
    await m.goto(`${BASE}/watch/${FILE}`);
    await m.waitForFunction(() => document.querySelector('video')?.readyState >= 1, null, { timeout: 20000 });
    const lp = await longPress(m);
    if (lp.during !== 2 || lp.after !== 1 || !lp.indicator) fail(`long press: ${JSON.stringify(lp)}`);
    step('long press → 2× with indicator, back to 1× on release');
    await m.goto(`${BASE}/title/${ITEM}`);
    await Promise.all([m.waitForURL('**party=**'), m.getByRole('button', { name: 'مشاهدة جماعية' }).click()]);
    await m.waitForFunction(() => document.querySelector('video')?.readyState >= 1, null, { timeout: 20000 });
    const lp2 = await longPress(m);
    if (lp2.during !== 1) fail('long press active in watch party');
    step('long press disabled in watch party (keeps sync)');

    // ---------- live: no pause screen ----------
    await page.goto(`${BASE}/live`);
    await page.getByText('Test Channel').first().click();
    await page.waitForFunction(() => { const v = document.querySelector('video'); return v && v.currentTime > 0.5; }, null, { timeout: 30000 });
    await page.evaluate(() => document.querySelector('video').pause());
    await page.waitForTimeout(6000);
    if (await page.getByTestId('pause-screen').count()) fail('pause screen on live TV');
    step('no pause screen / ambient on live channels');
    step('done');
} catch (e) {
    fail(e.message);
} finally {
    await browser.close();
}
