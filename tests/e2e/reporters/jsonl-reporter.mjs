// 把每個測試結果寫成一行 JSON（suite=e2e），驗收 ID 取自測試 tag（例如 @UI-02）。
import fs from 'node:fs';
import path from 'node:path';

const ID_RE = /^@((CLD|INF|WP|API|UI|COM|OPS|GOV|DAT|EXEC)-\d+)$/;

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
    const message = (result.errors || []).map((e) => (e.message || '').split('\n')[0]).join('；')
      .replace(/(ticket|token|password|cookie)=([^&\s]+)/gi, '$1=***').slice(0, 500);
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
