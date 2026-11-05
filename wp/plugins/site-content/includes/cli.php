<?php
/**
 * WP-CLI 指令（wp-jobs 與維運使用）。`site-content setup` 由 bootstrap.php 註冊，本檔以 before_invoke 擴充。
 *
 * @package SiteContent
 */

namespace SiteContent;

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

require_once __DIR__ . '/keyring.php';

/**
 * 內容事件 outbox：列出、投遞、重播。
 */
final class Outbox_Command {

	/**
	 * 投遞到期的 pending 事件（每次重新產生 timestamp 與 HMAC 簽章，body 位元組不變）。
	 *
	 * 2xx → delivered；401／409／422 → dead；其他（含連線失敗、5xx、429、3xx）→ 指數退避重試，10 次後 dead。
	 *
	 * ## OPTIONS
	 *
	 * [--limit=<n>]
	 * : 本輪最多認領筆數。
	 * ---
	 * default: 50
	 * ---
	 *
	 * [--event=<event_id>]
	 * : 只投遞指定事件。
	 *
	 * [--ignore-schedule]
	 * : 搭配 --event：不等待 next_attempt_at。
	 *
	 * [--endpoint=<url>]
	 * : 改送到指定接收端（僅 development，路徑仍須為 /internal/content-events/v1）。
	 *
	 * [--ignore-hold]
	 * : 忽略開發用暫停（site_content_jobs_hold_until）。
	 *
	 * [--ignore-maintenance]
	 * : CMS 維護模式中仍投遞（維運者確認接收端可用時才使用；預設維護期間整輪暫停）。
	 *
	 * ## EXAMPLES
	 *
	 *     wp site-content outbox deliver --quiet
	 *
	 * @param string[]             $args       位置參數。
	 * @param array<string,string> $assoc_args 具名參數。
	 */
	public function deliver( $args, $assoc_args ): void {
		$event = isset( $assoc_args['event'] ) ? (string) $assoc_args['event'] : null;
		if ( null === $event && empty( $assoc_args['ignore-maintenance'] ) && Maintenance::is_active() ) {
			// 換版時主站通常也在維護（接收端回 503），照常投遞只會白白消耗重試次數；維護結束後自動恢復。
			\WP_CLI::log( 'CMS 維護模式中，本輪不投遞（維護結束後自動恢復）。' );
			return;
		}
		if ( null === $event && empty( $assoc_args['ignore-hold'] ) && Outbox::hold_active() ) {
			\WP_CLI::log( '開發用暫停中（site_content_jobs_hold_until），本輪不投遞。' );
			return;
		}
		if ( null !== $event && ! preg_match( '/^[0-9a-f-]{36}$/', $event ) ) {
			\WP_CLI::error( 'event_id 格式不正確。', 10 );
		}
		try {
			$stats = Outbox::deliver(
				array(
					'endpoint'        => $assoc_args['endpoint'] ?? null,
					'event'           => $event,
					'limit'           => (int) ( $assoc_args['limit'] ?? Outbox::DEFAULT_LIMIT ),
					'ignore_schedule' => ! empty( $assoc_args['ignore-schedule'] ),
				)
			);
		} catch ( \InvalidArgumentException $e ) {
			\WP_CLI::error( $e->getMessage(), 10 );
			return;
		}
		\WP_CLI::log( sprintf( '認領 %d、送達 %d、待重試 %d、死信 %d。', $stats['claimed'], $stats['delivered'], $stats['retry'], $stats['dead'] ) );
	}

