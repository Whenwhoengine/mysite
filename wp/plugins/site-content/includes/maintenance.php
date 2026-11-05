<?php
/**
 * CMS 維護模式（GAP-CMS-MAINT-01；規劃書 v5 §14.7 第 5、7 步）。
 *
 * 狀態存在 WP option（資料庫），wp-php 與 wp-jobs 兩個容器讀到的是同一份；
 * 只記錄開啟／關閉時間（UTC）與原因，不記錄操作者或其他個資。
 *
 * 開啟時：
 *  - REST 的寫入方法（POST／PUT／PATCH／DELETE，含核心 /wp/v2/*、/batch/v1 與 site-content/v1）一律回 503＋Retry-After；
 *    判斷以 WP_REST_Request::get_method() 為準，`_method`／X-HTTP-Method-Override 改寫成寫入方法的請求同樣擋下。
 *    GET／HEAD／OPTIONS 照常（Laravel 依賴的 site-content/v1 公開讀取、health、私有預覽讀取不中斷）。
 *  - wp-admin（含 admin-ajax.php、admin-post.php、async-upload.php）對所有登入者只顯示維護說明（503），管理員也一樣。
 *  - 登入頁照常可用，並提示目前維護中。
 *  - 經 HTTP 觸發的 WP-Cron（直接請求 wp-cron.php）不執行到期排程。
 *  - wp-jobs 的到期排程、outbox 投遞與對帳由 includes/cli.php 暫停（見該檔 Maintenance_Command）。
 *
 * WP-CLI 是維運者的受控通道（migration、`wp core update-db`、`wp site-content setup` 都在維護期間執行），不受本模組限制。
 *
 * @package SiteContent
 */

namespace SiteContent;

defined( 'ABSPATH' ) || exit;

final class Maintenance {

	public const OPTION      = 'site_content_maintenance';
	public const RETRY_AFTER = 60;   // 暫定值：Retry-After 秒數（與 release.sh 的 artisan down --retry=60 一致）。
	public const MAX_REASON  = 200;  // 暫定值：原因最多 200 字。
	public const ERROR_CODE  = 'site_content_maintenance';
	public const TITLE       = 'CMS 維護中';
	public const MESSAGE     = 'CMS 維護中：暫時無法新增、修改、刪除內容或上傳媒體。已發布的內容與主站照常提供瀏覽；請稍後再試。';

	private const READ_METHODS   = array( 'GET', 'HEAD', 'OPTIONS' );
	private const DEFAULT_REASON = '未註明';

	public static function init(): void {
		add_filter( 'rest_pre_dispatch', array( self::class, 'guard_rest' ), 1, 3 );
		add_action( 'admin_init', array( self::class, 'guard_admin' ), 0 );
		add_filter( 'pre_get_ready_cron_jobs', array( self::class, 'guard_http_cron' ), 1 );
		add_filter( 'login_message', array( self::class, 'login_notice' ) );
	}

	// ---------------------------------------------------------------------
	// 狀態
	// ---------------------------------------------------------------------

	/**
	 * 目前狀態（option 不存在或格式不符時視為關閉）。
	 *
	 * @return array{active:bool,enabled_at:?string,reason:?string,disabled_at:?string}
	 */
	public static function state(): array {
		$raw = get_option( self::OPTION, array() );
		$raw = is_array( $raw ) ? $raw : array();
		$str = static function ( string $key ) use ( $raw ): ?string {
			return isset( $raw[ $key ] ) && is_string( $raw[ $key ] ) && '' !== $raw[ $key ] ? $raw[ $key ] : null;
		};
		return array(
			'active'      => ! empty( $raw['active'] ),
			'enabled_at'  => $str( 'enabled_at' ),
			'reason'      => $str( 'reason' ),
			'disabled_at' => $str( 'disabled_at' ),
		);
	}

	public static function is_active(): bool {
		return self::state()['active'];
	}

	/**
	 * 檢查並整理開啟原因：純文字、最多 MAX_REASON 字、不得含 email（避免記入個資）。
	 *
	 * @throws \InvalidArgumentException 原因不合規定。
	 */
	public static function clean_reason( string $reason ): string {
		$text = (string) preg_replace( '/[\x00-\x1F\x7F]/u', ' ', $reason );
		$text = trim( sanitize_text_field( $text ) );
		if ( '' === $text ) {
			return self::DEFAULT_REASON;
		}
		if ( mb_strlen( $text ) > self::MAX_REASON ) {
			throw new \InvalidArgumentException( sprintf( '原因最多 %d 字。', self::MAX_REASON ) );
		}
		if ( str_contains( $text, '@' ) ) {
			throw new \InvalidArgumentException( '原因不得包含 email 等個資。' );
		}
		return $text;
	}

	/**
	 * 開啟維護模式。已開啟時不變更（保留原本的開始時間與原因），可重複執行。
	 *
	 * @return array{changed:bool,state:array}
	 * @throws \InvalidArgumentException 原因不合規定。
	 */
	public static function enable( string $reason ): array {
		$reason = self::clean_reason( $reason );
		$state  = self::state();
		if ( $state['active'] ) {
			return array(
				'changed' => false,
				'state'   => $state,
			);
		}
		update_option(
			self::OPTION,
			array(
				'active'      => true,
				'enabled_at'  => Support::now(),
				'reason'      => $reason,
				'disabled_at' => null,
			),
			true
		);
		$state = self::state();
		Support::log( 'warning', 'cms_maintenance_enabled', array( 'reason' => $reason ) );
		return array(
			'changed' => true,
			'state'   => $state,
		);
	}

