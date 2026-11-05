<?php
/**
 * 內容事件 outbox：hook 觀察內容變更 → 遞增 content_version → 寫入 outbox 與對帳索引；
 * wp-jobs 以 WP-CLI 投遞（HMAC 簽章）與每分鐘對帳。
 *
 * - 不在編輯請求內同步呼叫 Laravel；hook 只寫本機資料庫。
 * - WordPress 核心儲存與 outbox 寫入不是同一個原子交易（v5 §8.5）；
 *   以 `reconcile` 比對 index 指紋與 wp_posts 補送漏掉的事件、偵測已刪除內容。
 * - outbox 寫入、index 更新與版本 meta 在同一個 DB 交易內，並以 GET_LOCK 序列化同一內容。
 *
 * @package SiteContent
 */

namespace SiteContent;

defined( 'ABSPATH' ) || exit;

final class Outbox {

	public const MAX_ATTEMPTS      = 10;      // 暫定值（docs/設定目錄.md 暫定值清單）：10 次後進死信。
	public const BACKOFF_BASE      = 30;      // 暫定值：退避 30s × 2^n。
	public const BACKOFF_MAX       = 3600;    // 暫定值：退避上限 1 小時。
	public const HTTP_TIMEOUT      = 5;       // 契約：每次投遞逾時 5 秒、不跟隨 redirect。
	public const DEFAULT_LIMIT     = 50;      // 暫定值：每輪最多投遞 50 筆。
	public const LEASE_SECONDS     = 120;     // 暫定值：認領租約 120 秒（超過 wp-jobs 單輪 timeout）。
	public const MAX_HOLD_SECONDS  = 900;     // 暫定值：開發用暫停投遞的上限 15 分鐘。
	public const DEAD_HTTP         = array( 401, 409, 422 );
	public const OPTION_FAULT      = 'site_content_simulate_outbox_failure';
	public const OPTION_HOLD_UNTIL = 'site_content_jobs_hold_until';
	public const EVENT_PATH        = '/internal/content-events/v1';

	/** @var array<int,bool> 待於 shutdown 觀察的內容。 */
	private static array $pending = array();

	/** @var array<int,string> 刪除中的內容 → post type。 */
	private static array $deleting = array();

	public static function init(): void {
		add_action( 'wp_after_insert_post', array( self::class, 'on_after_insert' ), 100, 4 );
		add_action( 'transition_post_status', array( self::class, 'on_transition' ), 100, 3 );
		add_action( 'before_delete_post', array( self::class, 'on_before_delete' ), 10, 2 );
		add_action( 'deleted_post', array( self::class, 'on_deleted' ), 100, 2 );
		add_action( 'acf/save_post', array( self::class, 'on_acf_save' ), 100 );
		add_action( 'set_object_terms', array( self::class, 'on_set_terms' ), 100, 4 );
		foreach ( array( 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ) as $hook ) {
			add_action( $hook, array( self::class, 'on_meta' ), 100, 3 );
		}
		add_action( 'shutdown', array( self::class, 'flush_pending' ), 1 );
	}

	// ---------------------------------------------------------------------
	// Hooks
	// ---------------------------------------------------------------------

	private static function tracked( ?\WP_Post $post ): bool {
		return $post instanceof \WP_Post && null !== Support::type_for( $post->post_type ) && ! in_array( $post->post_status, array( 'auto-draft', 'inherit' ), true );
	}

	public static function on_after_insert( $post_id, $post = null, $update = false, $post_before = null ): void {
		$post = $post instanceof \WP_Post ? $post : get_post( (int) $post_id );
		if ( self::tracked( $post ) ) {
			self::safe_observe( (int) $post->ID, 'hook' );
		}
	}

	public static function on_transition( $new_status, $old_status, $post ): void {
		if ( $post instanceof \WP_Post && null !== Support::type_for( $post->post_type ) ) {
			self::$pending[ (int) $post->ID ] = true;
		}
	}

	public static function on_before_delete( $post_id, $post = null ): void {
		$post = $post instanceof \WP_Post ? $post : get_post( (int) $post_id );
		if ( $post instanceof \WP_Post && null !== Support::type_for( $post->post_type ) ) {
			self::$deleting[ (int) $post->ID ] = $post->post_type;
		}
	}

