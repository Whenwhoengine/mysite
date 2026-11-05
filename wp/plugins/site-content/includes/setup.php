<?php
/**
 * 安裝／升級：外掛資料表（dbDelta＋版本 option）與本外掛擁有的能力（WP-04、WP-05）。
 *
 * 原則：只處理本外掛擁有的能力集合；絕不移除其他外掛或管理員原有能力。
 * 唯一例外是本外掛建立的 mysite_service 角色：整個角色屬本外掛，強制收斂為最小權限。
 *
 * @package SiteContent
 */

namespace SiteContent;

defined( 'ABSPATH' ) || exit;

final class Setup {

	public const DB_VERSION        = '1';
	public const CAPS_VERSION      = '1';
	public const OPTION_DB_VERSION = 'site_content_db_version';
	public const OPTION_CAPS       = 'site_content_caps_version';
	public const SERVICE_ROLE      = 'mysite_service';

	/**
	 * 服務帳號角色的完整能力集合（多一項都不行）。
	 */
	public const SERVICE_CAPS = array( 'read', 'site_content_read_preview', 'site_content_diagnostics' );

	/**
	 * 取得本外掛建立的資料表完整名稱。
	 */
	public static function table( string $name ): string {
		global $wpdb;
		return $wpdb->prefix . 'site_content_' . $name;
	}

	/**
	 * 外掛擁有、要授予 administrator 與 editor 的內容能力。
	 *
	 * @return string[]
	 */
	public static function content_caps(): array {
		$caps = array();
		foreach ( Content_Types::CAPABILITY_TYPES as $pair ) {
			$plural = $pair[1];
			foreach ( array( 'edit_%s', 'edit_others_%s', 'edit_private_%s', 'edit_published_%s', 'publish_%s', 'read_private_%s', 'delete_%s', 'delete_others_%s', 'delete_private_%s', 'delete_published_%s' ) as $pattern ) {
				$caps[] = sprintf( $pattern, $plural );
			}
		}
		return $caps;
	}

	/**
	 * 本外掛擁有的全部能力（供報表與測試使用）。
	 *
	 * @return string[]
	 */
	public static function owned_caps(): array {
		return array_values( array_unique( array_merge( self::content_caps(), array( 'site_content_read_preview', 'site_content_diagnostics' ) ) ) );
	}

	public static function init(): void {
		register_activation_hook( SITE_CONTENT_FILE, array( self::class, 'activate' ) );
		// 部署新版外掛後自動升級（只比較 autoload option，成本很低）。
		add_action( 'init', array( self::class, 'maybe_upgrade' ), 1 );
	}

	public static function activate(): void {
		self::install_schema();
		self::sync_caps();
	}

	public static function maybe_upgrade(): void {
		if ( get_option( self::OPTION_DB_VERSION ) !== self::DB_VERSION ) {
			self::install_schema();
		}
		if ( get_option( self::OPTION_CAPS ) !== self::CAPS_VERSION ) {
			self::sync_caps();
		}
	}

