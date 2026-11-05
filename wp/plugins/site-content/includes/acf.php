<?php
/**
 * ACF 整合：Local JSON 只從 ACF_JSON_PATH（wp/acf-json）載入；正式環境不寫回、不提供欄位結構編輯。
 * ACF 只做欄位 UI，不註冊 CPT／Options Page（ACF 免費版功能；不依賴 ACF Pro）。
 *
 * 欄位：
 *   影片    sc_youtube_url（YouTube 網址）、sc_duration_seconds（片長秒數）
 *   Podcast sc_audio_attachment（媒體庫音訊）、sc_audio_url（核准 HTTPS 音訊網址）、sc_duration_seconds
 *   共用    sc_is_sample（示例內容）、sc_exclude（不在主站公開）
 *
 * @package SiteContent
 */

namespace SiteContent;

defined( 'ABSPATH' ) || exit;

final class Acf_Integration {

	public static function init(): void {
		add_filter( 'acf/settings/load_json', array( self::class, 'load_paths' ), 20 );
		add_filter( 'acf/settings/save_json', array( self::class, 'save_path' ), 20 );
		add_filter( 'acf/json/save_paths', array( self::class, 'save_paths' ), 20 );
		add_filter( 'acf/settings/show_admin', array( self::class, 'show_admin' ), 20 );
		add_filter( 'acf/settings/enable_post_types', '__return_false', 20 );
		add_filter( 'acf/settings/enable_options_pages_ui', '__return_false', 20 );
		add_filter( 'acf/validate_value/name=sc_youtube_url', array( self::class, 'validate_youtube' ), 10, 2 );
		add_filter( 'acf/validate_value/name=sc_audio_url', array( self::class, 'validate_audio_url' ), 10, 2 );
		add_filter( 'acf/validate_value/name=sc_duration_seconds', array( self::class, 'validate_duration' ), 10, 2 );
	}

	public static function json_path(): string {
		return defined( 'ACF_JSON_PATH' ) ? (string) ACF_JSON_PATH : WP_CONTENT_DIR . '/acf-json';
	}

	/**
	 * @return string[]
	 */
	public static function load_paths(): array {
		return array( self::json_path() );
	}

	public static function save_path(): string {
		return Support::is_development() ? self::json_path() : '';
	}

	/**
	 * @param mixed $paths ACF 預設儲存路徑。
	 * @return string[]
	 */
	public static function save_paths( $paths ): array {
		return Support::is_development() ? array( self::json_path() ) : array();
	}

	/**
	 * staging／production 隱藏 ACF 欄位群組管理介面：編輯者只能改欄位值，不能改結構。
	 *
	 * @param mixed $show 原設定。
	 */
	public static function show_admin( $show ): bool {
		return (bool) $show && Support::is_development();
	}

	/**
	 * @param mixed $valid 先前結果。
	 * @param mixed $value 欄位值。
	 * @return mixed
	 */
	public static function validate_youtube( $valid, $value ) {
		if ( true !== $valid || ! is_string( $value ) || '' === trim( $value ) ) {
			return $valid;
		}
		return null !== Content_Model::youtube_id( $value ) ? $valid : '請輸入有效的 YouTube 影片網址（youtube.com/watch?v=… 或 youtu.be/…，影片 ID 為 11 碼）。';
	}

	/**
	 * @param mixed $valid 先前結果。
	 * @param mixed $value 欄位值。
	 * @return mixed
	 */
	public static function validate_audio_url( $valid, $value ) {
		if ( true !== $valid || ! is_string( $value ) || '' === trim( $value ) ) {
			return $valid;
		}
		$ext = strtolower( (string) pathinfo( (string) wp_parse_url( trim( $value ), PHP_URL_PATH ), PATHINFO_EXTENSION ) );
		if ( ! Content_Model::is_allowed_audio_url( trim( $value ) ) || ! isset( Content_Model::AUDIO_MIME_BY_EXT[ $ext ] ) ) {
			return '音訊網址必須是核准主機的 https 網址，副檔名為 mp3、m4a、wav 或 ogg；建議直接上傳到媒體庫。';
		}
		return $valid;
	}

	/**
	 * @param mixed $valid 先前結果。
	 * @param mixed $value 欄位值。
	 * @return mixed
	 */
	public static function validate_duration( $valid, $value ) {
		if ( true !== $valid || null === $value || '' === $value ) {
			return $valid;
		}
		return ( is_numeric( $value ) && (int) $value >= 1 && (int) $value <= 86400 && (string) (int) $value === (string) $value ) ? $valid : '長度請填 1 到 86400 之間的整數秒數。';
	}
}