	public static function on_deleted( $post_id, $post = null ): void {
		$post_id = (int) $post_id;
		if ( isset( self::$deleting[ $post_id ] ) ) {
			$post_type = self::$deleting[ $post_id ];
			unset( self::$deleting[ $post_id ], self::$pending[ $post_id ] );
			try {
				if ( self::fault_injected() ) {
					Support::log( 'warning', 'outbox_fault_injected', array( 'content_id' => $post_id ) );
					return;
				}
				self::observe_deleted( $post_id, $post_type, 'hook' );
			} catch ( \Throwable $e ) {
				Support::log( 'error', 'outbox_observe_failed', array( 'content_id' => $post_id, 'error' => $e->getMessage() ) );
			}
		}
	}

	public static function on_acf_save( $post_id ): void {
		if ( is_numeric( $post_id ) && (int) $post_id > 0 ) {
			$post = get_post( (int) $post_id );
			if ( self::tracked( $post ) ) {
				self::safe_observe( (int) $post_id, 'hook' );
			}
		}
	}

	public static function on_set_terms( $object_id, $terms = null, $tt_ids = null, $taxonomy = '' ): void {
		if ( in_array( $taxonomy, array( 'category', 'post_tag' ), true ) ) {
			self::$pending[ (int) $object_id ] = true;
		}
	}

	public static function on_meta( $meta_id, $object_id, $meta_key ): void {
		if ( is_string( $meta_key ) && in_array( $meta_key, Content_Model::WATCHED_META, true ) ) {
			self::$pending[ (int) $object_id ] = true;
		}
	}

	public static function flush_pending(): void {
		$ids            = array_keys( self::$pending );
		self::$pending  = array();
		foreach ( $ids as $id ) {
			$post = get_post( $id );
			if ( null === $post || self::tracked( $post ) ) {
				self::safe_observe( (int) $id, 'hook' );
			}
		}
	}

	/**
	 * hook 內觀察：任何錯誤只記錄，不影響編輯請求；漏掉的變更由對帳補上。
	 */
	private static function safe_observe( int $post_id, string $reason ): void {
		try {
			self::observe( $post_id, $reason );
		} catch ( \Throwable $e ) {
			Support::log( 'error', 'outbox_observe_failed', array( 'content_id' => $post_id, 'error' => $e->getMessage() ) );
		}
	}

	/**
	 * 故障注入（非 production 才生效）：模擬「WP 已儲存內容，但 hook／outbox 寫入失敗」。
	 */
	public static function fault_injected(): bool {
		return ! Support::is_production() && (bool) get_option( self::OPTION_FAULT, false );
	}

	/**
	 * 開發用暫停（非 production 才生效、最長 15 分鐘）：自動投遞與對帳暫停，供契約測試避免與 wp-jobs 競爭。
	 */
	public static function hold_active(): bool {
		if ( Support::is_production() ) {
			return false;
		}
		$until = (int) get_option( self::OPTION_HOLD_UNTIL, 0 );
		$now   = time();
		return $until > $now && ( $until - $now ) <= self::MAX_HOLD_SECONDS;
	}

	// ---------------------------------------------------------------------
	// 觀察與記錄
	// ---------------------------------------------------------------------

	/**
	 * 觀察一篇內容；有變更時遞增版本並（必要時）寫入事件。
	 *
	 * @param bool $force resync：即使指紋未變也遞增版本並送事件。
	 * @return array<string,mixed>|null 新事件（未產生事件時為 null）。
	 */
	public static function observe( int $post_id, string $reason = 'hook', bool $force = false ): ?array {
		unset( self::$pending[ $post_id ] );
		clean_post_cache( $post_id );
		$post = get_post( $post_id );
		if ( ! $post ) {
			return self::observe_deleted( $post_id, null, $reason, $force );
		}
		$type = Support::type_for( $post->post_type );
		if ( null === $type || in_array( $post->post_status, array( 'auto-draft', 'inherit' ), true ) ) {
			return null;
		}
		if ( 'hook' === $reason && self::fault_injected() ) {
			Support::log( 'warning', 'outbox_fault_injected', array( 'content_id' => $post_id ) );
			return null;
		}
		return self::record( $post_id, $type, Content_Model::fingerprint( $post ), self::event_status( $post->post_status ), Content_Model::is_public( $post ), true, $force );
	}