	/**
	 * 關閉維護模式（保留上次的開始時間與原因供查核）。已關閉時不變更。
	 *
	 * @return array{changed:bool,state:array}
	 */
	public static function disable(): array {
		$state = self::state();
		if ( ! $state['active'] ) {
			return array(
				'changed' => false,
				'state'   => $state,
			);
		}
		update_option(
			self::OPTION,
			array(
				'active'      => false,
				'enabled_at'  => $state['enabled_at'],
				'reason'      => $state['reason'],
				'disabled_at' => Support::now(),
			),
			true
		);
		Support::log( 'info', 'cms_maintenance_disabled', array( 'enabled_at' => $state['enabled_at'] ) );
		return array(
			'changed' => true,
			'state'   => self::state(),
		);
	}

	// ---------------------------------------------------------------------
	// 攔截
	// ---------------------------------------------------------------------

	private static function is_cli(): bool {
		return defined( 'WP_CLI' ) && WP_CLI;
	}

	/**
	 * REST：寫入方法回 503（在路由比對與權限檢查之前，未登入者同樣得到 503）。
	 *
	 * @param mixed            $result  其他 filter 的結果（null 表示尚未處理）。
	 * @param \WP_REST_Server  $server  REST server。
	 * @param \WP_REST_Request $request 請求。
	 * @return mixed
	 */
	public static function guard_rest( $result, $server, $request ) {
		if ( null !== $result || ! $request instanceof \WP_REST_Request || self::is_cli() ) {
			return $result;
		}
		if ( in_array( strtoupper( (string) $request->get_method() ), self::READ_METHODS, true ) || ! self::is_active() ) {
			return $result;
		}
		$response = rest_convert_error_to_response(
			new \WP_Error(
				self::ERROR_CODE,
				self::MESSAGE,
				array(
					'status'      => 503,
					'retry_after' => self::RETRY_AFTER,
				)
			)
		);
		$response->header( 'Retry-After', (string) self::RETRY_AFTER );
		return $response;
	}

	/**
	 * wp-admin（含 admin-ajax、admin-post、async-upload）：只顯示維護說明。
	 * 在 admin_init 最早執行，早於各頁面處理表單或上傳的程式。
	 */
	public static function guard_admin(): void {
		if ( self::is_cli() || ! self::is_active() ) {
			return;
		}
		if ( ! headers_sent() ) {
			header( 'Retry-After: ' . self::RETRY_AFTER );
		}
		if ( wp_doing_ajax() ) {
			wp_die( esc_html( self::MESSAGE ), esc_html( self::TITLE ), array( 'response' => 503 ) );
		}
		wp_die(
			self::admin_page_html(), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 內容已逐段跳脫。
			esc_html( self::TITLE ),
			array(
				'response'  => 503,
				'back_link' => false,
			)
		);
	}

	/**
	 * 維護期間直接請求 wp-cron.php 不執行到期排程（WP-CLI 不經此 filter）。
	 *
	 * @param mixed $pre 其他 filter 的結果。
	 * @return mixed
	 */
	public static function guard_http_cron( $pre ) {
		if ( null !== $pre || self::is_cli() || ! self::is_active() ) {
			return $pre;
		}
		return array();
	}

	/**
	 * 登入頁提示。
	 *
	 * @param mixed $message 既有訊息（HTML）。
	 * @return mixed
	 */
	public static function login_notice( $message ) {
		if ( ! self::is_active() ) {
			return $message;
		}
		return (string) $message . '<div id="site-content-maintenance" class="notice notice-warning message"><p><strong>'
			. esc_html( self::TITLE ) . '</strong>：可以登入，但維護結束前後臺只會顯示維護說明，暫時無法編輯、發布或上傳媒體。已發布的內容與主站照常提供瀏覽。</p></div>';
	}

	private static function admin_page_html(): string {
		$state = self::state();
		$since = '';
		if ( null !== $state['enabled_at'] ) {
			$ts    = strtotime( $state['enabled_at'] );
			$since = false === $ts ? '' : wp_date( 'Y-m-d H:i', $ts );
		}
		$html  = '<h1>' . esc_html( self::TITLE ) . '</h1>';
		$html .= '<p>系統正在進行維護（例如版本更新或資料庫調整）。這段期間暫時無法編輯、儲存、發布或刪除內容，也無法上傳媒體；管理員也一樣。</p>';
		$html .= '<p>已發布的內容與主站照常提供瀏覽，不受影響。</p>';
		if ( '' !== $since ) {
			$html .= '<p>維護開始時間：' . esc_html( $since ) . '（網站時區）。</p>';
		}
		$html .= '<p>請稍後重新整理本頁；維護結束後即可繼續編輯。若在維護開始前有尚未儲存的內容，請先把文字複製保存在自己的電腦，維護結束後再貼回。</p>';
		$html .= '<p><a href="' . esc_url( wp_logout_url() ) . '">登出</a></p>';
		return $html;
	}
}
