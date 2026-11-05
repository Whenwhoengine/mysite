// 草稿預覽：編輯器「在新分頁中預覽」→ WP 驗證與快照 → 票券交換 → 主站 /preview/view。
// 檢查：精確快照內容、預覽標示、網址不含票券、標頭（no-store／noindex／no-referrer 只有一個）、重播被拒、
// 預覽內容不出現在 RSS／sitemap／搜尋。只在 desktop-1440 執行；草稿全程不公開，結束時仍是草稿，不刪除。
import { test, expect } from '@playwright/test';
import { APP_URL, CMS_URL, EDITOR_PROJECT, appUrl, note, uniqueToken } from '../helpers/env.mjs';
import { headerValues, parseSetCookies } from '../helpers/site.mjs';
import { editorCanvas, editorState, ensureDraft, loginToCms, openNewPost, restStatus, saveDraft, typeTitleAndBody } from '../helpers/wp.mjs';

/**
 * 從編輯器按「檢視 → 在新分頁中預覽」，回傳預覽分頁與過程中的回應（票券只留在記憶體，不輸出）。
 */
async function openPreviewFromEditor(page) {
  const context = page.context();
  const seen = { exchangeUrl: null, exchange: null, view: null, hosts: new Set() };
  const onResponse = (res) => {
    const u = new URL(res.url());
    if (u.host === APP_URL.host && u.pathname === '/preview/exchange' && !seen.exchange) {
      seen.exchangeUrl = res.url();
      seen.exchange = res;
    }
    if (u.host === APP_URL.host && u.pathname === '/preview/view' && res.request().isNavigationRequest()) seen.view = res;
  };
  context.on('response', onResponse);
  try {
    await page.getByRole('region', { name: '編輯器頂端列' }).getByRole('button', { name: /^檢視$|^View$/ }).click();
    const popupPromise = context.waitForEvent('page');
    await page.getByRole('menuitem', { name: /在新分頁中預覽|Preview in new tab/ }).click();
    const popup = await popupPromise;
    popup.on('request', (req) => seen.hosts.add(new URL(req.url()).host));
    await popup.waitForURL((u) => u.host === APP_URL.host && u.pathname === '/preview/view', { timeout: 60_000 });
    await popup.waitForLoadState('load');
    await expect.poll(() => Boolean(seen.view), { message: '應取得 /preview/view 回應' }).toBe(true);
    return { popup, ...seen };
  } finally {
    context.off('response', onResponse);
  }
}