	/**
	 * 已刪除內容：以 index（或刪除前的 post type）判斷型別。
	 *
	 * @return array<string,mixed>|null
	 */
	public static function observe_deleted( int $post_id, ?string $post_type, string $reason = 'hook', bool $force = false ): ?array {
		global $wpdb;
		$type = null === $post_type ? null : Support::type_for( $post_type );
		if ( null === $type ) {
			$type = $wpdb->get_var( $wpdb->prepare( 'SELECT content_type FROM ' . Setup::table( 'index' ) . ' WHERE post_id = %d', $post_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$type = is_string( $type ) && null !== Support::post_type_for( $type ) ? $type : null;
		}
		if ( null === $type ) {
			return null;
		}
		$event = self::record( $post_id, $type, 'deleted', 'deleted', false, false, $force );
		$wpdb->delete( Setup::table( 'preview_snapshots' ), array( 'post_id' => $post_id ), array( '%d' ) );
		return $event;
	}

	public static function event_status( string $post_status ): string {
		return in_array( $post_status, Support::EVENT_STATUSES, true ) ? $post_status : 'draft';
	}

	/**
	 * 事件種類：只有公開狀態相關的變化才需要通知主站。
	 */
	public static function event_type( bool $was_public, bool $is_public, string $status, bool $had_row, bool $force ): ?string {
		if ( 'deleted' === $status ) {
			return ( $had_row || $force ) ? 'content.deleted' : null;
		}
		if ( $is_public ) {
			return $was_public ? 'content.updated' : 'content.published';
		}
		if ( $was_public ) {
			return 'content.unpublished';
		}
		return $force ? 'content.unpublished' : null;
	}

	/**
	 * 在鎖與交易內：比對 index、遞增版本、寫入 outbox、更新 index。
	 *
	 * @return array<string,mixed>|null
	 */
	private static function record( int $post_id, string $type, string $fingerprint, string $status, bool $is_public, bool $exists, bool $force ): ?array {
		global $wpdb;
		$index = Setup::table( 'index' );
		$lock  = 'sc_obs_' . substr( md5( $wpdb->prefix . DB_NAME ), 0, 12 ) . '_' . $post_id;

		$got = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 10)', $lock ) );
		if ( '1' !== (string) $got ) {
			throw new \RuntimeException( '無法取得內容觀察鎖' );
		}
		$event = null;
		try {
			$wpdb->query( 'START TRANSACTION' );
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$index} WHERE post_id = %d FOR UPDATE", $post_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			self::assert_db();
			if ( is_array( $row ) && hash_equals( (string) $row['fingerprint'], $fingerprint ) && ! $force ) {
				$wpdb->query( 'COMMIT' );
				return null;
			}
			$was_public   = is_array( $row ) && '1' === (string) $row['is_public'];
			$meta_version = $exists ? Content_Model::content_version( $post_id ) : 0;
			$version      = max( is_array( $row ) ? (int) $row['content_version'] : 0, $meta_version ) + 1;
			$event_type   = self::event_type( $was_public, $is_public, $status, is_array( $row ), $force );
			$now          = Support::mysql_utc();

			if ( null !== $event_type ) {
				$event_id = wp_generate_uuid4();
				$occurred = Support::now();
				$body     = (string) wp_json_encode(
					array(
						'event_id'        => $event_id,
						'event_type'      => $event_type,
						'content_type'    => $type,
						'content_id'      => $post_id,
						'content_version' => $version,
						'status'          => $status,
						'occurred_at'     => $occurred,
					),
					JSON_UNESCAPED_SLASHES
				);
				$ok = $wpdb->insert(
					Setup::table( 'outbox' ),
					array(
						'event_id'        => $event_id,
						'event_type'      => $event_type,
						'content_type'    => $type,
						'content_id'      => $post_id,
						'content_version' => $version,
						'status'          => $status,
						'occurred_at'     => gmdate( 'Y-m-d H:i:s', (int) strtotime( $occurred ) ),
						'body'            => $body,
						'state'           => 'pending',
						'attempts'        => 0,
						'next_attempt_at' => $now,
						'created_at'      => $now,
						'updated_at'      => $now,
					),
					array( '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s' )
				);
				if ( false === $ok ) {
					throw new \RuntimeException( 'outbox 寫入失敗' );
				}
				$event = array(
					'event_id'        => $event_id,
					'event_type'      => $event_type,
					'content_version' => $version,
					'status'          => $status,
				);
			}

			if ( $exists ) {
				update_post_meta( $post_id, Content_Model::META_VERSION, $version );
			}
			$wpdb->query(
				$wpdb->prepare(
					"INSERT INTO {$index} (post_id, content_type, content_version, fingerprint, status, is_public, last_event_id, observed_at)
					VALUES (%d, %s, %d, %s, %s, %d, NULLIF(%s, ''), %s) AS incoming
					ON DUPLICATE KEY UPDATE content_type = incoming.content_type, content_version = incoming.content_version,
						fingerprint = incoming.fingerprint, status = incoming.status, is_public = incoming.is_public,
						last_event_id = COALESCE(incoming.last_event_id, {$index}.last_event_id), observed_at = incoming.observed_at", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$post_id,
					$type,
					$version,
					$fingerprint,
					$status,
					$is_public ? 1 : 0,
					(string) ( $event['event_id'] ?? '' ),
					$now
				)
			);
			self::assert_db();
			$wpdb->query( 'COMMIT' );
		} catch ( \Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			throw $e;
		} finally {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
		return $event;
	}

	private static function assert_db(): void {
		global $wpdb;
		if ( '' !== (string) $wpdb->last_error ) {
			throw new \RuntimeException( '資料庫錯誤：' . $wpdb->last_error );
		}
	}

	// ---------------------------------------------------------------------
	// 投遞
	// ---------------------------------------------------------------------

	/**
	 * 驗證事件接收端網址（固定路徑 /internal/content-events/v1）。
	 */
	public static function endpoint( ?string $override ): string {
		if ( null !== $override && '' !== $override ) {
			if ( ! Support::is_development() ) {
				throw new \InvalidArgumentException( '--endpoint 只允許在 development 環境使用。' );
			}
			$url = $override;
		} else {
			$url = defined( 'MYSITE_CONTENT_EVENT_URL' ) ? (string) MYSITE_CONTENT_EVENT_URL : '';
		}
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || ! in_array( strtolower( (string) ( $parts['scheme'] ?? '' ) ), array( 'http', 'https' ), true ) || empty( $parts['host'] ) || isset( $parts['user'] ) ) {
			throw new \InvalidArgumentException( '事件接收端網址不合法。' );
		}
		if ( self::EVENT_PATH !== ( $parts['path'] ?? '' ) ) {
			throw new \InvalidArgumentException( '事件接收端路徑必須是 ' . self::EVENT_PATH );
		}
		return $url;
	}

