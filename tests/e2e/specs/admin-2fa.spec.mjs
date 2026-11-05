// 後臺 2FA（COM-10）：在 desktop-1440 完整走一次 2FA 啟用與 TOTP 登入。
// 使用拋棄式唯讀帳號（每輪新建），測完由 admin 停用（只停用，不刪除），不影響正式管理員帳號。
// 本檔關閉截圖：設定頁有金鑰與復原碼，任何情況（含失敗）都不得拍下；金鑰只存在記憶體、不輸出。
import fs from 'node:fs';
import { test, expect } from '@playwright/test';
import { EDITOR_PROJECT, appUrl, e2eSecretFile, note } from '../helpers/env.mjs';
import { createAdminAccount, expectDashboard, freshTotp, loginAdmin, newAdminContext, totp } from '../helpers/admin.mjs';

test.use({ screenshot: 'off' });

test.describe('後臺 2FA（TOTP）', () => {
  test.beforeEach(({}, testInfo) => {
    test.skip(testInfo.project.name !== EDITOR_PROJECT, `2FA 流程與視窗寬度無關，只在 ${EDITOR_PROJECT} 執行一次`);
  });

  test('拋棄式帳號完整啟用 2FA 並以 TOTP 登入，測後由 admin 停用', { tag: ['@COM-10'] }, async ({ browser }, testInfo) => {
    test.setTimeout(4 * 60_000);
    const passwordFile = e2eSecretFile('twofa_password');
    const password = fs.readFileSync(passwordFile, 'utf8');
    const email = `e2e-2fa-${Date.now()}@mysite.invalid`;
    expect(createAdminAccount({ email, name: 'E2E-2fa', role: 'viewer', passwordFile })).toBe('created');

    const context = await browser.newContext();
    let secret = null;
    try {
      const page = await context.newPage();
      expect(await loginAdmin(page, email, password)).toBe(302);
      await page.goto(appUrl('/admin/security'));
      await expect(page.getByText('未啟用')).toBeVisible();
      for (let i = 0; i < 2 && (await page.getByRole('button', { name: '開始設定 2FA' }).isVisible()); i += 1) {
        await page.getByRole('button', { name: '開始設定 2FA' }).click();
        if (/confirm-password/.test(page.url())) {
          await page.locator('#password').fill(password);
          await page.getByRole('button', { name: '確認' }).click();
          await page.waitForLoadState('load');
          if (!/\/admin\/security/.test(page.url())) await page.goto(appUrl('/admin/security'));
        }
      }
      const key = page.locator('p', { hasText: '手動輸入金鑰' }).locator('code');
      await expect(key).toBeVisible();
      secret = (await key.innerText()).trim(); // 只存在記憶體，不輸出
      const first = await freshTotp(page, secret);
      await page.locator('#code').fill(first.code);
      await page.getByRole('button', { name: '確認啟用' }).click();
      await expect(page.getByText('已啟用')).toBeVisible();
      await expect(page.getByText('復原碼（只顯示這一次')).toBeVisible();

      // 登出後重新登入：密碼正確後必須通過 TOTP 挑戰
      await page.getByRole('button', { name: '登出' }).click();
      await expect(page).toHaveURL(/\/admin\/login$/);
      await loginAdmin(page, email, password);
      await expect(page).toHaveURL(/\/admin\/two-factor-challenge$/);
      // 未通過挑戰前不能進入後臺
      const blocked = await page.request.get(appUrl('/admin'), { maxRedirects: 0 });
      expect(blocked.status()).toBe(302);
      const second = await freshTotp(page, secret, first.counter);
      await page.locator('#code').fill(second.code);
      await page.getByRole('button', { name: '驗證並登入' }).click();
      await expectDashboard(page);
      note(testInfo, '2FA：啟用（確認 TOTP）→ 登出 → 密碼＋TOTP 挑戰登入成功（金鑰與復原碼未輸出、未截圖）');
      expect(totp(secret).code).toMatch(/^\d{6}$/);
    } finally {
      await context.close();
      secret = null;
      // 收尾：由 admin 停用拋棄式帳號（只停用，不刪除）
      const { context: adminCtx, page: adminPage } = await newAdminContext(browser);
      try {
        await adminPage.goto(appUrl('/admin/users'));
        const row = adminPage.locator('main tr', { hasText: email });
        adminPage.once('dialog', (d) => d.accept());
        await row.getByRole('button', { name: '停用' }).click();
        await expect(adminPage.locator('main tr', { hasText: email })).toContainText('已停用');
      } finally {
        await adminCtx.close();
      }
    }
  });
});
