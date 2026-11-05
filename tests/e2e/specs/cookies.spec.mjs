// Cookie 與快取隔離（OPS-12）：CMS 與主站後臺 cookie 皆 host-only 且名稱不同；後臺、預覽、訂單為 private／no-store；
// 匿名瀏覽公開內容頁不設定任何 cookie。
import { test, expect } from '@playwright/test';
import { APP_URL, CMS_URL, appUrl, cmsUrl, note } from '../helpers/env.mjs';
import { ADMIN_EMAIL, adminPassword, expectDashboard, loginAdmin, newAdminContext, rememberAdminState } from '../helpers/admin.mjs';
import { loginToCms } from '../helpers/wp.mjs';
import { detailPaths, parseSetCookies, sitemapEntries } from '../helpers/site.mjs';

/** 收集 context 內某主機所有回應的 Set-Cookie（只保留名稱與屬性）。 */
function collectSetCookies(context, host) {
  const list = [];
  context.on('response', (res) => {
    if (new URL(res.url()).host !== host) return;
    for (const c of parseSetCookies(res)) list.push({ ...c, path: new URL(res.url()).pathname });
  });
  return list;
}

test.describe('Cookie 與快取隔離', () => {
  test('CMS 與主站後臺登入 cookie 皆為 host-only、名稱不同、互不送出', { tag: ['@OPS-12'] }, async ({ browser }, testInfo) => {
    const context = await browser.newContext();
    try {
      const cmsCookies = collectSetCookies(context, CMS_URL.host);
      const appCookies = collectSetCookies(context, APP_URL.host);
      const page = await context.newPage();

      await loginToCms(page, 'editor');
      expect(await loginAdmin(page, ADMIN_EMAIL, adminPassword())).toBe(302);
      await expectDashboard(page);
      await rememberAdminState(context);

      expect(cmsCookies.length, 'CMS 登入應設定 cookie').toBeGreaterThan(0);
      expect(appCookies.length, '主站後臺登入應設定 cookie').toBeGreaterThan(0);
      for (const c of [...cmsCookies, ...appCookies]) {
        expect(c.attrs.domain, `${c.name}（${c.path}）不得帶 Domain 屬性`).toBeUndefined();
      }
      const cmsNames = new Set(cmsCookies.filter((c) => c.attrs['max-age'] !== '0' && !/1970/.test(String(c.attrs.expires))).map((c) => c.name));
      const appNames = new Set(appCookies.map((c) => c.name));
      expect([...cmsNames].some((n) => n.startsWith('wordpress_logged_in_')), 'CMS 應設定 WordPress 登入 cookie').toBe(true);
      expect([...appNames].filter((n) => cmsNames.has(n)), 'CMS 與主站 cookie 名稱不得相同').toEqual([]);
      const session = appCookies.find((c) => /session/i.test(c.name));
      expect(session, '主站應有 Session cookie').toBeTruthy();
      expect(session.attrs.httponly, 'Session cookie 應為 HttpOnly').toBe(true);

      // 瀏覽器實際儲存：皆為 host-only，且不會送到另一個主機
      const stored = await context.cookies();
      for (const c of stored) expect(c.domain.startsWith('.'), `${c.name} 應為 host-only（實際網域 ${c.domain}）`).toBe(false);
      const toApp = (await context.cookies(appUrl('/'))).map((c) => c.name);
      const toCms = (await context.cookies(cmsUrl('/'))).map((c) => c.name);
      expect(toApp.filter((n) => n.startsWith('wordpress')), '主站請求不得帶 WordPress cookie').toEqual([]);
      expect(toCms.filter((n) => appNames.has(n)), 'CMS 請求不得帶主站 cookie').toEqual([]);
      note(testInfo, `CMS cookie：${[...cmsNames].map((n) => n.replace(/_[0-9a-f]{32}$/, '_<hash>')).join('、')}；主站 cookie：${[...appNames].join('、')}；皆 host-only`);
    } finally {
      await context.close();
    }
  });

  test('後臺、預覽、訂單回應為 private／no-store', { tag: ['@OPS-12'] }, async ({ browser, request }) => {
    const expectNoStore = (res, label) => {
      const cc = res.headers()['cache-control'] || '';
      expect(cc, `${label} Cache-Control 應含 no-store`).toMatch(/no-store/);
      expect(cc, `${label} Cache-Control 應含 private`).toMatch(/private/);
      expect(cc, `${label} 不得 public`).not.toMatch(/public/);
    };
    expectNoStore(await request.get(appUrl('/admin/login')), '/admin/login');
    expectNoStore(await request.get(appUrl('/admin'), { maxRedirects: 0 }), '/admin（未登入）');
    expectNoStore(await request.get(appUrl('/preview/exchange?ticket=e2e.invalid.ticket')), '/preview/exchange（無效票券）');
    expectNoStore(await request.get(appUrl('/preview/view'), { maxRedirects: 0 }), '/preview/view（無 session）');
    expectNoStore(await request.get(appUrl(`/orders/e2e${Date.now()}`)), '/orders/{token}（猜測 token）');

    const { context, page } = await newAdminContext(browser);
    try {
      for (const p of ['/admin', '/admin/products', '/admin/orders', '/admin/security']) {
        const res = await page.goto(appUrl(p));
        expect(res.status(), `${p} 應可讀取`).toBe(200);
        expectNoStore(res, `${p}（已登入）`);
      }
    } finally {
      await context.close();
    }
  });

  test('匿名瀏覽公開內容頁不設定任何 cookie', { tag: ['@OPS-12'] }, async ({ browser, request }, testInfo) => {
    const { paths } = await sitemapEntries(request);
    const extra = ['/search?q=%E7%A4%BA%E4%BE%8B', '/feed.xml', '/sitemap.xml', `/e2e-missing-${Date.now()}`];
    const targets = [...new Set([...paths, ...extra, ...(await detailPaths(request, '/videos', '/videos')).slice(0, 1)])];
    const context = await browser.newContext();
    try {
      const page = await context.newPage();
      const setters = [];
      page.on('response', (res) => {
        if (new URL(res.url()).host === APP_URL.host && parseSetCookies(res).length) setters.push(new URL(res.url()).pathname);
      });
      for (const p of targets) await page.goto(appUrl(p));
      expect([...new Set(setters)], '公開內容頁不得設定 cookie').toEqual([]);
      expect((await context.cookies()).map((c) => c.name), '瀏覽後瀏覽器內不得有 cookie').toEqual([]);
      note(testInfo, `匿名瀏覽 ${targets.length} 個公開頁：未設定任何 cookie`);
    } finally {
      await context.close();
    }
  });
});
