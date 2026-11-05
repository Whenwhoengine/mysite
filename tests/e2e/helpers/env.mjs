// E2E 共用環境設定：網址、範圍與秘密一律從環境變數讀取（run.sh 設定），不寫死開發環境才有的資料。
import fs from 'node:fs';
import path from 'node:path';
import crypto from 'node:crypto';
import { fileURLToPath } from 'node:url';

export const E2E_DIR = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
export const ROOT = path.resolve(E2E_DIR, '../..');

const trimSlash = (s) => s.replace(/\/+$/, '');
export const APP_BASE = trimSlash(process.env.MYSITE_APP_BASE || 'http://mysite.localhost:8080');
export const CMS_BASE = trimSlash(process.env.MYSITE_CMS_BASE || 'http://cms.mysite.localhost:8081');
export const APP_URL = new URL(APP_BASE);
export const CMS_URL = new URL(CMS_BASE);
export const ENV_NAME = process.env.MYSITE_ENV || 'development';
export const SECRETS_DIR = process.env.MYSITE_SECRETS_DIR || path.join(ROOT, '.runtime', 'secrets', ENV_NAME);
export const RESULTS_DIR = process.env.MYSITE_RESULTS_DIR || path.join(ROOT, '.runtime', 'results');
export const SCOPE = process.env.MYSITE_SCOPE || 'content';
export const SIMULATION = SCOPE === 'simulation';
export const SCREENSHOT_DIR = path.join(E2E_DIR, 'screenshots');
// 測試自建帳號的密碼檔（Git 忽略的 .runtime/e2e/<env>/，權限 0600）
export const E2E_STATE_DIR = path.join(ROOT, '.runtime', 'e2e', ENV_NAME);

// 契約上限：撤下後主站最遲 60 秒內停止供應正文（v5 §8.6；可見性租約 30 秒＋事件即時失效）
export const TAKEDOWN_LIMIT_MS = 60_000;

// 只在一個 project 執行的流程（WP 後臺編輯、預覽、2FA）：與視窗寬度無關，避免同一內容重複發布三次
export const EDITOR_PROJECT = 'desktop-1440';

/** 讀取秘密檔（不輸出內容；缺檔時錯誤訊息只含檔名）。 */
export function readSecret(name) {
  const file = path.join(SECRETS_DIR, name);
  let value = '';
  try {
    value = fs.readFileSync(file, 'utf8').replace(/\r?\n$/, '');
  } catch {
    throw new Error(`缺少秘密檔 ${name}（${ENV_NAME} 環境）`);
  }
  if (value === '') throw new Error(`秘密檔 ${name} 是空的`);
  return value;
}

/** 測試自建帳號的密碼：第一次產生隨機值寫入 0600 檔，之後重用（帳號已存在時 artisan 指令不會重設密碼）。 */
export function e2eSecretFile(name) {
  fs.mkdirSync(E2E_STATE_DIR, { recursive: true, mode: 0o700 });
  const file = path.join(E2E_STATE_DIR, name);
  if (!fs.existsSync(file)) {
    const value = crypto.randomBytes(24).toString('base64url');
    fs.writeFileSync(file, value, { mode: 0o600, flag: 'wx' });
  }
  fs.chmodSync(file, 0o600);
  return file;
}

/** 本輪唯一記號（英數，方便搜尋與比對）。 */
export function uniqueToken(prefix = 'e2e') {
  return `${prefix}${Date.now().toString(36)}${crypto.randomBytes(3).toString('hex')}`;
}

/** 相對路徑轉主站完整網址。 */
export const appUrl = (p = '/') => APP_BASE + (p.startsWith('/') ? p : `/${p}`);
export const cmsUrl = (p = '/') => CMS_BASE + (p.startsWith('/') ? p : `/${p}`);

/** 量測結果寫進測試註記（JSONL reporter 會一併寫入 message 欄位）。 */
export function note(testInfo, text) {
  testInfo.annotations.push({ type: '量測', description: text });
  // eslint-disable-next-line no-console
  console.log(`[${testInfo.project.name}] ${text}`);
}
