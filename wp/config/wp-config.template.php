<?php
/**
 * mysite WordPress 設定模板（建置時複製為 wp-config.php）。
 *
 * - 所有環境值由容器環境變數提供；秘密只從 *_FILE 指向的檔案讀取（ADR-006）。
 * - 缺少必要秘密、空值、權限過寬（其他人可讀寫）一律拒絕啟動，不落入示範密碼。
 * - 核心與外掛唯讀：禁止後臺編輯檔案、安裝外掛與自動更新。
 */

if ( ! function_exists( 'mysite_wp_fail' ) ) {
	function mysite_wp_fail( string $message ): void {
		error_log( 'mysite-wp-config: ' . $message );
		if ( PHP_SAPI === 'cli' ) {
			fwrite( STDERR, 'mysite-wp-config: ' . $message . PHP_EOL );
			exit( 20 );
		}
		http_response_code( 503 );
		header( 'Content-Type: text/plain; charset=utf-8' );
		echo "服務暫時無法使用。\n";
		exit;
	}
}

if ( ! function_exists( 'mysite_wp_env' ) ) {
	function mysite_wp_env( string $name, ?string $default = null ): string {
		$value = getenv( $name );
		if ( $value === false || $value === '' ) {
			if ( $default === null ) {
				mysite_wp_fail( "缺少必要環境變數 {$name}" );
			}
			return $default;
		}
		return $value;
	}
}

if ( ! function_exists( 'mysite_wp_secret' ) ) {
	/**
	 * 讀取 <NAME>_FILE 指向的秘密檔：去除結尾換行；缺檔／空值／權限過寬即失敗。
	 */
	function mysite_wp_secret( string $name ): string {
		$path = getenv( $name . '_FILE' );
		if ( $path === false || $path === '' ) {
			mysite_wp_fail( "缺少 {$name}_FILE" );
		}
		clearstatcache( true, $path );
		if ( ! is_file( $path ) ) {
			mysite_wp_fail( "{$name}_FILE 指向的檔案不存在" );
		}
		$perms = fileperms( $path ) & 0777;
		if ( ( $perms & 0007 ) !== 0 ) {
			mysite_wp_fail( "{$name}_FILE 權限過寬（其他使用者可存取）" );
		}
		$raw = @file_get_contents( $path );
		if ( $raw === false ) {
			mysite_wp_fail( "{$name}_FILE 無法讀取" );
		}
		$value = rtrim( $raw, "\r\n" );
		if ( $value === '' || trim( $value ) === '' ) {
			mysite_wp_fail( "{$name}_FILE 內容為空" );
		}
		if ( str_contains( $value, "\n" ) && $name !== 'WP_SALTS' ) {
			mysite_wp_fail( "{$name}_FILE 不得含多行內容" );
		}
		return $value;
	}
}

$mysite_env = mysite_wp_env( 'WP_ENVIRONMENT_TYPE' );
if ( ! in_array( $mysite_env, array( 'development', 'staging', 'production' ), true ) ) {
	mysite_wp_fail( 'WP_ENVIRONMENT_TYPE 不合法' );
}

// ---- 資料庫（WordPress 專用庫與帳號；不得使用 root）----
define( 'DB_NAME', mysite_wp_env( 'WP_DB_NAME' ) );
define( 'DB_USER', mysite_wp_env( 'WP_DB_USER' ) );
define( 'DB_PASSWORD', mysite_wp_secret( 'WP_DB_PASSWORD' ) );
define( 'DB_HOST', mysite_wp_env( 'WP_DB_HOST', 'mysql' ) );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', 'utf8mb4_0900_ai_ci' );
if ( strtolower( DB_USER ) === 'root' ) {
	mysite_wp_fail( 'WordPress 不得使用資料庫 root 帳號' );
}

