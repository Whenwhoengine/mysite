<?php
/**
 * Plugin Name: mysite 安全基線（mu-plugin）
 * Description: 關閉 XML-RPC 與 pingback、未登入者不能列舉 /wp/v2/users、上傳檔案白名單（副檔名＋實際 MIME＋大小）、
 *              管理員第二因素閘門（staging／production 強制，development 只提示）。不封鎖 Gutenberg、nonce、媒體上傳與 Application Password。
 * Version:     0.1.0
 * License:     GPL-2.0-or-later
 *
 * @package MysiteMu
 */

defined( 'ABSPATH' ) || exit;

// 暫定值：單檔上傳上限 32MB（與 infra/php/runtime.ini upload_max_filesize 一致）。
const MYSITE_UPLOAD_MAX_BYTES = 33554432;

/**
 * 核准上傳類型：副檔名 → WordPress 記錄的 MIME。
 */
const MYSITE_UPLOAD_MIMES = array(
	'jpg|jpeg|jpe' => 'image/jpeg',
	'png'          => 'image/png',
	'gif'          => 'image/gif',
	'webp'         => 'image/webp',
	'mp3'          => 'audio/mpeg',
	'm4a'          => 'audio/mp4',
	'wav'          => 'audio/wav',
	'ogg'          => 'audio/ogg',
);

/**
 * 副檔名 → 允許的實際內容 MIME（finfo 偵測值）。
 */
const MYSITE_UPLOAD_REAL_MIMES = array(
	'jpg'  => array( 'image/jpeg' ),
	'jpeg' => array( 'image/jpeg' ),
	'jpe'  => array( 'image/jpeg' ),
	'png'  => array( 'image/png' ),
	'gif'  => array( 'image/gif' ),
	'webp' => array( 'image/webp' ),
	'mp3'  => array( 'audio/mpeg', 'audio/mp3', 'audio/x-mpeg' ),
	'm4a'  => array( 'audio/mp4', 'audio/x-m4a', 'audio/m4a', 'video/mp4' ),
	'wav'  => array( 'audio/wav', 'audio/x-wav', 'audio/wave', 'audio/vnd.wave' ),
	'ogg'  => array( 'audio/ogg', 'application/ogg', 'audio/x-ogg' ),
);

/**
 * 視為危險的內層副檔名（雙副檔名偵測用）。
 */
const MYSITE_UPLOAD_DANGEROUS_EXT = array( 'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phar', 'pht', 'phps', 'cgi', 'pl', 'py', 'sh', 'bash', 'asp', 'aspx', 'jsp', 'js', 'mjs', 'html', 'htm', 'shtml', 'svg', 'svgz', 'exe', 'dll', 'bat', 'cmd', 'com', 'htaccess', 'ini', 'cfm', 'rb' );

// ---------------------------------------------------------------------------
// XML-RPC 與 pingback
// ---------------------------------------------------------------------------
add_filter( 'xmlrpc_enabled', '__return_false' );
add_filter( 'xmlrpc_methods', '__return_empty_array', PHP_INT_MAX );
add_filter( 'pings_open', '__return_false', PHP_INT_MAX );
add_filter(
	'wp_headers',
	static function ( $headers ) {
		unset( $headers['X-Pingback'] );
		return $headers;
	}
);
remove_action( 'wp_head', 'rsd_link' );

// CMS 不提供核心 sitemap（canonical 屬主站）；JSONP 關閉（區塊編輯器不使用）。
add_filter( 'wp_sitemaps_enabled', '__return_false' );
add_filter( 'rest_jsonp_enabled', '__return_false' );

// ---------------------------------------------------------------------------
// 使用者列舉：未登入者看不到 /wp/v2/users（登入的編輯器、nonce、Application Password 不受影響）
// ---------------------------------------------------------------------------
add_filter(
	'rest_endpoints',
	static function ( $endpoints ) {
		if ( is_user_logged_in() ) {
			return $endpoints;
		}
		foreach ( array_keys( $endpoints ) as $route ) {
			if ( preg_match( '#^/wp/v2/users(?:/|$)#', $route ) ) {
				unset( $endpoints[ $route ] );
			}
		}
		return $endpoints;
	}
);

/**
 * 登入錯誤訊息不區分「帳號不存在」與「密碼錯誤」。
 */
add_filter(
	'wp_login_errors',
	static function ( $errors ) {
		if ( ! $errors instanceof WP_Error ) {
			return $errors;
		}
		$generic = false;
		foreach ( array( 'invalid_username', 'incorrect_password', 'invalid_email' ) as $code ) {
			if ( $errors->get_error_message( $code ) ) {
				$errors->remove( $code );
				$generic = true;
			}
		}
		if ( $generic ) {
			$errors->add( 'mysite_login_failed', '<strong>登入失敗</strong>：帳號或密碼不正確。' );
		}
		return $errors;
	}
);

