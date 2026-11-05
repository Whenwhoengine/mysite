// 基礎設施層 E2E：經 gateway 的真實 HTTP 行為（不依賴頁面細節）。
import { test, expect } from '@playwright/test';

const cms = process.env.MYSITE_CMS_BASE || 'http://cms.mysite.localhost:8081';

test.describe('gateway 與健康檢查', () => {
  test('liveness 與 readiness 只回 ok，不帶版本或路徑', { tag: ['@INF-05'] }, async ({ request }) => {
    for (const path of ['/healthz', '/readyz']) {
      const res = await request.get(path);
      expect(res.status()).toBe(200);
      expect(await res.json()).toEqual({ status: 'ok' });
      expect(res.headers()['x-powered-by']).toBeUndefined();
    }
  });

  test('主站敏感檔與內部端點不可經 gateway 取得', { tag: ['@INF-03', '@INF-07'] }, async ({ request }) => {
    for (const path of ['/.env', '/.git/config', '/vendor/autoload.php', '/composer.json', '/artisan', '/storage/logs/laravel.log', '/internal/diagnostics/php', '/phpinfo.php', '/info.php']) {
      const res = await request.get(path, { maxRedirects: 0 });
      expect([403, 404], `${path} 應被拒`).toContain(res.status());
      const body = await res.text();
      expect(body).not.toMatch(/APP_KEY|DB_PASSWORD|phpinfo\(\)|<title>phpinfo/i);
    }
  });

  test('CMS 敏感檔不可取得、uploads 不可執行 PHP', { tag: ['@INF-03', '@INF-04'] }, async ({ request }) => {
    for (const path of ['/wp-config.php', '/xmlrpc.php', '/readme.html', '/wp-content/acf-json/', '/wp-content/uploads/x.php', '/wp-content/uploads/x.php.jpg']) {
      const res = await request.get(`${cms}${path}`, { maxRedirects: 0 });
      expect([403, 404], `${path} 應被拒`).toContain(res.status());
    }
  });

  test('回應帶基本安全標頭且不暴露 PHP 版本', { tag: ['@INF-07'] }, async ({ request }) => {
    const res = await request.get('/healthz');
    const h = res.headers();
    expect(h['x-content-type-options']).toBe('nosniff');
    expect(h['x-powered-by']).toBeUndefined();
    expect(h['server'] || '').not.toMatch(/\d/);
  });
});
