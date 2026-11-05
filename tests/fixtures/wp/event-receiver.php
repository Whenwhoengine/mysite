<?php
/**
 * 契約測試用的內容事件接收端（只在測試期間以 `php -S 127.0.0.1:<port>` 於 wp-php 容器內啟動）。
 *
 * - 回應狀態碼由 <dir>/mode 決定（預設 202；"302" 會回 Location 測試不跟隨 redirect）。
 * - 每個請求以一行 JSON 附加到 <dir>/requests.jsonl：方法、路徑、X-Mysite-* 標頭、body（base64）。
 * - 本檔不讀取也不輸出任何秘密；簽章由主機端 Python 測試以秘密檔路徑自行驗證。
 */

$dir = getenv( 'SC_RECEIVER_DIR' ) ?: '/tmp/sc-contract-receiver';
if ( ! is_dir( $dir ) ) {
	mkdir( $dir, 0700, true );
}
$mode = is_file( $dir . '/mode' ) ? trim( (string) file_get_contents( $dir . '/mode' ) ) : '202';
$body = (string) file_get_contents( 'php://input' );

$headers = array();
foreach ( $_SERVER as $key => $value ) {
	if ( str_starts_with( $key, 'HTTP_X_MYSITE_' ) || 'CONTENT_TYPE' === $key || 'HTTP_USER_AGENT' === $key ) {
		$headers[ strtolower( str_replace( '_', '-', preg_replace( '/^HTTP_/', '', $key ) ) ) ] = (string) $value;
	}
}
$line = json_encode(
	array(
		'received_at' => time(),
		'method'      => $_SERVER['REQUEST_METHOD'] ?? '',
		'path'        => parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH ),
		'headers'     => $headers,
		'body_b64'    => base64_encode( $body ),
	),
	JSON_UNESCAPED_SLASHES
);
file_put_contents( $dir . '/requests.jsonl', $line . "\n", FILE_APPEND | LOCK_EX );

header( 'Content-Type: application/json' );
if ( '302' === $mode ) {
	header( 'Location: http://127.0.0.1:' . ( $_SERVER['SERVER_PORT'] ?? '80' ) . '/redirected-target', true, 302 );
	echo '{"redirect":true}';
	return true;
}
$code = ctype_digit( $mode ) ? (int) $mode : 202;
http_response_code( $code );
$payload = array(
	202 => '{"status":"accepted"}',
	401 => '{"error":"EVENT_SIGNATURE_INVALID"}',
	409 => '{"error":"EVENT_CONFLICT"}',
	422 => '{"error":"EVENT_INVALID"}',
);
echo $payload[ $code ] ?? '{"error":"TEST_RECEIVER_' . $code . '"}';
return true;
