// 版面：三種寬度（390／768／1440）與 720×450（等同 1440 的 200% 縮放）下各頁無水平溢位，逐頁截圖。
// 截圖只拍公開的合成示例資料，存 tests/e2e/screenshots/<project>/<頁名>.png（Git 忽略，CI 上傳）；
// 同時記錄實驗室效能量測（本機 VM、合成資料，非真實使用者數據）。
import fs from 'node:fs';
import path from 'node:path';
import { test, expect } from '@playwright/test';
import { APP_BASE, ENV_NAME, SCREENSHOT_DIR, appUrl, note } from '../helpers/env.mjs';
import { detailPaths, expectNoHorizontalOverflow, sitemapEntries } from '../helpers/site.mjs';

/** 具名頁面（動態解析路徑；示例長標題／長網址文章來自 site-content 種子，CI 全新環境同樣存在）。 */
const NAMED_PAGES = [
  { name: 'home', label: '首頁', resolve: async () => '/' },
  { name: 'articles', label: '文章列表', resolve: async () => '/articles' },
  { name: 'videos', label: '影片列表', resolve: async () => '/videos' },
  { name: 'podcasts', label: 'Podcast 列表', resolve: async () => '/podcasts' },
  { name: 'category', label: '分類彙整', resolve: async (ctx) => ctx.sitemap.find((p) => p.startsWith('/categories/')) },
  { name: 'tag', label: '標籤彙整', resolve: async (ctx) => ctx.sitemap.find((p) => p.startsWith('/tags/')) },
  { name: 'article-long-title', label: '文章詳情（超長中文標題）', resolve: async (ctx) => ctx.articles.find((p) => p.includes('long-title')) || ctx.longestTitle },
  { name: 'article-long-url', label: '文章詳情（超長網址）', resolve: async (ctx) => ctx.articles.find((p) => p.includes('long-url')) || ctx.articles[0] },
  { name: 'article-blocks', label: '文章詳情（Gutenberg 區塊）', resolve: async (ctx) => ctx.articles.find((p) => p.includes('gutenberg')) || ctx.articles[0] },
  { name: 'video', label: '影片詳情', resolve: async (ctx) => ctx.videos[0] },
  { name: 'podcast', label: 'Podcast 詳情', resolve: async (ctx) => ctx.podcasts[0] },
  { name: 'search-results', label: '搜尋（有結果）', resolve: async () => '/search?q=%E7%A4%BA%E4%BE%8B' },
  { name: 'search-empty', label: '搜尋（無結果）', resolve: async () => '/search?q=zzqxe2enoresult' },
  { name: 'not-found', label: '404', resolve: async () => '/e2e-layout-not-found' },
  { name: 'about', label: '關於頁', resolve: async (ctx) => ctx.sitemap.find((p) => p === '/pages/about') || ctx.sitemap.find((p) => p.startsWith('/pages/')) },
];

let ctxCache = null;
async function siteContext(request) {
  if (ctxCache) return ctxCache;
  const { paths } = await sitemapEntries(request);
  const articles = await detailPaths(request, '/articles', '/articles');
  // 標題最長的文章（後備）：由列表連結文字判斷
  const html = await (await request.get(appUrl('/articles'))).text();
  const titled = [...html.matchAll(/<a[^>]+href="(\/articles\/[^"]+)"[^>]*>([^<]+)<\/a>/g)].map((m) => ({ p: m[1], len: m[2].trim().length }));
  titled.sort((a, b) => b.len - a.len);
  ctxCache = {
    sitemap: paths,
    articles,
    longestTitle: titled[0]?.p || articles[0],
    videos: await detailPaths(request, '/videos', '/videos'),
    podcasts: await detailPaths(request, '/podcasts', '/podcasts'),
  };
  return ctxCache;
}