	/**
	 * 列出 outbox 事件。
	 *
	 * ## OPTIONS
	 *
	 * [--state=<state>]
	 * : 篩選狀態。
	 * ---
	 * options:
	 *   - pending
	 *   - delivered
	 *   - dead
	 * ---
	 *
	 * [--event=<event_id>]
	 * : 指定事件。
	 *
	 * [--content-id=<id>]
	 * : 指定內容 ID。
	 *
	 * [--limit=<n>]
	 * : 最多筆數（最新在前）。
	 * ---
	 * default: 50
	 * ---
	 *
	 * [--fields=<fields>]
	 * : 欄位（逗號分隔；可含 body）。
	 *
	 * [--format=<format>]
	 * : 輸出格式。
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 *   - count
	 * ---
	 *
	 * @subcommand list
	 *
	 * @param string[]             $args       位置參數。
	 * @param array<string,string> $assoc_args 具名參數。
	 */
	public function list_( $args, $assoc_args ): void {
		global $wpdb;
		$where  = array( '1=1' );
		$params = array();
		if ( ! empty( $assoc_args['state'] ) ) {
			$where[]  = 'state = %s';
			$params[] = (string) $assoc_args['state'];
		}
		if ( ! empty( $assoc_args['event'] ) ) {
			$where[]  = 'event_id = %s';
			$params[] = (string) $assoc_args['event'];
		}
		if ( ! empty( $assoc_args['content-id'] ) ) {
			$where[]  = 'content_id = %d';
			$params[] = (int) $assoc_args['content-id'];
		}
		$limit    = max( 1, min( 1000, (int) ( $assoc_args['limit'] ?? 50 ) ) );
		$params[] = $limit;
		$sql      = 'SELECT * FROM ' . Setup::table( 'outbox' ) . ' WHERE ' . implode( ' AND ', $where ) . ' ORDER BY id DESC LIMIT %d';
		$rows     = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$fields   = isset( $assoc_args['fields'] ) ? array_map( 'trim', explode( ',', (string) $assoc_args['fields'] ) ) : array( 'event_id', 'event_type', 'content_type', 'content_id', 'content_version', 'status', 'state', 'attempts', 'next_attempt_at', 'last_http_status', 'last_error' );
		\WP_CLI\Utils\format_items( (string) ( $assoc_args['format'] ?? 'table' ), (array) $rows, $fields );
	}

	/**
	 * 重播事件：同 event_id、同 body 重新排入（死信或已送達）。
	 *
	 * ## OPTIONS
	 *
	 * <event_id>
	 * : 事件 ID。
	 *
	 * @param string[] $args 位置參數。
	 */
	public function replay( $args ): void {
		$event = (string) ( $args[0] ?? '' );
		if ( ! preg_match( '/^[0-9a-f-]{36}$/', $event ) ) {
			\WP_CLI::error( 'event_id 格式不正確。', 10 );
		}
		if ( ! Outbox::replay( $event ) ) {
			\WP_CLI::error( '找不到可重播的事件。', 30 );
		}
		\WP_CLI::success( '已重新排入：' . $event );
	}
}

/**
 * resync 指令。
 */
final class Resync_Command {
	/**
	 * 依內容 ID 重新同步：即使指紋未變也遞增版本並送出目前狀態的事件。
	 *
	 * ## OPTIONS
	 *
	 * <post_id>
	 * : 內容 ID（post、page、影片、Podcast；已刪除者送 content.deleted）。
	 *
	 * @param string[] $args 位置參數。
	 */
	public function __invoke( $args ): void {
		$id = Support::positive_int( (string) ( $args[0] ?? '' ) );
		if ( null === $id ) {
			\WP_CLI::error( 'post_id 必須是正整數。', 10 );
		}
		$event = Outbox::observe( (int) $id, 'resync', true );
		if ( null === $event ) {
			\WP_CLI::error( '這個 ID 不是本外掛管理的內容。', 30 );
		}
		\WP_CLI::success( sprintf( '已排入 %s（%s，版本 %d）。', $event['event_id'], $event['event_type'], $event['content_version'] ) );
	}
}

/**
 * reconcile 指令。
 */
