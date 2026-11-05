<?php
/**
 * 品牌最小集：「設定 → mysite 品牌」（WordPress Settings API，不依賴 ACF Pro Options Page）。
 *
 * 站名與副標沿用 WordPress「一般」設定（blogname、blogdescription）；
 * 作者介紹、社群連結（僅 https）、標誌附件與示例旗標存於 option `site_content_brand`。
 *
 * @package SiteContent
 */

namespace SiteContent;

defined( 'ABSPATH' ) || exit;

final class Branding {

	public const OPTION     = 'site_content_brand';
	public const GROUP      = 'site_content_brand_group';
	public const PAGE       = 'mysite-brand';
	public const MAX_LINKS  = 10;
	public const MAX_BIO    = 2000;
	public const CAPABILITY = 'manage_options';

	public static function init(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ) );
		add_action( 'admin_init', array( self::class, 'register_settings' ) );
	}

	/**
	 * @return array{author_bio:string,social_links:array<int,array{label:string,url:string}>,logo_id:int,is_sample:bool}
	 */
	public static function defaults(): array {
		return array(
			'author_bio'   => '',
			'social_links' => array(),
			'logo_id'      => 0,
			'is_sample'    => false,
		);
	}

	/**
	 * @return array{author_bio:string,social_links:array<int,array{label:string,url:string}>,logo_id:int,is_sample:bool}
	 */
	public static function get(): array {
		$value = get_option( self::OPTION, array() );
		$value = is_array( $value ) ? $value : array();
		$out   = array_merge( self::defaults(), array_intersect_key( $value, self::defaults() ) );
		$links = array();
		foreach ( (array) $out['social_links'] as $link ) {
			if ( is_array( $link ) && isset( $link['label'], $link['url'] ) && self::valid_https( (string) $link['url'] ) ) {
				$links[] = array(
					'label' => (string) $link['label'],
					'url'   => (string) $link['url'],
				);
			}
		}
		$out['social_links'] = $links;
		$out['author_bio']   = (string) $out['author_bio'];
		$out['logo_id']      = (int) $out['logo_id'];
		$out['is_sample']    = (bool) $out['is_sample'];
		return $out;
	}

	public static function valid_https( string $url ): bool {
		if ( strlen( $url ) > 2048 || preg_match( '/[\s\x00-\x1F\x7F<>"]/', $url ) ) {
			return false;
		}
		$parts = wp_parse_url( $url );
		return is_array( $parts ) && 'https' === strtolower( (string) ( $parts['scheme'] ?? '' ) ) && ! empty( $parts['host'] ) && ! isset( $parts['user'] ) && ! isset( $parts['pass'] );
	}

	/**
	 * GET /site 的 data。
	 *
	 * @return array<string,mixed>
	 */
	public static function public_data(): array {
		$brand = self::get();
		$links = array();
		foreach ( $brand['social_links'] as $link ) {
			$links[] = array(
				'label' => Support::plain_text( $link['label'] ),
				'url'   => esc_url_raw( $link['url'], array( 'https' ) ),
			);
		}
		$name = Support::plain_text( (string) get_option( 'blogname', '' ) );
		return array(
			'name'         => '' === $name ? 'mysite' : $name,
			'tagline'      => Support::plain_text( (string) get_option( 'blogdescription', '' ) ),
			'author_bio'   => Support::plain_text( $brand['author_bio'] ),
			'social_links' => $links,
			'logo'         => Content_Model::image( $brand['logo_id'] ),
			'is_sample'    => $brand['is_sample'],
		);
	}

	public static function menu(): void {
		add_options_page( 'mysite 品牌設定', 'mysite 品牌', self::CAPABILITY, self::PAGE, array( self::class, 'render' ) );
	}

	public static function register_settings(): void {
		register_setting(
			self::GROUP,
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( self::class, 'sanitize' ),
				'default'           => self::defaults(),
				'show_in_rest'      => false,
			)
		);
		add_settings_section( 'site_content_brand_main', '主站品牌資訊', static function () {
			echo '<p>站名與副標請在「設定 → 一般」修改；以下欄位由主站首頁與頁尾顯示。社群連結只接受 https 網址。</p>';
		}, self::PAGE );
		add_settings_field( 'author_bio', '作者介紹（純文字）', array( self::class, 'field_bio' ), self::PAGE, 'site_content_brand_main', array( 'label_for' => 'site_content_brand_author_bio' ) );
		add_settings_field( 'social_links', '社群連結', array( self::class, 'field_links' ), self::PAGE, 'site_content_brand_main', array( 'label_for' => 'site_content_brand_social_links' ) );
		add_settings_field( 'logo_id', '標誌圖片', array( self::class, 'field_logo' ), self::PAGE, 'site_content_brand_main', array( 'label_for' => 'site_content_brand_logo_id' ) );
		add_settings_field( 'is_sample', '示例內容', array( self::class, 'field_sample' ), self::PAGE, 'site_content_brand_main', array( 'label_for' => 'site_content_brand_is_sample' ) );
	}

	/**
	 * Settings API sanitize：任何不合法輸入都不寫入，並提示原因。
	 *
	 * @param mixed $input 表單輸入。
	 * @return array<string,mixed>
	 */
	public static function sanitize( $input ): array {
		$input   = is_array( $input ) ? $input : array();
		$current = self::get();
		$out     = self::defaults();

		$bio = sanitize_textarea_field( (string) ( $input['author_bio'] ?? '' ) );
		if ( mb_strlen( $bio ) > self::MAX_BIO ) {
			add_settings_error( self::OPTION, 'bio_too_long', sprintf( '作者介紹超過 %d 字，已截斷。', self::MAX_BIO ) );
			$bio = mb_substr( $bio, 0, self::MAX_BIO );
		}
		$out['author_bio'] = $bio;

		$raw_links = $input['social_links'] ?? '';
		$lines     = is_array( $raw_links ) ? array() : preg_split( '/\r?\n/', (string) $raw_links );
		if ( is_array( $raw_links ) ) {
			foreach ( $raw_links as $link ) {
				if ( is_array( $link ) ) {
					$lines[] = ( $link['label'] ?? '' ) . '|' . ( $link['url'] ?? '' );
				}
			}
		}
		foreach ( (array) $lines as $line ) {
			$line = trim( (string) $line );
			if ( '' === $line ) {
				continue;
			}
			$pair  = array_map( 'trim', explode( '|', $line, 2 ) );
			$label = sanitize_text_field( $pair[0] ?? '' );
			$url   = (string) ( $pair[1] ?? '' );
			if ( '' === $label || mb_strlen( $label ) > 50 || ! self::valid_https( $url ) ) {
				add_settings_error( self::OPTION, 'bad_link', '已略過不合法的社群連結（格式為「名稱|https://網址」，只接受 https）。' );
				continue;
			}
			if ( count( $out['social_links'] ) >= self::MAX_LINKS ) {
				add_settings_error( self::OPTION, 'too_many_links', sprintf( '社群連結最多 %d 筆。', self::MAX_LINKS ) );
				break;
			}
			$out['social_links'][] = array(
				'label' => $label,
				'url'   => esc_url_raw( $url, array( 'https' ) ),
			);
		}

		$logo = absint( $input['logo_id'] ?? 0 );
		if ( $logo > 0 && null === Content_Model::image( $logo ) ) {
			add_settings_error( self::OPTION, 'bad_logo', '標誌必須是媒體庫中的圖片，已保留原設定。' );
			$logo = $current['logo_id'];
		}
		$out['logo_id']   = $logo;
		$out['is_sample'] = ! empty( $input['is_sample'] );
		return $out;
	}

	public static function field_bio(): void {
		$brand = self::get();
		printf(
			'<textarea id="site_content_brand_author_bio" name="%1$s[author_bio]" rows="6" class="large-text" maxlength="%2$d">%3$s</textarea><p class="description">只顯示純文字；請勿填入未經同意公開的個人資料。</p>',
			esc_attr( self::OPTION ),
			(int) self::MAX_BIO,
			esc_textarea( $brand['author_bio'] )
		);
	}

	public static function field_links(): void {
		$brand = self::get();
		$lines = array();
		foreach ( $brand['social_links'] as $link ) {
			$lines[] = $link['label'] . '|' . $link['url'];
		}
		printf(
			'<textarea id="site_content_brand_social_links" name="%1$s[social_links]" rows="5" class="large-text code">%2$s</textarea><p class="description">每行一筆，格式「名稱|https://網址」，最多 %3$d 筆；非 https 會被略過。</p>',
			esc_attr( self::OPTION ),
			esc_textarea( implode( "\n", $lines ) ),
			(int) self::MAX_LINKS
		);
	}

	public static function field_logo(): void {
		$brand  = self::get();
		$images = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_mime_type' => 'image',
				'posts_per_page' => 200,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'fields'         => 'ids',
			)
		);
		printf( '<select id="site_content_brand_logo_id" name="%s[logo_id]">', esc_attr( self::OPTION ) );
		printf( '<option value="0">（不使用標誌）</option>' );
		foreach ( $images as $id ) {
			printf( '<option value="%1$d"%2$s>%3$s（ID %1$d）</option>', (int) $id, selected( (int) $id, $brand['logo_id'], false ), esc_html( get_the_title( (int) $id ) ) );
		}
		echo '</select><p class="description">請先在「媒體」上傳圖片並填寫替代文字。</p>';
		if ( $brand['logo_id'] > 0 ) {
			echo '<p>' . wp_get_attachment_image( $brand['logo_id'], 'thumbnail' ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
	}

	public static function field_sample(): void {
		$brand = self::get();
		printf(
			'<label><input type="checkbox" id="site_content_brand_is_sample" name="%1$s[is_sample]" value="1"%2$s> 目前的品牌資訊是示例內容（主站會標示「示例內容」）</label>',
			esc_attr( self::OPTION ),
			checked( $brand['is_sample'], true, false )
		);
	}

	public static function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.' ), 403 );
		}
		echo '<div class="wrap"><h1>mysite 品牌設定</h1><form method="post" action="options.php">';
		settings_fields( self::GROUP );
		do_settings_sections( self::PAGE );
		submit_button( '儲存品牌設定' );
		echo '</form></div>';
	}
}