/** 實驗室效能量測：導覽時間、LCP、CLS（Chromium PerformanceObserver，buffered）。 */
async function labMetrics(page) {
  return page.evaluate(async () => {
    const nav = performance.getEntriesByType('navigation')[0];
    const lcp = await new Promise((resolve) => {
      let last = null;
      try {
        new PerformanceObserver((list) => { for (const e of list.getEntries()) last = e.startTime; }).observe({ type: 'largest-contentful-paint', buffered: true });
      } catch { /* 不支援時略過 */ }
      setTimeout(() => resolve(last), 300);
    });
    let cls = 0;
    try {
      await new Promise((resolve) => {
        new PerformanceObserver((list) => { for (const e of list.getEntries()) if (!e.hadRecentInput) cls += e.value; }).observe({ type: 'layout-shift', buffered: true });
        setTimeout(resolve, 300);
      });
    } catch { /* 不支援時略過 */ }
    return {
      ttfb_ms: nav ? Math.round(nav.responseStart - nav.requestStart) : null,
      dom_content_loaded_ms: nav ? Math.round(nav.domContentLoadedEventEnd) : null,
      load_ms: nav ? Math.round(nav.loadEventEnd) : null,
      transfer_bytes: nav ? nav.transferSize : null,
      lcp_ms: lcp === null ? null : Math.round(lcp),
      cls: Math.round(cls * 1000) / 1000,
    };
  });
}

function writeShot(dir, name, buffer, meta) {
  fs.mkdirSync(dir, { recursive: true });
  fs.writeFileSync(path.join(dir, `${name}.png`), buffer);
  fs.writeFileSync(path.join(dir, `${name}.json`), JSON.stringify(meta, null, 2));
}

async function checkPage(page, request, def, outDir, testInfo, viewportLabel) {
  const ctx = await siteContext(request);
  const p = await def.resolve(ctx);
  expect(p, `${def.label} 應能從網站動態找到頁面`).toBeTruthy();
  const res = await page.goto(appUrl(p));
  expect(res.status(), `${def.label} HTTP 狀態`).toBe(def.name === 'not-found' ? 404 : 200);
  await page.waitForLoadState('networkidle');
  const overflow = await expectNoHorizontalOverflow(page, `${viewportLabel} ${def.label}`);
  const metrics = await labMetrics(page);
  const shot = await page.screenshot({ fullPage: true });
  writeShot(outDir, def.name, shot, {
    page: def.label,
    path: p,
    app_base: APP_BASE,
    environment: ENV_NAME,
    viewport: page.viewportSize(),
    viewport_label: viewportLabel,
    captured_at: new Date().toISOString(),
    data: '合成示例資料（site-content 種子）；量測為本機 VM 實驗室數據，非真實使用者流量',
    overflow,
    metrics,
  });
  note(testInfo, `${viewportLabel} ${def.label}：文件寬 ${overflow.scrollWidth}/${overflow.viewport}px，LCP ${metrics.lcp_ms ?? '—'}ms，CLS ${metrics.cls}`);
}

test.describe('版面：無水平溢位與截圖', () => {
  for (const def of NAMED_PAGES) {
    test(`${def.label}`, { tag: ['@UI-02', '@UI-08'] }, async ({ page, request }, testInfo) => {
      await checkPage(page, request, def, path.join(SCREENSHOT_DIR, testInfo.project.name), testInfo, testInfo.project.name);
    });
  }

  test('sitemap 全部公開頁無水平溢位', { tag: ['@UI-02'] }, async ({ page, request }, testInfo) => {
    test.setTimeout(3 * 60_000);
    const { paths } = await sitemapEntries(request);
    expect(paths.length).toBeGreaterThan(0);
    for (const p of paths) {
      const res = await page.goto(appUrl(p));
      expect.soft(res.status(), `${p} 應為 200`).toBe(200);
      await expectNoHorizontalOverflow(page, `${testInfo.project.name} ${p}`);
    }
    note(testInfo, `${testInfo.project.name}：sitemap ${paths.length} 頁檢查完畢`);
  });
});

test.describe('版面：200% 縮放（720×450）', () => {
  // 1440 寬在 200% 縮放下的 CSS 視窗等同 720×450；與 project 無關，只在 desktop-1440 執行一次
  test.use({ viewport: { width: 720, height: 450 }, deviceScaleFactor: 2, isMobile: false, hasTouch: false });
  test.beforeEach(({}, testInfo) => {
    test.skip(testInfo.project.name !== 'desktop-1440', '200% 縮放只在 desktop-1440 執行一次');
  });
  for (const def of NAMED_PAGES) {
    test(`200% 縮放：${def.label}`, { tag: ['@UI-02', '@UI-08'] }, async ({ page, request }, testInfo) => {
      await checkPage(page, request, def, path.join(SCREENSHOT_DIR, 'zoom-200'), testInfo, '720x450@2x');
    });
  }
});
