// 模擬商店：content 範圍（COMMERCE_MODE=disabled）全部 404、導覽無商店；
// simulation 範圍可瀏覽、加入購物車、結帳成功，畫面標示「模擬操作，不收款、不出貨」，不收卡號／地址／電話，
// 前端篡改價格無效、冪等鍵重送不產生第二單、他人 Session 不能讀訂單。模擬訂單保留（由保存期限機制處理）。
import { test, expect } from '@playwright/test';
import { APP_URL, SIMULATION, appUrl, note } from '../helpers/env.mjs';

const SIM_TEXT = '模擬操作，不收款、不出貨';
const SENSITIVE_FIELD = /card|cc-|cvc|cvv|pan|expiry|exp-|address|addr|street|city|zip|postal|phone|tel|mobile|payment|收件|地址|電話|卡號/i;

/** 表單欄位不得要求卡號、地址、電話等敏感資料。 */
async function expectNoSensitiveFields(page, label) {
  const fields = await page.locator('main input, main select, main textarea').evaluateAll((els) =>
    els.map((e) => ({ name: e.getAttribute('name') || '', type: e.getAttribute('type') || '', autocomplete: e.getAttribute('autocomplete') || '', id: e.id })));
  for (const f of fields) {
    expect(f.type, `${label} 不得有電話欄位（${f.name}）`).not.toBe('tel');
    expect(`${f.name} ${f.id} ${f.autocomplete}`, `${label} 不得有付款／地址／電話欄位`).not.toMatch(SENSITIVE_FIELD);
  }
  return fields.map((f) => f.name).filter(Boolean);
}

const money = (s) => Number(String(s).replace(/[^0-9.]/g, ''));

