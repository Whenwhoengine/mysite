<?php
/**
 * 以官方 checksums API 的 JSON（建置時 hash 鎖定）核對 WordPress 核心檔 md5，等同 `wp core verify-checksums`，
 * 但不需執行期對外連線。wp-content/ 不在核對範圍（外掛另以 zip hash 鎖定）。
 * 用法：php verify-core.php /var/www/wp /opt/mysite/wp-core-checksums.json
 */
[$self, $root, $json] = $argv + [null, '/var/www/wp', '/opt/mysite/wp-core-checksums.json'];
$data = json_decode((string) file_get_contents($json), true);
$sums = $data['checksums'] ?? null;
if (! is_array($sums) || $sums === []) {
    fwrite(STDERR, "checksums JSON 無效\n");
    exit(30);
}
$bad = 0;
$checked = 0;
foreach ($sums as $file => $md5) {
    if (str_starts_with($file, 'wp-content/')) {
        continue;
    }
    $path = rtrim($root, '/').'/'.$file;
    if (! is_file($path)) {
        fwrite(STDERR, "缺少核心檔：{$file}\n");
        $bad++;
        continue;
    }
    if (md5_file($path) !== $md5) {
        fwrite(STDERR, "核心檔不符：{$file}\n");
        $bad++;
    }
    $checked++;
}
echo json_encode(['checked' => $checked, 'mismatched' => $bad]), "\n";
exit($bad === 0 ? 0 : 30);
