<?php
/**
 * site-content 外掛的單元層自我測試（不寫入資料庫）。
 *
 * 執行：`bash scripts/cloud/dev.sh wp eval-file - < wp/tests/selftest.php`
 * （契約測試入口 tests/contracts/wp/run.sh 的 php_unit_selftest 會自動執行）。
 * 輸出一行 JSON：{"passed":N,"failed":[...]}；有失敗時以 exit 30 結束。
 *
 * @package SiteContent
 */

use SiteContent\Content_Model;
use SiteContent\Keyring;
use SiteContent\Outbox;
use SiteContent\Rest_Public;
use SiteContent\Support;
use SiteContent\Ticket;

$failed = array();
$passed = 0;
$assert = static function ( bool $cond, string $label ) use ( &$failed, &$passed ): void {
	if ( $cond ) {
		++$passed;
	} else {
		$failed[] = $label;
	}
};

// ---- YouTube ID 解析：host 白名單與 11 碼格式 ----
$yt = array(
	'https://www.youtube.com/watch?v=Abc-_123456'         => 'Abc-_123456',
	'https://youtube.com/watch?v=Abc-_123456&t=10'        => 'Abc-_123456',
	'https://youtu.be/Abc-_123456'                        => 'Abc-_123456',
	'https://www.youtube.com/embed/Abc-_123456'           => 'Abc-_123456',
	'https://www.youtube.com/shorts/Abc-_123456'          => 'Abc-_123456',
	'https://www.youtube-nocookie.com/embed/Abc-_123456'  => 'Abc-_123456',
	'https://evil.example/watch?v=Abc-_123456'            => null,
	'https://www.youtube.com.evil.example/watch?v=Abc-_123456' => null,
	'https://www.youtube.com/watch?v=short'               => null,
	'https://www.youtube.com/watch?v=Abc-_1234567'        => null,
	'javascript:alert(1)//youtube.com/watch?v=Abc-_123456' => null,
	'https://user:pw@www.youtube.com/watch?v=Abc-_123456' => null,
	'ftp://www.youtube.com/watch?v=Abc-_123456'           => null,
	'<iframe src="https://www.youtube.com/embed/Abc-_123456"></iframe>' => null,
	''                                                    => null,
);
foreach ( $yt as $url => $expected ) {
	$assert( Content_Model::youtube_id( $url ) === $expected, 'youtube_id: ' . $url );
}

// ---- 音訊來源政策 ----
$home = home_url();
$assert( Content_Model::is_allowed_audio_url( $home . '/wp-content/uploads/a.mp3' ), 'audio: CMS 自身 host 允許' );
$assert( ! Content_Model::is_allowed_audio_url( 'https://evil.example/a.mp3' ), 'audio: 其他 host 拒絕' );
$assert( ! Content_Model::is_allowed_audio_url( 'http://169.254.169.254/latest/meta-data' ), 'audio: metadata 位址拒絕' );
$assert( ! Content_Model::is_allowed_audio_url( "https://evil.example/a.mp3\nx" ), 'audio: 換行拒絕' );

// ---- slug 規則 ----
foreach ( array( 'sample-post', '%e7%a4%ba%e4%be%8b', 'a', str_repeat( 'a', 200 ) ) as $slug ) {
	$assert( Rest_Public::valid_slug( $slug ), 'slug 合法: ' . substr( $slug, 0, 20 ) );
}
foreach ( array( '', 'Upper', 'a_b', 'a b', '../x', str_repeat( 'a', 201 ), array( 'x' ), 5 ) as $slug ) {
	$assert( ! Rest_Public::valid_slug( $slug ), 'slug 不合法: ' . wp_json_encode( $slug ) );
}

// ---- 正整數參數 ----
$assert( 12 === Support::positive_int( '12', 50 ), 'positive_int 12' );
foreach ( array( '0', '-1', '1.5', '012', '+3', '51', 'abc', '', ' 1', '1e3' ) as $bad ) {
	$assert( null === Support::positive_int( $bad, 50 ), 'positive_int 拒絕 ' . $bad );
}

// ---- 選單自訂連結正規化 ----
$app = Support::app_url();
$cases = array(
	'/articles'                   => array( '/articles', false ),
	'//evil.example/x'            => array( null, false ),
	'javascript:alert(1)'         => array( null, false ),
	'http://external.example/'    => array( null, false ),
	'https://external.example/a'  => array( 'https://external.example/a', true ),
	'mailto:a@example.com'        => array( null, false ),
	$app . '/videos?x=1'          => array( '/videos?x=1', false ),
);
foreach ( $cases as $in => $out ) {
	$assert( Rest_Public::custom_url( $in ) === $out, 'menu custom_url: ' . $in );
}

