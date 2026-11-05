// Laravel 管理後臺：登入（含限流重試一次）、測試帳號建立（artisan，密碼只經檔案）、TOTP 計算（Node 內建 crypto）。
import fs from 'node:fs';
import path from 'node:path';
import crypto from 'node:crypto';
import { execFileSync } from 'node:child_process';
import { expect } from '@playwright/test';
import { APP_URL, ENV_NAME, ROOT, appUrl, e2eSecretFile, readSecret } from './env.mjs';

export const ADMIN_EMAIL = 'admin@mysite.invalid';
export const VIEWER_EMAIL = 'e2e-viewer@mysite.invalid';

export const adminPassword = () => readSecret('app_admin_password');

/**
 * 以登入頁登入。回傳 POST /admin/login 的回應狀態。
 * 遇到 429（同 email＋IP 每分鐘 5 次的限流）時等候 61 秒重試一次，避免三個 project 連續登入互相干擾。
 */
export async function loginAdmin(page, email, password, { retryOn429 = true } = {}) {
  // 開啟登入頁本身也受 Fortify 整體限流（每 IP 每分鐘 60 次）；E2E 全部來自同一 IP，前一個測試可能用完額度。
  // 拿到 429 時依 Retry-After 等候後重開（最多兩次），不把 429 頁面當成登入頁去填欄位。
  for (let attempt = 0; ; attempt += 1) {
    const opened = await page.goto(appUrl('/admin/login'));
    if (!opened || opened.status() !== 429 || attempt >= 2) break;
    const wait = Math.min(Number(opened.headers()['retry-after']) || 60, 65);
    await page.waitForTimeout((wait + 1) * 1000);
  }
  await page.waitForLoadState('load');
  await page.locator('#email').fill(email);
  await page.locator('#password').fill(password);
  // 確認密碼只在密碼欄（type=password）；不符就清空、不送出，避免失敗截圖出現明文
  const ok = await page.evaluate((e) => {
    const u = document.getElementById('email');
    const p = document.getElementById('password');
    return u.value === e && p.type === 'password' && p.value.length > 0;
  }, email);
  if (!ok) {
    await page.locator('#email').fill('');
    await page.locator('#password').fill('');
    throw new Error('後臺登入欄位填寫異常（已清空欄位，未送出）');
  }
  const [res] = await Promise.all([
    page.waitForResponse((r) => r.request().method() === 'POST' && new URL(r.url()).pathname === '/admin/login'),
    page.getByRole('button', { name: '登入' }).click(),
  ]);
  await page.waitForLoadState('load');
  if (res.status() === 429 && retryOn429) {
    await page.waitForTimeout(61_000);
    return loginAdmin(page, email, password, { retryOn429: false });
  }
  return res.status();
}

/** 在 app-php 容器以 artisan 建立後臺帳號；密碼經 stdin 寫入容器內暫存檔（umask 077），不出現在命令列。 */
export function createAdminAccount({ email, name, role, passwordFile }) {
  if (!/^[a-z0-9.+-]+@[a-z0-9.-]+$/.test(email) || !/^[A-Za-z0-9-]+$/.test(name) || !['admin', 'viewer'].includes(role)) {
    throw new Error('帳號參數不合法');
  }
  const script = `umask 077; f=$(mktemp); cat > "$f"; php artisan mysite:admin:create --email=${email} --name=${name} --role=${role} --password-file="$f"; rc=$?; rm -f "$f"; exit $rc`;
  const out = execFileSync('bash', [path.join(ROOT, 'scripts/cloud/dev.sh'), 'compose', 'exec', '-T', '-u', 'www-data', 'app-php', 'sh', '-c', script], {
    input: fs.readFileSync(passwordFile),
    env: { ...process.env, MYSITE_ENV: ENV_NAME },
    encoding: 'utf8',
    stdio: ['pipe', 'pipe', 'pipe'],
    timeout: 120_000,
  });
  if (!/已建立|no-op/.test(out)) throw new Error('建立後臺帳號的輸出不如預期');
  return out.includes('no-op') ? 'exists' : 'created';
}

/** 唯讀人員帳號（固定 email、密碼檔重用）。 */
export function ensureViewer() {
  const passwordFile = e2eSecretFile('viewer_password');
  createAdminAccount({ email: VIEWER_EMAIL, name: 'E2E-viewer', role: 'viewer', passwordFile });
  return { email: VIEWER_EMAIL, password: fs.readFileSync(passwordFile, 'utf8') };
}

function base32Decode(input) {
  const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
  const s = input.replace(/[\s=-]/g, '').toUpperCase();
  let bits = 0;
  let value = 0;
  const out = [];
  for (const c of s) {
    const i = alphabet.indexOf(c);
    if (i < 0) throw new Error('金鑰不是 base32');
    value = (value << 5) | i;
    bits += 5;
    if (bits >= 8) {
      out.push((value >>> (bits - 8)) & 0xff);
      bits -= 8;
    }
  }
  return Buffer.from(out);
}

/** RFC 6238 TOTP（SHA-1、30 秒、6 位）。 */
export function totp(secret, timeMs = Date.now()) {
  const counter = Math.floor(timeMs / 1000 / 30);
  const buf = Buffer.alloc(8);
  buf.writeBigUInt64BE(BigInt(counter));
  const h = crypto.createHmac('sha1', base32Decode(secret)).update(buf).digest();
  const o = h[h.length - 1] & 0x0f;
  const code = ((h.readUInt32BE(o) & 0x7fffffff) % 1_000_000).toString().padStart(6, '0');
  return { code, counter };
}

/** 取得「尚未用過」的 TOTP：同一時間窗的驗證碼已用過時，等到下一個 30 秒窗。 */
export async function freshTotp(page, secret, usedCounter) {
  let t = totp(secret);
  while (usedCounter !== undefined && t.counter <= usedCounter) {
    await page.waitForTimeout(1000);
    t = totp(secret);
  }
  // 避免剛好在時間窗尾端送出
  const left = 30_000 - (Date.now() % 30_000);
  if (left < 3000) {
    await page.waitForTimeout(left + 200);
    t = totp(secret);
  }
  return t;
}

export async function expectDashboard(page) {
  await expect(page).toHaveURL(/\/admin\/?$/);
  await expect(page.getByRole('heading', { level: 1, name: '儀表板' })).toBeVisible();
}

// 管理員登入狀態只快取在記憶體（同一 worker 內重用），不寫入檔案；減少登入次數以免觸發每分鐘 5 次的登入限流。
let adminState = null;

/** 記下目前 context 的主站後臺登入狀態（只保留主站主機的 cookie）。 */
export async function rememberAdminState(context) {
  const state = await context.storageState();
  adminState = {
    cookies: state.cookies.filter((c) => c.domain === APP_URL.hostname),
    origins: state.origins.filter((o) => o.origin === APP_URL.origin),
  };
}

/** 取得已登入管理員的新 context（有快取就重用，失效才重新登入）。 */
export async function newAdminContext(browser, options = {}) {
  if (adminState) {
    const context = await browser.newContext({ ...options, storageState: adminState });
    const page = await context.newPage();
    const res = await page.goto(appUrl('/admin'));
    if (res.status() === 200 && new URL(page.url()).pathname.replace(/\/$/, '') === '/admin') return { context, page };
    await context.close();
    adminState = null;
  }
  const context = await browser.newContext(options);
  const page = await context.newPage();
  const status = await loginAdmin(page, ADMIN_EMAIL, adminPassword());
  if (status !== 302) throw new Error(`管理員登入失敗：HTTP ${status}`);
  await rememberAdminState(context);
  return { context, page };
}
