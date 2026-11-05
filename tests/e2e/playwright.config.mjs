// mysite E2E 設定：真實 Chromium、三種寬度（390／768／1440），結果另寫 JSONL（逐驗收 ID）。
import { defineConfig } from '@playwright/test';

const appBase = process.env.MYSITE_APP_BASE || 'http://mysite.localhost:8080';

export default defineConfig({
  testDir: './specs',
  timeout: 90_000,
  expect: { timeout: 15_000 },
  fullyParallel: false,
  workers: 1,
  retries: 0,
  reporter: [['list'], ['./reporters/jsonl-reporter.mjs']],
  outputDir: './test-results',
  use: {
    baseURL: appBase,
    locale: 'zh-TW',
    timezoneId: 'Asia/Taipei',
    // trace／video 可能含 cookie 或票券，預設關閉；失敗時只保留截圖（DAT-02、EXEC-06）
    trace: 'off',
    video: 'off',
    screenshot: 'only-on-failure',
    ignoreHTTPSErrors: false,
  },
  projects: [
    { name: 'mobile-390', use: { viewport: { width: 390, height: 844 }, deviceScaleFactor: 1, isMobile: true, hasTouch: true } },
    { name: 'tablet-768', use: { viewport: { width: 768, height: 1024 }, deviceScaleFactor: 1 } },
    { name: 'desktop-1440', use: { viewport: { width: 1440, height: 900 }, deviceScaleFactor: 1 } },
  ],
});
