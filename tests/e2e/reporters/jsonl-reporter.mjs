// 把每個測試結果寫成一行 JSON（suite=e2e），驗收 ID 取自測試 tag（例如 @UI-02）。
// 測試以註記（type「量測」）記錄的實測值（例如撤下秒數、axe 嚴重項數）會一併寫入 message；跳過原因也寫入。
import fs from 'node:fs';
import path from 'node:path';

const ID_RE = /^@((CLD|INF|WP|API|UI|COM|OPS|GOV|DAT|EXEC)-\d+)$/;

const redact = (s) => s.replace(/(ticket|token|password|cookie|secret|code)=([^&\s]+)/gi, '$1=***');

export default class JsonlReporter {
  constructor() {
    const dir = process.env.MYSITE_RESULTS_DIR || path.resolve('../../.runtime/results');
    fs.mkdirSync(dir, { recursive: true });
    this.file = path.join(dir, 'e2e.jsonl');
    fs.writeFileSync(this.file, '');
  }

  onTestEnd(test, result) {
    const ids = (test.tags || []).map((t) => (t.match(ID_RE) || [])[1]).filter(Boolean);
    const status = result.status === 'passed' ? 'PASS' : (result.status === 'skipped' ? 'SKIPPED' : 'FAIL');
    const errors = (result.errors || []).map((e) => (e.message || '').replace(/\u001b\[[0-9;]*m/g, '').split('\n')[0]);
    const notes = [...new Set([...(result.annotations || []), ...(test.annotations || [])]
      .filter((a) => a.type === '量測' || a.type === 'skip')
      .map((a) => a.description || '')
      .filter(Boolean))];
    const message = redact([...errors, ...notes].join('；')).slice(0, 1000);
    const finished = new Date(result.startTime.getTime() + result.duration);
    fs.appendFileSync(this.file, JSON.stringify({
      suite: 'e2e',
      test: test.titlePath().filter(Boolean).join(' › '),
      ids,
      status,
      message,
      started_at: result.startTime.toISOString().replace(/\.\d{3}Z$/, 'Z'),
      finished_at: finished.toISOString().replace(/\.\d{3}Z$/, 'Z'),
    }) + '\n');
  }
}
