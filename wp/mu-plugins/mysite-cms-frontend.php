<?php
/**
 * Plugin Name: mysite CMS 前臺轉址（mu-plugin）
 * Description: CMS 前臺不公開重複正文：內容、分類、標籤、首頁、搜尋與 feed 一律 301 轉到主站（MYSITE_APP_URL）對應網址，
 *              並送 X-Robots-Tag: noindex。wp-admin、wp-login、wp-json（REST）、uploads、cron、admin-ajax、admin-post 不受影響。
 *              已登入編輯者的原生預覽（?preview=true）改走 site-content 外掛的主站預覽流程。
 * Version:     0.1.0
 * License:     GPL-2.0-or-later
 *
 * @package MysiteMu
 */

defined( 'ABSPATH' ) || exit;

/**
 * 不轉址的路徑（交給 WordPress 原生處理，例如 /login → wp-login.php）。
 */
const MYSITE_FRONTEND_PASSTHROUGH = array( '/login', '/admin', '/dashboard', '/wp-admin', '/wp-login', '/wp-login.php' );

add_action( 'template_redirect', 'mysite_cms_frontend_redirect', 0 );

function mysite_cms_frontend_redirect(): void {
	if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'WP_CLI' ) && WP_CLI ) || is_robots() ) {
		return;
	}
	$path = (string) wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '/' ), PHP_URL_PATH ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	if ( in_array( untrailingslashit( $path ), MYSITE_FRONTEND_PASSTHROUGH, true ) ) {
		return;
	}
	$app = defined( 'MYSITE_APP_URL' ) ? rtrim( (string) MYSITE_APP_URL, '/' ) : '';
	if ( '' === $app ) {
		return; // 未設定主站網址：維持 WordPress 原生行為，不猜測目的地。
	}

	// 已登入編輯者的原生預覽 → 本案預覽流程（驗 nonce 與 edit_post 後簽發票券）。
	if ( is_preview() && is_user_logged_in() && class_exists( 'SiteContent\\Preview' ) ) {
		$post_id = mysite_cms_preview_post_id();
		if ( $post_id > 0 && current_user_can( 'edit_post', $post_id ) ) {
			header( 'X-Robots-Tag: noindex, nofollow' );
			header( 'Referrer-Policy: no-referrer' );
			wp_redirect( SiteContent\Preview::link( $post_id ), 302, false ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
			exit;
		}
	}

	$target = mysite_cms_frontend_target( $app );
	header( 'X-Robots-Tag: noindex, nofollow' );
	wp_redirect( $target, 301, false ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
	exit;
}

function mysite_cms_preview_post_id(): int {
	foreach ( array( 'preview_id', 'p', 'page_id', 'post' ) as $key ) {
		if ( isset( $_GET[ $key ] ) && ctype_digit( (string) $_GET[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput
			return (int) $_GET[ $key ]; // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput
		}
	}
	$queried = get_queried_object();
	return $queried instanceof WP_Post ? (int) $queried->ID : 0;
}

/**
 * 依目前查詢決定主站目的地；無對應時回主站首頁。
 */
function mysite_cms_frontend_target( string $app ): string {
	$target = $app . '/';
	$mapper = class_exists( 'SiteContent\\Url_Mapping' ) && class_exists( 'SiteContent\\Content_Model' );
	if ( is_singular() ) {
		$post = get_queried_object();
		if ( $mapper && $post instanceof WP_Post && SiteContent\Content_Model::is_public( $post ) ) {
			$target = (string) ( SiteContent\Url_Mapping::url_for_post( $post ) ?? $target );
		}
	} elseif ( is_category() || is_tag() ) {
		$term = get_queried_object();
		if ( $mapper && $term instanceof WP_Term ) {
			$path   = SiteContent\Url_Mapping::path_for_term( $term );
			$target = null === $path ? $target : $app . $path;
		}
	} elseif ( is_post_type_archive() ) {
		$type   = (string) get_query_var( 'post_type' );
		$target = $mapper && isset( SiteContent\Url_Mapping::ARCHIVE[ $type ] ) ? $app . SiteContent\Url_Mapping::ARCHIVE[ $type ] : $target;
	} elseif ( is_search() ) {
		$query  = trim( (string) get_search_query( false ) );
		$target = '' === $query ? $app . '/search' : $app . '/search?q=' . rawurlencode( mb_substr( $query, 0, 100 ) );
	} elseif ( is_feed() ) {
		$target = $app . '/feed.xml';
	} elseif ( is_home() || is_front_page() ) {
		$target = $app . '/articles';
		if ( is_front_page() ) {
			$target = $app . '/';
		}
	}
	return str_starts_with( $target, $app . '/' ) ? $target : $app . '/';
}