test.describe('商店：content 範圍（disabled）', () => {
  test.skip(SIMULATION, 'simulation 範圍另測');

  test('/shop、/cart、/checkout、訂單與付款路由回 404，寫入也被拒，導覽無商店連結', { tag: ['@COM-01', '@COM-11'] }, async ({ page, request }) => {
    for (const p of ['/shop', '/shop/sample-mug', '/cart', '/checkout', '/checkout/payment', `/orders/e2e${Date.now()}`]) {
      const res = await request.get(appUrl(p), { maxRedirects: 0 });
      expect(res.status(), `${p} 應回 404`).toBe(404);
      const body = await res.text();
      expect(body, `${p} 不得出現結帳或付款表單`).not.toMatch(/name="(card|cc_|payment|address|phone)/i);
    }
    // 寫入：帶有效 CSRF token（取自同一 Session 的後臺登入頁）仍應 404，不因模式關閉而寫入
    await page.goto(appUrl('/admin/login'));
    const csrf = await page.locator('input[name="_token"]').first().inputValue();
    for (const [method, p] of [['post', '/cart/items'], ['post', '/checkout'], ['post', '/checkout/payment']]) {
      const res = await page.request[method](appUrl(p), { form: { _token: csrf, variant_id: '1', quantity: '1' }, maxRedirects: 0 });
      expect(res.status(), `${method.toUpperCase()} ${p} 應被拒（404）`).toBe(404);
    }
    await page.goto(appUrl('/'));
    await expect(page.locator('header a[href$="/shop"]')).toHaveCount(0);
    await expect(page.locator('a[href$="/shop"], a[href*="/shop/"], a[href$="/cart"]')).toHaveCount(0);
    await expect(page.locator('header')).not.toContainText('商店');
  });
});

test.describe('商店：simulation 範圍', () => {
  test.skip(!SIMULATION, '商店只在 MYSITE_SCOPE=simulation（COMMERCE_MODE=simulation）時開啟');

  test('瀏覽、加入購物車、結帳成功；每個畫面標示模擬且不收敏感資料；篡改價格與重送無效；他人 Session 被拒', {
    tag: ['@COM-01', '@COM-11', '@COM-03', '@COM-05', '@COM-09'],
  }, async ({ page, browser }, testInfo) => {
    test.setTimeout(3 * 60_000);
    let product;
    await test.step('商店列表與導覽：可瀏覽並標示模擬', async () => {
      await page.goto(appUrl('/'));
      // 手機寬度選單收合時商店連結在選單內
      const toggle = page.locator('[data-site-nav-toggle]');
      if (await toggle.isVisible()) await toggle.click();
      await expect(page.locator('header').getByRole('link', { name: '商店' })).toBeVisible();
      const res = await page.goto(appUrl('/shop'));
      expect(res.status()).toBe(200);
      await expect(page.locator('[data-sim-notice]')).toContainText(SIM_TEXT);
      const links = await page.locator('main a[href*="/shop/"]').evaluateAll((as) => [...new Set(as.map((a) => a.getAttribute('href')))]);
      expect(links.length, '至少一件上架商品').toBeGreaterThan(0);
      // 選庫存最多的規格（不寫死商品 ID；避免消耗只剩一件的示例品）
      let best = null;
      for (const href of links) {
        await page.goto(new URL(href, appUrl('/')).toString());
        const variants = await page.locator('.shop-variant').evaluateAll((ls) => ls.map((l) => {
          const input = l.querySelector('input[name="variant_id"]');
          const stock = Number((l.querySelector('.shop-stock')?.textContent || '').replace(/[^0-9]/g, '')) || 0;
          return { id: input?.value, disabled: input?.disabled, stock, price: l.querySelector('.shop-price')?.textContent || '' };
        }));
        for (const v of variants) if (!v.disabled && (!best || v.stock > best.stock)) best = { ...v, href };
      }
      expect(best, '至少一個有庫存的規格').toBeTruthy();
      expect(best.stock).toBeGreaterThanOrEqual(2);
      product = best;
    });

    await test.step('商品頁加入購物車（前端夾帶的假價格被忽略）', async () => {
      await page.goto(new URL(product.href, appUrl('/')).toString());
      await expect(page.locator('[data-sim-notice]')).toContainText(SIM_TEXT);
      await page.locator(`input[name="variant_id"][value="${product.id}"]`).check();
      await page.locator('#quantity').fill('1');
      // COM-03：在表單中注入前端價格欄位，伺服器不得採用
      await page.locator('form.shop-add').evaluate((f) => {
        for (const [n, v] of [['price', '1'], ['unit_price', '1'], ['total', '1']]) {
          const i = document.createElement('input');
          i.type = 'hidden'; i.name = n; i.value = v; f.appendChild(i);
        }
      });
      await page.getByRole('button', { name: /加入購物車/ }).click();
      await page.goto(appUrl('/cart'));
      await expect(page.locator('[data-sim-notice]')).toContainText(SIM_TEXT);
      await expect(page.locator('.shop-cart__table')).toBeVisible();
      await expectNoSensitiveFields(page, '購物車');
    });

    let orderUrl;
    let firstKey;
    let csrf;
    await test.step('結帳頁：標示模擬、不收卡號／地址／電話；篡改總價後送出，訂單以伺服器現價成立', async () => {
      const res = await page.goto(appUrl('/checkout'));
      expect(res.status()).toBe(200);
      await expect(page.locator('[data-sim-notice]')).toContainText(SIM_TEXT);
      const names = await expectNoSensitiveFields(page, '結帳頁');
      expect(names.sort()).toEqual(['_token', 'buyer_code', 'idempotency_key'].sort());
      expect(res.headers()['cache-control'] || '').toMatch(/no-store/);
      firstKey = await page.locator('input[name="idempotency_key"]').inputValue();
      csrf = await page.locator('form[data-checkout-form] input[name="_token"]').inputValue();
      await page.locator('#buyer_code').fill('E2E-SIM');
      await page.locator('form[data-checkout-form]').evaluate((f) => {
        for (const [n, v] of [['total', '1'], ['subtotal', '1'], ['price', '1'], ['discount', '99999']]) {
          const i = document.createElement('input');
          i.type = 'hidden'; i.name = n; i.value = v; f.appendChild(i);
        }
      });
      await Promise.all([page.waitForURL(/\/orders\/[A-Za-z0-9_-]+$/), page.getByRole('button', { name: '送出模擬訂單' }).click()]);
      orderUrl = page.url();
      await expect(page.locator('[data-sim-notice]')).toContainText(SIM_TEXT);
      await expect(page.locator('main')).toContainText('未收款（模擬）');
      await expect(page.locator('main')).toContainText('不出貨（模擬）');
      await expect(page.locator('main')).not.toContainText('已付款');
      const unit = await page.locator('main table tbody tr').first().locator('td').nth(2).innerText();
      expect(money(unit), '訂單單價應為伺服器現價，不受前端篡改').toBe(money(product.price));
      await expect(page.locator('meta[name="robots"]')).toHaveAttribute('content', /noindex/);
      const resp = await page.request.get(orderUrl);
      expect(resp.headers()['cache-control'] || '', '訂單頁應 no-store').toMatch(/no-store/);
      expect(resp.headers()['cache-control'] || '').toMatch(/private/);
    });

    await test.step('相同冪等鍵重送只回同一張訂單（不建第二單）', async () => {
      const res = await page.request.post(appUrl('/checkout'), { form: { _token: csrf, idempotency_key: firstKey, buyer_code: 'E2E-SIM' }, maxRedirects: 0 });
      expect([302, 303], '重送應導向既有結果').toContain(res.status());
      const loc = new URL(res.headers().location || '', appUrl('/'));
      expect(loc.toString(), '重送應回到同一張訂單').toBe(orderUrl);
    });

    await test.step('另一個瀏覽器 Session 開同一訂單網址被拒；猜測 token 也被拒；冪等鍵不跨 Session 共用', async () => {
      const other = await browser.newContext();
      try {
        const p2 = await other.newPage();
        const res = await p2.goto(orderUrl);
        expect(res.status(), '他人 Session 讀訂單應被拒').toBe(404);
        await expect(p2.locator('main')).not.toContainText('E2E-SIM');
        const guess = await p2.goto(orderUrl.replace(/[A-Za-z0-9_-]{4}$/, 'AAAA'));
        expect(guess.status()).toBe(404);
        // 另一 Session 帶第一個 Session 的冪等鍵結帳：不得拿到第一張訂單
        await p2.goto(new URL(product.href, appUrl('/')).toString());
        await p2.locator(`input[name="variant_id"][value="${product.id}"]`).check();
        await p2.getByRole('button', { name: /加入購物車/ }).click();
        await p2.goto(appUrl('/checkout'));
        await p2.locator('input[name="idempotency_key"]').evaluate((i, k) => { i.value = k; }, firstKey);
        await p2.getByRole('button', { name: '送出模擬訂單' }).click();
        await p2.waitForLoadState('load');
        expect(p2.url(), '不同 Session 不得取得第一張訂單').not.toBe(orderUrl);
        expect(new URL(p2.url()).host).toBe(APP_URL.host);
      } finally {
        await other.close();
      }
    });
    note(testInfo, `simulation：結帳成功（訂單網址不輸出）、篡改價格無效、重送同單、他人 Session 404；商品規格庫存 ${product.stock}`);
  });
});
