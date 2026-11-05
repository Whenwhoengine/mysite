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
