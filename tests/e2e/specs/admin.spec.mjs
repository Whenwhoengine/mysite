// Laravel 管理後臺（COM-10）：未登入導向登入、admin 看得到儀表板與商品、viewer 不能寫入（伺服器 403）、CSRF、
// 登入限流。與 docs/runbooks/商店與後臺.md 的權限矩陣逐項對照（GOV-06 的後臺部分）。
// 2FA 完整流程另見 admin-2fa.spec.mjs（該檔關閉截圖）。
import { test, expect } from '@playwright/test';
import { appUrl, note } from '../helpers/env.mjs';
import { ensureViewer, expectDashboard, loginAdmin, newAdminContext } from '../helpers/admin.mjs';

/** 從頁面任一表單取 CSRF token。 */
const csrfOf = (page) => page.locator('input[name="_token"]').first().inputValue();

test.describe('後臺存取控制', () => {
  test('未登入存取後臺一律導向登入頁', { tag: ['@COM-10'] }, async ({ page }) => {
    for (const p of ['/admin', '/admin/products', '/admin/orders', '/admin/users', '/admin/security']) {
      await page.goto(appUrl(p));
      await expect(page, `${p} 應導向登入`).toHaveURL(/\/admin\/login$/);
    }
    await expect(page.getByRole('button', { name: '登入' })).toBeVisible();
    await expect(page.locator('meta[name="robots"]')).toHaveAttribute('content', /noindex/);
  });

  test('admin 登入看到儀表板與商品列表；缺 CSRF 的寫入被拒（419）', { tag: ['@COM-10', '@GOV-06'] }, async ({ browser }) => {
    const { context, page } = await newAdminContext(browser);
    try {
      await page.goto(appUrl('/admin'));
      await expectDashboard(page);
      await page.getByRole('navigation', { name: '後臺導覽' }).getByRole('link', { name: '商品' }).click();
      await expect(page).toHaveURL(/\/admin\/products$/);
      await expect(page.getByRole('heading', { level: 1, name: '商品' })).toBeVisible();
      await expect(page.locator('main table, main .empty').first()).toBeVisible();
      await expect(page.getByRole('link', { name: '新增商品' })).toBeVisible();
      const res = await page.request.post(appUrl('/admin/products'), { form: { title: 'x' }, maxRedirects: 0 });
      expect(res.status(), '缺 CSRF token 的寫入應回 419').toBe(419);
    } finally {
      await context.close();
    }
  });

  test('viewer 看得到但不能寫入：介面無寫入按鈕，偽造請求伺服器回 403', { tag: ['@COM-10', '@GOV-06'] }, async ({ browser }, testInfo) => {
    // 先由 admin 取一個商品與規格 ID（動態取得，不寫死）
    let productId = null;
    let variantId = null;
    let adminId = null;
    {
      const { context, page } = await newAdminContext(browser);
      try {
        await page.goto(appUrl('/admin/products'));
        const href = await page.locator('main a[href*="/admin/products/"][href$="/edit"]').first().getAttribute('href').catch(() => null);
        productId = href ? Number(href.match(/products\/(\d+)\/edit/)[1]) : null;
        if (productId) {
          await page.goto(appUrl(`/admin/products/${productId}/edit`));
          const action = await page.locator('form[action*="/admin/variants/"][action$="/stock"]').first().getAttribute('action').catch(() => null);
          variantId = action ? Number(action.match(/variants\/(\d+)\/stock/)[1]) : null;
        }
        await page.goto(appUrl('/admin/users'));
        const row = page.locator('main tr', { hasText: 'admin@mysite.invalid' });
        adminId = Number((await row.locator('td').first().innerText()).trim()) || null;
      } finally {
        await context.close();
      }
    }

    const viewer = ensureViewer();
    const context = await browser.newContext();
    try {
      const page = await context.newPage();
      expect(await loginAdmin(page, viewer.email, viewer.password), 'viewer 應能登入').toBe(302);
      await expectDashboard(page);
      await page.goto(appUrl('/admin/products'));
      await expect(page.getByRole('heading', { level: 1, name: '商品' })).toBeVisible();
      await expect(page.getByRole('link', { name: '新增商品' })).toHaveCount(0);
      const create = await page.goto(appUrl('/admin/products/create'));
      expect(create.status(), 'viewer 開新增商品頁應 403').toBe(403);

      await page.goto(appUrl('/admin/products'));
      const token = await csrfOf(page);
      const forged = [
        ['POST', '/admin/products', { title: 'E2E 不應建立', slug: 'e2e-should-not-exist', price: '1' }],
      ];
      if (productId) {
        const edit = await page.goto(appUrl(`/admin/products/${productId}/edit`));
        expect(edit.status()).toBe(200);
        await expect(page.getByRole('heading', { level: 1 })).toContainText('商品詳情');
        await expect(page.getByRole('button', { name: /儲存商品|調整|新增規格/ })).toHaveCount(0);
        forged.push(['PUT', `/admin/products/${productId}`, { title: 'E2E 不應修改' }]);
        forged.push(['POST', `/admin/products/${productId}/variants`, { name: 'E2E', sku: 'E2E-NO' }]);
      }
      if (variantId) forged.push(['POST', `/admin/variants/${variantId}/stock`, { delta: '+1', note: 'E2E 不應調整' }]);
      if (adminId) forged.push(['POST', `/admin/users/${adminId}/disable`, {}]);
      for (const [method, p, data] of forged) {
        const form = { _token: token, ...data };
        if (method !== 'POST') form._method = method;
        const res = await page.request.post(appUrl(p), { form, maxRedirects: 0 });
        expect(res.status(), `viewer ${method} ${p} 應被伺服器拒絕（403）`).toBe(403);
      }
      note(testInfo, `viewer 偽造寫入 ${forged.length} 種皆 403`);
    } finally {
      await context.close();
    }
  });

  test('登入錯誤多次觸發限流', { tag: ['@COM-10'] }, async ({ page }, testInfo) => {
    const email = `e2e-throttle-${Date.now()}-${testInfo.project.name}@mysite.invalid`;
    let throttledAt = null;
    for (let i = 1; i <= 7 && throttledAt === null; i += 1) {
      const status = await loginAdmin(page, email, 'wrong-password-e2e', { retryOn429: false });
      const text = await page.locator('body').innerText();
      if (status === 429 || /過於頻繁|太多次|稍後再試|秒後再試/.test(text)) throttledAt = i;
      else expect(new URL(page.url()).pathname, '錯誤密碼應留在登入頁').toBe('/admin/login');
    }
    expect(throttledAt, '連續錯誤登入應觸發限流').not.toBeNull();
    note(testInfo, `第 ${throttledAt} 次錯誤登入觸發限流`);
  });
});