// ---- 事件種類 ----
$assert( 'content.published' === Outbox::event_type( false, true, 'publish', false, false ), 'event: 首次公開' );
$assert( 'content.updated' === Outbox::event_type( true, true, 'publish', true, false ), 'event: 公開中修改' );
$assert( 'content.unpublished' === Outbox::event_type( true, false, 'draft', true, false ), 'event: 撤下' );
$assert( null === Outbox::event_type( false, false, 'draft', true, false ), 'event: 草稿修改不送事件' );
$assert( 'content.deleted' === Outbox::event_type( true, false, 'deleted', true, false ), 'event: 刪除' );
$assert( null === Outbox::event_type( false, false, 'deleted', false, false ), 'event: 從未索引的刪除不送' );
$assert( 'content.unpublished' === Outbox::event_type( false, false, 'draft', true, true ), 'event: resync 非公開' );
$assert( array( 30, 60, 120, 240, 480, 960, 1920, 3600, 3600 ) === array_map( array( Outbox::class, 'backoff_seconds' ), range( 1, 9 ) ), 'backoff 30s×2^n 上限 1 小時' );

// ---- 預覽票券驗證 ----
$sid    = str_repeat( 'a', 32 );
$ticket = Ticket::issue( 'post', 123, $sid, 456, 7 );
$claims = Ticket::verify( $ticket );
$assert( is_array( $claims ) && 123 === $claims['cid'] && $sid === $claims['sid'] && 7 === $claims['sub'], 'ticket: 自己簽發的可驗證' );
$assert( null === Ticket::verify( $ticket, time() + Ticket::ttl() + 1 ), 'ticket: 過期拒絕' );
$parts = explode( '.', $ticket );
$assert( null === Ticket::verify( $parts[0] . '.' . $parts[1] . '.' . strrev( $parts[2] ) ), 'ticket: 簽章竄改拒絕' );
$assert( null === Ticket::verify( $parts[0] . '.' . $parts[1] ), 'ticket: 段數不符拒絕' );
$assert( null === Ticket::verify( $ticket . '=' ), 'ticket: padding 拒絕' );
$assert( null === Ticket::verify( str_repeat( 'a', 5000 ) ), 'ticket: 過長拒絕' );

// ---- 金鑰圈（GAP-KEYRING-01）：WP 以 active key 簽、帶 key ID；依 kid 驗證；retire 後舊 kid 拒絕 ----
$active_preview = Keyring::active( Keyring::PREVIEW );
$head           = json_decode( (string) Ticket::b64u_decode( $parts[0] ), true );
$assert( is_array( $head ) && array( 'alg', 'typ', 'kid' ) === array_keys( $head ) && 'HS256' === $head['alg'] && 'mysite-preview' === $head['typ'], 'ticket: header 為 alg／typ／kid' );
$assert( is_array( $head ) && $active_preview['id'] === $head['kid'], 'ticket: kid 為目前 active key ID' );
$assert( 1 === preg_match( Keyring::KEY_ID_PATTERN, (string) ( $head['kid'] ?? '' ) ), 'ticket: kid 格式 k[0-9]{1,6}' );
$assert( hash_equals( Ticket::b64u( hash_hmac( 'sha256', $parts[0] . '.' . $parts[1], $active_preview['key'], true ) ), $parts[2] ), 'ticket: 以 active key 簽章' );
// 容器有掛金鑰圈（*_KEYRING_FILE）時，wp-config 必須實際載入；沒掛時（舊部署）沿用單一金鑰，視為 k1 active
$ring_files = array(
	'preview' => (string) getenv( 'PREVIEW_SIGNING_KEYRING_FILE' ),
	'event'   => (string) getenv( 'CONTENT_EVENT_HMAC_KEYRING_FILE' ),
);
if ( '' !== $ring_files['preview'] ) {
	$assert( defined( 'MYSITE_PREVIEW_SIGNING_KEYRING' ), 'wp-config 已載入預覽金鑰圈（PREVIEW_SIGNING_KEYRING_FILE）' );
}
if ( '' !== $ring_files['event'] ) {
	$assert( defined( 'MYSITE_CONTENT_EVENT_HMAC_KEYRING' ), 'wp-config 已載入事件金鑰圈（CONTENT_EVENT_HMAC_KEYRING_FILE）' );
}
if ( defined( 'MYSITE_PREVIEW_SIGNING_KEY_ID' ) ) {
	$assert( MYSITE_PREVIEW_SIGNING_KEY_ID === $active_preview['id'], 'ticket: wp-config 的 active key ID 與金鑰圈一致' );
} else {
	$assert( 'k1' === $active_preview['id'], 'ticket: 沒有金鑰圈時 kid 為 k1' );
}
$assert( array_values( array_filter( Keyring::states( Keyring::PREVIEW ), static fn ( $s ) => 'active' === $s ) ) === array( 'active' ), 'preview 金鑰圈恰好一把 active' );

