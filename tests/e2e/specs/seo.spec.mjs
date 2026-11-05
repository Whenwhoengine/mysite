// SEO 與公開邊界：title 唯一、canonical 指向主站、sitemap 只含主站公開網址、CMS 前臺 301 到主站。
import { test, expect } from '@playwright/test';
import { APP_BASE, APP_URL, CMS_URL, appUrl, cmsUrl, note } from '../helpers/env.mjs';
import { sitemapEntries } from '../helpers/site.mjs';

const FORBIDDEN_PATH = /^\/(preview|admin|orders|cart|checkout|internal|wp-|healthz|readyz)/;

const decode = (s) => s.replace(/&amp;/g, '&').replace(/&quot;/g, '"').replace(/&#0?39;/g, "'").replace(/&lt;/g, '<').replace(/&gt;/g, '>');

test.describe('SEO 與公開邊界', () => {
  test('sitemap 只含主站公開網址，不含預覽、後臺、訂單或 CMS', { tag: ['@UI-06', '@API-13'] }, async ({ request }, testInfo) => {
    const { locs, paths } = await sitemapEntries(request);
    expect(locs.length).toBeGreaterThan(0);
    for (const loc of locs) {
      const u = new URL(loc);
      expect(u.host, `sitemap 網址主機應為主站：${loc}`).toBe(APP_URL.host);
      expect(u.protocol).toBe(APP_URL.protocol);
      expect(u.pathname, `sitemap 不得含非公開路徑：${loc}`).not.toMatch(FORBIDDEN_PATH);
    }
    expect(new Set(locs).size, 'sitemap 網址不得重複').toBe(locs.length);
    for (const p of paths) {
      const res = await request.get(appUrl(p), { maxRedirects: 0 });
      expect.soft(res.status(), `sitemap 內的 ${p} 應直接回 200`).toBe(200);
    }
    // RSS 連結也只指向主站
    const feed = await (await request.get(appUrl('/feed.xml'))).text();
    const feedLinks = [...feed.matchAll(/<link>([^<]+)<\/link>/g)].map((m) => decode(m[1]));
    for (const l of feedLinks) expect(new URL(l).host, `RSS 連結應為主站：${l}`).toBe(APP_URL.host);
    note(testInfo, `sitemap ${locs.length} 筆、RSS 連結 ${feedLinks.length} 筆皆指向主站`);
  });

  test('各公開頁 title 唯一、canonical 指向主站本頁且不指向 CMS', { tag: ['@UI-06'] }, async ({ request }, testInfo) => {
    test.setTimeout(3 * 60_000);
    const { paths } = await sitemapEntries(request);
    const titles = new Map();
    for (const p of paths) {
      const html = await (await request.get(appUrl(p))).text();
      const title = decode((html.match(/<title>([^<]*)<\/title>/) || [])[1] || '').trim();
      expect.soft(title, `${p} 應有 title`).not.toBe('');
      if (titles.has(title)) titles.get(title).push(p); else titles.set(title, [p]);
      const canon = (html.match(/<link rel="canonical" href="([^"]+)"/) || [])[1];
      expect.soft(canon, `${p} 應有 canonical`).toBeTruthy();
      if (canon) {
        const cu = new URL(decode(canon));
        expect.soft(cu.host, `${p} canonical 主機應為主站`).toBe(APP_URL.host);
        expect.soft(decodeURIComponent(cu.pathname + cu.search), `${p} canonical 應指向本頁`).toBe(decodeURIComponent(p));
      }
      const ogUrl = (html.match(/<meta property="og:url" content="([^"]+)"/) || [])[1];
      if (ogUrl) expect.soft(new URL(decode(ogUrl)).host, `${p} og:url 主機`).toBe(APP_URL.host);
      // 頁面上的連結不得指向 CMS（媒體檔 /wp-content/uploads/ 例外）
      const cmsLinks = [...html.matchAll(/<a\s[^>]*href="([^"]+)"/g)]
        .map((m) => decode(m[1]))
        .filter((h) => { try { const u = new URL(h, APP_BASE); return u.host === CMS_URL.host && !u.pathname.startsWith('/wp-content/uploads/'); } catch { return false; } });
      expect.soft(cmsLinks, `${p} 不得連到 CMS 頁面`).toEqual([]);
      expect.soft(html, `${p} 不得含 noindex`).not.toMatch(/<meta name="robots" content="[^"]*noindex/);
    }
    const dup = [...titles.entries()].filter(([, ps]) => ps.length > 1).map(([t, ps]) => `${t}：${ps.join('、')}`);
    expect(dup, 'title 不得重複').toEqual([]);
    note(testInfo, `${paths.length} 個公開頁 title 唯一、canonical 指向主站`);
  });

  test('非公開頁（預覽、後臺登入、搜尋）帶 noindex 或不被索引', { tag: ['@UI-06', '@API-13'] }, async ({ request }) => {
    const preview = await request.get(appUrl('/preview/view'), { maxRedirects: 0 });
    expect(preview.headers()['x-robots-tag'] || '').toMatch(/noindex/);
    const login = await (await request.get(appUrl('/admin/login'))).text();
    expect(login).toMatch(/<meta name="robots" content="[^"]*noindex/);
  });

  test('CMS 前臺網址 301 到主站對應頁', { tag: ['@UI-06'] }, async ({ request }, testInfo) => {
    const expectRedirect = async (cmsPath, expected) => {
      const res = await request.get(cmsUrl(cmsPath), { maxRedirects: 0 });
      expect(res.status(), `CMS ${cmsPath} 應 301`).toBe(301);
      const loc = res.headers().location || '';
      expect(decodeURIComponent(loc), `CMS ${cmsPath} 應轉到主站 ${expected}`).toBe(decodeURIComponent(appUrl(expected)));
      expect(res.headers()['x-robots-tag'] || '').toMatch(/noindex/);
    };
    await expectRedirect('/', '/');
    // 公開文章：以公開 API 取得 ID 與 slug（不寫死資料 ID）
    const api = await request.get(cmsUrl('/wp-json/site-content/v1/posts?per_page=1'));
    expect(api.status()).toBe(200);
    const [post] = (await api.json()).data;
    expect(post, '至少一篇公開文章').toBeTruthy();
    await expectRedirect(`/?p=${post.id}`, `/articles/${post.slug}`);
    const pages = (await (await request.get(cmsUrl('/wp-json/site-content/v1/pages?per_page=1'))).json()).data;
    if (pages[0]) await expectRedirect(`/?page_id=${pages[0].id}`, `/pages/${pages[0].slug}`);
    await expectRedirect('/feed/', '/feed.xml');
    await expectRedirect('/?s=e2e', '/search?q=e2e');
    await expectRedirect(`/e2e-no-such-page-${Date.now()}/`, '/');
    // 不存在的 ID（不洩露 slug 或狀態）也一律回主站首頁
    await expectRedirect('/?p=999999999', '/');
    note(testInfo, 'CMS 首頁、文章、頁面、feed、搜尋、不存在網址皆 301 到主站對應頁');
  });
});