	public static function signature( string $timestamp, string $event_id, string $body ): string {
		return hash_hmac( 'sha256', $timestamp . "\n" . $event_id . "\n" . $body, (string) MYSITE_CONTENT_EVENT_HMAC_KEY );
	}

	public static function backoff_seconds( int $attempts ): int {
		return (int) min( self::BACKOFF_MAX, self::BACKOFF_BASE * ( 2 ** max( 0, $attempts - 1 ) ) );
	}

	/**
	 * 認領並投遞到期事件。
	 *
	 * @param array{endpoint?:?string,event?:?string,limit?:int,ignore_schedule?:bool} $opts 選項。
	 * @return array<string,int>
	 */
	public static function deliver( array $opts = array() ): array {
		global $wpdb;
		if ( ! defined( 'MYSITE_CONTENT_EVENT_HMAC_KEY' ) || '' === (string) MYSITE_CONTENT_EVENT_HMAC_KEY || ! defined( 'MYSITE_CONTENT_EVENT_KEY_ID' ) ) {
			throw new \RuntimeException( '缺少事件簽章金鑰或 key ID 設定。' );
		}
		$endpoint = self::endpoint( $opts['endpoint'] ?? null );
		$limit    = max( 1, min( 500, (int) ( $opts['limit'] ?? self::DEFAULT_LIMIT ) ) );
		$table    = Setup::table( 'outbox' );
		$token    = bin2hex( random_bytes( 16 ) );
		$now      = Support::mysql_utc();
		$lease    = Support::mysql_utc( self::LEASE_SECONDS );

		if ( ! empty( $opts['event'] ) ) {
			// 指定事件：--ignore-schedule 時不看 next_attempt_at（仍只處理 pending 且未被他人認領者）。
			$due = empty( $opts['ignore_schedule'] ) ? $now : '9999-12-31 23:59:59';
			$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET lock_token = %s, locked_until = %s WHERE event_id = %s AND state = 'pending' AND next_attempt_at <= %s AND (locked_until IS NULL OR locked_until < %s)", $token, $lease, (string) $opts['event'], $due, $now ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		} else {
			$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET lock_token = %s, locked_until = %s WHERE state = 'pending' AND next_attempt_at <= %s AND (locked_until IS NULL OR locked_until < %s) ORDER BY id ASC LIMIT %d", $token, $lease, $now, $now, $limit ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		}
		self::assert_db();
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE lock_token = %s ORDER BY id ASC", $token ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$stats = array(
			'claimed'   => 0,
			'delivered' => 0,
			'retry'     => 0,
			'dead'      => 0,
		);
		foreach ( (array) $rows as $row ) {
			++$stats['claimed'];
			$result = self::send( $endpoint, $row );
			++$stats[ $result ];
		}
		return $stats;
	}