test.describe('草稿預覽', () => {
  test.beforeEach(({}, testInfo) => {
    test.skip(testInfo.project.name !== EDITOR_PROJECT, `後臺預覽流程與視窗寬度無關，只在 ${EDITOR_PROJECT} 執行`);
  });

  test('編輯器預覽經票券交換顯示草稿精確快照，重播失效且不進公開索引', {
    tag: ['@API-11', '@API-12', '@API-13', '@OPS-12'],
  }, async ({ page, browser, request }, testInfo) => {
    test.setTimeout(4 * 60_000);
    const token = uniqueToken('e2epv');
    const title = `E2E 預覽草稿 ${token}`;
    const v1 = `預覽段落第一版 ${token}-v1。`;
    const v2tail = `第二版追加 ${token}-v2`;
    let postId = null;

    try {
      await test.step('編輯者建立草稿並儲存', async () => {
        await loginToCms(page, 'editor');
        await openNewPost(page);
        await typeTitleAndBody(page, title, [v1]);
        const s = await saveDraft(page);
        postId = s.id;
        expect(s.status).toBe('draft');
      });

      let first;
      await test.step('按「在新分頁中預覽」落在 /preview/view，顯示精確快照與預覽標示', async () => {
        first = await openPreviewFromEditor(page);
        const { popup, exchange, view } = first;
        expect(new URL(popup.url()).search, '網址列不得含票券或任何參數').toBe('');
        expect(popup.url()).not.toMatch(/ticket/i);

        // 交換頁：302、清除參數、no-store、no-referrer，cookie 為 host-only、HttpOnly、path=/preview
        expect(exchange.status()).toBe(302);
        expect(exchange.headers().location || '').toMatch(/\/preview\/view$/);
        const cookies = await parseSetCookies(exchange);
        const pv = cookies.find((c) => c.name === 'mysite_preview');
        expect(pv, '交換後應設定預覽 cookie').toBeTruthy();
        expect(pv.attrs.domain, '預覽 cookie 不得帶 Domain（host-only）').toBeUndefined();
        expect(pv.attrs.path).toBe('/preview');
        expect(pv.attrs.httponly).toBe(true);
        for (const res of [exchange, view]) {
          const label = new URL(res.url()).pathname;
          expect(res.headers()['cache-control'] || '', `${label} Cache-Control`).toMatch(/no-store/);
          expect(res.headers()['cache-control'] || '', `${label} Cache-Control`).toMatch(/private/);
          expect(res.headers()['x-robots-tag'] || '', `${label} X-Robots-Tag`).toMatch(/noindex/);
          expect(await headerValues(res, 'referrer-policy'), `${label} Referrer-Policy 只能有一個且為 no-referrer`).toEqual(['no-referrer']);
        }
        expect(view.status()).toBe(200);

        await expect(popup.getByRole('heading', { level: 1 })).toHaveText(title);
        await expect(popup.locator('main')).toContainText(v1);
        await expect(popup.locator('.preview-banner')).toContainText('預覽');
        await expect(popup.locator('meta[name="robots"]')).toHaveAttribute('content', /noindex/);
        // 預覽頁不載入第三方資源（只允許主站與 CMS 媒體）
        const foreign = [...first.hosts].filter((h) => h !== APP_URL.host && h !== CMS_URL.host);
        expect(foreign, '預覽頁不得連線第三方網域').toEqual([]);
      });

      await test.step('同一票券重播被拒，顯示預覽失效畫面', async () => {
        const fresh = await browser.newContext();
        try {
          const p = await fresh.newPage();
          const res = await p.goto(first.exchangeUrl);
          expect(res.status(), '重播票券應回 403').toBe(403);
          await expect(p.locator('body')).toContainText('預覽已失效');
          await expect(p.locator('body')).not.toContainText(v1);
          expect(await headerValues(res, 'referrer-policy')).toEqual(['no-referrer']);
          expect(res.headers()['cache-control'] || '').toMatch(/no-store/);
        } finally {
          await fresh.close();
        }
        // 第一個預覽分頁仍在 preview session 內，可重新整理但不得換成其他版本
        await first.popup.close();
      });

      await test.step('修改正文後再次預覽：顯示新快照（第二版），不混用舊版', async () => {
        const canvas = await editorCanvas(page);
        await canvas.getByText(v1).click();
        await page.keyboard.press('End');
        await page.keyboard.type(v2tail);
        const second = await openPreviewFromEditor(page);
        await expect(second.popup.locator('main')).toContainText(`${v1}${v2tail}`);
        expect(second.exchangeUrl, '每次預覽應簽發不同票券').not.toBe(first.exchangeUrl);
        await second.popup.close();
        const s = await editorState(page);
        expect(s.status, '預覽不應改變草稿狀態').toBe('draft');
      });

      await test.step('預覽內容不出現在主站 RSS、sitemap、搜尋與列表', async () => {
        const feed = await (await request.get(appUrl('/feed.xml'))).text();
        const sitemap = await (await request.get(appUrl('/sitemap.xml'))).text();
        const list = await (await request.get(appUrl('/articles'))).text();
        const searchRes = await request.get(appUrl(`/search?q=${token}`));
        expect(searchRes.status()).toBe(200);
        const search = await searchRes.text();
        expect(feed.includes(token), 'RSS 不得含預覽內容').toBe(false);
        expect(sitemap.includes(token) || /\/preview\//.test(sitemap), 'sitemap 不得含預覽內容或預覽網址').toBe(false);
        expect(list.includes(token), '列表不得含預覽內容').toBe(false);
        expect(/class="search-result"/.test(search), '搜尋不得找到預覽內容').toBe(false);
        note(testInfo, '預覽：票券交換、精確快照、重播 403、第二版快照、RSS／sitemap／搜尋／列表皆無預覽內容');
      });
    } finally {
      if (postId) {
        const status = await restStatus(page.context(), postId).catch(() => null);
        if (status && !['draft', 'auto-draft'].includes(status)) await ensureDraft(page.context(), postId).catch(() => {});
      }
    }
  });
});
