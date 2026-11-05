<?php
/**
 * 公開 REST（site-content/v1）：列表、單篇、可見性、分類標籤、選單、搜尋、品牌。
 *
 * 每個 route 的 permission_callback 都實際檢查參數界限、型別與公開狀態（WP-03、API-03）；
 * 參數錯誤回 400 site_content_invalid_param，不靜默截斷；不存在或非公開回 404 site_content_not_found。
 *
 * @package SiteContent
 */

namespace SiteContent;

defined( 'ABSPATH' ) || exit;

final class Rest_Public {

	public const MAX_PER_PAGE  = 50;
	public const MAX_PAGE      = 1000;
	public const DEFAULT_PAGE  = 12;
	public const MAX_SLUG      = 200;
	public const MAX_QUERY_LEN = 100;
	public const MENU_LOCATIONS = array( 'primary', 'footer' );

	public static function init(): void {
		add_action( 'rest_api_init', array( self::class, 'register' ) );
	}

	public static function register(): void {
		$ns = Support::REST_NS;

		register_rest_route(
			$ns,
			'/(?P<collection>posts|pages|videos|podcasts)',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'list_items' ),
				'permission_callback' => array( self::class, 'check_list' ),
			)
		);
		register_rest_route(
			$ns,
			'/(?P<collection>posts|pages|videos|podcasts)/(?P<id>[^/]+)',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'get_item' ),
				'permission_callback' => array( self::class, 'check_item' ),
			)
		);
		register_rest_route(
			$ns,
			'/content/(?P<type>[^/]+)/(?P<id>[^/]+)/visibility',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'visibility' ),
				'permission_callback' => array( self::class, 'check_visibility' ),
			)
		);
		register_rest_route(
			$ns,
			'/taxonomies',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'taxonomies' ),
				'permission_callback' => array( self::class, 'check_read_only' ),
			)
		);
		register_rest_route(
			$ns,
			'/menus/(?P<location>[^/]+)',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'menu' ),
				'permission_callback' => array( self::class, 'check_menu' ),
			)
		);
		register_rest_route(
			$ns,
			'/search',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'search' ),
				'permission_callback' => array( self::class, 'check_search' ),
			)
		);
		register_rest_route(
			$ns,
			'/site',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'site' ),
				'permission_callback' => array( self::class, 'check_read_only' ),
			)
		);
	}

	// ---------------------------------------------------------------------
	// 參數驗證
	// ---------------------------------------------------------------------

	/**
	 * 只接受 GET／HEAD（REST server 已依 route 限制，這裡再確認一次）。
	 */
	public static function check_read_only( \WP_REST_Request $request ) {
		return in_array( $request->get_method(), array( 'GET', 'HEAD' ), true )
			? true
			: Support::error( 'site_content_forbidden', '不允許的操作', 403 );
	}

	/**
	 * 取參數原始值（只看 query string，避免 body／JSON 改變語意）。
	 */
	private static function raw( \WP_REST_Request $request, string $name ): mixed {
		$query = $request->get_query_params();
		return array_key_exists( $name, $query ) ? $query[ $name ] : null;
	}

	/**
	 * 驗證列表共同參數。
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function list_params( \WP_REST_Request $request, bool $allow_filters = true ) {
		$out = array(
			'page'     => 1,
			'per_page' => self::DEFAULT_PAGE,
			'slug'     => null,
			'category' => null,
			'tag'      => null,
			'orderby'  => 'date',
			'order'    => 'desc',
		);

		$page = self::raw( $request, 'page' );
		if ( null !== $page ) {
			$value = Support::positive_int( $page, self::MAX_PAGE );
			if ( null === $value ) {
				return Support::invalid_param( 'page' );
			}
			$out['page'] = $value;
		}
		$per_page = self::raw( $request, 'per_page' );
		if ( null !== $per_page ) {
			$value = Support::positive_int( $per_page, self::MAX_PER_PAGE );
			if ( null === $value ) {
				return Support::invalid_param( 'per_page' );
			}
			$out['per_page'] = $value;
		}
		if ( ! $allow_filters ) {
			return $out;
		}
		foreach ( array( 'slug', 'category', 'tag' ) as $name ) {
			$value = self::raw( $request, $name );
			if ( null === $value ) {
				continue;
			}
			if ( ! self::valid_slug( $value ) ) {
				return Support::invalid_param( $name );
			}
			$out[ $name ] = $value;
		}
		$orderby = self::raw( $request, 'orderby' );
		if ( null !== $orderby ) {
			if ( ! in_array( $orderby, array( 'date', 'title' ), true ) ) {
				return Support::invalid_param( 'orderby' );
			}
			$out['orderby'] = $orderby;
		}
		$order = self::raw( $request, 'order' );
		if ( null !== $order ) {
			if ( ! in_array( $order, array( 'desc', 'asc' ), true ) ) {
				return Support::invalid_param( 'order' );
			}
			$out['order'] = $order;
		}
		return $out;
	}

	/**
	 * slug：`[a-z0-9-]`，WP 中文 slug 為 URL 編碼形式，接受 `[%a-z0-9-]`，長度 1–200。
	 */
	public static function valid_slug( mixed $value ): bool {
		return is_string( $value ) && strlen( $value ) >= 1 && strlen( $value ) <= self::MAX_SLUG && (bool) preg_match( '/^[%a-z0-9-]+$/', $value );
	}

	public static function check_list( \WP_REST_Request $request ) {
		$ok = self::check_read_only( $request );
		if ( true !== $ok ) {
			return $ok;
		}
		$type = Support::COLLECTIONS[ (string) $request['collection'] ] ?? null;
		if ( null === $type ) {
			return Support::not_found();
		}
		$params = self::list_params( $request );
		return is_wp_error( $params ) ? $params : true;
	}

	/**
	 * 單篇：ID 合法、內容存在、型別相符且公開；否則 404（不揭露私人狀態）。
	 */
	public static function check_item( \WP_REST_Request $request ) {
		$ok = self::check_read_only( $request );
		if ( true !== $ok ) {
			return $ok;
		}
		$type = Support::COLLECTIONS[ (string) $request['collection'] ] ?? null;
		$id   = Support::positive_int( $request['id'] );
		if ( null === $type ) {
			return Support::not_found();
		}
		if ( null === $id ) {
			return Support::invalid_param( 'id' );
		}
		$post = get_post( $id );
		if ( ! $post || $post->post_type !== Support::post_type_for( $type ) || ! Content_Model::is_public( $post ) ) {
			return Support::not_found();
		}
		return true;
	}

	public static function check_visibility( \WP_REST_Request $request ) {
		$ok = self::check_read_only( $request );
		if ( true !== $ok ) {
			return $ok;
		}
		if ( null === Support::post_type_for( (string) $request['type'] ) ) {
			return Support::invalid_param( 'type' );
		}
		if ( null === Support::positive_int( $request['id'] ) ) {
			return Support::invalid_param( 'id' );
		}
		return true;
	}

	public static function check_menu( \WP_REST_Request $request ) {
		$ok = self::check_read_only( $request );
		if ( true !== $ok ) {
			return $ok;
		}
		return in_array( (string) $request['location'], self::MENU_LOCATIONS, true ) ? true : Support::invalid_param( 'location' );
	}

	/**
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function search_params( \WP_REST_Request $request ) {
		$params = self::list_params( $request, false );
		if ( is_wp_error( $params ) ) {
			return $params;
		}
		$q = self::raw( $request, 'q' );
		if ( ! is_string( $q ) ) {
			return Support::invalid_param( 'q' );
		}
		$q = trim( $q );
		if ( '' === $q || mb_strlen( $q ) > self::MAX_QUERY_LEN || ! mb_check_encoding( $q, 'UTF-8' ) || preg_match( '/[\x00-\x1F\x7F]/', $q ) ) {
			return Support::invalid_param( 'q' );
		}
		$params['q'] = $q;
		$type        = self::raw( $request, 'type' );
		if ( null !== $type ) {
			if ( ! is_string( $type ) || null === Support::post_type_for( $type ) ) {
				return Support::invalid_param( 'type' );
			}
		}
		$params['type'] = $type;
		return $params;
	}

	public static function check_search( \WP_REST_Request $request ) {
		$ok = self::check_read_only( $request );
		if ( true !== $ok ) {
			return $ok;
		}
		$params = self::search_params( $request );
		return is_wp_error( $params ) ? $params : true;
	}

	// ---------------------------------------------------------------------
	// 查詢
	// ---------------------------------------------------------------------

	/**
	 * 公開內容查詢的固定條件：publish、無密碼、核准型別、非 sc_exclude。
	 *
	 * @param string[]            $post_types WP post types。
	 * @param array<string,mixed> $params     已驗證參數。
	 */
	public static function public_query( array $post_types, array $params ): \WP_Query {
		$order = 'asc' === ( $params['order'] ?? 'desc' ) ? 'ASC' : 'DESC';
		$args  = array(
			'post_type'              => $post_types,
			'post_status'            => 'publish',
			'has_password'           => false,
			'posts_per_page'         => (int) $params['per_page'],
			'paged'                  => (int) $params['page'],
			'orderby'                => 'title' === ( $params['orderby'] ?? 'date' ) ? array( 'title' => $order, 'ID' => $order ) : array( 'date' => $order, 'ID' => $order ),
			'ignore_sticky_posts'    => true,
			'suppress_filters'       => true,
			'no_found_rows'          => false,
			'update_post_term_cache' => true,
			'update_post_meta_cache' => true,
			'meta_query'             => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'relation' => 'OR',
				array(
					'key'     => 'sc_exclude',
					'compare' => 'NOT EXISTS',
				),
				array(
					'key'     => 'sc_exclude',
					'value'   => '1',
					'compare' => '!=',
				),
			),
		);
		$tax = array();
		if ( ! empty( $params['category'] ) ) {
			$tax[] = array(
				'taxonomy'         => 'category',
				'field'            => 'slug',
				'terms'            => array( $params['category'] ),
				'include_children' => false,
			);
		}
		if ( ! empty( $params['tag'] ) ) {
			$tax[] = array(
				'taxonomy' => 'post_tag',
				'field'    => 'slug',
				'terms'    => array( $params['tag'] ),
			);
		}
		if ( $tax ) {
			$args['tax_query'] = array_merge( array( 'relation' => 'AND' ), $tax ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
		}
		if ( ! empty( $params['slug'] ) ) {
			$args['name'] = $params['slug'];
		}
		if ( ! empty( $params['q'] ) ) {
			$args['s'] = $params['q'];
		}
		return new \WP_Query( $args );
	}

	/**
	 * @param array<string,mixed> $params 已驗證參數。
	 * @param array<int,array<string,mixed>> $items 項目。
	 */
	private static function list_response( \WP_Query $query, array $params, array $items ): \WP_REST_Response {
		$total = (int) $query->found_posts;
		return new \WP_REST_Response(
			array(
				'data' => $items,
				'meta' => Support::meta(
					array(
						'page'        => (int) $params['page'],
						'per_page'    => (int) $params['per_page'],
						'total'       => $total,
						'total_pages' => (int) ceil( $total / max( 1, (int) $params['per_page'] ) ),
					)
				),
			),
			200
		);
	}

	public static function list_items( \WP_REST_Request $request ) {
		$params = self::list_params( $request );
		if ( is_wp_error( $params ) ) {
			return $params;
		}
		$type      = Support::COLLECTIONS[ (string) $request['collection'] ];
		$post_type = (string) Support::post_type_for( $type );
		$query     = self::public_query( array( $post_type ), $params );
		$with_body = ! empty( $params['slug'] );
		$items     = array();
		foreach ( $query->posts as $post ) {
			if ( $post instanceof \WP_Post && Content_Model::is_public( $post ) ) {
				$items[] = Content_Model::build_item( $post, $with_body );
			}
		}
		// slug 精確查詢：回單筆陣列或 404（不回空列表假裝存在）。
		if ( ! empty( $params['slug'] ) && array() === $items ) {
			return Support::not_found();
		}
		return self::list_response( $query, $params, $items );
	}

	public static function get_item( \WP_REST_Request $request ) {
		$post = get_post( (int) $request['id'] );
		if ( ! $post || ! Content_Model::is_public( $post ) ) {
			return Support::not_found();
		}
		return new \WP_REST_Response(
			array(
				'data' => Content_Model::build_item( $post, true ),
				'meta' => Support::meta(),
			),
			200
		);
	}

	public static function visibility( \WP_REST_Request $request ) {
		global $wpdb;
		$type      = (string) $request['type'];
		$id        = (int) Support::positive_int( $request['id'] );
		$post      = get_post( $id );
		$post_type = Support::post_type_for( $type );
		$visible   = false;
		$version   = 0;
		if ( $post && $post->post_type === $post_type ) {
			$visible = Content_Model::is_public( $post );
			$version = Content_Model::content_version( $id );
		} elseif ( ! $post ) {
			// 已刪除內容：沿用對帳索引的最後版本（型別必須相符）。
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT content_type, content_version FROM ' . Setup::table( 'index' ) . ' WHERE post_id = %d', $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			if ( is_array( $row ) && $row['content_type'] === $type ) {
				$version = (int) $row['content_version'];
			}
		}
		return new \WP_REST_Response(
			array(
				'data' => array(
					'type'            => $type,
					'id'              => $id,
					'visible'         => $visible,
					'content_version' => $version,
					'checked_at'      => Support::now(),
				),
				'meta' => Support::meta(),
			),
			200
		);
	}

	/**
	 * 分類與標籤：count 只計公開內容；沒有公開內容的詞彙不列出（不洩漏私人內容統計）。
	 */
	public static function taxonomies() {
		global $wpdb;
		$types        = Support::post_types();
		$placeholders = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$sql          = "SELECT t.term_id, t.slug, t.name, tt.taxonomy, COUNT(DISTINCT p.ID) AS cnt
			FROM {$wpdb->terms} t
			INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id
			INNER JOIN {$wpdb->term_relationships} tr ON tr.term_taxonomy_id = tt.term_taxonomy_id
			INNER JOIN {$wpdb->posts} p ON p.ID = tr.object_id
			LEFT JOIN {$wpdb->postmeta} ex ON ex.post_id = p.ID AND ex.meta_key = 'sc_exclude' AND ex.meta_value = '1'
			WHERE tt.taxonomy IN ('category','post_tag')
				AND p.post_status = 'publish' AND p.post_password = ''
				AND p.post_type IN ({$placeholders})
				AND ex.meta_id IS NULL
			GROUP BY t.term_id, t.slug, t.name, tt.taxonomy
			ORDER BY t.name ASC, t.term_id ASC";
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $types ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$data = array(
			'categories' => array(),
			'tags'       => array(),
		);
		foreach ( (array) $rows as $row ) {
			$key            = 'category' === $row['taxonomy'] ? 'categories' : 'tags';
			$data[ $key ][] = array(
				'id'    => (int) $row['term_id'],
				'slug'  => (string) $row['slug'],
				'name'  => Support::plain_text( (string) $row['name'] ),
				'count' => (int) $row['cnt'],
			);
		}
		return new \WP_REST_Response(
			array(
				'data' => $data,
				'meta' => Support::meta(),
			),
			200
		);
	}

	public static function search( \WP_REST_Request $request ) {
		$params = self::search_params( $request );
		if ( is_wp_error( $params ) ) {
			return $params;
		}
		$post_types = null === $params['type'] ? Support::post_types() : array( (string) Support::post_type_for( $params['type'] ) );
		$query      = self::public_query( $post_types, $params );
		$items      = array();
		foreach ( $query->posts as $post ) {
			if ( $post instanceof \WP_Post && Content_Model::is_public( $post ) ) {
				$items[] = Content_Model::build_item( $post, false );
			}
		}
		return self::list_response( $query, $params, $items );
	}

	public static function site() {
		return new \WP_REST_Response(
			array(
				'data' => Branding::public_data(),
				'meta' => Support::meta(),
			),
			200
		);
	}

	// ---------------------------------------------------------------------
	// 選單
	// ---------------------------------------------------------------------

	public static function menu( \WP_REST_Request $request ) {
		$location  = (string) $request['location'];
		$locations = get_nav_menu_locations();
		$menu_id   = isset( $locations[ $location ] ) ? (int) $locations[ $location ] : 0;
		$items     = $menu_id > 0 ? wp_get_nav_menu_items( $menu_id, array( 'update_post_term_cache' => false ) ) : array();
		$items     = is_array( $items ) ? $items : array();

		$by_parent = array();
		foreach ( $items as $item ) {
			$by_parent[ (int) $item->menu_item_parent ][] = $item;
		}
		$build = static function ( int $parent ) use ( &$build, $by_parent ): array {
			$out = array();
			foreach ( $by_parent[ $parent ] ?? array() as $item ) {
				$mapped = self::menu_item( $item );
				if ( null === $mapped ) {
					continue; // 非公開目標、不允許的網址：整項（含子項）略過。
				}
				$children = $build( (int) $item->ID );
				if ( $children ) {
					$mapped['children'] = $children;
				}
				$out[] = $mapped;
			}
			return $out;
		};

		return new \WP_REST_Response(
			array(
				'data' => array(
					'location' => $location,
					'items'    => $build( 0 ),
				),
				'meta' => Support::meta(),
			),
			200
		);
	}

	/**
	 * 選單項目 → {label, url, external}；內部一律轉主站相對路徑，外部只允許 https。
	 *
	 * @return array<string,mixed>|null
	 */
	public static function menu_item( object $item ): ?array {
		$label = Support::plain_text( (string) ( $item->title ?? '' ) );
		$url   = null;
		$ext   = false;
		switch ( (string) ( $item->type ?? '' ) ) {
			case 'post_type':
				$target = get_post( (int) $item->object_id );
				if ( $target && Content_Model::is_public( $target ) ) {
					$url = Url_Mapping::path_for_post( $target );
					if ( '' === $label ) {
						$label = Support::plain_text( $target->post_title );
					}
				}
				break;
			case 'post_type_archive':
				$url = Url_Mapping::ARCHIVE[ (string) $item->object ] ?? null;
				break;
			case 'taxonomy':
				$term = get_term( (int) $item->object_id, (string) $item->object );
				if ( $term instanceof \WP_Term ) {
					$url = Url_Mapping::path_for_term( $term );
					if ( '' === $label ) {
						$label = Support::plain_text( $term->name );
					}
				}
				break;
			case 'custom':
				list( $url, $ext ) = self::custom_url( (string) ( $item->url ?? '' ) );
				break;
		}
		if ( null === $url || '' === $label ) {
			return null;
		}
		return array(
			'label'    => $label,
			'url'      => $url,
			'external' => $ext,
		);
	}

	/**
	 * 自訂連結正規化。
	 *
	 * @return array{0:?string,1:bool} [網址, 是否外部]
	 */
	public static function custom_url( string $url ): array {
		$url = trim( $url );
		if ( '' === $url || strlen( $url ) > 2048 || preg_match( '/[\s\x00-\x1F\x7F<>"\\\\]/', $url ) ) {
			return array( null, false );
		}
		// 主站相對路徑。
		if ( str_starts_with( $url, '/' ) && ! str_starts_with( $url, '//' ) ) {
			return array( $url, false );
		}
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return array( null, false );
		}
		$scheme = strtolower( $parts['scheme'] );
		$host   = strtolower( $parts['host'] );
		$path   = ( $parts['path'] ?? '' ) === '' ? '/' : (string) $parts['path'];
		$rel    = $path . ( isset( $parts['query'] ) ? '?' . $parts['query'] : '' ) . ( isset( $parts['fragment'] ) ? '#' . $parts['fragment'] : '' );

		$app = wp_parse_url( Support::app_url() );
		if ( in_array( $scheme, array( 'http', 'https' ), true ) && is_array( $app ) && $host === strtolower( (string) ( $app['host'] ?? '' ) ) && ( $parts['port'] ?? null ) === ( $app['port'] ?? null ) ) {
			return array( $rel, false );
		}
		$cms = wp_parse_url( home_url() );
		if ( in_array( $scheme, array( 'http', 'https' ), true ) && is_array( $cms ) && $host === strtolower( (string) ( $cms['host'] ?? '' ) ) ) {
			// CMS 網址：能對應到公開內容才轉主站路徑，否則略過（不把訪客導回 CMS）。
			if ( '/' === $path ) {
				return array( '/', false );
			}
			$post_id = url_to_postid( $url );
			$target  = $post_id ? get_post( $post_id ) : null;
			if ( $target && Content_Model::is_public( $target ) ) {
				return array( Url_Mapping::path_for_post( $target ), false );
			}
			return array( null, false );
		}
		if ( 'https' === $scheme ) {
			return array( esc_url_raw( $url, array( 'https' ) ), true );
		}
		return array( null, false );
	}
}
