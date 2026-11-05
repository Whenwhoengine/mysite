// 鍵盤操作、焦點可見、手機選單、減少動態效果（UI-04），以及 axe-core 自動掃描（UI-05，P1）。
// axe 不停用任何規則：有 serious／critical 項目即判 FAIL 並列出規則；摘要另寫入結果目錄。
import fs from 'node:fs';
import path from 'node:path';
import { createRequire } from 'node:module';
import { test, expect } from '@playwright/test';
import { RESULTS_DIR, SIMULATION, appUrl, note } from '../helpers/env.mjs';
import { detailPaths } from '../helpers/site.mjs';

const require = createRequire(import.meta.url);
const AXE_SOURCE = fs.readFileSync(require.resolve('axe-core/axe.min.js'), 'utf8');
const AXE_TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22a', 'wcag22aa'];

/** 目前焦點元素的描述與焦點樣式。 */
const activeInfo = (page) =>
  page.evaluate(() => {
    const el = document.activeElement;
    if (!el || el === document.body) return { tag: 'body' };
    const cs = getComputedStyle(el);
    const r = el.getBoundingClientRect();
    // 焦點色（設計 token --color-focus）轉成 rgb，用來辨認畫在外層容器（:focus-within）的焦點框
    const probe = document.createElement('span');
    probe.style.color = getComputedStyle(document.documentElement).getPropertyValue('--color-focus').trim() || 'transparent';
    document.body.appendChild(probe);
    const focusRgb = getComputedStyle(probe).color;
    probe.remove();
    const own = (cs.boxShadow && cs.boxShadow !== 'none') || (cs.outlineStyle !== 'none' && parseFloat(cs.outlineWidth) > 0);
    let ancestorRing = false;
    for (let p = el.parentElement, i = 0; p && i < 4 && !ancestorRing; p = p.parentElement, i += 1) {
      const ps = getComputedStyle(p);
      ancestorRing = p.matches(':focus-within') && focusRgb !== 'rgba(0, 0, 0, 0)' && ((ps.boxShadow || '').includes(focusRgb) || ((ps.outlineColor || '') === focusRgb && ps.outlineStyle !== 'none'));
    }
    return {
      tag: el.tagName.toLowerCase(),
      id: el.id,
      cls: typeof el.className === 'string' ? el.className : '',
      text: (el.textContent || el.getAttribute('aria-label') || '').trim().slice(0, 30),
      href: el.getAttribute('href'),
      focusVisible: el.matches(':focus-visible'),
      indicator: own || ancestorRing,
      inViewport: r.bottom > 0 && r.top < window.innerHeight && r.right > 0 && r.left < window.innerWidth && r.width > 0 && r.height > 0,
    };
  });

