<?php
/**
 * 排程補救（WP-07）：補排遺失的 publish_future_post 事件，補發已到期仍未發布的排程內容。
 *
 * 原因：WordPress 把所有排程事件存在同一個 option（cron 陣列），排入與執行事件都是「讀整包 → 改 → 寫整包」。
 * 編輯者排程文章的同時若 wp-jobs 正在執行到期事件，後寫入的一方會蓋掉前者，新排入的發布事件可能遺失，
 * 文章便永遠停在「已排程」。wp-jobs 每輪呼叫 `wp site-content schedule sweep` 修正這種情況。
 *
 * @package SiteContent
 */

namespace SiteContent;

defined( 'ABSPATH' ) || exit;

/**
 * 排程補救的核心邏輯（WP-CLI 與測試共用）。
 */
final class Schedule_Sweep {

	public const HOOK = 'publish_future_post';

	/**
	 * 每次最多處理的排程內容數（暫定值：一般站台同時排程的內容遠少於此數）。
	 */
	public const MAX_POSTS = 500;

	/**
	 * 執行一次補救。
	 *
	 * @return array{checked:int,rescheduled:int[],published:int[],skipped:string}
	 */
	public static function run(): array {
		$result = array(
			'checked'     => 0,
			'rescheduled' => array(),
			'published'   => array(),
			'skipped'     => '',
		);
		// CMS 維護模式期間不寫入內容（與 Maintenance 模組的保護一致）；結束後下一輪自然補上。
		if ( class_exists( Maintenance::class ) && Maintenance::is_active() ) {
			$result['skipped'] = 'maintenance';
			return $result;
		}
		$ids = get_posts(
			array(
				'post_type'        => Support::post_types(),
				'post_status'      => 'future',
				'fields'           => 'ids',
				'posts_per_page'   => self::MAX_POSTS,
				'orderby'          => 'date',
				'order'            => 'ASC',
				'no_found_rows'    => true,
				'suppress_filters' => true,
			)
		);
		$now = time();
		foreach ( $ids as $id ) {
			$id   = (int) $id;
			$post = get_post( $id );
			if ( ! $post || 'future' !== $post->post_status ) {
				continue;
			}
			++$result['checked'];
			$due = strtotime( $post->post_date_gmt . ' GMT' );
			if ( false === $due ) {
				continue;
			}
			if ( $due <= $now ) {
				// 核心函式：只在時間已到時發布，並觸發一般的狀態轉換（outbox 事件照常產生）。
				check_and_publish_future_post( $id );
				if ( 'publish' === get_post_status( $id ) ) {
					$result['published'][] = $id;
				}
				continue;
			}
			if ( false === wp_next_scheduled( self::HOOK, array( $id ) ) ) {
				wp_schedule_single_event( $due, self::HOOK, array( $id ) );
				$result['rescheduled'][] = $id;
			}
		}
		return $result;
	}
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	/**
	 * 排程補救指令。
	 */
	final class Schedule_Command {

		/**
		 * 補排遺失的發布事件，並補發已到期仍未發布的排程內容。
		 *
		 * ## OPTIONS
		 *
		 * [--quiet]
		 * : 沒有需要處理的內容時不輸出。
		 *
		 * ## EXAMPLES
		 *
		 *     wp site-content schedule sweep
		 *
		 * @param array<int,string>    $args  位置參數（未使用）。
		 * @param array<string,string> $assoc 選項。
		 */
		public function sweep( array $args, array $assoc ): void {
			unset( $args );
			$r     = Schedule_Sweep::run();
			$quiet = isset( $assoc['quiet'] );
			if ( 'maintenance' === $r['skipped'] ) {
				if ( ! $quiet ) {
					\WP_CLI::log( 'CMS 維護模式中，本輪不處理排程。' );
				}
				return;
			}
			if ( $quiet && ! $r['rescheduled'] && ! $r['published'] ) {
				return;
			}
			\WP_CLI::success(
				sprintf(
					'檢查 %d 筆排程內容；補排 %d 筆（%s）；補發 %d 筆（%s）。',
					$r['checked'],
					count( $r['rescheduled'] ),
					implode( ',', $r['rescheduled'] ) ?: '無',
					count( $r['published'] ),
					implode( ',', $r['published'] ) ?: '無'
				)
			);
		}
	}

	\WP_CLI::add_command( 'site-content schedule', Schedule_Command::class );
}