final class Reconcile_Command {
	/**
	 * 每分鐘對帳：比對 index 與 wp_posts 指紋、補送漏掉的事件、偵測已刪除內容、清除過期預覽快照。
	 *
	 * ## OPTIONS
	 *
	 * [--ignore-hold]
	 * : 忽略開發用暫停。
	 *
	 * [--ignore-maintenance]
	 * : CMS 維護模式中仍對帳（預設維護期間暫停，因為對帳會寫入索引表與 outbox）。
	 *
	 * @param string[]             $args       位置參數。
	 * @param array<string,string> $assoc_args 具名參數。
	 */
	public function __invoke( $args, $assoc_args ): void {
		if ( empty( $assoc_args['ignore-maintenance'] ) && Maintenance::is_active() ) {
			\WP_CLI::log( 'CMS 維護模式中，本輪不對帳（維護結束後自動恢復）。' );
			return;
		}
		if ( empty( $assoc_args['ignore-hold'] ) && Outbox::hold_active() ) {
			\WP_CLI::log( '開發用暫停中（site_content_jobs_hold_until），本輪不對帳。' );
			return;
		}
		$stats = Outbox::reconcile();
		\WP_CLI::log( sprintf( '掃描 %d、修補 %d、新事件 %d、偵測刪除 %d、清除快照 %d。', $stats['scanned'], $stats['repaired'], $stats['events'], $stats['deleted_detected'], $stats['snapshots_purged'] ) );
	}
}

/**
 * seed 指令。
 */
final class Seed_Command {
	/**
	 * 建立合成示例資料（只限 development／testing；production、staging 拒絕）。可重跑不重複（依 slug／種子鍵）。
	 *
	 * @param string[]             $args       位置參數。
	 * @param array<string,string> $assoc_args 具名參數。
	 */
	public function __invoke( $args, $assoc_args ): void {
		if ( Support::is_production_like() ) {
			\WP_CLI::error( 'WP_ENVIRONMENT_TYPE 為 ' . Support::environment() . '：拒絕建立示例資料。', 40 );
		}
		$report = Seed::run();
		foreach ( $report['log'] as $line ) {
			\WP_CLI::log( $line );
		}
		\WP_CLI::success( sprintf( '示例資料完成：新建 %d、既有略過 %d。', $report['created'], $report['existing'] ) );
	}
}

/**
 * CMS 維護模式指令（GAP-CMS-MAINT-01）。狀態存在資料庫，wp-php 與 wp-jobs 一致。
 */
final class Maintenance_Command {

	/**
	 * 開啟 CMS 維護模式：wp-admin 與 REST 寫入一律回 503（管理員也一樣），公開讀取與 health 照常；wp-jobs 暫停排程、投遞與對帳。
	 *
	 * 已開啟時不變更（保留原本的開始時間與原因）。WP-CLI 不受維護模式限制。
	 *
	 * ## OPTIONS
	 *
	 * [--reason=<text>]
	 * : 開啟原因（最多 200 字；不要填姓名、email、電話等個資）。未提供時記為「未註明」。
	 *
	 * ## EXAMPLES
	 *
	 *     wp site-content maintenance on --reason="release seq-12 contract"
	 *
	 * @param string[]             $args       位置參數。
	 * @param array<string,string> $assoc_args 具名參數。
	 */
	public function on( $args, $assoc_args ): void {
		try {
			$result = Maintenance::enable( (string) ( $assoc_args['reason'] ?? '' ) );
		} catch ( \InvalidArgumentException $e ) {
			\WP_CLI::error( $e->getMessage(), 10 );
			return;
		}
		$state = $result['state'];
		if ( ! $result['changed'] ) {
			\WP_CLI::success( sprintf( 'CMS 已在維護模式中（自 %s，原因：%s），未變更。', $state['enabled_at'] ?? '?', $state['reason'] ?? '?' ) );
			return;
		}
		\WP_CLI::success( sprintf( 'CMS 已進入維護模式（開始 %s，原因：%s）。', $state['enabled_at'] ?? '?', $state['reason'] ?? '?' ) );
	}

