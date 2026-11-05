<?php
/**
 * 合成示例資料（`wp site-content seed`）。
 *
 * - 只限 development／testing；production、staging 由指令層拒絕，這裡再檢查一次。
 * - 可重跑不重複：每筆以種子鍵（meta `_sc_seed_key`）或 slug 判斷是否已存在；已存在一律不改寫。
 * - 全部標記 `sc_is_sample`，標題或內文明示「示例內容」；不捏造委託人的履歷、言論、聯絡方式或商品承諾。
 * - 封面圖以 GD 產生、音訊以 PHP 產生 WAV，不從網路抓取任何素材。
 *
 * @package SiteContent
 */

namespace SiteContent;

defined( 'ABSPATH' ) || exit;

final class Seed {

	public const KEY_META = '_sc_seed_key';

	/** @var array{created:int,existing:int,log:string[]} */
	private static array $report = array(
		'created'  => 0,
		'existing' => 0,
		'log'      => array(),
	);

	/** @var array<string,int> */
	private static array $media = array();

	/**
	 * @return array{created:int,existing:int,log:string[]}
	 */
	public static function run(): array {
		if ( Support::is_production_like() ) {
			throw new \RuntimeException( '正式等級環境拒絕建立示例資料。' );
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/post.php';

		self::$report = array(
			'created'  => 0,
			'existing' => 0,
			'log'      => array(),
		);
		$author = get_user_by( 'login', 'mysite-editor' );
		if ( ! $author ) {
			$admins = get_users(
				array(
					'role'   => 'administrator',
					'number' => 1,
					'fields' => 'all',
				)
			);
			$author = $admins[0] ?? null;
		}
		if ( ! $author ) {
			throw new \RuntimeException( '找不到可作為示例作者的使用者（mysite-editor 或管理員）。' );
		}
		wp_set_current_user( (int) $author->ID );

		$terms = self::terms();
		self::media();
		self::posts( (int) $author->ID, $terms );
		self::videos( (int) $author->ID, $terms );
		self::podcasts( (int) $author->ID, $terms );
		$pages = self::pages( (int) $author->ID );
		self::non_public( (int) $author->ID, $terms );
		self::menus( $pages );
		self::brand();
		return self::$report;
	}

	private static function note( bool $created, string $what ): void {
		if ( $created ) {
			++self::$report['created'];
			self::$report['log'][] = '新建：' . $what;
		} else {
			++self::$report['existing'];
		}
	}

	// ---------------------------------------------------------------------
	// 分類與標籤
	// ---------------------------------------------------------------------

	/**
	 * @return array{categories:array<string,int>,tags:array<string,string>}
	 */
	private static function terms(): array {
		$categories = array(
			'sample-notes' => '隨筆（示例）',
			'sample-tech'  => '技術（示例）',
			'sample-media' => '影音（示例）',
		);
		$tags       = array(
			'sample'          => '示例',
			'sample-gutenberg' => 'Gutenberg 區塊',
			'sample-layout'   => '版面測試',
			'sample-audio'    => '音訊',
			'sample-video'    => '影片',
		);
		$out = array(
			'categories' => array(),
			'tags'       => array(),
		);
		foreach ( $categories as $slug => $name ) {
			$out['categories'][ $slug ] = self::term( $slug, $name, 'category' );
		}
		foreach ( $tags as $slug => $name ) {
			self::term( $slug, $name, 'post_tag' );
			$out['tags'][ $slug ] = $slug;
		}
		return $out;
	}

	private static function term( string $slug, string $name, string $taxonomy ): int {
		$existing = get_term_by( 'slug', $slug, $taxonomy );
		if ( $existing instanceof \WP_Term ) {
			self::note( false, $taxonomy . ':' . $slug );
			return (int) $existing->term_id;
		}
		$result = wp_insert_term(
			$name,
			$taxonomy,
			array(
				'slug'        => $slug,
				'description' => '示例內容用的' . ( 'category' === $taxonomy ? '分類' : '標籤' ) . '。',
			)
		);
		if ( is_wp_error( $result ) ) {
			throw new \RuntimeException( '建立詞彙失敗：' . $slug . '（' . $result->get_error_message() . '）' );
		}
		self::note( true, $taxonomy . ':' . $slug );
		return (int) $result['term_id'];
	}

	// ---------------------------------------------------------------------
	// 媒體（GD 圖片、PHP 產生的 WAV）
	// ---------------------------------------------------------------------

	private static function find_by_key( string $key, string $post_type = 'any' ): int {
		$ids = get_posts(
			array(
				'post_type'        => $post_type,
				'post_status'      => array( 'publish', 'draft', 'pending', 'private', 'future', 'trash', 'inherit' ),
				'meta_key'         => self::KEY_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'       => $key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'posts_per_page'   => 1,
				'fields'           => 'ids',
				'suppress_filters' => true,
				'orderby'          => 'ID',
				'order'            => 'ASC',
			)
		);
		return (int) ( $ids[0] ?? 0 );
	}

	private static function media(): void {
		$images = array(
			'cover-1' => array( 'SAMPLE COVER 1', array( 32, 76, 135 ), '示例封面一：深藍色背景與幾何圖形' ),
			'cover-2' => array( 'SAMPLE COVER 2', array( 150, 52, 72 ), '示例封面二：酒紅色背景與幾何圖形' ),
			'cover-3' => array( 'SAMPLE COVER 3', array( 34, 112, 86 ), '示例封面三：墨綠色背景與幾何圖形' ),
			'cover-4' => array( 'SAMPLE COVER 4', array( 120, 92, 30 ), '示例封面四：土黃色背景與幾何圖形' ),
			'cover-5' => array( 'SAMPLE COVER 5', array( 70, 60, 130 ), '示例封面五：紫色背景與幾何圖形' ),
			'logo'    => array( 'MYSITE', array( 24, 24, 27 ), '示例標誌：mysite 字樣' ),
		);
		foreach ( $images as $key => $spec ) {
			$seed_key = 'media-' . $key;
			$id       = self::find_by_key( $seed_key, 'attachment' );
			if ( $id ) {
				self::$media[ $key ] = $id;
				self::note( false, $seed_key );
				continue;
			}
			$size = 'logo' === $key ? array( 512, 512 ) : array( 1200, 630 );
			$tmp  = self::make_png( $spec[0], $spec[1], $size[0], $size[1] );
			$id   = self::sideload( $tmp, 'sample-' . $key . '.png', '示例圖片 ' . $key, $seed_key );
			update_post_meta( $id, '_wp_attachment_image_alt', $spec[2] );
			self::$media[ $key ] = $id;
			self::note( true, $seed_key );
		}
		$audios = array(
			'episode-1' => array( 440.0, 3 ),
			'episode-2' => array( 523.25, 4 ),
		);
		foreach ( $audios as $key => $spec ) {
			$seed_key = 'media-audio-' . $key;
			$id       = self::find_by_key( $seed_key, 'attachment' );
			if ( $id ) {
				self::$media[ 'audio-' . $key ] = $id;
				self::note( false, $seed_key );
				continue;
			}
			$tmp = self::make_wav( $spec[0], $spec[1] );
			$id  = self::sideload( $tmp, 'sample-' . $key . '.wav', '示例音訊 ' . $key, $seed_key );
			self::$media[ 'audio-' . $key ] = $id;
			self::note( true, $seed_key );
		}
	}

	private static function sideload( string $tmp, string $name, string $title, string $seed_key ): int {
		$file = array(
			'name'     => $name,
			'tmp_name' => $tmp,
			'error'    => 0,
			'size'     => (int) filesize( $tmp ),
		);
		$id = media_handle_sideload( $file, 0, $title, array( 'post_content' => '示例內容：由系統產生的測試素材。' ) );
		if ( is_wp_error( $id ) ) {
			if ( file_exists( $tmp ) ) {
				wp_delete_file( $tmp );
			}
			throw new \RuntimeException( '上傳示例媒體失敗：' . $name . '（' . $id->get_error_message() . '）' );
		}
		update_post_meta( (int) $id, self::KEY_META, $seed_key );
		update_post_meta( (int) $id, 'sc_is_sample', '1' );
		return (int) $id;
	}

	/**
	 * GD 產生 PNG：底色、幾何圖形與放大的英數字標示（中文需字型檔，故只用內建點陣字）。
	 *
	 * @param int[] $rgb 底色。
	 */
	private static function make_png( string $label, array $rgb, int $width, int $height ): string {
		if ( ! function_exists( 'imagecreatetruecolor' ) ) {
			throw new \RuntimeException( 'PHP GD 不可用，無法產生示例圖片。' );
		}
		$im   = imagecreatetruecolor( $width, $height );
		$bg   = imagecolorallocate( $im, $rgb[0], $rgb[1], $rgb[2] );
		$fg   = imagecolorallocate( $im, 255, 255, 255 );
		$soft = imagecolorallocatealpha( $im, 255, 255, 255, 100 );
		imagefilledrectangle( $im, 0, 0, $width - 1, $height - 1, $bg );
		for ( $i = 0; $i < 6; $i++ ) {
			imagefilledellipse( $im, (int) ( $width * ( 0.1 + 0.16 * $i ) ), (int) ( $height * ( 0.25 + 0.1 * ( $i % 3 ) ) ), (int) ( $height * 0.35 ), (int) ( $height * 0.35 ), $soft );
		}
		imagerectangle( $im, 12, 12, $width - 13, $height - 13, $fg );

		// 以小畫布寫字再放大，得到清楚的大字。
		$small_w = (int) ( strlen( $label ) * imagefontwidth( 5 ) + 8 );
		$small_h = (int) ( imagefontheight( 5 ) + 6 );
		$small   = imagecreatetruecolor( $small_w, $small_h );
		$tbg     = imagecolorallocate( $small, $rgb[0], $rgb[1], $rgb[2] );
		imagefilledrectangle( $small, 0, 0, $small_w, $small_h, $tbg );
		imagestring( $small, 5, 4, 3, $label, imagecolorallocate( $small, 255, 255, 255 ) );
		$scale  = max( 1, (int) floor( min( ( $width * 0.8 ) / $small_w, ( $height * 0.4 ) / $small_h ) ) );
		$dst_w  = $small_w * $scale;
		$dst_h  = $small_h * $scale;
		imagecopyresized( $im, $small, (int) ( ( $width - $dst_w ) / 2 ), (int) ( ( $height - $dst_h ) / 2 ), 0, 0, $dst_w, $dst_h, $small_w, $small_h );

		$tmp = wp_tempnam( 'sample.png' );
		if ( ! imagepng( $im, $tmp ) ) {
			throw new \RuntimeException( '寫入示例圖片失敗。' );
		}
		return $tmp;
	}

	/**
	 * 產生 8kHz、16-bit、單聲道的正弦波 WAV（含淡入淡出）。
	 */
	private static function make_wav( float $frequency, int $seconds ): string {
		$rate    = 8000;
		$samples = $rate * $seconds;
		$fade    = (int) ( $rate * 0.2 );
		$data    = '';
		for ( $n = 0; $n < $samples; $n++ ) {
			$envelope = min( 1.0, $n / $fade, ( $samples - $n ) / $fade );
			$value    = (int) round( 9000 * $envelope * sin( 2 * M_PI * $frequency * $n / $rate ) );
			$data    .= pack( 'v', $value & 0xFFFF );
		}
		$header = 'RIFF' . pack( 'V', 36 + strlen( $data ) ) . 'WAVE'
			. 'fmt ' . pack( 'V', 16 ) . pack( 'v', 1 ) . pack( 'v', 1 ) . pack( 'V', $rate ) . pack( 'V', $rate * 2 ) . pack( 'v', 2 ) . pack( 'v', 16 )
			. 'data' . pack( 'V', strlen( $data ) );
		$tmp = wp_tempnam( 'sample.wav' );
		if ( false === file_put_contents( $tmp, $header . $data ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			throw new \RuntimeException( '寫入示例音訊失敗。' );
		}
		return $tmp;
	}

	// ---------------------------------------------------------------------
	// 內容
	// ---------------------------------------------------------------------

	/**
	 * 建立內容（已存在則略過）。
	 *
	 * @param array<string,mixed> $data wp_insert_post 參數（必含 post_name、post_type）。
	 */
	private static function ensure_post( string $key, array $data ): int {
		$id = self::find_by_key( $key );
		if ( ! $id ) {
			$existing = get_page_by_path( (string) $data['post_name'], OBJECT, (string) $data['post_type'] );
			if ( $existing instanceof \WP_Post ) {
				self::$report['log'][] = 'slug 已被既有內容使用，略過：' . $data['post_name'];
				self::note( false, $key );
				return (int) $existing->ID;
			}
		}
		if ( $id ) {
			self::note( false, $key );
			return $id;
		}
		$meta               = (array) ( $data['meta_input'] ?? array() );
		$meta[ self::KEY_META ] = $key;
		$meta['sc_is_sample']   = '1';
		$meta['_sc_is_sample']  = 'field_sc_is_sample';
		$data['meta_input']     = $meta;
		$data['comment_status'] = 'closed';
		$data['ping_status']    = 'closed';
		$id                     = wp_insert_post( wp_slash( $data ), true );
		if ( is_wp_error( $id ) ) {
			throw new \RuntimeException( '建立示例內容失敗：' . $key . '（' . $id->get_error_message() . '）' );
		}
		self::note( true, $key . '（' . $data['post_type'] . ' #' . $id . '）' );
		return (int) $id;
	}

	private static function date_days_ago( int $days, int $hour = 10 ): string {
		$ts = time() - $days * DAY_IN_SECONDS;
		return wp_date( 'Y-m-d', $ts ) . sprintf( ' %02d:00:00', $hour );
	}

	private static function paragraph( string $text ): string {
		return "<!-- wp:paragraph -->\n<p>" . $text . "</p>\n<!-- /wp:paragraph -->";
	}

	private const DISCLAIMER = '本文為系統產生的<strong>示例內容</strong>，只用於版面與功能測試，不代表網站作者的觀點、經歷或任何承諾。';

	/**
	 * @param array{categories:array<string,int>,tags:array<string,string>} $terms 詞彙。
	 */
	private static function posts( int $author, array $terms ): void {
		$cat   = $terms['categories'];
		$cover = static fn( string $k ) => array( '_thumbnail_id' => self::$media[ $k ] );

		self::ensure_post(
			'post-gutenberg',
			array(
				'post_type'     => 'post',
				'post_status'   => 'publish',
				'post_author'   => $author,
				'post_name'     => 'sample-gutenberg-blocks',
				'post_title'    => '示例內容：Gutenberg 區塊格式驗收',
				'post_excerpt'  => '示例內容：涵蓋段落、標題、圖片與圖說、引用、列表、連結、分隔線、程式碼、表格與封面區塊的驗收文章。',
				'post_content'  => self::gutenberg_content(),
				'post_date'     => self::date_days_ago( 1 ),
				'post_category' => array( $cat['sample-tech'] ),
				'tags_input'    => array( 'sample', 'sample-gutenberg' ),
				'meta_input'    => $cover( 'cover-1' ),
			)
		);
		self::ensure_post(
			'post-long-title',
			array(
				'post_type'     => 'post',
				'post_status'   => 'publish',
				'post_author'   => $author,
				'post_name'     => 'sample-very-long-title',
				'post_title'    => '示例內容：這是一個刻意設計得非常非常長的中文標題，用來測試主站在桌面、平板與手機寬度下的換行、截斷與版面配置是否仍然正常，並確認卡片、麵包屑與相關連結區塊都不會因為標題過長而溢出容器或遮住其他內容',
				'post_content'  => self::paragraph( self::DISCLAIMER ) . "\n\n" . self::paragraph( '這篇示例文章的重點是標題長度；正文保持簡短。' ),
				'post_date'     => self::date_days_ago( 2 ),
				'post_category' => array( $cat['sample-notes'] ),
				'tags_input'    => array( 'sample', 'sample-layout' ),
				'meta_input'    => $cover( 'cover-2' ),
			)
		);
		$long_url = 'https://example.com/sample/very/long/path/that/keeps/going/and/going/to-test-wrapping-in-narrow-layouts/' . str_repeat( 'segment-', 12 ) . 'end?query=' . str_repeat( 'value', 10 ) . '&another=parameter-for-layout-testing#section-anchor';
		self::ensure_post(
			'post-long-url',
			array(
				'post_type'     => 'post',
				'post_status'   => 'publish',
				'post_author'   => $author,
				'post_name'     => 'sample-long-url',
				'post_title'    => '示例內容：含超長網址的文章',
				'post_content'  => self::paragraph( self::DISCLAIMER ) . "\n\n" . self::paragraph( '以下是一個刻意很長、沒有空白的網址，用來測試窄螢幕換行：' ) . "\n\n" . self::paragraph( '<a href="' . esc_url( $long_url ) . '">' . esc_html( $long_url ) . '</a>' ),
				'post_date'     => self::date_days_ago( 3 ),
				'post_category' => array( $cat['sample-tech'] ),
				'tags_input'    => array( 'sample', 'sample-layout' ),
				'meta_input'    => $cover( 'cover-3' ),
			)
		);
		self::ensure_post(
			'post-no-cover',
			array(
				'post_type'     => 'post',
				'post_status'   => 'publish',
				'post_author'   => $author,
				'post_name'     => 'sample-article-no-cover',
				'post_title'    => '示例內容：沒有封面圖的文章',
				'post_content'  => self::paragraph( self::DISCLAIMER ) . "\n\n" . self::paragraph( '這篇示例文章刻意不設定封面，用來測試主站在缺少圖片時的版面。' ),
				'post_date'     => self::date_days_ago( 4 ),
				'post_category' => array( $cat['sample-notes'] ),
				'tags_input'    => array( 'sample' ),
			)
		);
		self::ensure_post(
			'post-multi-paragraph',
			array(
				'post_type'     => 'post',
				'post_status'   => 'publish',
				'post_author'   => $author,
				'post_name'     => 'sample-article-multi-paragraph',
				'post_title'    => '示例內容：多段落閱讀排版',
				'post_content'  => implode(
					"\n\n",
					array(
						self::paragraph( self::DISCLAIMER ),
						self::paragraph( '第一段：示例文字用來確認段落間距、行高與字級在不同裝置上的可讀性。' ),
						self::paragraph( '第二段：中文段落與 English words 混排，檢查斷行與標點擠壓是否自然。' ),
						self::paragraph( '第三段：段落中的<a href="https://example.com/">外部連結示例</a>應以主站樣式呈現。' ),
					)
				),
				'post_date'     => self::date_days_ago( 5 ),
				'post_category' => array( $cat['sample-notes'] ),
				'tags_input'    => array( 'sample', 'sample-layout' ),
				'meta_input'    => $cover( 'cover-4' ),
			)
		);
		self::ensure_post(
			'post-tags',
			array(
				'post_type'     => 'post',
				'post_status'   => 'publish',
				'post_author'   => $author,
				'post_name'     => 'sample-article-taxonomy',
				'post_title'    => '示例內容：分類與標籤展示',
				'post_content'  => self::paragraph( self::DISCLAIMER ) . "\n\n" . self::paragraph( '這篇示例文章同時屬於多個標籤，用來測試分類與標籤彙整頁。' ),
				'post_date'     => self::date_days_ago( 6 ),
				'post_category' => array( $cat['sample-media'], $cat['sample-notes'] ),
				'tags_input'    => array( 'sample', 'sample-video', 'sample-audio' ),
				'meta_input'    => $cover( 'cover-5' ),
			)
		);
		self::ensure_post(
			'post-manual-excerpt',
			array(
				'post_type'     => 'post',
				'post_status'   => 'publish',
				'post_author'   => $author,
				'post_name'     => 'sample-article-manual-excerpt',
				'post_title'    => '示例內容：手動摘要',
				'post_excerpt'  => '這是手動填寫的示例摘要，主站列表應優先顯示這段文字。',
				'post_content'  => self::paragraph( self::DISCLAIMER ) . "\n\n" . self::paragraph( '正文與摘要不同，用來確認列表顯示的是手動摘要。' ),
				'post_date'     => self::date_days_ago( 8 ),
				'post_category' => array( $cat['sample-notes'] ),
				'tags_input'    => array( 'sample' ),
				'meta_input'    => $cover( 'cover-1' ),
			)
		);
		self::ensure_post(
			'post-older',
			array(
				'post_type'     => 'post',
				'post_status'   => 'publish',
				'post_author'   => $author,
				'post_name'     => 'sample-article-older',
				'post_title'    => '示例內容：較早發布的文章',
				'post_content'  => self::paragraph( self::DISCLAIMER ) . "\n\n" . self::paragraph( '發布日期較早，用來測試列表排序與分頁。' ),
				'post_date'     => self::date_days_ago( 30 ),
				'post_category' => array( $cat['sample-notes'] ),
				'tags_input'    => array( 'sample' ),
				'meta_input'    => $cover( 'cover-2' ),
			)
		);
	}

	/**
	 * Gutenberg 驗收文章（WP-06）：段落、H2／H3、圖片＋圖說、引用、列表、連結、分隔線、程式碼、表格、封面。
	 */
	private static function gutenberg_content(): string {
		$image_id  = self::$media['cover-2'];
		$image_url = (string) wp_get_attachment_image_url( $image_id, 'large' );
		$cover_id  = self::$media['cover-3'];
		$cover_url = (string) wp_get_attachment_url( $cover_id );
		$blocks    = array(
			self::paragraph( self::DISCLAIMER . '本文逐一列出主站必須支援的區塊格式。' ),
			"<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">第二層標題（H2）</h2>\n<!-- /wp:heading -->",
			self::paragraph( 'H2 之下的段落，包含<strong>粗體</strong>、<em>斜體</em>與<a href="https://example.com/">外部連結（example.com）</a>。' ),
			"<!-- wp:heading {\"level\":3} -->\n<h3 class=\"wp-block-heading\">第三層標題（H3）</h3>\n<!-- /wp:heading -->",
			sprintf(
				"<!-- wp:image {\"id\":%1\$d,\"sizeSlug\":\"large\",\"linkDestination\":\"none\"} -->\n<figure class=\"wp-block-image size-large\"><img src=\"%2\$s\" alt=\"示例圖片：酒紅色背景與幾何圖形\" class=\"wp-image-%1\$d\"/><figcaption class=\"wp-element-caption\">示例圖說：圖片下方的說明文字。</figcaption></figure>\n<!-- /wp:image -->",
				$image_id,
				esc_url( $image_url )
			),
			"<!-- wp:quote -->\n<blockquote class=\"wp-block-quote\"><!-- wp:paragraph -->\n<p>這是一段示例引用文字，用來確認引用區塊的樣式。</p>\n<!-- /wp:paragraph --><cite>示例來源</cite></blockquote>\n<!-- /wp:quote -->",
			"<!-- wp:list -->\n<ul class=\"wp-block-list\"><!-- wp:list-item -->\n<li>無序列表項目一</li>\n<!-- /wp:list-item -->\n\n<!-- wp:list-item -->\n<li>無序列表項目二</li>\n<!-- /wp:list-item --></ul>\n<!-- /wp:list -->",
			"<!-- wp:list {\"ordered\":true} -->\n<ol class=\"wp-block-list\"><!-- wp:list-item -->\n<li>有序列表第一步</li>\n<!-- /wp:list-item -->\n\n<!-- wp:list-item -->\n<li>有序列表第二步</li>\n<!-- /wp:list-item --></ol>\n<!-- /wp:list -->",
			"<!-- wp:separator -->\n<hr class=\"wp-block-separator has-alpha-channel-opacity\"/>\n<!-- /wp:separator -->",
			"<!-- wp:code -->\n<pre class=\"wp-block-code\"><code>&lt;?php\necho '示例程式碼：不會被執行';</code></pre>\n<!-- /wp:code -->",
			"<!-- wp:table -->\n<figure class=\"wp-block-table\"><table class=\"has-fixed-layout\"><thead><tr><th>欄位</th><th>說明</th></tr></thead><tbody><tr><td>示例 A</td><td>表格第一列</td></tr><tr><td>示例 B</td><td>表格第二列</td></tr></tbody></table><figcaption class=\"wp-element-caption\">示例表格說明</figcaption></figure>\n<!-- /wp:table -->",
			sprintf(
				"<!-- wp:cover {\"url\":\"%1\$s\",\"id\":%2\$d,\"dimRatio\":50} -->\n<div class=\"wp-block-cover\"><span aria-hidden=\"true\" class=\"wp-block-cover__background has-background-dim\"></span><img class=\"wp-block-cover__image-background wp-image-%2\$d\" alt=\"\" src=\"%1\$s\" data-object-fit=\"cover\"/><div class=\"wp-block-cover__inner-container\"><!-- wp:paragraph {\"align\":\"center\"} -->\n<p class=\"has-text-align-center\">封面區塊上的示例文字</p>\n<!-- /wp:paragraph --></div></div>\n<!-- /wp:cover -->",
				esc_url( $cover_url ),
				$cover_id
			),
			self::paragraph( '以上為全部驗收區塊；主站顯示時應保留語意（標題階層、列表、表格、圖說）與基本排版。' ),
		);
		return implode( "\n\n", $blocks );
	}

	/**
	 * @param array{categories:array<string,int>,tags:array<string,string>} $terms 詞彙。
	 */
	private static function videos( int $author, array $terms ): void {
		$cat    = $terms['categories']['sample-media'];
		$videos = array(
			// 影片 ID 只符合 11 碼格式、為明顯的示例值，不對應真實影片；主站應顯示來源失效狀態。
			array( 'video-1', 'sample-video-1', '示例影片一：watch 網址格式', 'https://www.youtube.com/watch?v=Sample-Vid1', '245', 'cover-1', 7 ),
			array( 'video-2', 'sample-video-2', '示例影片二：youtu.be 短網址格式', 'https://youtu.be/Sample-Vid2', '600', 'cover-4', 9 ),
			array( 'video-3', 'sample-video-3', '示例影片三：embed 網址、無片長', 'https://www.youtube.com/embed/Sample_Vid3', '', 'cover-5', 11 ),
		);
		foreach ( $videos as $v ) {
			self::ensure_post(
				$v[0],
				array(
					'post_type'     => 'sc_video',
					'post_status'   => 'publish',
					'post_author'   => $author,
					'post_name'     => $v[1],
					'post_title'    => $v[2],
					'post_excerpt'  => '示例內容：影片介紹文字。影片 ID 僅為格式正確的示例值，不代表真實影片。',
					'post_content'  => self::paragraph( self::DISCLAIMER ) . "\n\n" . self::paragraph( '影片頁的介紹文字示例。主站以影片 ID 產生 youtube-nocookie 播放器，訪客點擊後才載入。' ),
					'post_date'     => self::date_days_ago( $v[6] ),
					'post_category' => array( $cat ),
					'tags_input'    => array( 'sample', 'sample-video' ),
					'meta_input'    => array(
						'sc_youtube_url'       => $v[3],
						'_sc_youtube_url'      => 'field_sc_youtube_url',
						'sc_duration_seconds'  => $v[4],
						'_sc_duration_seconds' => 'field_sc_video_duration',
						'_thumbnail_id'        => self::$media[ $v[5] ],
					),
				)
			);
		}
	}

	/**
	 * @param array{categories:array<string,int>,tags:array<string,string>} $terms 詞彙。
	 */
	private static function podcasts( int $author, array $terms ): void {
		$cat = $terms['categories']['sample-media'];
		self::ensure_post(
			'podcast-1',
			array(
				'post_type'     => 'sc_podcast',
				'post_status'   => 'publish',
				'post_author'   => $author,
				'post_name'     => 'sample-podcast-episode-1',
				'post_title'    => '示例 Podcast 第 1 集：媒體庫音訊',
				'post_excerpt'  => '示例內容：音訊為系統產生的 3 秒測試音。',
				'post_content'  => self::paragraph( self::DISCLAIMER ) . "\n\n" . self::paragraph( '本集音訊由媒體庫附件提供（系統產生的 440Hz 測試音），用來測試播放器、跳轉與時間顯示。本站未宣稱已在任何 Podcast 平台上架。' ),
				'post_date'     => self::date_days_ago( 10 ),
				'post_category' => array( $cat ),
				'tags_input'    => array( 'sample', 'sample-audio' ),
				'meta_input'    => array(
					'sc_audio_attachment'  => (string) self::$media['audio-episode-1'],
					'_sc_audio_attachment' => 'field_sc_audio_attachment',
					'sc_duration_seconds'  => '3',
					'_sc_duration_seconds' => 'field_sc_podcast_duration',
					'_thumbnail_id'        => self::$media['cover-3'],
				),
			)
		);
		self::ensure_post(
			'podcast-2',
			array(
				'post_type'     => 'sc_podcast',
				'post_status'   => 'publish',
				'post_author'   => $author,
				'post_name'     => 'sample-podcast-episode-2',
				'post_title'    => '示例 Podcast 第 2 集：核准網址音訊',
				'post_excerpt'  => '示例內容：音訊以 CMS 主機上的網址提供。',
				'post_content'  => self::paragraph( self::DISCLAIMER ) . "\n\n" . self::paragraph( '本集音訊以核准主機（本 CMS）的網址欄位提供，用來測試「音訊網址」路徑。' ),
				'post_date'     => self::date_days_ago( 12 ),
				'post_category' => array( $cat ),
				'tags_input'    => array( 'sample', 'sample-audio' ),
				'meta_input'    => array(
					'sc_audio_url'         => (string) wp_get_attachment_url( self::$media['audio-episode-2'] ),
					'_sc_audio_url'        => 'field_sc_audio_url',
					'sc_duration_seconds'  => '4',
					'_sc_duration_seconds' => 'field_sc_podcast_duration',
					'_thumbnail_id'        => self::$media['cover-4'],
				),
			)
		);
	}

	/**
	 * @return array<string,int>
	 */
	private static function pages( int $author ): array {
		$pages = array();

		$pages['about'] = self::ensure_post(
			'page-about',
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_author'  => $author,
				'post_name'    => 'about',
				'post_title'   => '關於（示例內容）',
				'post_content' => implode(
					"\n\n",
					array(
						self::paragraph( '本頁為<strong>示例內容</strong>。正式的網站與作者介紹將由網站管理者提供後更新；在那之前，本站不陳述作者的任何經歷或身分資訊。' ),
						self::paragraph( '本站是一個個人影音部落格的示範環境，內容包含文章、影片與 Podcast。' ),
					)
				),
				'menu_order'   => 1,
			)
		);

		$privacy = array(
			self::paragraph( '本頁為<strong>示例內容</strong>，描述本站目前的實際技術行為；正式上線前需由網站管理者確認並補上正式聯絡方式。' ),
			"<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">YouTube 影片</h2>\n<!-- /wp:heading -->",
			self::paragraph( '影片頁預設只顯示封面與播放按鈕，不會自動連線到 YouTube。只有在你點擊播放後，才會以 youtube-nocookie.com 網域載入 YouTube 播放器；載入後 YouTube 可能依其自身政策處理資料或設定 cookie。' ),
			"<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">音訊與圖片</h2>\n<!-- /wp:heading -->",
			self::paragraph( 'Podcast 音訊與文章圖片由本站的內容管理主機（CMS）直接提供，不經第三方媒體服務或廣告網路。' ),
			"<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">Cookie</h2>\n<!-- /wp:heading -->",
			"<!-- wp:list -->\n<ul class=\"wp-block-list\"><!-- wp:list-item -->\n<li>主站使用 Laravel 的工作階段 cookie（mysite_app_session）與防偽造請求 cookie（XSRF-TOKEN），用於維持瀏覽狀態與模擬購物車，不用於追蹤或廣告。</li>\n<!-- /wp:list-item -->\n\n<!-- wp:list-item -->\n<li>編輯者使用草稿預覽時，主站會設定只在預覽路徑有效的 mysite_preview cookie。</li>\n<!-- /wp:list-item -->\n\n<!-- wp:list-item -->\n<li>內容管理系統（WordPress）的登入 cookie 只在編輯者登入 CMS 後臺時使用，一般訪客瀏覽主站不會取得。</li>\n<!-- /wp:list-item --></ul>\n<!-- /wp:list -->",
			"<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">分析與追蹤</h2>\n<!-- /wp:heading -->",
			self::paragraph( '本站目前不使用任何網站分析、廣告或跨站追蹤工具。' ),
			"<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">模擬商店</h2>\n<!-- /wp:heading -->",
			self::paragraph( '本站的商店功能僅供模擬，不會收集信用卡號、真實地址或電話，也不會產生真實交易。' ),
			"<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">聯絡方式</h2>\n<!-- /wp:heading -->",
			self::paragraph( '正式的隱私聯絡方式尚待網站管理者提供。' ),
		);
		$pages['privacy'] = self::ensure_post(
			'page-privacy',
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_author'  => $author,
				'post_name'    => 'privacy',
				'post_title'   => '隱私說明（示例內容）',
				'post_content' => implode( "\n\n", $privacy ),
				'menu_order'   => 2,
			)
		);

		$pages['contact'] = self::ensure_post(
			'page-contact',
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_author'  => $author,
				'post_name'    => 'contact',
				'post_title'   => '聯絡（示例內容）',
				'post_content' => implode(
					"\n\n",
					array(
						self::paragraph( '本頁為<strong>示例內容</strong>。正式聯絡方式尚待網站管理者提供。' ),
						self::paragraph( '本站首版不提供線上聯絡表單，也不會透過本頁收集任何個人資料。' ),
					)
				),
				'menu_order'   => 3,
			)
		);
		return $pages;
	}

	/**
	 * 權限與排程測試用的非公開內容（WP-03、WP-07）。
	 *
	 * @param array{categories:array<string,int>,tags:array<string,string>} $terms 詞彙。
	 */
	private static function non_public( int $author, array $terms ): void {
		$cat  = $terms['categories']['sample-notes'];
		$base = array(
			'post_type'     => 'post',
			'post_author'   => $author,
			'post_category' => array( $cat ),
			'tags_input'    => array( 'sample' ),
		);
		self::ensure_post(
			'hidden-draft',
			array_merge(
				$base,
				array(
					'post_status'  => 'draft',
					'post_name'    => 'sample-hidden-draft',
					'post_title'   => '示例內容：草稿（不得出現在公開 API）',
					'post_content' => self::paragraph( '草稿正文 sc-hidden-draft-marker，不得出現在任何公開回應。' ),
				)
			)
		);
		self::ensure_post(
			'hidden-private',
			array_merge(
				$base,
				array(
					'post_status'  => 'private',
					'post_name'    => 'sample-hidden-private',
					'post_title'   => '示例內容：私人文章（不得出現在公開 API）',
					'post_content' => self::paragraph( '私人正文 sc-hidden-private-marker，不得出現在任何公開回應。' ),
				)
			)
		);
		self::ensure_post(
			'hidden-password',
			array_merge(
				$base,
				array(
					'post_status'   => 'publish',
					'post_name'     => 'sample-hidden-password',
					'post_title'    => '示例內容：密碼保護文章（不得出現在公開 API）',
					'post_content'  => self::paragraph( '密碼保護正文 sc-hidden-password-marker，不得出現在任何公開回應。' ),
					'post_password' => wp_generate_password( 20, false ),
				)
			)
		);
		$trash_id = self::find_by_key( 'hidden-trash' );
		if ( ! $trash_id ) {
			$trash_id = self::ensure_post(
				'hidden-trash',
				array_merge(
					$base,
					array(
						'post_status'  => 'trash',
						'post_name'    => 'sample-hidden-trash__trashed',
						'post_title'   => '示例內容：垃圾桶文章（不得出現在公開 API）',
						'post_content' => self::paragraph( '垃圾桶正文 sc-hidden-trash-marker，不得出現在任何公開回應。' ),
						'meta_input'   => array(
							'_wp_trash_meta_status' => 'draft',
							'_wp_trash_meta_time'   => time(),
						),
					)
				)
			);
		} else {
			self::note( false, 'hidden-trash' );
		}
		$future = gmdate( 'Y-m-d H:i:s', time() + 365 * DAY_IN_SECONDS );
		self::ensure_post(
			'hidden-future',
			array_merge(
				$base,
				array(
					'post_status'   => 'future',
					'post_name'     => 'sample-scheduled-future',
					'post_title'    => '示例內容：排程到未來的文章（到期前不得公開）',
					'post_content'  => self::paragraph( '排程正文 sc-hidden-future-marker，發布時間前不得出現在公開回應。' ),
					'post_date_gmt' => $future,
					'post_date'     => get_date_from_gmt( $future ),
				)
			)
		);
	}

	// ---------------------------------------------------------------------
	// 選單與品牌
	// ---------------------------------------------------------------------

	/**
	 * @param array<string,int> $pages 頁面 ID。
	 */
	private static function menus( array $pages ): void {
		$menus = array(
			'primary' => array(
				'name'  => '主站主選單（示例）',
				'items' => array(
					array( 'custom', '首頁', '/' ),
					array( 'custom', '文章', '/articles' ),
					array( 'custom', '影片', '/videos' ),
					array( 'custom', 'Podcast', '/podcasts' ),
					array( 'page', '關於', $pages['about'] ?? 0 ),
				),
			),
			'footer'  => array(
				'name'  => '主站頁尾選單（示例）',
				'items' => array(
					array( 'page', '隱私說明', $pages['privacy'] ?? 0 ),
					array( 'page', '聯絡', $pages['contact'] ?? 0 ),
					array( 'custom', 'RSS', '/feed.xml' ),
					array( 'custom', '外部連結示例（example.com）', 'https://example.com/' ),
				),
			),
		);
		$locations = get_nav_menu_locations();
		$changed   = false;
		foreach ( $menus as $location => $spec ) {
			$menu = wp_get_nav_menu_object( $spec['name'] );
			if ( $menu ) {
				$menu_id = (int) $menu->term_id;
				self::note( false, 'menu:' . $location );
			} else {
				$menu_id = wp_create_nav_menu( $spec['name'] );
				if ( is_wp_error( $menu_id ) ) {
					throw new \RuntimeException( '建立選單失敗：' . $menu_id->get_error_message() );
				}
				$order = 1;
				foreach ( $spec['items'] as $item ) {
					$args = array(
						'menu-item-title'    => $item[1],
						'menu-item-status'   => 'publish',
						'menu-item-position' => $order++,
					);
					if ( 'page' === $item[0] ) {
						if ( ! $item[2] ) {
							continue;
						}
						$args['menu-item-type']      = 'post_type';
						$args['menu-item-object']    = 'page';
						$args['menu-item-object-id'] = (int) $item[2];
					} else {
						$args['menu-item-type'] = 'custom';
						$args['menu-item-url']  = (string) $item[2];
					}
					wp_update_nav_menu_item( (int) $menu_id, 0, $args );
				}
				self::note( true, 'menu:' . $location );
			}
			if ( empty( $locations[ $location ] ) ) {
				$locations[ $location ] = (int) $menu_id;
				$changed                = true;
			}
		}
		if ( $changed ) {
			set_theme_mod( 'nav_menu_locations', $locations );
			self::$report['log'][] = '已指定選單位置（只補未指定者）。';
		}
	}

	private static function brand(): void {
		if ( false !== get_option( Branding::OPTION, false ) ) {
			self::note( false, 'brand' );
			return;
		}
		add_option(
			Branding::OPTION,
			array(
				'author_bio'   => '（示例內容）作者介紹待網站管理者提供；在此之前本站不陳述作者的任何經歷。',
				'social_links' => array(
					array(
						'label' => '示例連結（example.com）',
						'url'   => 'https://example.com/',
					),
				),
				'logo_id'      => self::$media['logo'] ?? 0,
				'is_sample'    => true,
			)
		);
		self::note( true, 'brand' );
	}
}