$active_event = Keyring::active( Keyring::EVENT );
$assert( MYSITE_CONTENT_EVENT_KEY_ID === $active_event['id'], 'event: X-Mysite-Key-Id 使用 active key ID' );
$assert( hash_hmac( 'sha256', "1800000000\nevt\n{}", $active_event['key'] ) === Outbox::signature( '1800000000', 'evt', '{}' ), 'event: 以 active key 簽章' );
$assert( array_values( array_filter( Keyring::states( Keyring::EVENT ), static fn ( $s ) => 'active' === $s ) ) === array( 'active' ), 'event 金鑰圈恰好一把 active' );
$assert( ! hash_equals( $active_event['key'], $active_preview['key'] ), '事件與預覽金鑰用途分離（不同金鑰）' );

// 以合成金鑰圈驗證三期（只在記憶體，不碰設定）
$k1    = str_repeat( '1a', 32 );
$k2    = str_repeat( '2b', 32 );
$forge = static function ( string $key, ?string $kid ) use ( $parts ): string {
	$h = null === $kid ? Ticket::b64u( Ticket::HEADER ) : Ticket::b64u( Ticket::header_json( $kid ) );
	return $h . '.' . $parts[1] . '.' . Ticket::b64u( hash_hmac( 'sha256', $h . '.' . $parts[1], $key, true ) );
};
$ring_add     = array( 'k1' => array( 'key' => $k1, 'state' => 'active' ), 'k2' => array( 'key' => $k2, 'state' => 'accept' ) );
$ring_promote = array( 'k1' => array( 'key' => $k1, 'state' => 'accept' ), 'k2' => array( 'key' => $k2, 'state' => 'active' ) );
$ring_retire  = array( 'k2' => array( 'key' => $k2, 'state' => 'active' ) );
$assert( is_array( Ticket::verify( $forge( $k1, 'k1' ), null, $ring_add ) ), 'keyring add: active kid 通過' );
$assert( is_array( Ticket::verify( $forge( $k2, 'k2' ), null, $ring_add ) ), 'keyring add: accept kid 通過' );
$assert( is_array( Ticket::verify( $forge( $k1, 'k1' ), null, $ring_promote ) ), 'keyring promote: 舊 kid 在 retire 前仍通過' );
$assert( is_array( Ticket::verify( $forge( $k2, null ), null, $ring_promote ) ), 'keyring: 無 kid 舊格式以 active key 通過' );
$assert( null === Ticket::verify( $forge( $k1, null ), null, $ring_promote ), 'keyring: 無 kid 不嘗試 accept key' );
$assert( null === Ticket::verify( $forge( $k1, 'k9' ), null, $ring_add ), 'keyring: 未知 kid 拒絕' );
$assert( null === Ticket::verify( $forge( $k1, 'k1' ), null, $ring_retire ), 'keyring retire: 舊 kid 拒絕' );
$assert( null === Ticket::verify( $forge( $k1, 'K1' ), null, $ring_add ), 'keyring: kid 格式不合法拒絕' );
$assert( null === Ticket::verify( $forge( $k2, 'k1' ), null, $ring_add ), 'keyring: kid 與金鑰不符拒絕' );

// wp-config 的金鑰圈解析（格式錯誤即拒絕啟動）；舊映像的 wp-config 沒有此函式時，只在容器掛有金鑰圈時判失敗
if ( ! function_exists( 'mysite_wp_parse_keyring' ) ) {
	$assert( '' === $ring_files['preview'] && '' === $ring_files['event'], 'wp-config 缺少金鑰圈解析（WordPress 映像需重建）' );
	$ring_cases = array();
	$valid_ring = null;
} else {
	$valid_ring = mysite_wp_parse_keyring( "k1 {$k1} accept\r\n\nk2\t{$k2}  active\n" );
	$ring_cases = array(
		'兩把 active'   => "k1 {$k1} active\nk2 {$k2} active",
		'沒有 active'   => "k1 {$k1} accept",
		'不是 64 hex'   => 'k1 abc active',
		'key ID 不合法' => "K1 {$k1} active",
		'狀態不合法'    => "k1 {$k1} primary",
		'欄位數錯'      => "k1 {$k1}",
		'重複 key ID'   => "k1 {$k1} active\nk1 {$k2} accept",
		'空內容'        => "\n",
	);
}
if ( null !== $valid_ring ) {
	$assert( is_array( $valid_ring ) && 'active' === $valid_ring['k2']['state'] && $k1 === $valid_ring['k1']['key'], 'keyring parse: 合法內容' );
}
foreach ( $ring_cases as $label => $raw ) {
	$parsed = mysite_wp_parse_keyring( $raw );
	$assert( is_string( $parsed ) && false === strpos( $parsed, $k1 ) && false === strpos( $parsed, $k2 ), 'keyring parse 拒絕（不含金鑰）: ' . $label );
}

echo wp_json_encode(
	array(
		'passed' => $passed,
		'failed' => $failed,
	),
	JSON_UNESCAPED_UNICODE
) . "\n";
if ( $failed ) {
	exit( 30 );
}