/** 連續按 Tab 直到條件成立；途中每個焦點都必須可見且有焦點樣式。 */
async function tabUntil(page, predicate, max = 40) {
  const path = [];
  for (let i = 0; i < max; i += 1) {
    await page.keyboard.press('Tab');
    const info = await activeInfo(page);
    path.push(info);
    if (info.tag !== 'body') {
      expect(info.focusVisible && info.indicator, `焦點應可見：${info.tag}${info.id ? `#${info.id}` : ''}「${info.text}」`).toBe(true);
    }
    if (predicate(info)) return { info, path };
  }
  throw new Error(`按 ${max} 次 Tab 仍未到達目標；途經：${path.map((p) => p.text || p.tag).join(' → ')}`);
}

const isMobileWidth = (page) => page.viewportSize().width < 768;

test.describe('鍵盤與焦點', () => {
  test('第一個 Tab 聚焦「跳到主要內容」且可見，Enter 後焦點移到主要內容', { tag: ['@UI-04'] }, async ({ page }) => {
    await page.goto(appUrl('/'));
    await page.keyboard.press('Tab');
    const info = await activeInfo(page);
    expect(info.cls).toContain('skip-link');
    expect(info.text).toBe('跳到主要內容');
    expect(info.inViewport, '跳過連結聚焦時應出現在畫面內').toBe(true);
    expect(info.focusVisible && info.indicator, '跳過連結應有可見焦點樣式').toBe(true);
    await page.keyboard.press('Enter');
    await expect.poll(() => page.evaluate(() => document.activeElement?.id)).toBe('main');
  });

  test('只用鍵盤操作主選單前往文章列表', { tag: ['@UI-04'] }, async ({ page }) => {
    await page.goto(appUrl('/'));
    if (isMobileWidth(page)) {
      await tabUntil(page, (i) => (i.cls || '').includes('site-nav__toggle'));
      await page.keyboard.press('Enter');
      await expect(page.locator('[data-site-nav-toggle]')).toHaveAttribute('aria-expanded', 'true');
    }
    const { info } = await tabUntil(page, (i) => (i.cls || '').includes('site-nav__link'));
    expect(info.href).toBeTruthy();
    await Promise.all([page.waitForURL((u) => u.pathname === new URL(info.href, appUrl('/')).pathname), page.keyboard.press('Enter')]);
    await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
  });

  test('只用鍵盤送出頁首搜尋表單', { tag: ['@UI-04'] }, async ({ page }) => {
    await page.goto(appUrl('/'));
    if (isMobileWidth(page)) {
      // 手機寬度：搜尋表單在收合的選單內，先以鍵盤展開
      await tabUntil(page, (i) => (i.cls || '').includes('site-nav__toggle'));
      await page.keyboard.press('Enter');
      await expect(page.locator('[data-site-nav-toggle]')).toHaveAttribute('aria-expanded', 'true');
    }
    await tabUntil(page, (i) => i.id === 'site-search-q');
    await page.keyboard.type('示例');
    await Promise.all([page.waitForURL(/\/search\?q=/), page.keyboard.press('Enter')]);
    await expect(page.locator('#search-q')).toHaveValue('示例');
    await expect(page.locator('main [role="status"]').first()).toBeVisible();
  });

  test('手機寬度選單切換可用；平板與桌面直接顯示選單', { tag: ['@UI-04'] }, async ({ page }) => {
    await page.goto(appUrl('/'));
    const toggle = page.locator('[data-site-nav-toggle]');
    const list = page.locator('.site-nav__list');
    if (isMobileWidth(page)) {
      await expect(toggle).toBeVisible();
      await expect(toggle).toHaveAttribute('aria-expanded', 'false');
      await expect(list).toBeHidden();
      await toggle.click();
      await expect(toggle).toHaveAttribute('aria-expanded', 'true');
      await expect(list).toBeVisible();
      await toggle.click();
      await expect(toggle).toHaveAttribute('aria-expanded', 'false');
      await expect(list).toBeHidden();
      const box = await toggle.boundingBox();
      expect(Math.min(box.width, box.height), '觸控目標至少 24px').toBeGreaterThanOrEqual(24);
    } else {
      await expect(toggle).toBeHidden();
      await expect(list).toBeVisible();
    }
  });

  test('prefers-reduced-motion 生效：動態時間歸零', { tag: ['@UI-04'] }, async ({ browser }, testInfo) => {
    const read = async (reducedMotion) => {
      const { viewport, deviceScaleFactor, isMobile, hasTouch, locale } = testInfo.project.use;
      const ctx = await browser.newContext({ viewport, deviceScaleFactor, isMobile, hasTouch, locale, reducedMotion });
      try {
        const p = await ctx.newPage();
        await p.goto(appUrl('/'));
        return await p.evaluate(() => {
          const el = document.querySelector('.site-nav__link, .button, a');
          return {
            token: getComputedStyle(document.documentElement).getPropertyValue('--duration').trim(),
            transition: getComputedStyle(el).transitionDuration,
          };
        });
      } finally {
        await ctx.close();
      }
    };
    const reduce = await read('reduce');
    const normal = await read('no-preference');
    expect(reduce.token).toMatch(/^0(ms|s)?$/);
    expect(normal.token).not.toMatch(/^0(ms|s)?$/);
    const maxSeconds = (v) => Math.max(...v.split(',').map((x) => (x.trim().endsWith('ms') ? parseFloat(x) / 1000 : parseFloat(x))));
    expect(maxSeconds(reduce.transition), '減少動態時轉場應趨近 0').toBeLessThanOrEqual(0.001);
  });
});

/** axe 掃描一頁並回傳違規摘要。 */
async function axeScan(page) {
  await page.evaluate(AXE_SOURCE);
  return page.evaluate(async (tags) => {
    const r = await window.axe.run(document, { runOnly: { type: 'tag', values: tags }, resultTypes: ['violations'] });
    return {
      version: window.axe.version,
      passes: r.passes.length,
      incomplete: r.incomplete.map((v) => `${v.id}(${v.impact || '—'})`),
      violations: r.violations.map((v) => ({ id: v.id, impact: v.impact, help: v.help, nodes: v.nodes.length, targets: v.nodes.slice(0, 3).map((n) => n.target.join(' ')) })),
    };
  }, AXE_TAGS);
}

const AXE_PAGES = [
  { name: 'home', label: '首頁', path: async () => '/' },
  { name: 'article', label: '文章詳情', path: async (request) => (await detailPaths(request, '/articles', '/articles'))[0] },
  { name: 'search', label: '搜尋', path: async () => '/search?q=%E7%A4%BA%E4%BE%8B' },
  { name: 'shop', label: '商店', path: async () => '/shop', simulationOnly: true },
  { name: 'admin-login', label: '後臺登入', path: async () => '/admin/login' },
];

test.describe('axe-core 自動無障礙掃描', () => {
  for (const def of AXE_PAGES) {
    test(`axe 掃描：${def.label}（serious／critical 必須為 0）`, { tag: ['@UI-05'] }, async ({ page, request }, testInfo) => {
      test.skip(def.simulationOnly && !SIMULATION, '商店只在 simulation 範圍開啟');
      const p = await def.path(request);
      expect(p, `${def.label} 應有可掃描的頁面`).toBeTruthy();
      const res = await page.goto(appUrl(p));
      expect(res.status()).toBe(200);
      await page.waitForLoadState('networkidle');
      const scan = await axeScan(page);
      expect(scan.passes, 'axe 應實際評估到規則（防止注入失敗而假通過）').toBeGreaterThan(0);
      const { violations } = scan;
      const count = (lvl) => violations.filter((v) => v.impact === lvl).reduce((n, v) => n + v.nodes, 0);
      const summary = { project: testInfo.project.name, page: def.name, path: p, axe: scan.version, tags: AXE_TAGS, rules_passed: scan.passes, needs_review: scan.incomplete, critical: count('critical'), serious: count('serious'), moderate: count('moderate'), minor: count('minor'), violations };
      fs.mkdirSync(RESULTS_DIR, { recursive: true });
      fs.writeFileSync(path.join(RESULTS_DIR, `axe-${testInfo.project.name}-${def.name}.json`), JSON.stringify(summary, null, 2));
      const severe = violations.filter((v) => v.impact === 'serious' || v.impact === 'critical');
      note(testInfo, `axe ${scan.version} ${def.label}：通過規則 ${scan.passes}、critical ${summary.critical}、serious ${summary.serious}、moderate ${summary.moderate}、minor ${summary.minor}、待人工確認 ${scan.incomplete.join('、') || '0'}${severe.length ? `；嚴重規則：${severe.map((v) => `${v.id}(${v.impact},${v.nodes})`).join('、')}` : ''}`);
      expect(severe.map((v) => `${v.id}［${v.impact}］${v.help}：${v.targets.join('，')}`), `${def.label} 不得有 serious／critical 違規`).toEqual([]);
    });
  }
});