// ---- 金鑰與 salts（8 行 NAME=value）----
$mysite_salt_names = array( 'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT' );
$mysite_salts      = array();
foreach ( preg_split( "/\r?\n/", mysite_wp_secret( 'WP_SALTS' ) ) as $mysite_line ) {
	if ( preg_match( '/^([A-Z_]+)=(.{32,})$/', $mysite_line, $m ) ) {
		$mysite_salts[ $m[1] ] = $m[2];
	}
}
foreach ( $mysite_salt_names as $mysite_salt_name ) {
	if ( empty( $mysite_salts[ $mysite_salt_name ] ) ) {
		mysite_wp_fail( "WP_SALTS 缺少 {$mysite_salt_name}" );
	}
	define( $mysite_salt_name, $mysite_salts[ $mysite_salt_name ] );
}
unset( $mysite_salts, $mysite_line, $mysite_salt_name, $m );

// ---- 本案整合設定（site-content 外掛讀取）----
define( 'MYSITE_APP_URL', rtrim( mysite_wp_env( 'MYSITE_APP_URL' ), '/' ) );
define( 'MYSITE_CONTENT_EVENT_URL', mysite_wp_env( 'CONTENT_EVENT_URL' ) );
define( 'MYSITE_CONTENT_EVENT_KEY_ID', mysite_wp_env( 'CONTENT_EVENT_KEY_ID' ) );
define( 'MYSITE_CONTENT_EVENT_HMAC_KEY', mysite_wp_secret( 'CONTENT_EVENT_HMAC_KEY' ) );
define( 'MYSITE_PREVIEW_SIGNING_KEY', mysite_wp_secret( 'PREVIEW_SIGNING_KEY' ) );
define( 'MYSITE_PREVIEW_TTL_SECONDS', min( 900, max( 60, (int) mysite_wp_env( 'PREVIEW_TTL_SECONDS', '600' ) ) ) );
$mysite_diag = getenv( 'DIAGNOSTICS_TOKEN_FILE' );
if ( $mysite_diag !== false && $mysite_diag !== '' ) {
	define( 'MYSITE_DIAGNOSTICS_TOKEN', mysite_wp_secret( 'DIAGNOSTICS_TOKEN' ) );
}
unset( $mysite_diag );
define( 'MYSITE_PODCAST_AUDIO_ALLOWED_HOSTS', mysite_wp_env( 'PODCAST_AUDIO_ALLOWED_HOSTS', '' ) );

if ( defined( 'MYSITE_CONFIG_CHECK_ONLY' ) ) {
	return;
}

// ---- 網址（瀏覽器使用的 CMS 網址；不是容器內部名稱）----
define( 'WP_HOME', rtrim( mysite_wp_env( 'WP_PUBLIC_URL' ), '/' ) );
define( 'WP_SITEURL', WP_HOME );

// ---- 環境與安全 ----
define( 'WP_ENVIRONMENT_TYPE', $mysite_env );
define( 'DISALLOW_FILE_EDIT', true );
define( 'DISALLOW_FILE_MODS', true );
define( 'AUTOMATIC_UPDATER_DISABLED', true );
define( 'WP_AUTO_UPDATE_CORE', false );
define( 'DISABLE_WP_CRON', true ); // 由 wp-jobs 固定觸發到期排程
define( 'WP_POST_REVISIONS', 30 );
define( 'EMPTY_TRASH_DAYS', 30 );
define( 'FS_METHOD', 'direct' );
define( 'WP_DEBUG', $mysite_env === 'development' );
define( 'WP_DEBUG_DISPLAY', false );
define( 'WP_DEBUG_LOG', false );
define( 'SCRIPT_DEBUG', false );
define( 'CONCATENATE_SCRIPTS', false );
if ( $mysite_env !== 'development' ) {
	define( 'FORCE_SSL_ADMIN', true );
}
define( 'ACF_JSON_PATH', __DIR__ . '/wp-content/acf-json' );

$table_prefix = 'wp_';

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
require_once ABSPATH . 'wp-settings.php';
