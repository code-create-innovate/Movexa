// E2E: admin creates a family account from the UI (no live TV, age limit 7), the new person signs in with
// their own username and only sees what they're allowed to.
import { createRequire } from 'node:module';
const require = createRequire(import.meta.url);
const { chromium } = require('playwright');
const BASE = process.env.BASE ?? 'http://127.0.0.1:8765';
const ITEM = process.env.ITEM ?? '1';
const step = (m) => console.log('✓', m);
const fail = (m) => { console.error('✗', m); process.exit(1); };
const browser = await chromium.launch();
const signIn = async (user, pass) => {
    const ctx = await browser.newContext({ viewport: { width: 1280, height: 800 } });
    const p = await ctx.newPage();
    await p.goto(`${BASE}/login`);
    await p.fill('#email', user);
    await p.fill('#password', pass);
    await Promise.all([p.waitForURL('**/home'), p.click('button[type=submit]')]);
    await p.keyboard.press('Escape'); // skip intro
    return p;
};
try {
    const admin = await signIn('admin', 'password-e2e');
    await admin.goto(`${BASE}/admin/users`);
    await admin.getByPlaceholder('الاسم').first().fill('سارة');
    await admin.getByPlaceholder('اسم المستخدم').first().fill('sara');
    await admin.getByPlaceholder('كلمة المرور').first().fill('sara-pass-1');
    const form = admin.locator('form').first();
    await form.locator('[data-perm="live"]').click();       // remove live TV
    await form.getByRole('button', { name: '7+' }).click(); // age limit 7
    await form.getByRole('button', { name: 'إضافة مستخدم' }).click();
    await admin.getByTestId('user-sara').waitFor();
    step('admin created account "sara" (no live TV, age ≤ 7) from the UI');

    const sara = await signIn('sara', 'sara-pass-1');
    step('sara signs in with her own username → straight to home');
    if (await sara.getByRole('link', { name: 'البث المباشر' }).count()) fail('live link visible without permission');
    if (await sara.getByRole('link', { name: 'الإدارة' }).count()) fail('admin link visible');
    const live = await sara.goto(`${BASE}/live`);
    if (live.status() !== 403) fail(`/live → ${live.status()}`);
    const t = await sara.goto(`${BASE}/title/${ITEM}`); // the seed movie is rated 0 → allowed
    if (t.status() !== 200) fail(`allowed title → ${t.status()}`);
    step('no live TV / admin for sara (hidden in UI, 403 on the server); allowed titles open');

    // Admin disables the account → sara is signed out on her next request.
    await admin.goto(`${BASE}/admin/users`);
    const card = admin.getByTestId('user-sara');
    await card.getByRole('switch').last().click();
    await card.getByRole('button', { name: 'حفظ' }).click();
    await admin.waitForTimeout(800);
    await sara.goto(`${BASE}/home`);
    if (!sara.url().includes('/login')) fail('disabled account still signed in');
    step('disabling the account signs sara out immediately');
    step('done');
} catch (e) {
    fail(e.message);
} finally {
    await browser.close();
}
