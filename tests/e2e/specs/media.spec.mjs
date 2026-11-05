// 影音：YouTube 點擊前零連線、點擊後 youtube-nocookie iframe；Podcast 播放器、片長、下載替代、跳轉；
// 圖片／音訊失效畫面；隱私說明與實際行為一致（DAT-04）。三個 project 都執行。
import { test, expect } from '@playwright/test';
import { APP_URL, CMS_URL, appUrl, note } from '../helpers/env.mjs';
import { YOUTUBE_HOST_RE, detailPaths, parseSetCookies } from '../helpers/site.mjs';

/** 找第一個有可用播放器（data-youtube-player）的影片詳情頁。 */
async function findPlayableVideo(request) {
  for (const p of await detailPaths(request, '/videos', '/videos')) {
    const html = await (await request.get(appUrl(p))).text();
    const m = html.match(/data-embed-src="([^"]+)"/);
    if (m) return { path: p, embedSrc: m[1].replace(/&amp;/g, '&') };
  }
  return null;
}

test.describe('影片嵌入', () => {
  test('影片詳情點擊前不連線 YouTube，點擊後建立 youtube-nocookie iframe', { tag: ['@UI-03', '@DAT-04'] }, async ({ page, request }, testInfo) => {
    const video = await findPlayableVideo(request);
    expect(video, '影片列表中至少有一部可嵌入的影片').toBeTruthy();
    const youtubeRequests = [];
    page.on('request', (req) => {
      const host = new URL(req.url()).hostname;
      if (YOUTUBE_HOST_RE.test(host)) youtubeRequests.push(req.url());
    });
    // 外部播放在 VM 可能無法連線：攔下 YouTube 網域請求（仍會被上方監聽記錄），不依賴外網
    await page.route((url) => YOUTUBE_HOST_RE.test(url.hostname), (route) => route.abort());

    const res = await page.goto(appUrl(video.path));
    expect(res.status()).toBe(200);
    await page.waitForLoadState('networkidle');
    expect(youtubeRequests, '點擊前不得有任何 YouTube 網域請求').toEqual([]);
    await expect(page.locator('iframe')).toHaveCount(0);

    const player = page.locator('[data-youtube-player]').first();
    const start = player.locator('[data-youtube-start]');
    await expect(start).toBeVisible();
    await expect(start).toHaveAttribute('href', /^https:\/\/www\.youtube\.com\/watch\?v=[A-Za-z0-9_-]{11}$/);
    await start.click();
    const iframe = player.locator('iframe');
    await expect(iframe).toHaveCount(1);
    const src = await iframe.getAttribute('src');
    expect(src.startsWith('https://www.youtube-nocookie.com/embed/'), 'iframe 應使用 youtube-nocookie.com').toBe(true);
    expect(src).toMatch(/^https:\/\/www\.youtube-nocookie\.com\/embed\/[A-Za-z0-9_-]{11}(\?|$)/);
    expect(src).toBe(video.embedSrc);
    await expect(iframe).toHaveAttribute('title', /YouTube 影片播放器/);
    await expect(iframe).toHaveAttribute('referrerpolicy', 'strict-origin-when-cross-origin');
    // 點擊後的請求只能是 youtube-nocookie（不得有 youtube.com 追蹤網域被主動載入）
    await expect.poll(() => youtubeRequests.length, { message: '點擊後應嘗試載入 youtube-nocookie' }).toBeGreaterThan(0);
    for (const u of youtubeRequests) expect(new URL(u).hostname).toBe('www.youtube-nocookie.com');
    note(testInfo, '嵌入契約通過、外部播放未驗證（VM 不連外，YouTube 請求已攔截）');
  });
});

