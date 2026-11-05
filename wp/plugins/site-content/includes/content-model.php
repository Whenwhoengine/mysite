<?php
/**
 * 內容模型：公開判定、ContentItem（model_version 1）序列化、指紋、媒體欄位解析。
 *
 * 欄位與型別完全依 docs/API契約.md §2；不得回傳 email、登入名稱、內部路徑或 post_password。
 *
 * @package SiteContent
 */

namespace SiteContent;

defined( 'ABSPATH' ) || exit;

final class Content_Model {

	public const META_VERSION = '_sc_content_version';

	/**
	 * 影響公開內容的 meta（ACF 欄位與封面）；變更時重新觀察內容。
	 */
	public const WATCHED_META = array( '_thumbnail_id', 'sc_youtube_url', 'sc_duration_seconds', 'sc_audio_url', 'sc_audio_attachment', 'sc_is_sample', 'sc_exclude' );

	/**
	 * 進入預覽快照的 ACF 欄位。
	 */
	public const ACF_META = array( 'sc_youtube_url', 'sc_duration_seconds', 'sc_audio_url', 'sc_audio_attachment', 'sc_is_sample' );

	public const YOUTUBE_HOSTS = array( 'youtube.com', 'www.youtube.com', 'm.youtube.com', 'music.youtube.com', 'youtu.be', 'www.youtu.be', 'youtube-nocookie.com', 'www.youtube-nocookie.com' );

	/**
	 * 音訊副檔名 → 對外 MIME。
	 */
	public const AUDIO_MIME_BY_EXT = array(
		'mp3' => 'audio/mpeg',
		'm4a' => 'audio/mp4',
		'wav' => 'audio/wav',
		'ogg' => 'audio/ogg',
	);

	/**
	 * 公開條件（全部成立）：publish、無密碼、核准型別、非 sc_exclude。
	 */
	public static function is_public( \WP_Post $post ): bool {
		return 'publish' === $post->post_status
			&& '' === (string) $post->post_password
			&& null !== Support::type_for( $post->post_type )
			&& ! self::is_excluded( (int) $post->ID );
	}

	public static function is_excluded( int $post_id ): bool {
		return '1' === (string) get_post_meta( $post_id, 'sc_exclude', true );
	}

	public static function content_version( int $post_id ): int {
		return max( 0, (int) get_post_meta( $post_id, self::META_VERSION, true ) );
	}