// ---------------------------------------------------------------------------
// 上傳白名單（WP-08、INF-04）：副檔名、雙副檔名、實際 MIME、圖片可解析、大小
// ---------------------------------------------------------------------------
add_filter(
	'upload_mimes',
	static function () {
		return MYSITE_UPLOAD_MIMES;
	},
	PHP_INT_MAX
);
add_filter(
	'upload_size_limit',
	static function ( $bytes ) {
		return (int) min( (int) $bytes, MYSITE_UPLOAD_MAX_BYTES );
	},
	PHP_INT_MAX
);
add_filter( 'wp_handle_upload_prefilter', 'mysite_validate_upload' );
add_filter( 'wp_handle_sideload_prefilter', 'mysite_validate_upload' );

/**
 * 檢查上傳檔；不合格時設定 $file['error']（WordPress 會拒絕並回報訊息）。
 *
 * @param array<string,mixed> $file $_FILES 形式的檔案資訊。
 * @return array<string,mixed>
 */
function mysite_validate_upload( $file ) {
	if ( ! is_array( $file ) || ! empty( $file['error'] ) ) {
		return $file;
	}
	$reason = mysite_upload_rejection( (string) ( $file['name'] ?? '' ), (string) ( $file['tmp_name'] ?? '' ), (int) ( $file['size'] ?? 0 ) );
	if ( null !== $reason ) {
		$file['error'] = $reason;
	}
	return $file;
}

/**
 * 回傳拒絕原因；合格回 null。
 */