	/**
	 * 投遞單筆：每次重新產生 timestamp 與簽章；body 位元組不變。
	 *
	 * @param array<string,mixed> $row outbox 列。
	 * @return string delivered|retry|dead
	 */
	private static function send( string $endpoint, array $row ): string {
		global $wpdb;
		$timestamp = (string) time();
		$event_id  = (string) $row['event_id'];
		$body      = (string) $row['body'];
		$response  = wp_remote_post(
			$endpoint,
			array(
				'body'                => $body,
				'headers'             => array(
					'Content-Type'       => 'application/json',
					'Accept'             => 'application/json',
					'X-Mysite-Key-Id'    => (string) MYSITE_CONTENT_EVENT_KEY_ID,
					'X-Mysite-Timestamp' => $timestamp,
					'X-Mysite-Signature' => self::signature( $timestamp, $event_id, $body ),
					'X-Mysite-Event-Id'  => $event_id,
				),
				'timeout'             => self::HTTP_TIMEOUT,
				'redirection'         => 0,
				'httpversion'         => '1.1',
				'blocking'            => true,
				'sslverify'           => true,
				'reject_unsafe_urls'  => false,
				'limit_response_size' => 65536,
				'user-agent'          => 'mysite-site-content/' . ( defined( 'SITE_CONTENT_VERSION' ) ? SITE_CONTENT_VERSION : '0' ),
			)
		);

		$attempts = (int) $row['attempts'] + 1;
		$now      = Support::mysql_utc();
		$code     = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
		$error    = null;
		if ( is_wp_error( $response ) ) {
			$error = 'transport: ' . $response->get_error_code();
		} elseif ( $code < 200 || $code >= 300 ) {
			$snippet = (string) preg_replace( '/[^\x20-\x7E]/', '', substr( (string) wp_remote_retrieve_body( $response ), 0, 120 ) );
			$error   = 'HTTP ' . $code . ( '' !== $snippet ? ' ' . $snippet : '' );
		}

		if ( null === $error ) {
			$state = 'delivered';
			$next  = $row['next_attempt_at'];
		} elseif ( in_array( $code, self::DEAD_HTTP, true ) || $attempts >= self::MAX_ATTEMPTS ) {
			$state = 'dead';
			$next  = $row['next_attempt_at'];
		} else {
			$state = 'pending';
			$next  = Support::mysql_utc( self::backoff_seconds( $attempts ) );
		}
		$wpdb->update(
			Setup::table( 'outbox' ),
			array(
				'state'            => $state,
				'attempts'         => $attempts,
				'next_attempt_at'  => $next,
				'last_error'       => null === $error ? null : substr( $error, 0, 255 ),
				'last_http_status' => $code > 0 ? $code : null,
				'locked_until'     => null,
				'lock_token'       => null,
				'updated_at'       => $now,
				'delivered_at'     => 'delivered' === $state ? $now : null,
			),
			array( 'id' => (int) $row['id'] ),
			array( '%s', '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);
		if ( 'delivered' !== $state ) {
			Support::log(
				'dead' === $state ? 'error' : 'warning',
				'dead' === $state ? 'outbox_event_dead' : 'outbox_delivery_retry',
				array(
					'event_id' => $event_id,
					'attempts' => $attempts,
					'status'   => $code,
					'error'    => $error,
				)
			);
		}
		return 'delivered' === $state ? 'delivered' : ( 'dead' === $state ? 'dead' : 'retry' );
	}

	/**
	 * 重播：同 event_id、同 body 重新排入（死信或已送達皆可）。
	 */
	public static function replay( string $event_id ): bool {
		global $wpdb;
		$now     = Support::mysql_utc();
		$updated = $wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . Setup::table( 'outbox' ) . " SET state = 'pending', attempts = 0, next_attempt_at = %s, last_error = NULL, locked_until = NULL, lock_token = NULL, replay_count = replay_count + 1, updated_at = %s WHERE event_id = %s AND state IN ('dead','delivered','pending')", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$now,
				$now,
				$event_id
			)
		);
		if ( $updated ) {
			Support::log( 'info', 'outbox_event_replayed', array( 'event_id' => $event_id ) );
		}
		return (bool) $updated;
	}

