<?php
/**
 * 共用工具：型別對照、UTC 時間格式、環境判斷、回應外框與錯誤。
 *
 * @package SiteContent
 */

namespace SiteContent;

defined( 'ABSPATH' ) || exit;

/**
 * 跨模組共用的小工具（無狀態）。
 */
final class Support {

	public const API_VERSION   = 'v1';
	public const MODEL_VERSION = 1;
	public const REST_NS       = 'site-content/v1';

	/**
	 * 契約型別 → WordPress post type（docs/API契約.md「核准內容型別」）。
	 */
	public const TYPES = array(
		'post'    => 'post',
		'page'    => 'page',
		'video'   => 'sc_video',
		'podcast' => 'sc_podcast',
	);

	/**
	 * 列表端點 collection 名稱 → 契約型別。
	 */
	public const COLLECTIONS = array(
		'posts'    => 'post',
		'pages'    => 'page',
		'videos'   => 'video',
		'podcasts' => 'podcast',
	);

	/**
	 * 事件 body 可用的狀態值（其他 WP 狀態一律歸為 draft）。
	 */
	public const EVENT_STATUSES = array( 'publish', 'draft', 'pending', 'private', 'future', 'trash', 'deleted' );

	public static function post_type_for( string $type ): ?string {
		return self::TYPES[ $type ] ?? null;
	}

	public static function type_for( string $post_type ): ?string {
		$found = array_search( $post_type, self::TYPES, true );
		return false === $found ? null : (string) $found;
	}

	/**
	 * @return string[] 核准的 WP post type 清單。
	 */
	public static function post_types(): array {
		return array_values( self::TYPES );
	}

	/**
	 * MySQL GMT 日期時間 → `YYYY-MM-DDTHH:MM:SSZ`；空值或零日期回 null。
	 */
	public static function utc( ?string $gmt ): ?string {
		if ( null === $gmt || '' === $gmt || str_starts_with( $gmt, '0000-00-00' ) ) {
			return null;
		}
		$ts = strtotime( $gmt . ' UTC' );
		return false === $ts ? null : gmdate( 'Y-m-d\TH:i:s\Z', $ts );
	}

	public static function now(): string {
		return gmdate( 'Y-m-d\TH:i:s\Z' );
	}

	/**
	 * 給資料庫用的 UTC 時間字串（DATETIME）。
	 */
	public static function mysql_utc( int $offset_seconds = 0 ): string {
		return gmdate( 'Y-m-d H:i:s', time() + $offset_seconds );
	}

	public static function environment(): string {
		return function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production';
	}

	public static function is_development(): bool {
		return 'development' === self::environment();
	}

	public static function is_production(): bool {
		return 'production' === self::environment();
	}

	/**
	 * staging 與 production 視為正式等級（示例資料、故障注入等一律拒絕）。
	 */
	public static function is_production_like(): bool {
		return in_array( self::environment(), array( 'production', 'staging' ), true );
	}

	/**
	 * 主站（Laravel）對外網址，不含結尾斜線。
	 */
	public static function app_url(): string {
		if ( defined( 'MYSITE_APP_URL' ) && '' !== (string) MYSITE_APP_URL ) {
			return rtrim( (string) MYSITE_APP_URL, '/' );
		}
		return rtrim( home_url(), '/' );
	}

	/**
	 * 共同 meta（分頁欄位由呼叫端合併）。
	 *
	 * @param array<string,mixed> $extra 額外欄位。
	 * @return array<string,mixed>
	 */
	public static function meta( array $extra = array() ): array {
		return array_merge(
			array(
				'api_version'   => self::API_VERSION,
				'model_version' => self::MODEL_VERSION,
				'generated_at'  => self::now(),
			),
			$extra
		);
	}

	public static function error( string $code, string $message, int $status ): \WP_Error {
		return new \WP_Error( $code, $message, array( 'status' => $status ) );
	}

	public static function not_found(): \WP_Error {
		return self::error( 'site_content_not_found', '找不到內容', 404 );
	}

	public static function invalid_param( string $name ): \WP_Error {
		return self::error( 'site_content_invalid_param', sprintf( '參數 %s 不合法', $name ), 400 );
	}

	/**
	 * HTML → 純文字：去標籤、解實體、壓縮空白。
	 */
	public static function plain_text( string $html ): string {
		$text = wp_strip_all_tags( $html, false );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = (string) preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text );
		$text = (string) preg_replace( '/\s+/u', ' ', $text );
		return trim( $text );
	}

	/**
	 * 結構化日誌（stderr）；呼叫端不得傳入秘密或票券。
	 *
	 * @param array<string,mixed> $context 附加欄位。
	 */
	public static function log( string $level, string $event, array $context = array() ): void {
		$line = wp_json_encode(
			array_merge(
				array(
					'timestamp'   => self::now(),
					'service'     => 'wordpress',
					'component'   => 'site-content',
					'environment' => self::environment(),
					'level'       => $level,
					'event'       => $event,
				),
				$context
			),
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		);
		error_log( (string) $line ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}

	/**
	 * 嚴格的正整數字串（不接受 +、小數、前導零、科學記號）。
	 */
	public static function positive_int( mixed $value, int $max = PHP_INT_MAX ): ?int {
		if ( is_int( $value ) ) {
			return ( $value >= 1 && $value <= $max ) ? $value : null;
		}
		if ( ! is_string( $value ) || ! preg_match( '/^[1-9][0-9]{0,17}$/', $value ) ) {
			return null;
		}
		$int = (int) $value;
		return $int <= $max ? $int : null;
	}
}