function mysite_upload_rejection( string $name, string $tmp, int $size ): ?string {
	$base = strtolower( wp_basename( $name ) );
	if ( '' === $base || preg_match( '/[\x00-\x1F\x7F\/\\\\]/', $base ) ) {
		return '檔名不合法。';
	}
	$parts = explode( '.', $base );
	if ( count( $parts ) < 2 ) {
		return '檔案必須有副檔名（允許 jpg、jpeg、png、gif、webp、mp3、m4a、wav、ogg）。';
	}
	$ext = (string) end( $parts );
	if ( ! isset( MYSITE_UPLOAD_REAL_MIMES[ $ext ] ) ) {
		return '不允許的檔案類型；只接受 jpg、jpeg、png、gif、webp、mp3、m4a、wav、ogg。';
	}
	$known = array_keys( MYSITE_UPLOAD_REAL_MIMES );
	foreach ( array_slice( $parts, 1, -1 ) as $inner ) {
		if ( in_array( $inner, MYSITE_UPLOAD_DANGEROUS_EXT, true ) || in_array( $inner, $known, true ) ) {
			return '不接受雙副檔名的檔案（例如 photo.php.jpg）。';
		}
	}
	if ( '' === $tmp || ! is_file( $tmp ) ) {
		return '找不到上傳的暫存檔。';
	}
	$actual = (int) filesize( $tmp );
	if ( $actual <= 0 ) {
		return '檔案是空的。';
	}
	if ( $actual > MYSITE_UPLOAD_MAX_BYTES || $size > MYSITE_UPLOAD_MAX_BYTES ) {
		return '檔案超過 32MB 上限。';
	}
	$finfo = finfo_open( FILEINFO_MIME_TYPE );
	$real  = $finfo ? (string) finfo_file( $finfo, $tmp ) : '';
	if ( ! in_array( $real, MYSITE_UPLOAD_REAL_MIMES[ $ext ], true ) ) {
		return '檔案內容與副檔名不符（偵測到 ' . ( '' === $real ? '未知格式' : $real ) . '）。';
	}
	if ( str_starts_with( $real, 'image/' ) ) {
		$info = @getimagesize( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! is_array( $info ) || empty( $info[0] ) || empty( $info[1] ) ) {
			return '圖片無法解析。';
		}
	}
	$head = (string) file_get_contents( $tmp, false, null, 0, 1048576 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	// 只比對長片段，避免壓縮資料隨機出現短位元組序列而誤判。
	if ( false !== stripos( $head, '<?php' ) || false !== stripos( $head, '<script' ) ) {
		return '檔案含有可執行程式片段，已拒絕。';
	}
	return null;
}

/**
 * 實際 MIME 檢查由 mysite_validate_upload 負責；這裡把核准的 m4a／wav 記錄為對外 MIME，
 * 避免核心以主類型比對時誤拒（例如 finfo 回 audio/x-wav）。
 */
add_filter(
	'wp_check_filetype_and_ext',
	static function ( $data, $file, $filename ) {
		$ext = strtolower( (string) pathinfo( (string) $filename, PATHINFO_EXTENSION ) );
		if ( empty( $data['ext'] ) && in_array( $ext, array( 'm4a', 'wav', 'ogg', 'mp3' ), true ) && null === mysite_upload_rejection( (string) $filename, (string) $file, 0 ) ) {
			$type = wp_check_filetype( (string) $filename, MYSITE_UPLOAD_MIMES );
			if ( ! empty( $type['ext'] ) ) {
				$data['ext']  = $type['ext'];
				$data['type'] = $type['type'];
			}
		}
		return $data;
	},
	10,
	3
);

// ---------------------------------------------------------------------------
// 管理員第二因素閘門（WP-10 程式部分）
// ---------------------------------------------------------------------------

/**
 * 純函式：決定管理員請求的處置。
 *
 * @return string allow｜notice｜redirect
 */
function mysite_2fa_gate_decision( string $environment, bool $is_admin_user, bool $has_two_factor, string $pagenow, bool $two_factor_available ): string {
	if ( ! $is_admin_user || $has_two_factor ) {
		return 'allow';
	}
	if ( ! in_array( $environment, array( 'staging', 'production' ), true ) ) {
		return 'notice';
	}
	$allowed = array( 'profile.php' );
	if ( ! $two_factor_available ) {
		$allowed[] = 'plugins.php'; // Two Factor 未啟用時仍要能啟用外掛，不鎖死管理員。
	}
	return in_array( $pagenow, $allowed, true ) ? 'notice' : 'redirect';
}

function mysite_user_has_two_factor( WP_User $user ): bool {
	return class_exists( 'Two_Factor_Core' ) && Two_Factor_Core::is_user_using_two_factor( $user->ID );
}

function mysite_is_gated_admin( ?WP_User $user = null ): bool {
	$user = $user ?? wp_get_current_user();
	return $user instanceof WP_User && $user->exists() && user_can( $user, 'manage_options' );
}

add_action(
	'admin_init',
	static function () {
		if ( wp_doing_ajax() || ! mysite_is_gated_admin() ) {
			return;
		}
		$user     = wp_get_current_user();
		$decision = mysite_2fa_gate_decision( wp_get_environment_type(), true, mysite_user_has_two_factor( $user ), (string) ( $GLOBALS['pagenow'] ?? '' ), class_exists( 'Two_Factor_Core' ) );
		if ( 'redirect' === $decision ) {
			wp_safe_redirect( admin_url( 'profile.php?mysite_2fa_required=1#two-factor-options' ) );
			exit;
		}
		if ( 'notice' === $decision ) {
			add_action(
				'admin_notices',
				static function () {
					$enforced = in_array( wp_get_environment_type(), array( 'staging', 'production' ), true );
					printf(
						'<div class="notice notice-%1$s"><p><strong>請完成第二因素驗證設定。</strong>%2$s <a href="%3$s">前往個人資料的「Two-Factor Options」</a>，並把備援碼保存在安全的地方（不要放進程式庫或截圖）。</p></div>',
						$enforced ? 'error' : 'warning',
						$enforced ? '正式環境中，未啟用第二因素的管理員只能使用個人資料頁。' : '（開發環境只提示，不強制。）',
						esc_url( admin_url( 'profile.php#two-factor-options' ) )
					);
				}
			);
		}
	}
);

/**
 * 以 cookie 登入、尚未啟用第二因素的管理員：正式環境只能呼叫設定第二因素所需的 REST。
 * Application Password 認證的請求（例如服務帳號）不受影響。
 */
add_filter(
	'rest_pre_dispatch',
	static function ( $result, $server, $request ) {
		if ( null !== $result || ! $request instanceof WP_REST_Request || ! mysite_is_gated_admin() ) {
			return $result;
		}
		if ( function_exists( 'rest_get_authenticated_app_password' ) && null !== rest_get_authenticated_app_password() ) {
			return $result;
		}
		$route = (string) $request->get_route();
		if ( str_starts_with( $route, '/two-factor/' ) || str_starts_with( $route, '/wp/v2/users/me' ) ) {
			return $result;
		}
		$decision = mysite_2fa_gate_decision( wp_get_environment_type(), true, mysite_user_has_two_factor( wp_get_current_user() ), 'rest', class_exists( 'Two_Factor_Core' ) );
		if ( 'redirect' === $decision ) {
			return new WP_Error( 'mysite_two_factor_required', '請先在個人資料頁完成第二因素驗證設定。', array( 'status' => 403 ) );
		}
		return $result;
	},
	5,
	3
);