	// ---------------------------------------------------------------------
	// 對帳
	// ---------------------------------------------------------------------

	/**
	 * 比對 index 與 wp_posts 指紋、補送漏掉的事件、偵測已刪除內容、清除過期預覽快照。
	 *
	 * @return array<string,int>
	 */
	public static function reconcile(): array {
		global $wpdb;
		$stats      = array(
			'scanned'          => 0,
			'repaired'         => 0,
			'events'           => 0,
			'deleted_detected' => 0,
			'snapshots_purged' => 0,
		);
		$types      = Support::post_types();
		$type_in    = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$index      = Setup::table( 'index' );
		$last_id    = 0;
		$batch_size = 200;

		do {
			$ids = array_map(
				'intval',
				(array) $wpdb->get_col(
					$wpdb->prepare(
						"SELECT ID FROM {$wpdb->posts} WHERE ID > %d AND post_type IN ({$type_in}) AND post_status NOT IN ('auto-draft','inherit') ORDER BY ID ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
						array_merge( array( $last_id ), $types, array( $batch_size ) )
					)
				)
			);
			if ( array() === $ids ) {
				break;
			}
			$last_id = (int) end( $ids );
			_prime_post_caches( $ids, true, true );
			$id_in   = implode( ',', $ids );
			$known   = array();
			foreach ( (array) $wpdb->get_results( "SELECT post_id, fingerprint FROM {$index} WHERE post_id IN ({$id_in})", ARRAY_A ) as $row ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$known[ (int) $row['post_id'] ] = (string) $row['fingerprint'];
			}
			foreach ( $ids as $id ) {
				++$stats['scanned'];
				$post = get_post( $id );
				if ( ! $post ) {
					continue;
				}
				if ( isset( $known[ $id ] ) && hash_equals( $known[ $id ], Content_Model::fingerprint( $post ) ) ) {
					continue;
				}
				++$stats['repaired'];
				if ( null !== self::observe( $id, 'reconcile' ) ) {
					++$stats['events'];
				}
			}
		} while ( count( $ids ) === $batch_size );

		$gone = $wpdb->get_results( "SELECT i.post_id, i.content_type FROM {$index} i LEFT JOIN {$wpdb->posts} p ON p.ID = i.post_id WHERE p.ID IS NULL AND i.status <> 'deleted'", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( (array) $gone as $row ) {
			++$stats['deleted_detected'];
			if ( null !== self::observe_deleted( (int) $row['post_id'], Support::post_type_for( (string) $row['content_type'] ), 'reconcile' ) ) {
				++$stats['events'];
			}
		}

		$stats['snapshots_purged'] = (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Setup::table( 'preview_snapshots' ) . ' WHERE expires_at < %s', Support::mysql_utc( -3600 ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return $stats;
	}
}
