// WordPress 後臺（CMS）真實操作：登入、區塊編輯器（Gutenberg）輸入、發布、改標題、改回草稿。
// 結束時只把測試內容改回草稿（非公開），一律不刪除任何文章或媒體。
import { expect } from '@playwright/test';
import { cmsUrl, readSecret } from './env.mjs';

const ACCOUNTS = {
  editor: { login: 'mysite-editor', secret: 'wp_editor_password' },
  admin: { login: 'mysite-admin', secret: 'wp_admin_password' },
};

/** 以 CMS 登入頁登入（密碼只填入瀏覽器輸入框）。 */
export async function loginToCms(page, role = 'editor') {
  const acct = ACCOUNTS[role];
  await page.goto(cmsUrl('/wp-login.php'));
  await page.locator('#user_login').fill(acct.login);
  await page.locator('#user_pass').fill(readSecret(acct.secret));
  await Promise.all([page.waitForURL(/\/wp-admin\//, { timeout: 30_000 }), page.locator('#wp-submit').click()]);
}

/** 歡迎導覽彈窗可能隨時出現：註冊處理器，出現時按「關閉」。 */
export async function handleWelcomeGuide(page) {
  const guide = page.getByRole('dialog', { name: /歡迎使用/ });
  await page.addLocatorHandler(guide, async () => {
    await guide.getByRole('button', { name: '關閉' }).click();
  });
}

async function waitEditorReady(page) {
  await page.waitForFunction(() => Boolean(window.wp?.data?.select('core/editor')?.getCurrentPostId?.()), null, { timeout: 60_000 });
}

/** 編輯器畫布：新版在 iframe（editor-canvas）內，舊版直接在頁面上。 */
export async function editorCanvas(page) {
  const iframe = page.locator('iframe[name="editor-canvas"]');
  if (await iframe.count()) return page.frameLocator('iframe[name="editor-canvas"]');
  return page;
}

const topBar = (page) => page.getByRole('region', { name: '編輯器頂端列' });

/** 打開右側設定欄並切到「文章」分頁（選取區塊時側欄會自動切到「區塊」分頁）。 */
export async function openPostSidebar(page) {
  const settings = page.getByRole('region', { name: /編輯器設定|Editor settings/ });
  if (!(await settings.isVisible().catch(() => false))) {
    await topBar(page).getByRole('button', { name: /^設定$|^Settings$/ }).first().click();
  }
  const tab = settings.getByRole('tab', { name: /^文章$|^頁面$|^影片$|^Podcast$|^Post$|^Page$/ }).first();
  if ((await tab.getAttribute('aria-selected')) !== 'true') await tab.click();
  await expect(tab).toHaveAttribute('aria-selected', 'true');
}

export async function openNewPost(page, postType = 'post') {
  await handleWelcomeGuide(page);
  await page.goto(cmsUrl(postType === 'post' ? '/wp-admin/post-new.php' : `/wp-admin/post-new.php?post_type=${postType}`));
  await waitEditorReady(page);
}

export async function openPost(page, postId) {
  await handleWelcomeGuide(page);
  await page.goto(cmsUrl(`/wp-admin/post.php?post=${postId}&action=edit`));
  await waitEditorReady(page);
}

/** 以鍵盤在區塊編輯器輸入標題與段落（真實輸入，不直接改資料庫）。 */
export async function typeTitleAndBody(page, title, paragraphs) {
  const canvas = await editorCanvas(page);
  const titleBox = canvas.getByRole('textbox', { name: /新增標題|Add title/ });
  await titleBox.click();
  await page.keyboard.type(title);
  for (const p of paragraphs) {
    await page.keyboard.press('Enter');
    await page.keyboard.type(p);
  }
  await expect(titleBox).toHaveText(title);
}

/** 取代標題文字（全選後重新輸入）。 */
export async function replaceTitle(page, title) {
  const canvas = await editorCanvas(page);
  const titleBox = canvas.getByRole('textbox', { name: /新增標題|Add title/ });
  await titleBox.click();
  await page.keyboard.press('ControlOrMeta+A');
  await page.keyboard.type(title);
  await expect(titleBox).toHaveText(title);
}

export const editorState = (page) =>
  page.evaluate(() => {
    const ed = window.wp.data.select('core/editor');
    return {
      id: ed.getCurrentPostId(),
      status: ed.getCurrentPostAttribute('status'),
      title: ed.getCurrentPostAttribute('title'),
      link: ed.getPermalink(),
      saving: ed.isSavingPost(),
      dirty: ed.isEditedPostDirty(),
    };
  });

async function waitSaved(page, predicate, what) {
  await expect
    .poll(async () => {
      const s = await editorState(page);
      return !s.saving && !s.dirty && predicate(s);
    }, { message: what, timeout: 45_000 })
    .toBe(true);
}

/** 按頂端列「發佈」，若出現發佈前檢查面板再按面板內的「發佈」。 */
export async function publish(page) {
  await topBar(page).getByRole('button', { name: /^發佈$|^Publish$/ }).click();
  const panelButton = page.locator('.editor-post-publish-panel').getByRole('button', { name: /^發佈$|^Publish$/ });
  // 編輯者若關閉了「永遠顯示發佈前檢查項目」，第一次按就會直接發布
  await Promise.race([
    panelButton.waitFor({ state: 'visible', timeout: 10_000 }).then(() => panelButton.click()),
    page.waitForFunction(() => window.wp.data.select('core/editor').getCurrentPostAttribute('status') === 'publish', null, { timeout: 10_000 }),
  ]).catch(() => {});
  await waitSaved(page, (s) => s.status === 'publish', '文章應成為已發佈');
  const closePanel = page.locator('.editor-post-publish-panel').getByRole('button', { name: /關閉|Close/ }).first();
  if (await closePanel.isVisible().catch(() => false)) await closePanel.click();
  return editorState(page);
}

export async function saveDraft(page) {
  await topBar(page).getByRole('button', { name: /^儲存草稿$|^Save draft$/ }).click();
  await waitSaved(page, (s) => s.status === 'draft', '草稿應已儲存');
  return editorState(page);
}

/** 已發佈內容修改後按「儲存」（舊版為「更新」）。 */
export async function saveChanges(page) {
  await topBar(page).getByRole('button', { name: /^(儲存|更新|Save|Update)$/ }).click();
  await waitSaved(page, () => true, '修改應已儲存');
  return editorState(page);
}

/** 以側欄「狀態」改回草稿並儲存（撤下）。回傳按下儲存的時間點。 */
export async function switchToDraft(page) {
  await openPostSidebar(page);
  await page.getByRole('button', { name: /^變更狀態|^Change status/ }).click();
  await page.getByRole('radio', { name: /^草稿$|^Draft$/ }).check();
  await page.keyboard.press('Escape');
  const clickedAt = Date.now();
  await topBar(page).getByRole('button', { name: /^(儲存|更新|Save|Update)$/ }).click();
  await waitSaved(page, (s) => s.status === 'draft', '文章應已改回草稿');
  return clickedAt;
}

/**
 * 保險：以登入中的瀏覽器 context 呼叫 WP 原生 REST（cookie＋nonce）把內容改回草稿。
 * 只改狀態，不刪除。回傳最終狀態。
 */
export async function ensureDraft(context, postId, restBase = 'posts') {
  if (!postId) return null;
  const nonceRes = await context.request.get(cmsUrl('/wp-admin/admin-ajax.php?action=rest-nonce'));
  const nonce = (await nonceRes.text()).trim();
  const res = await context.request.post(cmsUrl(`/wp-json/wp/v2/${restBase}/${postId}`), {
    headers: { 'X-WP-Nonce': nonce },
    data: { status: 'draft' },
  });
  if (!res.ok()) throw new Error(`改回草稿失敗：HTTP ${res.status()}`);
  return (await res.json()).status;
}

/** 以 WP 原生 REST 查目前狀態（cookie＋nonce）。 */
export async function restStatus(context, postId, restBase = 'posts') {
  const nonceRes = await context.request.get(cmsUrl('/wp-admin/admin-ajax.php?action=rest-nonce'));
  const nonce = (await nonceRes.text()).trim();
  const res = await context.request.get(cmsUrl(`/wp-json/wp/v2/${restBase}/${postId}?context=edit`), { headers: { 'X-WP-Nonce': nonce } });
  if (!res.ok()) return null;
  return (await res.json()).status;
}
