<?php
/**
 * 主站網址映射：WP 產生的內容連結一律指向 MYSITE_APP_URL（canonical 屬主站）。
 *
 * 對照表（docs/網址映射.md）：
 *   post → /articles/{slug}、sc_video → /videos/{slug}、sc_podcast → /podcasts/{slug}、
 *   page → /pages/{slug}、category → /categories/{slug}、post_tag → /tags/{slug}。
 *
 * 只有已發布（或私人）內容映射到主站；草稿、待審、排程保留 WP 原生連結（?p=ID），
 * 讓區塊編輯器的「預覽」經由 CMS 前臺轉入本外掛的預覽流程。
 *
 * @package SiteContent
 */

namespace SiteContent;

defined( 'ABSPATH' ) || exit;

final class Url_Mapping {

	public const PREFIX = array(
		'post'    => 'articles',
		'page'    => 'pages',
		'video'   => 'videos',
		'podcast' => 'podcasts',
	);

	public const ARCHIVE = array(
		'post'       => '/articles',
		'sc_video'   => '/videos',
		'sc_podcast' => '/podcasts',
	);

	public static function init(): void {
		add_filter( 'post_link', array( self::class, 'filter_post_link' ), 20, 3 );
		add_filter( 'page_link', array( self::class, 'filter_page_link' ), 20, 3 );
		add_filter( 'post_type_link', array( self::class, 'filter_post_type_link' ), 20, 4 );
		add_filter( 'term_link', array( self::class, 'filter_term_link' ), 20, 3 );
		add_filter( 'post_type_archive_link', array( self::class, 'filter_archive_link' ), 20, 2 );
	}

	/**
	 * 主站相對路徑；沒有 slug 時回 null。
	 */
	public static function path_for_post( \WP_Post $post ): ?string {
		$type = Support::type_for( $post->post_type );
		if ( null === $type || '' === (string) $post->post_name ) {
			return null;
		}
		return '/' . self::PREFIX[ $type ] . '/' . $post->post_name;
	}

	public static function url_for_post( \WP_Post $post ): ?string {
		$path = self::path_for_post( $post );
		return null === $path ? null : Support::app_url() . $path;
	}

	public static function path_for_term( \WP_Term $term ): ?string {
		if ( 'category' === $term->taxonomy ) {
			return '/categories/' . $term->slug;
		}
		if ( 'post_tag' === $term->taxonomy ) {
			return '/tags/' . $term->slug;
		}
		return null;
	}

	/**
	 * 是否映射：已發布／私人，或 get_sample_permalink() 的範本模式。
	 */
	private static function should_map( \WP_Post $post, bool $sample ): bool {
		return $sample || in_array( $post->post_status, array( 'publish', 'private' ), true ) || 'sample' === ( $post->filter ?? '' );
	}

	public static function filter_post_link( $permalink, $post, $leavename = false ) {
		if ( ! $post instanceof \WP_Post || 'post' !== $post->post_type || ! is_string( $permalink ) ) {
			return $permalink;
		}
		if ( $leavename ) {
			return Support::app_url() . '/articles/%postname%';
		}
		if ( ! self::should_map( $post, false ) ) {
			return $permalink;
		}
		return self::url_for_post( $post ) ?? $permalink;
	}

	public static function filter_page_link( $link, $post_id, $sample = false ) {
		$post = get_post( (int) $post_id );
		if ( ! $post || 'page' !== $post->post_type || ! is_string( $link ) ) {
			return $link;
		}
		// get_permalink( $post, true ) 會帶 %pagename% 佔位字（編輯器修改 slug 用）。
		if ( $sample || str_contains( $link, '%pagename%' ) ) {
			return Support::app_url() . '/pages/%pagename%';
		}
		if ( ! self::should_map( $post, false ) ) {
			return $link;
		}
		return self::url_for_post( $post ) ?? $link;
	}

	public static function filter_post_type_link( $post_link, $post, $leavename = false, $sample = false ) {
		if ( ! $post instanceof \WP_Post || ! in_array( $post->post_type, array( 'sc_video', 'sc_podcast' ), true ) || ! is_string( $post_link ) ) {
			return $post_link;
		}
		$type = (string) Support::type_for( $post->post_type );
		if ( $leavename ) {
			// get_sample_permalink() 會把 %sc_video% 換成 %pagename% 供編輯器修改 slug。
			return Support::app_url() . '/' . self::PREFIX[ $type ] . '/%' . $post->post_type . '%';
		}
		if ( ! self::should_map( $post, (bool) $sample ) ) {
			return $post_link;
		}
		return self::url_for_post( $post ) ?? $post_link;
	}

	public static function filter_term_link( $termlink, $term, $taxonomy = '' ) {
		if ( ! $term instanceof \WP_Term ) {
			return $termlink;
		}
		$path = self::path_for_term( $term );
		return null === $path ? $termlink : Support::app_url() . $path;
	}

	public static function filter_archive_link( $link, $post_type ) {
		return is_string( $post_type ) && isset( self::ARCHIVE[ $post_type ] ) ? Support::app_url() . self::ARCHIVE[ $post_type ] : $link;
	}
}
