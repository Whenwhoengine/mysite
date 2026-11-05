// WP 後臺真實編輯流程（區塊編輯器）→ 主站：發布、修改、撤下，量測主站反映秒數。
// 只在 desktop-1440 執行（後臺編輯與視窗寬度無關，避免同一內容重複發布三次）。
// 結束（含失敗）時一律把測試文章改回草稿；不刪除任何文章或媒體。
import { test, expect } from '@playwright/test';
import { APP_BASE, EDITOR_PROJECT, TAKEDOWN_LIMIT_MS, appUrl, note, uniqueToken } from '../helpers/env.mjs';
import { formatHits, pollChecks } from '../helpers/site.mjs';
import { syntheticPng } from '../helpers/png.mjs';
import {
  editorState, ensureDraft, loginToCms, openNewPost, publish, replaceTitle, restStatus, saveChanges, switchToDraft, typeTitleAndBody,
} from '../helpers/wp.mjs';

// 搜尋頁會回顯查詢字串，所以以「有搜尋結果項目」判斷，而不是只找記號
const hasSearchResult = (html) => /class="search-result"/.test(html);
// 搜尋端點有每分鐘 30 次的限流：搜尋條件每 4 秒才查一次
const SEARCH_EVERY_MS = 4000;

test.describe('WP 後臺編輯流程', () => {
  test.beforeEach(({}, testInfo) => {
    test.skip(testInfo.project.name !== EDITOR_PROJECT, `後臺編輯流程與視窗寬度無關，只在 ${EDITOR_PROJECT} 執行`);
  });

  test('編輯者在區塊編輯器發布、修改、撤下文章，主站 60 秒內反映', {
    tag: ['@WP-01', '@WP-08', '@API-01', '@UI-01', '@API-15'],
  }, async ({ page, browser, request }, testInfo) => {
    test.setTimeout(6 * 60_000);
    const token = uniqueToken('e2eed');
    const title = `E2E 編輯流程 ${token}`;
    const editedTitle = `E2E 編輯流程（已修改） ${token}`;
    const paragraph = `這是端到端測試以真實區塊編輯器輸入的段落，記號 ${token}。`;
    const altText = `E2E 合成封面 ${token}`;
    let postId = null;

    // 取得主站頁面：只接受 200 的內容（避免把 429／503 誤判成「已移除」）
    const body200 = async (p) => {
      const res = await request.get(appUrl(p));
      if (res.status() !== 200) throw new Error(`${p} 回 ${res.status()}`);
      return res.text();
    };

    // 匿名訪客的主站分頁（不帶任何 CMS cookie）
    const visitor = await browser.newContext();
    const app = await visitor.newPage();

    try {
      await test.step('編輯者經 CMS 登入並在區塊編輯器輸入內容', async () => {
        await loginToCms(page, 'editor');
        await openNewPost(page);
        await typeTitleAndBody(page, title, [paragraph]);
        postId = (await editorState(page)).id;
        expect(postId, '編輯器應建立文章 ID').toBeGreaterThan(0);
      });

      await test.step('經媒體視窗上傳合成封面並設為精選圖片（原生 REST／nonce／上傳經 gateway）', async () => {
        await page.getByRole('button', { name: /^設定精選圖片$/ }).click();
        const modal = page.locator('.media-modal');
        await expect(modal).toBeVisible();
        const uploadRes = page.waitForResponse((r) => r.request().method() === 'POST' && (r.url().includes('/wp-admin/async-upload.php') || r.url().includes('/wp/v2/media')));
        await modal.locator('input[type="file"]').setInputFiles({ name: `${token}.png`, mimeType: 'image/png', buffer: syntheticPng() });
        expect((await uploadRes).status(), '上傳應成功').toBeLessThan(400);
        const alt = modal.getByLabel(/替代文字|Alternative Text/);
        await expect(alt).toBeVisible({ timeout: 30_000 });
        const saveAlt = page.waitForResponse((r) => r.request().method() === 'POST' && r.url().includes('admin-ajax.php'), { timeout: 15_000 }).catch(() => null);
        await alt.fill(altText);
        await alt.press('Tab'); // 觸發儲存附件中繼資料
        await saveAlt;
        await modal.getByRole('button', { name: /^設定精選圖片$/ }).click();
        await expect(modal).toBeHidden();
        await expect.poll(() => page.evaluate(() => window.wp.data.select('core/editor').getEditedPostAttribute('featured_media')), { message: '精選圖片應已設定' }).toBeGreaterThan(0);
      });

      let detailPath = '';
      await test.step('發布並在主站 60 秒內出現於首頁、列表、詳情、搜尋、RSS 與 sitemap', async () => {
        const state = await publish(page);
        const publishedAt = Date.now();
        expect(state.link.startsWith(`${APP_BASE}/articles/`), `編輯器顯示的連結應指向主站（實際：${state.link.replace(/\?.*/, '')}）`).toBe(true);
        detailPath = new URL(state.link).pathname;

        const hit = await pollChecks({
          詳情: { fn: async () => (await request.get(appUrl(detailPath))).status() === 200 },
          列表: { fn: async () => (await body200('/articles')).includes(token) },
          首頁: { fn: async () => (await body200('/')).includes(token) },
          搜尋: { fn: async () => hasSearchResult(await body200(`/search?q=${token}`)), everyMs: SEARCH_EVERY_MS },
          RSS: { fn: async () => (await body200('/feed.xml')).includes(token) },
          sitemap: { fn: async () => decodeURIComponent(await body200('/sitemap.xml')).includes(decodeURIComponent(detailPath)) },
        }, { startedAt: publishedAt, timeoutMs: TAKEDOWN_LIMIT_MS, what: '發布後主站出現' });
        note(testInfo, `發布後主站出現秒數：${formatHits(hit)}`);

        // 以真實瀏覽器檢視主站詳情：標題、段落、封面替代文字
        const res = await app.goto(appUrl(detailPath));
        expect(res.status()).toBe(200);
        await expect(app.getByRole('heading', { level: 1 })).toHaveText(title);
        await expect(app.locator('main')).toContainText(paragraph);
        await expect(app.locator(`main img[alt="${altText}"]`).first()).toBeVisible();
        await app.goto(appUrl('/articles'));
        await expect(app.getByRole('link', { name: title }).first()).toBeVisible();
      });

      await test.step('修改標題後主站 60 秒內反映（詳情、列表、首頁、搜尋摘要、RSS）', async () => {
        await replaceTitle(page, editedTitle);
        await saveChanges(page);
        const hit = await pollChecks({
          詳情: { fn: async () => (await body200(detailPath)).includes(editedTitle) },
          列表: { fn: async () => (await body200('/articles')).includes(editedTitle) },
          首頁: { fn: async () => (await body200('/')).includes(editedTitle) },
          搜尋: { fn: async () => (await body200(`/search?q=${token}`)).includes(`>${editedTitle}<`), everyMs: SEARCH_EVERY_MS },
          RSS: { fn: async () => (await body200('/feed.xml')).includes(editedTitle) },
        }, { timeoutMs: TAKEDOWN_LIMIT_MS, what: '修改標題後主站反映' });
        note(testInfo, `修改標題後主站反映秒數：${formatHits(hit)}`);
        await app.goto(appUrl(detailPath));
        await expect(app.getByRole('heading', { level: 1 })).toHaveText(editedTitle);
      });

      await test.step('改回草稿（撤下）後主站 60 秒內停止供應：詳情 404、列表／首頁／搜尋／RSS／sitemap 皆移除', async () => {
        const clickedAt = await switchToDraft(page);
        const hit = await pollChecks({
          詳情404: { fn: async () => (await request.get(appUrl(detailPath))).status() === 404 },
          列表: { fn: async () => !(await body200('/articles')).includes(token) },
          首頁: { fn: async () => !(await body200('/')).includes(token) },
          搜尋: { fn: async () => !hasSearchResult(await body200(`/search?q=${token}`)), everyMs: SEARCH_EVERY_MS },
          RSS: { fn: async () => !(await body200('/feed.xml')).includes(token) },
          sitemap: { fn: async () => !decodeURIComponent(await body200('/sitemap.xml')).includes(decodeURIComponent(detailPath)) },
        }, { startedAt: clickedAt, timeoutMs: TAKEDOWN_LIMIT_MS, what: '撤下後主站停止供應' });
        note(testInfo, `撤下秒數（自按下儲存起）：${formatHits(hit)}`);

        const res = await app.goto(appUrl(detailPath));
        expect(res.status()).toBe(404);
        await expect(app.locator('main')).not.toContainText(paragraph);
        await expect(app.locator('main')).not.toContainText(token);
      });
    } finally {
      // 保險：不論成敗都改回草稿（非公開）；只改狀態、不刪除
      if (postId) {
        const status = await restStatus(page.context(), postId).catch(() => null);
        if (status && status !== 'draft') await ensureDraft(page.context(), postId).catch(() => {});
      }
      await visitor.close();
    }
  });
});
