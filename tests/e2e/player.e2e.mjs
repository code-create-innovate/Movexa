// End-to-end: login → profile → open movie → play → seek → change quality → change subtitle.
// Usage: BASE=http://127.0.0.1:8765 ITEM=1 node tests/e2e/player.e2e.mjs
import { createRequire } from 'node:module';
const require = createRequire(import.meta.url);
const { chromium } = require('playwright');

const BASE = process.env.BASE ?? 'http://127.0.0.1:8765';
const ITEM = process.env.ITEM ?? '1';
const SHOTS = process.env.SHOTS ?? '.';
const step = (m) => console.log('✓', m);
const fail = (m) => { console.error('✗', m); process.exit(1); };

// NGINX=1: go through the real nginx on :8088 (movexa.lan → 127.0.0.1, local CA accepted).
const browser = await chromium.launch(process.env.NGINX ? { args: ['--host-resolver-rules=MAP movexa.lan 127.0.0.1', '--no-proxy-server'] } : {});
const page = await browser.newPage({ viewport: { width: 1280, height: 720 }, ignoreHTTPSErrors: !!process.env.NGINX });
page.on('pageerror', (e) => console.error('pageerror', e.message));
try {
    await page.goto(`${BASE}/login`);
    // Unauthenticated media access is refused (no redirect to login, no intended-URL)
    const blocked = await page.evaluate(() => fetch('/m/x.y/stream.mp4', { redirect: 'manual' }).then((r) => r.status));
    if (blocked !== 403) fail(`media without session returned ${blocked}`);
    step(`media without session → ${blocked}`);
    await page.fill('#email', 'admin@e2e.lan');
    await page.fill('#password', 'password-e2e');
    await Promise.all([page.waitForURL('**/profiles'), page.click('button[type=submit]')]);
    step('login');

    await Promise.all([page.waitForURL('**/home'), page.click('button[aria-label="Othman"]')]);
    if ((await page.getAttribute('html', 'dir')) !== 'rtl') fail('default direction should be RTL');
    step('profile selected, UI is RTL');
    await page.screenshot({ path: `${SHOTS}/e2e-home.png` });

    await page.goto(`${BASE}/title/${ITEM}`);
    await page.getByText('فيلم تجريبي').first().waitFor();
    await Promise.all([page.waitForURL('**/watch/**'), page.getByRole('link', { name: /تشغيل|استكمال/ }).first().click()]);
    step('opened player');

    const v = page.locator('video');
    await page.waitForFunction(() => { const el = document.querySelector('video'); return el && el.currentTime > 0.5 && !el.paused; }, null, { timeout: 30000 });
    const method1 = await page.locator('text=تشغيل مباشر').count();
    step(`playing (direct play label: ${method1 > 0})`);

    // Seek via the progress bar (always LTR): click at 50%
    const bar = page.locator('[aria-label="seek"]');
    await page.mouse.move(640, 360);
    const box = await bar.boundingBox();
    await page.mouse.click(box.x + box.width * 0.5, box.y + box.height / 2);
    await page.waitForTimeout(800);
    const t = await v.evaluate((el) => el.currentTime);
    if (t < 15 || t > 28) fail(`seek landed at ${t}`);
    step(`seek to ${t.toFixed(1)}s`);
    if ((await bar.getAttribute('dir')) !== 'ltr') fail('progress bar must be LTR');
    step('progress bar is LTR inside RTL UI');

    // Keyboard: +10s
    const before = await v.evaluate((el) => el.currentTime);
    await page.keyboard.press('ArrowRight');
    await page.waitForTimeout(300);
    const after = await v.evaluate((el) => el.currentTime);
    if (after - before < 8) fail(`ArrowRight moved ${after - before}s`);
    step('keyboard +10s');

    // Change subtitle → English
    await page.mouse.move(640, 300);
    await page.getByRole('button', { name: 'الترجمة' }).click();
    await page.getByRole('menuitem', { name: 'English' }).click();
    await page.waitForFunction(() => Array.from(document.querySelector('video').textTracks).some((tr) => tr.mode === 'showing' && tr.language === 'en'));
    step('subtitle switched to English');

    // Change quality → 480p (server switches to HLS transcode)
    await page.mouse.move(640, 300);
    await page.getByRole('button', { name: 'الإعدادات' }).click();
    await page.getByRole('menuitem', { name: '480p' }).click();
    await page.getByText('تحويل', { exact: true }).waitFor({ timeout: 90000 });
    await page.waitForFunction(() => { const el = document.querySelector('video'); return el && el.currentTime > 20 && !el.paused && el.src.startsWith('blob:'); }, null, { timeout: 90000 });
    const kept = await v.evaluate((el) => el.currentTime);
    if (kept < 20) fail(`quality switch lost the position (${kept}s)`);
    step(`quality 480p → HLS transcode playing via hls.js, position kept (${kept.toFixed(1)}s)`);
    await page.mouse.move(640, 300);
    await page.screenshot({ path: `${SHOTS}/e2e-player.png` });

    // Progress heartbeat saved the position → the title page now offers "Resume"
    await page.waitForTimeout(5500);
    await page.goto(`${BASE}/title/${ITEM}`);
    await page.getByRole('link', { name: 'استكمال' }).waitFor({ timeout: 10000 });
    step('progress saved → Resume shown');
    await page.screenshot({ path: `${SHOTS}/e2e-title.png` });
    if (process.env.EXTRA) {
        for (const [path, name] of [['/admin', 'admin'], ['/admin/quarantine', 'quarantine'], ['/live', 'live']]) {
            await page.goto(BASE + path);
            await page.waitForLoadState('networkidle');
            await page.screenshot({ path: `${SHOTS}/e2e-${name}.png`, fullPage: false });
        }
        await page.goto(`${BASE}/locale/en?back=/home`);
        if ((await page.getAttribute('html', 'dir')) !== 'ltr') fail('English must be LTR');
        step('language switch → English LTR');
        await page.screenshot({ path: `${SHOTS}/e2e-home-en.png` });
        await page.setViewportSize({ width: 390, height: 844 });
        await page.goto(`${BASE}/locale/ar?back=/home`);
        await page.screenshot({ path: `${SHOTS}/e2e-home-mobile.png` });
    }
    step('done');
} catch (e) {
    await page.screenshot({ path: `${SHOTS}/e2e-failure.png` }).catch(() => {});
    fail(e.message);
} finally {
    await browser.close();
}