	/**
	 * 建立／升級資料表（dbDelta 冪等）。
	 *
	 * @return string[] dbDelta 回報的變更。
	 */
	public static function install_schema(): array {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$outbox  = self::table( 'outbox' );
		$index   = self::table( 'index' );
		$snap    = self::table( 'preview_snapshots' );

		// dbDelta 格式要求：每欄一行、PRIMARY KEY 後兩個空白、使用 KEY。
		$sql = array(
			"CREATE TABLE {$outbox} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  event_id char(36) NOT NULL,
  event_type varchar(32) NOT NULL,
  content_type varchar(16) NOT NULL,
  content_id bigint(20) unsigned NOT NULL,
  content_version bigint(20) unsigned NOT NULL,
  status varchar(16) NOT NULL,
  occurred_at datetime NOT NULL,
  body blob NOT NULL,
  state varchar(16) NOT NULL DEFAULT 'pending',
  attempts int(10) unsigned NOT NULL DEFAULT 0,
  next_attempt_at datetime NOT NULL,
  last_error varchar(255) DEFAULT NULL,
  last_http_status smallint(5) unsigned DEFAULT NULL,
  locked_until datetime DEFAULT NULL,
  lock_token char(32) DEFAULT NULL,
  replay_count int(10) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  delivered_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY event_id (event_id),
  KEY state_next (state,next_attempt_at),
  KEY content (content_type,content_id),
  KEY lock_token (lock_token)
) {$charset};",
			"CREATE TABLE {$index} (
  post_id bigint(20) unsigned NOT NULL,
  content_type varchar(16) NOT NULL,
  content_version bigint(20) unsigned NOT NULL DEFAULT 0,
  fingerprint char(64) NOT NULL,
  status varchar(20) NOT NULL,
  is_public tinyint(1) NOT NULL DEFAULT 0,
  last_event_id char(36) DEFAULT NULL,
  observed_at datetime NOT NULL,
  PRIMARY KEY  (post_id),
  KEY content_type (content_type),
  KEY status (status)
) {$charset};",
			"CREATE TABLE {$snap} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  snapshot_id char(32) NOT NULL,
  post_id bigint(20) unsigned NOT NULL,
  content_type varchar(16) NOT NULL,
  revision_id bigint(20) unsigned NOT NULL,
  issued_by bigint(20) unsigned NOT NULL,
  payload longtext NOT NULL,
  created_at datetime NOT NULL,
  expires_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY snapshot_id (snapshot_id),
  KEY post_id (post_id),
  KEY expires_at (expires_at)
) {$charset};",
		);

		$changes = dbDelta( $sql );
		update_option( self::OPTION_DB_VERSION, self::DB_VERSION, true );
		return array_values( (array) $changes );
	}

	/**
	 * 同步本外掛擁有的能力；回傳變更摘要。
	 *
	 * @return array<string,mixed>
	 */
	public static function sync_caps(): array {
		$report = array(
			'granted'         => array(),
			'service_removed' => array(),
			'service_added'   => array(),
		);

		foreach ( array( 'administrator', 'editor' ) as $role_name ) {
			$role = get_role( $role_name );
			if ( ! $role ) {
				continue;
			}
			foreach ( self::content_caps() as $cap ) {
				if ( empty( $role->capabilities[ $cap ] ) ) {
					$role->add_cap( $cap );
					$report['granted'][] = $role_name . ':' . $cap;
				}
			}
		}

		// 服務帳號角色：本外掛建立並擁有，收斂為 SERVICE_CAPS。
		$service = get_role( self::SERVICE_ROLE );
		if ( ! $service ) {
			add_role( self::SERVICE_ROLE, 'mysite 服務帳號', array( 'read' => true ) );
			$service = get_role( self::SERVICE_ROLE );
		}
		if ( $service ) {
			foreach ( array_keys( (array) $service->capabilities ) as $cap ) {
				if ( ! in_array( $cap, self::SERVICE_CAPS, true ) ) {
					$service->remove_cap( $cap );
					$report['service_removed'][] = $cap;
				}
			}
			foreach ( self::SERVICE_CAPS as $cap ) {
				if ( empty( $service->capabilities[ $cap ] ) ) {
					$service->add_cap( $cap );
					$report['service_added'][] = $cap;
				}
			}
		}

		update_option( self::OPTION_CAPS, self::CAPS_VERSION, true );
		return $report;
	}

	/**
	 * 只檢查（不修改）服務帳號使用者是否有角色以外的額外權限。
	 *
	 * @return string[] 警告訊息。
	 */
	public static function audit_service_users(): array {
		$warnings = array();
		$users    = get_users(
			array(
				'role'   => self::SERVICE_ROLE,
				'fields' => 'all',
			)
		);
		foreach ( $users as $user ) {
			$extra_roles = array_diff( (array) $user->roles, array( self::SERVICE_ROLE ) );
			if ( $extra_roles ) {
				$warnings[] = sprintf( '服務帳號（ID %d）另有角色：%s', $user->ID, implode( ',', $extra_roles ) );
			}
			$direct = array_diff( array_keys( array_filter( (array) $user->caps ) ), array_merge( array( self::SERVICE_ROLE ), self::SERVICE_CAPS ) );
			if ( $direct ) {
				$warnings[] = sprintf( '服務帳號（ID %d）有直接授予的能力：%s', $user->ID, implode( ',', $direct ) );
			}
		}
		return $warnings;
	}

	/**
	 * 管理員基本能力健全性檢查（WP-05：不得鎖死 admin）。
	 *
	 * @return string[] 缺少的能力。
	 */
	public static function admin_missing_core_caps(): array {
		$role = get_role( 'administrator' );
		if ( ! $role ) {
			return array( 'administrator 角色不存在' );
		}
		$missing = array();
		foreach ( array( 'manage_options', 'activate_plugins', 'edit_users', 'list_users', 'promote_users', 'edit_posts', 'edit_pages', 'edit_theme_options' ) as $cap ) {
			if ( empty( $role->capabilities[ $cap ] ) ) {
				$missing[] = $cap;
			}
		}
		return $missing;
	}

	/**
	 * `wp site-content setup` 的擴充步驟：由 before_invoke hook 呼叫，
	 * 之後才執行 bootstrap.php 原有的角色建立與成功訊息。
	 */
	public static function run_cli(): void {
		$changes = self::install_schema();
		foreach ( $changes as $change ) {
			\WP_CLI::log( '資料表：' . $change );
		}
		\WP_CLI::log( sprintf( '資料表版本：%s', self::DB_VERSION ) );

		$report = self::sync_caps();
		\WP_CLI::log( sprintf( '能力：新授予 %d 項；服務帳號移除 %d 項、補上 %d 項。', count( $report['granted'] ), count( $report['service_removed'] ), count( $report['service_added'] ) ) );
		foreach ( $report['service_removed'] as $cap ) {
			\WP_CLI::log( '服務帳號角色移除非核准能力：' . $cap );
		}
		foreach ( self::audit_service_users() as $warning ) {
			\WP_CLI::warning( $warning );
		}
		$missing = self::admin_missing_core_caps();
		if ( $missing ) {
			// 本外掛從不移除這些能力；缺少代表環境另有問題，必須人工處理。
			\WP_CLI::warning( 'administrator 缺少核心能力（本外掛未移除，請人工檢查）：' . implode( ',', $missing ) );
		}
		flush_rewrite_rules( false );
	}
}
