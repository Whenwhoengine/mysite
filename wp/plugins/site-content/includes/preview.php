<?php
/**
 * 草稿預覽：編輯器「預覽」→ admin-post 驗 nonce 與 edit_post → 建立快照 → 簽發票券 → 302 至主站交換頁；
 * 私密端點 GET /preview/{type}/{id}?snapshot= 以服務帳號（Application Password，僅 HTTPS）＋票券讀取快照。
 *
 * 票券格式與驗證依 docs/API契約.md §5（ADR-008）。log 一律不記票券。
 *
 * @package SiteContent
 */

namespace SiteContent;

defined( 'ABSPATH' ) || exit;

/**
 * HS256 compact 預覽票券。
 */
final class Ticket {

	public const HEADER      = '{"alg":"HS256","typ":"mysite-preview"}';
	public const ISSUER      = 'mysite-cms';
	public const AUDIENCE    = 'mysite-app';
	public const TYPE        = 'preview';
	public const MAX_TTL     = 900;
	public const CLOCK_SKEW  = 60;
	public const MAX_LENGTH  = 4096;

	public static function b64u( string $raw ): string {
		return rtrim( strtr( base64_encode( $raw ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	public static function b64u_decode( string $text ): ?string {
		if ( '' === $text || ! preg_match( '/^[A-Za-z0-9_-]+$/', $text ) || 1 === strlen( $text ) % 4 ) {
			return null;
		}
		$raw = base64_decode( strtr( $text, '-_', '+/' ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		return false === $raw ? null : $raw;
	}

	private static function key(): string {
		$key = defined( 'MYSITE_PREVIEW_SIGNING_KEY' ) ? (string) MYSITE_PREVIEW_SIGNING_KEY : '';
		if ( '' === $key ) {
			throw new \RuntimeException( '缺少預覽簽章金鑰。' );
		}
		return $key;
	}

	public static function ttl(): int {
		$ttl = defined( 'MYSITE_PREVIEW_TTL_SECONDS' ) ? (int) MYSITE_PREVIEW_TTL_SECONDS : 600;
		return max( 60, min( self::MAX_TTL, $ttl ) );
	}

	public static function issue( string $ctype, int $cid, string $sid, int $rev, int $sub ): string {
		$iat    = time();
		$claims = array(
			'iss'   => self::ISSUER,
			'aud'   => self::AUDIENCE,
			'typ'   => self::TYPE,
			'ctype' => $ctype,
			'cid'   => $cid,
			'sid'   => $sid,
			'rev'   => $rev,
			'iat'   => $iat,
			'exp'   => $iat + self::ttl(),
			'jti'   => bin2hex( random_bytes( 16 ) ),
			'sub'   => $sub,
		);
		$signing = self::b64u( self::HEADER ) . '.' . self::b64u( (string) wp_json_encode( $claims, JSON_UNESCAPED_SLASHES ) );
		return $signing . '.' . self::b64u( hash_hmac( 'sha256', $signing, self::key(), true ) );
	}

	/**
	 * 驗簽與固定欄位；成功回 claims，失敗回 null（不透露是哪一項不符）。
	 *
	 * @return array<string,mixed>|null
	 */
	public static function verify( string $ticket, ?int $now = null ): ?array {
		$now = $now ?? time();
		if ( strlen( $ticket ) > self::MAX_LENGTH ) {
			return null;
		}
		$parts = explode( '.', $ticket );
		if ( 3 !== count( $parts ) ) {
			return null;
		}
		$header_raw = self::b64u_decode( $parts[0] );
		$claims_raw = self::b64u_decode( $parts[1] );
		$sig_raw    = self::b64u_decode( $parts[2] );
		if ( null === $header_raw || null === $claims_raw || null === $sig_raw ) {
			return null;
		}
		$expected = hash_hmac( 'sha256', $parts[0] . '.' . $parts[1], self::key(), true );
		if ( ! hash_equals( $expected, $sig_raw ) ) {
			return null;
		}
		$header = json_decode( $header_raw, true );
		if ( ! is_array( $header ) || 2 !== count( $header ) || 'HS256' !== ( $header['alg'] ?? null ) || 'mysite-preview' !== ( $header['typ'] ?? null ) ) {
			return null;
		}
		$c = json_decode( $claims_raw, true );
		if ( ! is_array( $c ) ) {
			return null;
		}
		foreach ( array( 'cid', 'rev', 'iat', 'exp', 'sub' ) as $int_key ) {
			if ( ! isset( $c[ $int_key ] ) || ! is_int( $c[ $int_key ] ) ) {
				return null;
			}
		}
		foreach ( array( 'iss', 'aud', 'typ', 'ctype', 'sid', 'jti' ) as $str_key ) {
			if ( ! isset( $c[ $str_key ] ) || ! is_string( $c[ $str_key ] ) ) {
				return null;
			}
		}
		if ( self::ISSUER !== $c['iss'] || self::AUDIENCE !== $c['aud'] || self::TYPE !== $c['typ'] ) {
			return null;
		}
		if ( null === Support::post_type_for( $c['ctype'] ) || $c['cid'] < 1 || $c['rev'] < 1 || $c['sub'] < 1 ) {
			return null;
		}
		if ( ! preg_match( '/^[a-f0-9]{32}$/', $c['sid'] ) || ! preg_match( '/^[a-f0-9]{32}$/', $c['jti'] ) ) {
			return null;
		}
		$lifetime = $c['exp'] - $c['iat'];
		if ( $lifetime < 1 || $lifetime > self::MAX_TTL || $c['exp'] <= $now || $c['iat'] > $now + self::CLOCK_SKEW ) {
			return null;
		}
		return $c;
	}
}

final class Preview {

	public const ACTION = 'site_content_preview';

	/** @var array<int,array<string,mixed>> 已通過驗證的快照（以 request 物件 ID 為鍵）。 */
	private static array $validated = array();

	public static function init(): void {
		add_filter( 'preview_post_link', array( self::class, 'filter_preview_link' ), 20, 2 );
		add_action( 'admin_post_' . self::ACTION, array( self::class, 'handle' ) );
		add_action( 'admin_post_nopriv_' . self::ACTION, array( self::class, 'handle_logged_out' ) );
		add_action( 'rest_api_init', array( self::class, 'register_route' ) );
		add_filter( 'rest_post_dispatch', array( self::class, 'headers' ), 100, 3 );
		add_filter( 'rest_send_nocache_headers', array( self::class, 'skip_core_nocache' ), 100 );
	}

	/**
	 * 預覽端點自行送出契約規定的 `Cache-Control: private, no-store`，不讓核心的 nocache 標頭覆寫。
	 *
	 * @param mixed $send 核心預設值。
	 * @return mixed
	 */
	public static function skip_core_nocache( $send ) {
		$uri   = (string) wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$route = isset( $_GET['rest_route'] ) ? (string) wp_unslash( $_GET['rest_route'] ) : ''; // phpcs:ignore WordPress.Security
		$path  = '/' . Support::REST_NS . '/preview/';
		return ( str_contains( $uri, '/wp-json' . $path ) || str_starts_with( $route, $path ) ) ? false : $send;
	}

	public static function nonce_action( int $post_id ): string {
		return self::ACTION . '_' . $post_id;
	}

	/**
	 * 本外掛的預覽入口（不使用 wp_nonce_url，避免 &amp; 破壞編輯器開新視窗）。
	 */
	public static function link( int $post_id ): string {
		return add_query_arg(
			array(
				'action'   => self::ACTION,
				'post'     => $post_id,
				'_wpnonce' => wp_create_nonce( self::nonce_action( $post_id ) ),
			),
			admin_url( 'admin-post.php' )
		);
	}

	public static function filter_preview_link( $link, $post ) {
		if ( $post instanceof \WP_Post && null !== Support::type_for( $post->post_type ) && is_user_logged_in() ) {
			return self::link( (int) $post->ID );
		}
		return $link;
	}

	public static function handle_logged_out(): void {
		auth_redirect();
		exit;
	}

	private static function deny( int $status, string $message ): void {
		wp_die( esc_html( $message ), esc_html( '無法預覽' ), array( 'response' => $status ) );
	}

	/**
	 * admin-post.php?action=site_content_preview&post=<id>&_wpnonce=…
	 */
	public static function handle(): void {
		nocache_headers();
		header( 'Referrer-Policy: no-referrer' );
		header( 'X-Robots-Tag: noindex, nofollow' );

		$post_id = isset( $_GET['post'] ) ? Support::positive_int( (string) wp_unslash( $_GET['post'] ) ) : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$nonce   = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( null === $post_id || ! wp_verify_nonce( $nonce, self::nonce_action( $post_id ) ) ) {
			self::deny( 403, '預覽連結已失效，請回到編輯器重新點選「預覽」。' );
			return;
		}
		$post = get_post( $post_id );
		if ( ! $post || null === Support::type_for( $post->post_type ) || 'trash' === $post->post_status ) {
			self::deny( 404, '找不到要預覽的內容。' );
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			self::deny( 403, '你沒有預覽這篇內容的權限。' );
			return;
		}
		$thumbnail = null;
		if ( isset( $_GET['_thumbnail_id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$candidate = (int) wp_unslash( $_GET['_thumbnail_id'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			$thumbnail = ( -1 === $candidate || null !== Content_Model::image( $candidate ) ) ? max( 0, $candidate ) : null;
		}
		try {
			$snapshot = self::create_snapshot( $post, get_current_user_id(), $thumbnail );
			$ticket   = Ticket::issue( $snapshot['content_type'], $post_id, $snapshot['snapshot_id'], $snapshot['revision_id'], get_current_user_id() );
		} catch ( \Throwable $e ) {
			Support::log( 'error', 'preview_issue_failed', array( 'content_id' => $post_id, 'error' => $e->getMessage() ) );
			self::deny( 500, '預覽暫時無法建立，請稍後再試。' );
			return;
		}
		Support::log( 'info', 'preview_issued', array( 'content_id' => $post_id, 'snapshot_id' => $snapshot['snapshot_id'], 'user_id' => get_current_user_id() ) );
		wp_redirect( Support::app_url() . '/preview/exchange?ticket=' . rawurlencode( $ticket ), 302, false ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
		exit;
	}

	/**
	 * 建立快照：最新 autosave（若較新）或內容本身；正文、ACF 欄位、封面寫入同一份 JSON。
	 *
	 * @return array{snapshot_id:string,revision_id:int,content_type:string,item:array<string,mixed>}
	 */
	public static function create_snapshot( \WP_Post $post, int $user_id, ?int $thumbnail_override = null ): array {
		global $wpdb;
		$type     = (string) Support::type_for( $post->post_type );
		$override = array( 'meta' => array() );
		$autosave = wp_get_post_autosave( $post->ID, $user_id );
		$rev_id   = 0;

		if ( $autosave instanceof \WP_Post && strtotime( $autosave->post_modified_gmt ) >= strtotime( $post->post_modified_gmt ) ) {
			$override['title']   = $autosave->post_title;
			$override['content'] = $autosave->post_content;
			$override['excerpt'] = $autosave->post_excerpt;
			$rev_id              = (int) $autosave->ID;
			foreach ( Content_Model::ACF_META as $key ) {
				if ( metadata_exists( 'post', $autosave->ID, $key ) ) {
					$override['meta'][ $key ] = get_post_meta( $autosave->ID, $key, true );
				}
			}
		} else {
			$revisions = wp_get_post_revisions(
				$post->ID,
				array(
					'posts_per_page' => 5,
					'orderby'        => 'ID',
					'order'          => 'DESC',
					'check_enabled'  => false,
				)
			);
			foreach ( $revisions as $revision ) {
				if ( ! wp_is_post_autosave( $revision ) ) {
					$rev_id = (int) $revision->ID;
					break;
				}
			}
			if ( 0 === $rev_id ) {
				$rev_id = (int) $post->ID; // 修訂停用時以內容 ID 代表目前保存版本。
			}
		}
		foreach ( Content_Model::ACF_META as $key ) {
			if ( ! array_key_exists( $key, $override['meta'] ) ) {
				$override['meta'][ $key ] = get_post_meta( $post->ID, $key, true );
			}
		}
		$override['thumbnail_id'] = null !== $thumbnail_override ? $thumbnail_override : (int) get_post_thumbnail_id( $post );

		$sid                 = bin2hex( random_bytes( 16 ) );
		$item                = Content_Model::build_item( $post, true, $override );
		$item['preview']     = true;
		$item['snapshot_id'] = $sid;
		$item['revision_id'] = $rev_id;
		$now                 = Support::mysql_utc();

		$ok = $wpdb->insert(
			Setup::table( 'preview_snapshots' ),
			array(
				'snapshot_id'  => $sid,
				'post_id'      => (int) $post->ID,
				'content_type' => $type,
				'revision_id'  => $rev_id,
				'issued_by'    => $user_id,
				'payload'      => (string) wp_json_encode( $item, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
				'created_at'   => $now,
				'expires_at'   => Support::mysql_utc( Ticket::ttl() ),
			),
			array( '%s', '%d', '%s', '%d', '%d', '%s', '%s', '%s' )
		);
		if ( false === $ok ) {
			throw new \RuntimeException( '預覽快照寫入失敗' );
		}
		return array(
			'snapshot_id'  => $sid,
			'revision_id'  => $rev_id,
			'content_type' => $type,
			'item'         => $item,
		);
	}

	public static function register_route(): void {
		register_rest_route(
			Support::REST_NS,
			'/preview/(?P<type>[^/]+)/(?P<id>[^/]+)',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'rest_preview' ),
				'permission_callback' => array( self::class, 'check_preview' ),
			)
		);
	}

	private static function invalid( string $reason ): \WP_Error {
		// 只記原因代碼，不記票券。
		Support::log( 'warning', 'preview_rejected', array( 'reason' => $reason ) );
		return Support::error( 'site_content_preview_invalid', '預覽票券無效或已過期', 403 );
	}

	/**
	 * 私密預覽端點的完整檢查（API-11、API-12）。
	 */
	public static function check_preview( \WP_REST_Request $request ) {
		global $wpdb;
		if ( ! is_ssl() ) {
			return self::invalid( 'not_https' );
		}
		if ( ! is_user_logged_in() || ! function_exists( 'rest_get_authenticated_app_password' ) || null === rest_get_authenticated_app_password() ) {
			return self::invalid( 'not_app_password' );
		}
		if ( ! current_user_can( 'site_content_read_preview' ) ) {
			return self::invalid( 'missing_capability' );
		}
		$type     = (string) $request['type'];
		$id       = Support::positive_int( $request['id'] );
		$query    = $request->get_query_params();
		$snapshot = isset( $query['snapshot'] ) && is_string( $query['snapshot'] ) ? $query['snapshot'] : '';
		if ( null === Support::post_type_for( $type ) || null === $id || ! preg_match( '/^[a-f0-9]{32}$/', $snapshot ) ) {
			return self::invalid( 'bad_params' );
		}
		$ticket = (string) $request->get_header( 'x_mysite_preview_ticket' );
		$claims = '' === $ticket ? null : Ticket::verify( $ticket );
		if ( null === $claims ) {
			return self::invalid( 'bad_ticket' );
		}
		if ( $claims['ctype'] !== $type || $claims['cid'] !== $id || $claims['sid'] !== $snapshot ) {
			return self::invalid( 'claims_mismatch' );
		}
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Setup::table( 'preview_snapshots' ) . ' WHERE snapshot_id = %s', $snapshot ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( ! is_array( $row ) ) {
			return self::invalid( 'snapshot_missing' );
		}
		if ( (int) $row['post_id'] !== $id || $row['content_type'] !== $type || (int) $row['revision_id'] !== $claims['rev'] || (int) $row['issued_by'] !== $claims['sub'] ) {
			return self::invalid( 'snapshot_mismatch' );
		}
		$post = get_post( $id );
		if ( ! $post || Support::post_type_for( $type ) !== $post->post_type || 'trash' === $post->post_status ) {
			return self::invalid( 'content_deleted' );
		}
		$issuer = get_userdata( $claims['sub'] );
		if ( ! $issuer || ! user_can( $issuer, 'edit_post', $id ) ) {
			return self::invalid( 'issuer_cannot_edit' );
		}
		self::$validated[ spl_object_id( $request ) ] = $row;
		return true;
	}

	public static function rest_preview( \WP_REST_Request $request ) {
		$row = self::$validated[ spl_object_id( $request ) ] ?? null;
		if ( null === $row ) {
			return self::invalid( 'not_validated' );
		}
		$item = json_decode( (string) $row['payload'], true );
		if ( ! is_array( $item ) ) {
			return self::invalid( 'payload_corrupt' );
		}
		$response = new \WP_REST_Response(
			array(
				'data' => $item,
				'meta' => Support::meta(),
			),
			200
		);
		return $response;
	}

	/**
	 * 預覽回應（含錯誤）的快取與索引標頭。
	 */
	public static function headers( $response, $server, $request ) {
		if ( $request instanceof \WP_REST_Request && str_starts_with( (string) $request->get_route(), '/' . Support::REST_NS . '/preview/' ) && $response instanceof \WP_HTTP_Response ) {
			$response->header( 'Cache-Control', 'private, no-store' );
			$response->header( 'X-Robots-Tag', 'noindex, nofollow' );
			$response->header( 'Referrer-Policy', 'no-referrer' );
			$response->header( 'Expires', '0' );
		}
		return $response;
	}
}
