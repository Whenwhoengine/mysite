<?php
/**
 * 內容型別（影片、Podcast）、選單位置與受保護 meta 註冊。
 *
 * ACF 只提供欄位 UI，不重複註冊這兩個 CPT（見 includes/acf.php）。
 *
 * @package SiteContent
 */

namespace SiteContent;

defined( 'ABSPATH' ) || exit;

final class Content_Types {

	/**
	 * CPT → capability_type（單數、複數）。
	 */
	public const CAPABILITY_TYPES = array(
		'sc_video'   => array( 'sc_video', 'sc_videos' ),
		'sc_podcast' => array( 'sc_podcast', 'sc_podcasts' ),
	);

	public static function init(): void {
		add_action( 'init', array( self::class, 'register' ), 5 );
		add_action( 'after_setup_theme', array( self::class, 'register_menus' ), 20 );
	}

	public static function register(): void {
		$common = array(
			'public'              => true,
			'publicly_queryable'  => true,
			'show_ui'             => true,
			'show_in_menu'        => true,
			'show_in_nav_menus'   => true,
			'show_in_admin_bar'   => true,
			'show_in_rest'        => true,
			'exclude_from_search' => false,
			'has_archive'         => false,
			'hierarchical'        => false,
			'map_meta_cap'        => true,
			'supports'            => array( 'title', 'editor', 'excerpt', 'thumbnail', 'revisions', 'author', 'custom-fields' ),
			'taxonomies'          => array( 'category', 'post_tag' ),
			'delete_with_user'    => false,
		);

		register_post_type(
			'sc_video',
			array_merge(
				$common,
				array(
					'labels'          => self::labels( '影片', '影片' ),
					'description'     => '主站「影片」內容（YouTube 連結＋介紹）。',
					'menu_icon'       => 'dashicons-video-alt3',
					'menu_position'   => 6,
					'capability_type' => self::CAPABILITY_TYPES['sc_video'],
					'rest_base'       => 'sc_video',
					'rewrite'         => array(
						'slug'       => 'videos',
						'with_front' => false,
					),
				)
			)
		);

		register_post_type(
			'sc_podcast',
			array_merge(
				$common,
				array(
					'labels'          => self::labels( 'Podcast', 'Podcast' ),
					'description'     => '主站「Podcast」內容（核准來源的音訊＋介紹）。',
					'menu_icon'       => 'dashicons-microphone',
					'menu_position'   => 7,
					'capability_type' => self::CAPABILITY_TYPES['sc_podcast'],
					'rest_base'       => 'sc_podcast',
					'rewrite'         => array(
						'slug'       => 'podcasts',
						'with_front' => false,
					),
				)
			)
		);

		// 內容版本：外掛維護的單調遞增整數；受保護 meta（底線開頭），不經 REST 寫入。
		foreach ( Support::post_types() as $post_type ) {
			register_post_meta(
				$post_type,
				Content_Model::META_VERSION,
				array(
					'type'          => 'integer',
					'single'        => true,
					'show_in_rest'  => false,
					'auth_callback' => '__return_false',
				)
			);
		}
	}

	/**
	 * 主站選單位置（區塊佈景主題也會因此顯示「外觀 → 選單」）。
	 */
	public static function register_menus(): void {
		register_nav_menus(
			array(
				'primary' => '主站主選單',
				'footer'  => '主站頁尾選單',
			)
		);
	}

	/**
	 * @return array<string,string>
	 */
	private static function labels( string $singular, string $plural ): array {
		return array(
			'name'                  => $plural,
			'singular_name'         => $singular,
			'menu_name'             => $plural,
			'add_new'               => '新增' . $singular,
			'add_new_item'          => '新增' . $singular,
			'edit_item'             => '編輯' . $singular,
			'new_item'              => '新' . $singular,
			'view_item'             => '在主站檢視' . $singular,
			'view_items'            => '在主站檢視' . $plural,
			'search_items'          => '搜尋' . $plural,
			'not_found'             => '找不到' . $plural,
			'not_found_in_trash'    => '垃圾桶中沒有' . $plural,
			'all_items'             => '全部' . $plural,
			'archives'              => $plural . '彙整',
			'attributes'            => $singular . '屬性',
			'insert_into_item'      => '插入' . $singular,
			'uploaded_to_this_item' => '上傳到此' . $singular,
			'featured_image'        => '封面圖片',
			'set_featured_image'    => '設定封面圖片',
			'remove_featured_image' => '移除封面圖片',
			'use_featured_image'    => '使用為封面圖片',
			'item_published'        => $singular . '已發布',
			'item_updated'          => $singular . '已更新',
			'item_scheduled'        => $singular . '已排程',
			'item_reverted_to_draft' => $singular . '已轉為草稿',
		);
	}
}
