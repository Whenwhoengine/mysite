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

if ( ! function_exists( 'mysite_wp_parse_keyring' ) ) {
	/**
	 * 解析金鑰圈內容（GAP-KEYRING-01）：每個非空行 `<key_id> <64 hex> <active|accept>`，
	 * key_id 符合 k[0-9]{1,6} 且不重複，恰好一把 active。成功回傳 key_id → array{key,state}；
	 * 不合法時回傳錯誤說明字串（只含行號與欄位種類，不含金鑰）。
	 *
	 * @return array<string,array{key:string,state:string}>|string
	 */
	function mysite_wp_parse_keyring( string $raw ) {
		$ring   = array();
		$active = 0;
		foreach ( preg_split( "/\r?\n/", $raw ) as $n => $line ) {
			if ( '' === trim( $line ) ) {
				continue;
			}
			$p = preg_split( '/[ \t]+/', trim( $line ) );
			if ( 3 !== count( $p ) ) {
				return '第 ' . ( $n + 1 ) . ' 行格式不合法（必須是 <key_id> <64 hex> <active|accept>）';
			}
			if ( ! preg_match( '/^k[0-9]{1,6}$/D', $p[0] ) ) {
				return '第 ' . ( $n + 1 ) . ' 行的 key ID 不合法';
			}
			if ( ! preg_match( '/^[0-9a-f]{64}$/D', $p[1] ) ) {
				return '第 ' . ( $n + 1 ) . ' 行的金鑰不是 64 字元小寫 hex';
			}
			if ( 'active' !== $p[2] && 'accept' !== $p[2] ) {
				return '第 ' . ( $n + 1 ) . ' 行的狀態必須是 active 或 accept';
			}
			if ( isset( $ring[ $p[0] ] ) ) {
				return 'key ID ' . $p[0] . ' 重複';
			}
			$ring[ $p[0] ] = array(
				'key'   => $p[1],
				'state' => $p[2],
			);
			if ( 'active' === $p[2] ) {
				++$active;
			}
		}
		if ( array() === $ring ) {
			return '內容為空';
		}
		if ( 1 !== $active ) {
			return '必須恰好一把 active';
		}
		return $ring;
	}
}

if ( ! function_exists( 'mysite_wp_keyring' ) ) {
	/**
	 * 讀取 <NAME>_KEYRING_FILE 金鑰圈；未設定時回傳 null（沿用單一金鑰檔）。
	 * 檔案缺失、權限過寬或格式不合法一律拒絕啟動（不默默退回單一金鑰，避免回到已撤銷的舊 key）。
	 *
	 * @return array<string,array{key:string,state:string}>|null
	 */
	function mysite_wp_keyring( string $name ): ?array {
		$path = getenv( $name . '_KEYRING_FILE' );
		if ( false === $path || '' === $path ) {
			return null;
		}
		clearstatcache( true, $path );
		if ( ! is_file( $path ) ) {
			mysite_wp_fail( "{$name}_KEYRING_FILE 指向的檔案不存在" );
		}
		if ( ( fileperms( $path ) & 0007 ) !== 0 ) {
			mysite_wp_fail( "{$name}_KEYRING_FILE 權限過寬（其他使用者可存取）" );
		}
		$raw = @file_get_contents( $path );
		if ( false === $raw ) {
			mysite_wp_fail( "{$name}_KEYRING_FILE 無法讀取" );
		}
		$ring = mysite_wp_parse_keyring( $raw );
		if ( is_string( $ring ) ) {
			mysite_wp_fail( "{$name}_KEYRING_FILE {$ring}" );
		}
		return $ring;
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
// 簽章金鑰：有金鑰圈（*_KEYRING_FILE）時以其中的 active key 簽章、key ID 取自金鑰圈；
// 沒有金鑰圈時沿用單一金鑰檔（事件 key ID 為 CONTENT_EVENT_KEY_ID，預覽視為 k1）。
foreach ( array(
	'CONTENT_EVENT_HMAC' => array( 'MYSITE_CONTENT_EVENT_HMAC_KEYRING', 'MYSITE_CONTENT_EVENT_KEY_ID', 'MYSITE_CONTENT_EVENT_HMAC_KEY' ),
	'PREVIEW_SIGNING'    => array( 'MYSITE_PREVIEW_SIGNING_KEYRING', 'MYSITE_PREVIEW_SIGNING_KEY_ID', 'MYSITE_PREVIEW_SIGNING_KEY' ),
) as $mysite_ring_name => $mysite_ring_consts ) {
	$mysite_ring = mysite_wp_keyring( $mysite_ring_name );
	if ( null === $mysite_ring ) {
		continue;
	}
	define( $mysite_ring_consts[0], $mysite_ring );
	foreach ( $mysite_ring as $mysite_ring_id => $mysite_ring_entry ) {
		if ( 'active' === $mysite_ring_entry['state'] ) {
			define( $mysite_ring_consts[1], (string) $mysite_ring_id );
			define( $mysite_ring_consts[2], $mysite_ring_entry['key'] );
		}
	}
}
unset( $mysite_ring, $mysite_ring_name, $mysite_ring_consts, $mysite_ring_id, $mysite_ring_entry );
if ( ! defined( 'MYSITE_CONTENT_EVENT_HMAC_KEYRING' ) ) {
	define( 'MYSITE_CONTENT_EVENT_KEY_ID', mysite_wp_env( 'CONTENT_EVENT_KEY_ID' ) );
	define( 'MYSITE_CONTENT_EVENT_HMAC_KEY', mysite_wp_secret( 'CONTENT_EVENT_HMAC_KEY' ) );
}
if ( ! defined( 'MYSITE_PREVIEW_SIGNING_KEYRING' ) ) {
	define( 'MYSITE_PREVIEW_SIGNING_KEY_ID', 'k1' );
	define( 'MYSITE_PREVIEW_SIGNING_KEY', mysite_wp_secret( 'PREVIEW_SIGNING_KEY' ) );
}
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