	/**
	 * 內容指紋：涵蓋所有會影響公開 ContentItem 或公開狀態的欄位（不含 post_modified）。
	 */
	public static function fingerprint( \WP_Post $post ): string {
		$terms = array();
		foreach ( array( 'category', 'post_tag' ) as $taxonomy ) {
			if ( ! is_object_in_taxonomy( $post->post_type, $taxonomy ) ) {
				continue;
			}
			$ids = wp_get_object_terms(
				$post->ID,
				$taxonomy,
				array(
					'fields'                 => 'ids',
					'update_term_meta_cache' => false,
				)
			);
			$ids = is_array( $ids ) ? array_map( 'intval', $ids ) : array();
			sort( $ids );
			$terms[ $taxonomy ] = $ids;
		}
		$meta = array();
		foreach ( self::WATCHED_META as $key ) {
			$meta[ $key ] = (string) get_post_meta( $post->ID, $key, true );
		}
		$data = array(
			'type'     => $post->post_type,
			'status'   => $post->post_status,
			'password' => '' !== (string) $post->post_password,
			'slug'     => $post->post_name,
			'title'    => $post->post_title,
			'excerpt'  => $post->post_excerpt,
			'content'  => $post->post_content,
			'date_gmt' => $post->post_date_gmt,
			'date'     => $post->post_date,
			'author'   => (int) $post->post_author,
			'parent'   => (int) $post->post_parent,
			'terms'    => $terms,
			'meta'     => $meta,
		);
		return hash( 'sha256', (string) wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
	}

	/**
	 * 建立 ContentItem。
	 *
	 * @param \WP_Post            $post      內容。
	 * @param bool                $with_body 是否含 body_html（列表不含）。
	 * @param array<string,mixed> $override  預覽快照用：title、content、excerpt、meta（陣列）、thumbnail_id。
	 * @return array<string,mixed>
	 */
	public static function build_item( \WP_Post $post, bool $with_body, array $override = array() ): array {
		$type        = (string) Support::type_for( $post->post_type );
		$title_raw   = array_key_exists( 'title', $override ) ? (string) $override['title'] : $post->post_title;
		$content_raw = array_key_exists( 'content', $override ) ? (string) $override['content'] : $post->post_content;
		$excerpt_raw = array_key_exists( 'excerpt', $override ) ? (string) $override['excerpt'] : $post->post_excerpt;
		$meta_over   = isset( $override['meta'] ) && is_array( $override['meta'] ) ? $override['meta'] : array();
		$meta        = static function ( string $key ) use ( $post, $meta_over ) {
			return array_key_exists( $key, $meta_over ) ? $meta_over[ $key ] : get_post_meta( $post->ID, $key, true );
		};
		$thumbnail = array_key_exists( 'thumbnail_id', $override ) ? (int) $override['thumbnail_id'] : (int) get_post_thumbnail_id( $post );

		$item = array(
			'id'      => (int) $post->ID,
			'type'    => $type,
			'slug'    => (string) $post->post_name,
			'title'   => Support::plain_text( $title_raw ),
			'excerpt' => self::excerpt( $excerpt_raw, $content_raw ),
		);
		if ( $with_body ) {
			$item['body_html'] = self::render_body( $post, $content_raw );
		}
		$item['author']          = array( 'display_name' => self::author_name( (int) $post->post_author ) );
		$item['categories']      = self::terms( $post, 'category' );
		$item['tags']            = self::terms( $post, 'post_tag' );
		$item['cover']           = self::image( $thumbnail );
		$item['published_at']    = self::published_at( $post );
		$item['modified_at']     = Support::utc( $post->post_modified_gmt ) ?? $item['published_at'];
		$item['content_version'] = self::content_version( (int) $post->ID );
		$item['is_sample']       = '1' === (string) $meta( 'sc_is_sample' );
		if ( 'video' === $type ) {
			$item['video'] = self::video( (string) $meta( 'sc_youtube_url' ), $meta( 'sc_duration_seconds' ) );
		}
		if ( 'podcast' === $type ) {
			$item['podcast'] = self::podcast( (int) $meta( 'sc_audio_attachment' ), (string) $meta( 'sc_audio_url' ), $meta( 'sc_duration_seconds' ) );
		}
		return $item;
	}

	private static function published_at( \WP_Post $post ): ?string {
		$gmt = Support::utc( $post->post_date_gmt );
		if ( null !== $gmt ) {
			return $gmt;
		}
		// 草稿可能沒有 GMT 日期：以站台時區換算 post_date。
		if ( $post->post_date && ! str_starts_with( $post->post_date, '0000-00-00' ) ) {
			return Support::utc( get_gmt_from_date( $post->post_date ) );
		}
		return null;
	}

	/**
	 * 純文字摘要：手動摘要優先，否則由正文擷取前 120 字。
	 */
	public static function excerpt( string $excerpt, string $content ): string {
		if ( '' !== trim( $excerpt ) ) {
			return Support::plain_text( $excerpt );
		}
		$text = excerpt_remove_blocks( $content );
		$text = strip_shortcodes( $text );
		$text = Support::plain_text( $text );
		if ( mb_strlen( $text ) > 120 ) {
			$text = rtrim( mb_substr( $text, 0, 120 ) ) . '…';
		}
		return $text;
	}

	/**
	 * 渲染正文：區塊與 the_content 篩選器 → wp_kses_post。
	 * 暫停 oEmbed 自動嵌入，避免讀取 API 時對外抓取（SSRF／延遲）；嵌入區塊降級為連結文字。
	 */
	public static function render_body( \WP_Post $post, string $content ): string {
		global $wp_embed;
		$previous_post     = $GLOBALS['post'] ?? null;
		$GLOBALS['post']   = $post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		setup_postdata( $post );

		$removed = array();
		if ( isset( $wp_embed ) && is_object( $wp_embed ) ) {
			foreach ( array( 'run_shortcode' => 8, 'autoembed' => 8 ) as $method => $priority ) {
				if ( false !== has_filter( 'the_content', array( $wp_embed, $method ) ) ) {
					remove_filter( 'the_content', array( $wp_embed, $method ), $priority );
					$removed[ $method ] = $priority;
				}
			}
		}
		add_filter( 'pre_oembed_result', array( self::class, 'block_oembed' ), 1 );

		try {
			$html = (string) apply_filters( 'the_content', $content ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		} finally {
			remove_filter( 'pre_oembed_result', array( self::class, 'block_oembed' ), 1 );
			foreach ( $removed as $method => $priority ) {
				add_filter( 'the_content', array( $wp_embed, $method ), $priority );
			}
			$GLOBALS['post'] = $previous_post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			if ( $previous_post instanceof \WP_Post ) {
				setup_postdata( $previous_post );
			}
		}
		return trim( wp_kses_post( $html ) );
	}

	/**
	 * pre_oembed_result：一律不對外取得嵌入內容。
	 */
	public static function block_oembed(): string {
		return '';
	}

	/**
	 * 公開作者名稱：顯示名稱若等同登入名稱或像 email，改用中性名稱（API-14）。
	 */
	public static function author_name( int $user_id ): string {
		$user = $user_id > 0 ? get_userdata( $user_id ) : false;
		if ( ! $user ) {
			return '編輯部';
		}
		$name = Support::plain_text( (string) $user->display_name );
		if ( '' === $name || 0 === strcasecmp( $name, (string) $user->user_login ) || str_contains( $name, '@' ) || 0 === strcasecmp( $name, (string) $user->user_nicename ) ) {
			return '編輯部';
		}
		return $name;
	}

	/**
	 * @return array<int,array{id:int,slug:string,name:string}>
	 */
	public static function terms( \WP_Post $post, string $taxonomy ): array {
		if ( ! is_object_in_taxonomy( $post->post_type, $taxonomy ) ) {
			return array();
		}
		$terms = get_the_terms( $post, $taxonomy );
		if ( ! is_array( $terms ) ) {
			return array();
		}
		$out = array();
		foreach ( $terms as $term ) {
			$out[] = array(
				'id'   => (int) $term->term_id,
				'slug' => (string) $term->slug,
				'name' => Support::plain_text( (string) $term->name ),
			);
		}
		return $out;
	}

	/**
	 * 圖片附件 → {url, alt, width, height}；不存在或非圖片回 null。
	 *
	 * @return array<string,mixed>|null
	 */
	public static function image( int $attachment_id ): ?array {
		if ( $attachment_id <= 0 ) {
			return null;
		}
		$attachment = get_post( $attachment_id );
		if ( ! $attachment || 'attachment' !== $attachment->post_type || ! str_starts_with( (string) $attachment->post_mime_type, 'image/' ) ) {
			return null;
		}
		$src = wp_get_attachment_image_src( $attachment_id, 'full' );
		if ( ! is_array( $src ) || empty( $src[0] ) ) {
			return null;
		}
		return array(
			'url'    => (string) $src[0],
			'alt'    => Support::plain_text( (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) ),
			'width'  => (int) $src[1],
			'height' => (int) $src[2],
		);
	}

	/**
	 * 由網址解析 11 碼 YouTube ID；host 不在白名單或格式不符回 null。
	 */
	public static function youtube_id( string $url ): ?string {
		$url = trim( $url );
		if ( '' === $url || strlen( $url ) > 2048 ) {
			return null;
		}
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || empty( $parts['scheme'] ) ) {
			return null;
		}
		if ( ! in_array( strtolower( $parts['scheme'] ), array( 'https', 'http' ), true ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return null;
		}
		$host = strtolower( $parts['host'] );
		if ( ! in_array( $host, self::YOUTUBE_HOSTS, true ) ) {
			return null;
		}
		$path = (string) ( $parts['path'] ?? '' );
		$id   = null;
		if ( str_ends_with( $host, 'youtu.be' ) ) {
			$id = ltrim( $path, '/' );
		} elseif ( '/watch' === rtrim( $path, '/' ) ) {
			parse_str( (string) ( $parts['query'] ?? '' ), $query );
			$id = isset( $query['v'] ) && is_string( $query['v'] ) ? $query['v'] : null;
		} elseif ( preg_match( '#^/(?:embed|shorts|live|v)/([^/?]+)/?$#', $path, $m ) ) {
			$id = $m[1];
		}
		return ( is_string( $id ) && preg_match( '/^[A-Za-z0-9_-]{11}$/', $id ) ) ? $id : null;
	}

	private static function duration( mixed $value ): ?int {
		if ( is_int( $value ) ) {
			$value = (string) $value;
		}
		if ( ! is_string( $value ) || ! preg_match( '/^[0-9]{1,6}$/', trim( $value ) ) ) {
			return null;
		}
		$int = (int) trim( $value );
		return ( $int > 0 && $int <= 86400 ) ? $int : null;
	}

	/**
	 * @return array<string,mixed>|null
	 */
	public static function video( string $url, mixed $duration ): ?array {
		$id = self::youtube_id( $url );
		if ( null === $id ) {
			return null;
		}
		return array(
			'youtube_id'       => $id,
			'youtube_url'      => 'https://www.youtube.com/watch?v=' . $id,
			'duration_seconds' => self::duration( $duration ),
		);
	}

	/**
	 * Podcast 音訊：附件優先，其次核准 host 的 HTTPS 網址。
	 *
	 * @return array<string,mixed>|null
	 */
	public static function podcast( int $attachment_id, string $url, mixed $duration ): ?array {
		$audio_url = null;
		$mime      = null;
		if ( $attachment_id > 0 ) {
			$attachment = get_post( $attachment_id );
			if ( $attachment && 'attachment' === $attachment->post_type ) {
				$file_url = (string) wp_get_attachment_url( $attachment_id );
				$ext      = strtolower( (string) pathinfo( (string) wp_parse_url( $file_url, PHP_URL_PATH ), PATHINFO_EXTENSION ) );
				if ( isset( self::AUDIO_MIME_BY_EXT[ $ext ] ) && self::is_allowed_audio_url( $file_url ) ) {
					$audio_url = $file_url;
					$mime      = self::AUDIO_MIME_BY_EXT[ $ext ];
				}
			}
		}
		if ( null === $audio_url && '' !== trim( $url ) && self::is_allowed_audio_url( trim( $url ) ) ) {
			$ext = strtolower( (string) pathinfo( (string) wp_parse_url( trim( $url ), PHP_URL_PATH ), PATHINFO_EXTENSION ) );
			if ( isset( self::AUDIO_MIME_BY_EXT[ $ext ] ) ) {
				$audio_url = trim( $url );
				$mime      = self::AUDIO_MIME_BY_EXT[ $ext ];
			}
		}
		if ( null === $audio_url ) {
			return null;
		}
		return array(
			'audio_url'        => $audio_url,
			'audio_mime'       => $mime,
			'duration_seconds' => self::duration( $duration ),
		);
	}

	/**
	 * 音訊來源政策：host 在 PODCAST_AUDIO_ALLOWED_HOSTS（空＝CMS 自身 host），僅 https；
	 * 非正式環境允許 CMS 自身 host 沿用 WP_PUBLIC_URL 原 scheme（開發為 http）。
	 */
	public static function is_allowed_audio_url( string $url ): bool {
		if ( strlen( $url ) > 2048 || preg_match( '/[\s\x00-\x1F]/', $url ) ) {
			return false;
		}
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || empty( $parts['scheme'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return false;
		}
		$scheme = strtolower( $parts['scheme'] );
		$host   = strtolower( $parts['host'] );
		$home   = wp_parse_url( home_url() );
		$home_h = strtolower( (string) ( $home['host'] ?? '' ) );
		$home_s = strtolower( (string) ( $home['scheme'] ?? '' ) );

		$configured = defined( 'MYSITE_PODCAST_AUDIO_ALLOWED_HOSTS' ) ? (string) MYSITE_PODCAST_AUDIO_ALLOWED_HOSTS : '';
		$allowed    = array_values( array_filter( array_map( static fn( $h ) => strtolower( trim( $h ) ), explode( ',', $configured ) ) ) );
		if ( array() === $allowed ) {
			$allowed = array( $home_h );
		}
		if ( ! in_array( $host, $allowed, true ) ) {
			return false;
		}
		if ( 'https' === $scheme ) {
			return true;
		}
		return 'http' === $scheme && ! Support::is_production_like() && $host === $home_h && 'http' === $home_s;
	}
}
