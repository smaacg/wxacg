<?php
/**
 * Editorial Routing v3.3.0
 *
 * 文章內容層專用：
 * - 註冊 channel taxonomy（綁定 post）
 * - 提供 /news/anime/post-slug/、/review/manga/post-slug/、/feature/game/post-slug/
 * - 提供 /announcement/ 列表頁與 /announcement/post-slug/ 單篇（無 channel）
 *
 * v3.3.0 變更：
 * - [新增] filter_term_permalink()：將內容型分類（news / review / feature /
 *          announcement）的 term 連結從 /topic/{slug}/ 改寫為 /{slug}/。
 *          連帶讓 Rank Math 的 canonical、og:url、breadcrumb、schema 全部
 *          指向乾淨網址，消除 /topic/ 前綴造成的重複內容問題。
 *          掛在 term_link 鉤子，攔截於最源頭，不需更動後台 category base。
 *
 * v3.2.1 變更：
 * - [修正] filter_post_permalink() 移除第二參數的 WP_Post 強制型別宣告，
 *          改為方法內 instanceof 判斷 + get_post() 還原。
 *          原因：Rank Math 產生 sitemap 時以輕量化 stdClass 呼叫 get_permalink()，
 *          觸發本過濾器時傳入 stdClass，導致 TypeError fatal error，
 *          使 post-sitemap.xml 回傳 HTTP 500、整份 sitemap 無法產生。
 *
 * v3.2 變更：
 * - [新增] /announcement/ 列表 rewrite（規則 0a）
 * - [新增] /announcement/page/{n}/ 分頁 rewrite（規則 0b）
 * - [新增] /category/announcement/ → /announcement/ 301 導向，避免孤兒網址
 * - [新增] template_include filter，公告列表載入 category-announcement.php，
 *          單篇公告載入 single-announcement.php（若主題存在對應檔）
 * - 規則優先級調整：list 規則放在 single 規則之後 add，確保 list 比 single 先比對
 *
 * v3.1 變更：
 * - [改進] channel taxonomy 改為 hierarchical = true
 *
 * v3 變更（保留紀錄）：
 * - [修正] rewrite 規則 6 限定為 announcement 專用
 * - [改進] filter_post_permalink 加入 fallback
 * - [改進] maybe_redirect_canonical_post_url 加入 password / customizer 跳過
 * - [清理] 移除冗餘的 register_query_vars()
 *
 * @package Anime_Sync_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Anime_Sync_Editorial_Routing {

	/**
	 * 允許的文章內容型態（category slug）
	 */
	private const CONTENT_TYPES = [
		'announcement',
		'news',
		'review',
		'feature',
	];

	/**
	 * 不需要 channel 即可直接訪問單篇的內容型
	 */
	private const CHANNELLESS_TYPES = [
		'announcement',
	];

	/**
	 * 允許的文章頻道（channel slug）
	 */
	private const CHANNELS = [
		'anime',
		'manga',
		'novel',
		'game',
		'vtuber',
		'cosplay',
		'ai-tools',
		'voice-actor',
		'music',
		'merchandise',
		'event',
		'industry',
	];

	public function __construct() {
		add_action( 'init', [ $this, 'register_channel_taxonomy' ], 20 );
		add_action( 'init', [ $this, 'add_rewrite_rules' ], 30 );

		add_filter( 'post_link', [ $this, 'filter_post_permalink' ], 10, 3 );

		// v3.3.0 新增：分類 term 連結改寫（消除 /topic/ 前綴）
		add_filter( 'term_link', [ $this, 'filter_term_permalink' ], 10, 3 );

		add_action( 'pre_get_posts',     [ $this, 'tune_editorial_queries' ] );
		add_action( 'template_redirect', [ $this, 'maybe_redirect_canonical_post_url' ] );

		// v3.2 新增
		add_action( 'template_redirect', [ $this, 'redirect_legacy_category_announcement' ], 5 );
		add_filter( 'template_include',  [ $this, 'load_announcement_templates' ] );
	}

	/**
	 * 註冊文章頻道 taxonomy
	 *
	 * 變更（2026-05-31）：
	 * - rewrite 由 false 改為 /channel/ 前綴的 archive 路由，
	 *   讓每個頻道擁有獨立頁面（例：/channel/cosplay/、/channel/industry/）。
	 * - get_term_link() 因此會回傳乾淨網址，連帶修正 single.php 麵包屑、
	 *   文章標籤、category.php 篩選等所有 channel 連結（不再是 ?channel=xxx）。
	 * - hierarchical 網址結構設為 false（扁平），避免與 /news/{channel}/
	 *   兩段式組合路由互相干擾。
	 *
	 * ⚠ 改完後務必到「設定 → 永久連結」按一次「儲存變更」以 flush rewrite，
	 *    否則 /channel/{slug}/ 會 404。
	 */
	public function register_channel_taxonomy(): void {
		register_taxonomy( 'channel', [ 'post' ], [
			'labels' => [
				'name'                       => '內容頻道',
				'singular_name'              => '內容頻道',
				'search_items'               => '搜尋頻道',
				'popular_items'              => '常用頻道',
				'all_items'                  => '所有頻道',
				'parent_item'                => '上層頻道',
				'parent_item_colon'          => '上層頻道：',
				'edit_item'                  => '編輯頻道',
				'update_item'                => '更新頻道',
				'add_new_item'               => '新增頻道',
				'new_item_name'              => '新頻道名稱',
				'separate_items_with_commas' => '請用逗號分隔頻道',
				'add_or_remove_items'        => '新增或移除頻道',
				'choose_from_most_used'      => '從最常用頻道選擇',
				'menu_name'                  => '內容頻道',
			],
			'public'             => true,
			'show_ui'            => true,
			'show_admin_column'  => true,
			'show_in_rest'       => true,
			'hierarchical'       => true,
			'query_var'          => 'channel',
			'rewrite'            => false,
			'show_in_nav_menus'  => true,
		] );
	}


	/**
	 * 新增文章 rewrite 規則
	 *
	 * 規則優先級（add_rewrite_rule with 'top' 是堆疊到最前，
	 * 後 add 的會排在更前面，因此實際比對順序為：
	 *   0b → 0a → 6 → 5 → 4 → 3 → 2 → 1
	 *
	 *   1.  /news/                          → category 列表
	 *   2.  /news/page/2/                   → category 分頁
	 *   3.  /news/anime/                    → category + channel 列表
	 *   4.  /news/anime/page/2/             → category + channel 分頁
	 *   5.  /news/anime/post-slug/          → 單篇文章
	 *   6.  /announcement/post-slug/        → 公告單篇
	 *   0a. /announcement/                  → 公告列表（v3.2 新增，需在規則 6 之後 add）
	 *   0b. /announcement/page/2/           → 公告分頁（v3.2 新增）
	 *
	 * [新增 v3.2] /announcement/ 與 /announcement/page/{n}/
	 *   必須在規則 6 之後 add，才會被優先比對；否則 /announcement/ 會被規則 6
	 *   解析為「post slug 為空」而導向 404。
	 */
	public function add_rewrite_rules(): void {
		$content_regex      = implode( '|', array_map( [ $this, 'quote_for_regex' ], self::CONTENT_TYPES ) );
		$channel_regex      = implode( '|', array_map( [ $this, 'quote_for_regex' ], self::CHANNELS ) );
		$channelless_regex  = implode( '|', array_map( [ $this, 'quote_for_regex' ], self::CHANNELLESS_TYPES ) );

		// 1. /news/
		add_rewrite_rule(
			'^(' . $content_regex . ')/?$',
			'index.php?category_name=$matches[1]',
			'top'
		);

		// 2. /news/page/2/
		add_rewrite_rule(
			'^(' . $content_regex . ')/page/([0-9]{1,})/?$',
			'index.php?category_name=$matches[1]&paged=$matches[2]',
			'top'
		);

		// 3. /news/anime/
		add_rewrite_rule(
			'^(' . $content_regex . ')/(' . $channel_regex . ')/?$',
			'index.php?category_name=$matches[1]&channel=$matches[2]',
			'top'
		);

		// 4. /news/anime/page/2/
		add_rewrite_rule(
			'^(' . $content_regex . ')/(' . $channel_regex . ')/page/([0-9]{1,})/?$',
			'index.php?category_name=$matches[1]&channel=$matches[2]&paged=$matches[3]',
			'top'
		);

		// 5. /news/anime/post-slug/
		add_rewrite_rule(
			'^(' . $content_regex . ')/(' . $channel_regex . ')/([^/]+)/?$',
			'index.php?post_type=post&name=$matches[3]&category_name=$matches[1]&channel=$matches[2]',
			'top'
		);

		// 6. /announcement/post-slug/  公告類專用單篇路由（無 channel）
		add_rewrite_rule(
			'^(' . $channelless_regex . ')/([^/]+)/?$',
			'index.php?post_type=post&name=$matches[2]&category_name=$matches[1]',
			'top'
		);

		// 0a. /announcement/  公告列表（v3.2 新增，須在規則 6 之後 add）
		add_rewrite_rule(
			'^(' . $channelless_regex . ')/?$',
			'index.php?category_name=$matches[1]',
			'top'
		);


                // 0c. /upcoming-anime/  尚未播出列表（v3.4 新增）
                add_rewrite_rule(
                        'upcoming-anime/?$',
                        'index.php?post_type=anime&anime_upcoming=1',
                        'top'
                );

                // 0d. /upcoming-anime/page/2/  尚未播出分頁（v3.4 新增）
                add_rewrite_rule(
                        'upcoming-anime/page/([0-9]{1,})/?$',
                        'index.php?post_type=anime&anime_upcoming=1&paged=$matches[1]',
                        'top'
                );
		// 0b. /announcement/page/2/  公告分頁（v3.2 新增）
		add_rewrite_rule(
			'^(' . $channelless_regex . ')/page/([0-9]{1,})/?$',
			'index.php?category_name=$matches[1]&paged=$matches[2]',
			'top'
		);
	}

	/**
	 * 文章 permalink 改寫
	 *
	 * ⚠ 第二參數不可宣告為 WP_Post 型別。
	 *   Rank Math 產生 sitemap 時，為效能會以輕量化 stdClass（而非完整 WP_Post）
	 *   呼叫 get_permalink()，進而觸發本過濾器。若強制宣告 WP_Post 會丟出
	 *   TypeError 並造成 sitemap HTTP 500。此處以 instanceof 判斷並用 get_post()
	 *   還原為 WP_Post，取不到則原樣返回，確保絕不丟例外。
	 */
	public function filter_post_permalink( $permalink, $post, $leavename = false ) {
		// 防呆：sitemap 等情境可能傳入 stdClass，先還原為 WP_Post
		if ( ! ( $post instanceof WP_Post ) ) {
			$post_id = is_object( $post ) && isset( $post->ID ) ? (int) $post->ID : 0;
			$post    = $post_id ? get_post( $post_id ) : null;
		}

		if ( ! $post instanceof WP_Post ) {
			return $permalink;
		}

		if ( $post->post_type !== 'post' ) {
			return $permalink;
		}

		$content_type = $this->get_primary_content_type_slug( $post->ID );
		if ( $content_type === '' ) {
			return $permalink;
		}

		$post_slug = $leavename ? '%postname%' : $post->post_name;

		// 公告類（無 channel）→ /announcement/post-slug/
		if ( $this->is_channelless_type( $content_type ) ) {
			return home_url( user_trailingslashit( "{$content_type}/{$post_slug}" ) );
		}

		// 一般文章必須有 channel 才改寫
		$channel = $this->get_primary_channel_slug( $post->ID );
		if ( $channel === '' ) {
			return $permalink;
		}

		return home_url( user_trailingslashit( "{$content_type}/{$channel}/{$post_slug}" ) );
	}

	/**
	 * [v3.3.0 新增] 分類 term 連結改寫
	 *
	 * 將內容型分類（news / review / feature / announcement）的連結
	 * 從 /topic/{slug}/ 改寫為 /{slug}/，與文章路由一致。
	 *
	 * 掛在 term_link 鉤子，因此所有透過 get_term_link() 取得分類網址的地方
	 * （含 Rank Math 的 canonical、og:url、breadcrumb、JSON-LD schema，
	 * 以及主題麵包屑、列表頁連結）都會自動取得乾淨網址，無需更動後台
	 * 「分類目錄起點（category base）」，避免與 rewrite 規則衝突。
	 *
	 * 僅針對白名單分類生效，其他 category / 其他 taxonomy 一律原樣返回。
	 */
	public function filter_term_permalink( $termlink, $term, $taxonomy ) {
		if ( $taxonomy !== 'category' ) {
			return $termlink;
		}

		if ( ! is_object( $term ) || ! isset( $term->slug ) ) {
			return $termlink;
		}

		if ( ! $this->is_allowed_content_type( (string) $term->slug ) ) {
			return $termlink;
		}

		return home_url( user_trailingslashit( (string) $term->slug ) );
	}

	/**
	 * 調整文章列表查詢
	 */
	public function tune_editorial_queries( WP_Query $query ): void {
		if ( is_admin() || ! $query->is_main_query() ) {
			return;
		}

		$category_name = (string) $query->get( 'category_name' );
		if ( ! $this->is_allowed_content_type( $category_name ) ) {
			return;
		}

		$query->set( 'post_type', 'post' );
		$query->set( 'ignore_sticky_posts', false ); // 公告需置頂功能

		if ( ! $query->get( 'orderby' ) ) {
			$query->set( 'orderby', 'date' );
		}
		if ( ! $query->get( 'order' ) ) {
			$query->set( 'order', 'DESC' );
		}
	}

	/**
	 * 若文章被錯誤網址打開，301 導向 canonical permalink
	 */
	public function maybe_redirect_canonical_post_url(): void {
		if ( is_admin() || ! is_singular( 'post' ) ) {
			return;
		}

		if ( is_preview() || post_password_required() ) {
			return;
		}
		if ( function_exists( 'is_customize_preview' ) && is_customize_preview() ) {
			return;
		}

		if ( strtoupper( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) !== 'GET' ) {
			return;
		}

		$post = get_queried_object();
		if ( ! $post instanceof WP_Post || $post->post_type !== 'post' ) {
			return;
		}

		$canonical = get_permalink( $post );
		if ( ! $canonical ) {
			return;
		}

		$request_uri  = $_SERVER['REQUEST_URI'] ?? '';
		$request_path = wp_parse_url( $request_uri, PHP_URL_PATH );
		$target_path  = wp_parse_url( $canonical,   PHP_URL_PATH );

		if ( ! is_string( $request_path ) || ! is_string( $target_path ) ) {
			return;
		}

		if ( untrailingslashit( $request_path ) !== untrailingslashit( $target_path ) ) {
			wp_safe_redirect( $canonical, 301 );
			exit;
		}
	}

	/**
	 * [v3.2 新增] /category/announcement/ → /announcement/ 301 導向
	 *
	 * announcement 已有專屬路徑 /announcement/，避免使用者誤入孤兒網址。
	 * 同時處理 /category/announcement/page/2/ 之類的分頁。
	 */
	public function redirect_legacy_category_announcement(): void {
		if ( is_admin() ) {
			return;
		}
		if ( strtoupper( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) !== 'GET' ) {
			return;
		}

		$request_uri  = $_SERVER['REQUEST_URI'] ?? '';
		$request_path = wp_parse_url( $request_uri, PHP_URL_PATH );
		if ( ! is_string( $request_path ) ) {
			return;
		}

		$trimmed = trim( $request_path, '/' );

		foreach ( self::CHANNELLESS_TYPES as $type ) {
			// /category/announcement/  或  /category/announcement/page/2/
			if ( $trimmed === "category/{$type}" ) {
				wp_safe_redirect( home_url( "/{$type}/" ), 301 );
				exit;
			}
			if ( preg_match( '#^category/' . preg_quote( $type, '#' ) . '/page/([0-9]+)/?$#', $trimmed, $m ) ) {
				wp_safe_redirect( home_url( "/{$type}/page/{$m[1]}/" ), 301 );
				exit;
			}
		}
	}

	/**
	 * [v3.2 新增] 載入 announcement 專屬模板
	 *
	 * - 列表頁：is_category('announcement') → category-announcement.php
	 * - 單篇：  is_singular('post') 且該 post 為 announcement → single-announcement.php
	 *
	 * 若主題不存在對應檔，回傳預設模板（不影響行為）。
	 */
	public function load_announcement_templates( string $template ): string {
		// 列表頁
		if ( is_category( 'announcement' ) ) {
			$custom = locate_template( 'category-announcement.php' );
			if ( $custom ) {
				return $custom;
			}
		}

		// 單篇
		if ( is_singular( 'post' ) ) {
			$post = get_queried_object();
			if ( $post instanceof WP_Post && has_category( 'announcement', $post ) ) {
				$custom = locate_template( 'single-announcement.php' );
				if ( $custom ) {
					return $custom;
				}
			}
		}

		return $template;
	}

	/**
	 * 取得主內容型 category slug
	 */
	private function get_primary_content_type_slug( int $post_id ): string {
		$terms = get_the_category( $post_id );

		if ( empty( $terms ) ) {
			return '';
		}

		foreach ( $terms as $term ) {
			if ( isset( $term->slug ) && $this->is_allowed_content_type( $term->slug ) ) {
				return (string) $term->slug;
			}
		}

		return '';
	}

	/**
	 * 取得主 channel slug
	 */
	private function get_primary_channel_slug( int $post_id ): string {
		$terms = get_the_terms( $post_id, 'channel' );

		if ( empty( $terms ) || is_wp_error( $terms ) ) {
			return '';
		}

		foreach ( $terms as $term ) {
			if ( isset( $term->slug ) && $this->is_allowed_channel( $term->slug ) ) {
				return (string) $term->slug;
			}
		}

		return '';
	}

	private function is_allowed_content_type( string $slug ): bool {
		return in_array( $slug, self::CONTENT_TYPES, true );
	}

	private function is_allowed_channel( string $slug ): bool {
		return in_array( $slug, self::CHANNELS, true );
	}

	private function is_channelless_type( string $slug ): bool {
		return in_array( $slug, self::CHANNELLESS_TYPES, true );
	}

	private function quote_for_regex( string $value ): string {
		return preg_quote( $value, '/' );
	}
}