	/**
	 * 關閉 CMS 維護模式（已關閉時不變更）。
	 *
	 * ## EXAMPLES
	 *
	 *     wp site-content maintenance off
	 *
	 * @param string[] $args 位置參數。
	 */
	public function off( $args ): void {
		$result = Maintenance::disable();
		$state  = $result['state'];
		if ( ! $result['changed'] ) {
			\WP_CLI::success( '目前不在維護模式，未變更。' );
			return;
		}
		\WP_CLI::success( sprintf( 'CMS 已退出維護模式（%s 開始，%s 結束）。', $state['enabled_at'] ?? '?', $state['disabled_at'] ?? '?' ) );
	}

	/**
	 * 查詢 CMS 維護模式狀態。
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : 輸出格式。
	 * ---
	 * default: text
	 * options:
	 *   - text
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp site-content maintenance status --format=json
	 *
	 * @param string[]             $args       位置參數。
	 * @param array<string,string> $assoc_args 具名參數。
	 */
	public function status( $args, $assoc_args ): void {
		$state = Maintenance::state();
		if ( 'json' === ( $assoc_args['format'] ?? 'text' ) ) {
			\WP_CLI::line( (string) wp_json_encode( $state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
			return;
		}
		if ( $state['active'] ) {
			\WP_CLI::line( sprintf( '維護模式：開啟（自 %s，原因：%s）', $state['enabled_at'] ?? '?', $state['reason'] ?? '?' ) );
			return;
		}
		\WP_CLI::line( null === $state['disabled_at'] ? '維護模式：關閉' : sprintf( '維護模式：關閉（上次 %s 開始、%s 結束）', $state['enabled_at'] ?? '?', $state['disabled_at'] ) );
	}

	/**
	 * before_invoke:cron event run：wp-jobs（MYSITE_ROLE=jobs）在維護模式中不執行 WP-Cron 到期排程
	 * （例如排程發布會寫入文章）；本輪以 exit 0 結束，心跳照常更新。維運者在 wp-php 手動執行不受影響。
	 */
	public static function pause_jobs_cron(): void {
		if ( 'jobs' === getenv( 'MYSITE_ROLE' ) && Maintenance::is_active() ) {
			\WP_CLI::log( 'CMS 維護模式中，wp-jobs 本輪不執行到期排程（維護結束後自動恢復）。' );
			\WP_CLI::halt( 0 );
		}
	}
}

\WP_CLI::add_command( 'site-content maintenance', Maintenance_Command::class );
\WP_CLI::add_hook( 'before_invoke:cron event run', array( Maintenance_Command::class, 'pause_jobs_cron' ) );
/**
 * keyring 指令（GAP-KEYRING-01）。
 */
final class Keyring_Command {
	/**
	 * 列出目前載入的簽章金鑰圈：每行 `<key_id> <active|accept>`，絕不輸出金鑰。
	 * scripts/ops/rotate-secrets.sh 重建容器後以此確認 WordPress 實際載入的金鑰圈與秘密檔一致。
	 *
	 * ## OPTIONS
	 *
	 * <kind>
	 * : event-hmac 或 preview。
	 *
	 * @param string[] $args 位置參數。
	 */
	public function __invoke( $args ): void {
		$kind = (string) ( $args[0] ?? '' );
		if ( ! in_array( $kind, array( Keyring::EVENT, Keyring::PREVIEW ), true ) ) {
			\WP_CLI::error( 'kind 只允許 event-hmac 或 preview。', 10 );
		}
		foreach ( Keyring::states( $kind ) as $id => $state ) {
			\WP_CLI::line( $id . ' ' . $state );
		}
	}
}

\WP_CLI::add_command( 'site-content keyring', Keyring_Command::class );
\WP_CLI::add_command( 'site-content outbox', Outbox_Command::class );
\WP_CLI::add_command( 'site-content resync', Resync_Command::class );
\WP_CLI::add_command( 'site-content reconcile', Reconcile_Command::class );
\WP_CLI::add_command( 'site-content seed', Seed_Command::class );
\WP_CLI::add_hook( 'before_invoke:site-content setup', array( Setup::class, 'run_cli' ) );
