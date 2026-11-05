// 主站共用小工具：sitemap、輪詢、版面溢位、Set-Cookie 解析與內容頁探索（全部動態取得，不寫死資料 ID）。
import { expect } from '@playwright/test';
import { APP_BASE, APP_URL, appUrl } from './env.mjs';

/** 讀 sitemap，回傳主站相對路徑清單（同時回傳原始 loc 供邊界檢查）。 */
export async function sitemapEntries(request) {
  const res = await request.get(appUrl('/sitemap.xml'));
  expect(res.status(), 'sitemap.xml 應可讀取').toBe(200);
  const xml = await res.text();
  const locs = [...xml.matchAll(/<loc>([^<]+)<\/loc>/g)].map((m) => m[1].replace(/&amp;/g, '&').trim());
  const paths = locs
    .map((loc) => {
      try {
        const u = new URL(loc);
        return u.host === APP_URL.host ? u.pathname + u.search : null;
      } catch {
        return null;
      }
    })
    .filter(Boolean);
  return { xml, locs, paths };
}

/** 取得某列表頁上第一個（或符合條件的）詳情連結路徑。 */
export async function detailPaths(request, listPath, prefix) {
  const res = await request.get(appUrl(listPath));
  expect(res.status(), `${listPath} 應可讀取`).toBe(200);
  const html = await res.text();
  const re = new RegExp(`href="(?:${APP_BASE.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')})?(${prefix}/[^"?#]+)"`, 'g');
  return [...new Set([...html.matchAll(re)].map((m) => m[1]))];
}

/**
 * 輪詢直到條件成立，回傳經過毫秒數；超過上限則丟出含實測秒數的錯誤。
 * check() 回傳 truthy 表示成立；可回傳描述字串供失敗訊息使用。
 */
export async function pollUntil(check, { timeoutMs, intervalMs = 1000, what }) {
  const started = Date.now();
  let last = '';
  for (;;) {
    let ok = false;
    try {
      const r = await check();
      ok = r === true || (r && r.ok === true);
      if (r && typeof r === 'object' && r.detail) last = r.detail;
    } catch (e) {
      last = (e && e.message ? e.message : String(e)).split('\n')[0];
    }
    const elapsed = Date.now() - started;
    if (ok) return elapsed;
    if (elapsed >= timeoutMs) {
      throw new Error(`${what}：${(elapsed / 1000).toFixed(1)} 秒內未成立（上限 ${timeoutMs / 1000} 秒）${last ? `；最後狀態：${last}` : ''}`);
    }
    await new Promise((r) => setTimeout(r, intervalMs));
  }
}

export const secs = (ms) => (ms / 1000).toFixed(1);

/**
 * 同時輪詢多個條件，各自記錄第一次成立的毫秒數（自 startedAt 起）。
 * checks：{ 名稱: { fn, everyMs } }；fn 回傳 true 表示成立。everyMs 用來避開搜尋等端點的限流。
 */
export async function pollChecks(checks, { startedAt = Date.now(), timeoutMs, what, tickMs = 500 }) {
  const hit = {};
  const lastRun = {};
  for (;;) {
    const now = Date.now();
    for (const [name, { fn, everyMs = 1000 }] of Object.entries(checks)) {
      if (hit[name] !== undefined || (lastRun[name] && now - lastRun[name] < everyMs)) continue;
      lastRun[name] = now;
      try {
        if (await fn()) hit[name] = Date.now() - startedAt;
      } catch {
        // 單次失敗（例如連線重設）視為尚未成立
      }
    }
    const left = Object.keys(checks).filter((k) => hit[k] === undefined);
    if (left.length === 0) return hit;
    const elapsed = Date.now() - startedAt;
    if (elapsed >= timeoutMs) {
      const done = Object.entries(hit).map(([k, v]) => `${k} ${secs(v)}s`).join('，') || '無';
      throw new Error(`${what}：${secs(elapsed)} 秒內仍未成立：${left.join('、')}（已成立：${done}；上限 ${timeoutMs / 1000} 秒）`);
    }
    await new Promise((r) => setTimeout(r, tickMs));
  }
}

export const formatHits = (hit) => Object.entries(hit).map(([k, v]) => `${k} ${secs(v)}s`).join('，');

/** 版面水平溢位檢查：文件寬度不得超出視窗，且不得有可見元素被推出視窗（捲動容器內的除外）。 */
export async function horizontalOverflow(page) {
  return page.evaluate(() => {
    const doc = document.documentElement;
    const vw = doc.clientWidth;
    const offenders = [];
    const scrollable = (el) => {
      for (let p = el.parentElement; p && p !== document.body && p !== doc; p = p.parentElement) {
        const ox = getComputedStyle(p).overflowX;
        if (ox === 'auto' || ox === 'scroll' || ox === 'hidden' || ox === 'clip') return true;
      }
      return false;
    };
    for (const el of document.body.querySelectorAll('*')) {
      const r = el.getBoundingClientRect();
      if (r.width < 2 || r.height < 2) continue;
      if (r.right <= vw + 1 && r.left >= -1) continue;
      const cs = getComputedStyle(el);
      if (cs.visibility === 'hidden' || cs.display === 'contents') continue;
      if (el.closest('.skip-link, .visually-hidden')) continue;
      if (scrollable(el)) continue;
      const id = el.id ? `#${el.id}` : '';
      const cls = typeof el.className === 'string' && el.className.trim() ? `.${el.className.trim().split(/\s+/).slice(0, 2).join('.')}` : '';
      offenders.push(`${el.tagName.toLowerCase()}${id}${cls}（left ${Math.round(r.left)}，right ${Math.round(r.right)}）`);
      if (offenders.length >= 8) break;
    }
    return { viewport: vw, scrollWidth: doc.scrollWidth, bodyScrollWidth: document.body.scrollWidth, offenders };
  });
}

export async function expectNoHorizontalOverflow(page, label) {
  const o = await horizontalOverflow(page);
  expect.soft(o.scrollWidth, `${label}：文件寬 ${o.scrollWidth}px 超出視窗 ${o.viewport}px`).toBeLessThanOrEqual(o.viewport);
  expect.soft(o.offenders, `${label}：有元素超出視窗`).toEqual([]);
  return o;
}

/** 解析一個回應的全部 Set-Cookie 標頭：[{ name, attrs:{domain,path,httponly,...}, raw }]（raw 不輸出）。 */
export function parseSetCookies(response) {
  return response
    .headersArray()
    .filter((h) => h.name.toLowerCase() === 'set-cookie')
    .flatMap((h) => h.value.split('\n'))
    .map((line) => {
      const [pair, ...attrParts] = line.split(';');
      const name = pair.split('=')[0].trim();
      const attrs = {};
      for (const a of attrParts) {
        const [k, ...v] = a.split('=');
        attrs[k.trim().toLowerCase()] = v.join('=').trim() || true;
      }
      return { name, attrs };
    });
}

/** 標頭出現次數（大小寫不分）。 */
export function headerValues(response, name) {
  return response.headersArray().filter((h) => h.name.toLowerCase() === name.toLowerCase()).map((h) => h.value);
}

/** 把列表頁上的內容連結轉成 { path, title }。 */
export async function listItems(page) {
  return page.locator('main a[href]').evaluateAll((as) => as.map((a) => ({ href: a.getAttribute('href'), text: a.textContent.trim() })));
}

/** 判斷網址是否屬於 YouTube／Google 影音網域。 */
export const YOUTUBE_HOST_RE = /(^|\.)(youtube\.com|youtube-nocookie\.com|youtu\.be|ytimg\.com|googlevideo\.com|ggpht\.com)$/i;