test.describe('Podcast 播放器', () => {
  test('Podcast 詳情有 audio、片長、下載替代連結，且可跳轉播放', { tag: ['@UI-07', '@UI-03'] }, async ({ page, request }, testInfo) => {
    const paths = await detailPaths(request, '/podcasts', '/podcasts');
    expect(paths.length, 'Podcast 列表至少有一集').toBeGreaterThan(0);
    let checked = 0;
    for (const p of paths) {
      await page.goto(appUrl(p));
      const audio = page.locator('[data-audio-player] audio');
      if ((await audio.count()) === 0) continue;
      checked += 1;
      await expect(audio).toHaveAttribute('controls', '');
      await expect(audio).toHaveAttribute('preload', 'none');
      const src = await audio.evaluate((a) => a.currentSrc || a.getAttribute('src') || a.querySelector('source')?.getAttribute('src') || '');
      expect(src, '音訊應有來源').not.toBe('');
      const duration = page.locator('[data-audio-player] time').first();
      await expect(duration, '應顯示片長').toBeVisible();
      await expect(duration).toHaveAttribute('datetime', /^PT/);
      const download = page.locator('[data-audio-player] .player__audio-meta a[download]').first();
      await expect(download, '應有下載替代連結').toBeVisible();
      expect(await download.getAttribute('href')).toBe(await audio.evaluate((a) => a.querySelector('source')?.getAttribute('src') || a.getAttribute('src')));
      // 使用者主動播放（靜音避免聲音），再跳轉到第 2 秒，確認 Range 讀取與 seek 可用
      if (checked === 1) {
        const seek = await audio.evaluate(async (a) => {
          a.muted = true;
          await a.play().catch(() => {});
          await new Promise((r) => (a.readyState >= 1 ? r() : a.addEventListener('loadedmetadata', r, { once: true })));
          const target = Math.min(2, Math.max(0, (a.duration || 3) - 0.5));
          await new Promise((r) => {
            a.addEventListener('seeked', r, { once: true });
            a.currentTime = target;
          });
          a.pause();
          return { duration: a.duration, currentTime: a.currentTime, target, error: a.error ? a.error.code : null };
        });
        expect(seek.error, '音訊不應發生錯誤').toBeNull();
        expect(Math.abs(seek.currentTime - seek.target)).toBeLessThan(0.6);
        note(testInfo, `Podcast 播放與跳轉：片長 ${seek.duration.toFixed(1)} 秒，跳到 ${seek.currentTime.toFixed(1)} 秒`);
      }
    }
    expect(checked, '至少一集有可播放的 audio').toBeGreaterThan(0);
  });

  test('音訊來源失效時顯示錯誤訊息並保留下載連結與簡介', { tag: ['@UI-03', '@UI-07'] }, async ({ page, request }) => {
    const [p] = await detailPaths(request, '/podcasts', '/podcasts');
    await page.route(/\.(wav|mp3|m4a|ogg)(\?|$)/i, (route) => route.fulfill({ status: 404, body: '' }));
    await page.goto(appUrl(p));
    const audio = page.locator('[data-audio-player] audio');
    await audio.evaluate((a) => { a.muted = true; a.play().catch(() => {}); });
    await expect(page.locator('[data-audio-error]')).toBeVisible({ timeout: 15_000 });
    await expect(page.locator('[data-audio-player] .player__audio-meta a[download]').first()).toBeVisible();
    await expect(page.locator('main')).toContainText(/\S{10,}/);
  });
});

test.describe('失效與空狀態畫面', () => {
  test('圖片載入失敗顯示明確的失效狀態，不留破圖', { tag: ['@UI-03'] }, async ({ page }) => {
    await page.route(/\/wp-content\/uploads\/.*\.(png|jpe?g|gif|webp)(\?|$)/i, (route) => route.abort());
    await page.goto(appUrl('/articles'));
    const imgs = page.locator('img[data-media-img]');
    test.skip((await imgs.count()) === 0, '列表沒有封面圖片可測');
    await expect(page.locator('.media-frame.is-broken').first()).toBeVisible({ timeout: 15_000 });
  });

  test('404 與無搜尋結果有明確畫面', { tag: ['@UI-03'] }, async ({ page }) => {
    const res = await page.goto(appUrl(`/articles/e2e-not-found-${Date.now()}`));
    expect(res.status()).toBe(404);
    await expect(page.getByRole('heading', { level: 1 })).toContainText('找不到');
    await expect(page.locator('main').getByRole('link', { name: '回首頁', exact: true })).toBeVisible();
    await expect(page.locator('main')).toContainText('請求編號');
    await page.goto(appUrl(`/search?q=${encodeURIComponent(`查無此字${Date.now()}`)}`));
    await expect(page.locator('main [role="status"]')).toContainText('找不到符合');
  });
});

test.describe('隱私說明與實際行為一致', () => {
  test('隱私說明列出 youtube-nocookie 與實際設定的 cookie 名稱', { tag: ['@DAT-04'] }, async ({ page, browser }) => {
    await page.goto(appUrl('/'));
    await page.locator('footer').getByRole('link', { name: '隱私說明' }).click();
    await expect(page).toHaveURL(/\/pages\/privacy$/);
    const text = await page.locator('main').innerText();
    expect(text).toContain('youtube-nocookie.com');
    expect(text).toMatch(/點擊播放/);
    // 實際觀察主站會設定的 cookie（後臺登入頁的 Session 與 CSRF；預覽 cookie 名稱見預覽測試）
    const ctx = await browser.newContext();
    try {
      const p = await ctx.newPage();
      const res = await p.goto(appUrl('/admin/login'));
      const names = (await parseSetCookies(res)).map((c) => c.name);
      expect(names.length).toBeGreaterThan(0);
      for (const n of [...new Set([...names, 'mysite_preview'])]) expect(text, `隱私說明應列出 cookie「${n}」`).toContain(n);
      const hosts = new Set();
      p.on('request', (r) => hosts.add(new URL(r.url()).host));
      await p.goto(appUrl('/'));
      await p.waitForLoadState('networkidle');
      const third = [...hosts].filter((h) => h !== APP_URL.host && h !== CMS_URL.host);
      expect(third, '首頁不得連線第三方（分析、廣告、字型等）').toEqual([]);
    } finally {
      await ctx.close();
    }
  });
});
