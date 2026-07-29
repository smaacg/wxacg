<?php
/**
 * Plugin Name: Anime Sync Pro
 * Description: 從 AniList、Bangumi 自動同步動畫資料，並支援多媒體形式（動畫/漫畫/小說/遊戲/音樂）的作品系列聚合。
 * Version:     1.5.1
 * Author:      weixiaoacg
 * Requires PHP: 8.0
 * Text Domain: anime-sync-pro
 *
 * 完整 Changelog 請見 CHANGELOG.md
 *
 * 1.5.1 — 角色/聲優頁自動攤平（2026-07-29）
 *   - [新增] enrich_anime_data() 成功後，自動排程獨立的
 *     anime_sync_migrate_entities 事件，把 anime_cast_json /
 *     anime_staff_json 攤平進 wp_anime_characters /
 *     wp_anime_persons / wp_anime_relations 三張表，不必再手動下
 *     wp anime migrate-entities 指令，/person/ /character/ 頁面
 *     即可直接顯示內容。
 *   - [新增] anime_sync_migrate_entities cron callback：帶逾時保護
 *     （抓 max_execution_time 留 10 秒緩衝），角色數多的作品若單次
 *     處理不完，會自動排程接續，不會被伺服器執行時間限制中斷到
 *     資料寫一半。
 *   - [新增] save_post_anime 追加一個 hook：偵測到文章已有
 *     anime_cast_json / anime_staff_json 時，存檔（例如人工校對
 *     台灣譯名後儲存）會自動重新排程攤平，讓 person/character 頁面
 *     同步顯示最新譯名，不需要記得手動重跑遷移指令。
 *   - [向下相容] 100% 保留 v1.5.0 所有功能與 hook 順序；未安裝/未啟用
 *     entity-migrator 相關功能時（class_exists 檢查失敗）不影響
 *     既有匯入 / enrich 流程。
 *
 * 1.5.0 — 角色/聲優獨立實體頁 /person/ /character/（2026-07-28）
 *   - [新增] plugins_loaded 初始化 Anime_Sync_Entity_Routing
 *     （/person/{bgm_id} 與 /character/{bgm_id} rewrite + template_include）
 *   - [新增] Anime_Sync_Entity_Repository 唯讀查詢層（前端 + 未來 API 共用）
 *   - [新增] WP-CLI: wp anime migrate-entities（cast/staff JSON 攤平進三張表）
 *   - [向下相容] 100% 保留 v1.4.9 所有功能與 hook 順序
 *
 * 1.4.9 — 系列總覽頁 /series/（2026-07-20）
 *   - [新增] plugins_loaded 初始化 Anime_Sync_Series_Index
 *     （/series/ 根目錄顯示所有系列總覽，原本 404）
 *   - [向下相容] 100% 保留 v1.4.8 所有功能與 hook 順序
 *
 * 1.4.8 — 操作紀錄模組（2026-07-19）
 *   - [新增] plugins_loaded 初始化 Anime_Sync_Activity_Logger
 *     （誰發布/修改/刪除了幾篇動畫，含後台統計頁 + 一次性補登舊紀錄）
 *   - [向下相容] 100% 保留 v1.4.7 所有功能與 hook 順序
 *
 * 1.3.2 — 漫畫匯入模組（2026-07-16）
 *   - [新增] plugins_loaded 建立 $manga_import_manager（共用 api_handler / converter 實例）
 *   - [新增] plugins_loaded 初始化 Anime_Sync_Manga_Admin（漫畫後台匯入入口）
 *   - [向下相容] 100% 保留 v1.3.1 所有功能與 hook 順序
 *
 * 1.3.1 — SEO Auto 載入
 *   - [新增] 載入 includes/anime-seo-auto.php
 *   - [優化] /upcoming-anime/ SQL 使用 $wpdb->posts，避免資料表前綴問題
 *
 * 1.2.2 — Cron 優化（2026-06-23）
 *   - [優化] enrich 回呼加入 import_manager null fallback，防止殭屍 scheduled event 累積
 *   - [優化] register_activation_hook 新增 Anime_Sync_User_Status_Cron::schedule()
 *            靜態呼叫，排程改由啟用時設定，不再每頁 init 重複呼叫 wp_next_scheduled
 *   - [向下相容] 100% 保留 v1.2.1 所有功能與 hook 順序
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* ============================================================
 * 1. 常數定義
 * ============================================================ */
define( 'ANIME_SYNC_PRO_VERSION',  '1.5.1' );
define( 'ANIME_SYNC_PRO_DIR',      plugin_dir_path( __FILE__ ) );
define( 'ANIME_SYNC_PRO_URL',      plugin_dir_url( __FILE__ ) );
define( 'ANIME_SYNC_PRO_BASENAME', plugin_basename( __FILE__ ) );

/* ============================================================
 * 1.1. SEO Auto for Rank Math
 * ============================================================ */
$anime_sync_seo_auto_file = ANIME_SYNC_PRO_DIR . 'includes/anime-seo-auto.php';

if ( file_exists( $anime_sync_seo_auto_file ) ) {
	require_once $anime_sync_seo_auto_file;
}

if ( ! defined( 'ANIME_SYNC_PRO_CPTS' ) ) {
	define( 'ANIME_SYNC_PRO_CPTS', 'anime,manga,novel,game,music' );
}

if ( ! defined( 'ANIME_SYNC_DISABLE_LARGE_SIZES' ) ) {
	define( 'ANIME_SYNC_DISABLE_LARGE_SIZES', true );
}

/* ============================================================
 * 1.5. Global Helper：smacg_get_meta()
 * ============================================================ */
if ( ! function_exists( 'smacg_get_meta' ) ) {
	function smacg_get_meta( int $post_id, string $key, $default = '' ) {
		$new = get_post_meta( $post_id, '_smacg_' . $key, true );
		if ( $new !== '' && $new !== null && $new !== false ) {
			return $new;
		}

		$old = get_post_meta( $post_id, 'anime_' . $key, true );
		if ( $old !== '' && $old !== null && $old !== false ) {
			return $old;
		}

		return $default;
	}
}

/* ============================================================
 * 2. Autoloader
 * ============================================================ */
spl_autoload_register( function ( string $class ): void {
	if ( strpos( $class, 'Anime_Sync_' ) !== 0 ) {
		return;
	}

	$file_name = 'class-' . strtolower(
		str_replace( [ 'Anime_Sync_', '_' ], [ '', '-' ], $class )
	) . '.php';

	$file_name = preg_replace( '/-+/', '-', $file_name );

	$sources = [
		ANIME_SYNC_PRO_DIR . 'includes/',
		ANIME_SYNC_PRO_DIR . 'admin/',
		ANIME_SYNC_PRO_DIR . 'public/',
	];

	foreach ( $sources as $source ) {
		$file = $source . $file_name;

		if ( file_exists( $file ) ) {
			require_once $file;
			return;
		}
	}
} );

/* ============================================================
 * WP-CLI 指令:主動載入(spl_autoload 只在 class 被使用時才載,
 * CLI 指令註冊需在啟動時就 require,故此處手動載入)
 * ============================================================ */
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	$anime_sync_cli_files = [
		ANIME_SYNC_PRO_DIR . 'includes/class-entity-migrator.php',
	];
	foreach ( $anime_sync_cli_files as $anime_sync_cli_file ) {
		if ( file_exists( $anime_sync_cli_file ) ) {
			require_once $anime_sync_cli_file;
		}
	}
}


/* ============================================================
 * 3. 註冊 Post Type 與 Taxonomy
 * ============================================================ */
add_action( 'init', 'anime_sync_register_post_types', 10 );
add_action( 'init', 'anime_sync_register_taxonomies', 10 );

/* ============================================================
 * 3.1. /upcoming-anime/ — Rank Math SEO 覆蓋
 * ============================================================ */
add_filter( 'rank_math/frontend/title', function( string $title ): string {
	if ( get_query_var( 'anime_upcoming' ) ) {
		return '動畫化確定・製作進行中作品 2026 - 微笑動漫';
	}

	return $title;
} );

add_filter( 'rank_math/frontend/description', function( string $desc ): string {
	if ( get_query_var( 'anime_upcoming' ) ) {
		return '整理目前已宣布動畫化決定、製作確定但尚未播出的日本動畫作品，涵蓋漫畫動畫化、輕小說改編、第二季製作決定等情報，持續同步最新資訊。';
	}

	return $desc;
} );

add_filter( 'rank_math/frontend/canonical', function( string $canonical ): string {
	if ( get_query_var( 'anime_upcoming' ) ) {
		return home_url( '/upcoming-anime/' );
	}

	return $canonical;
} );

add_filter( 'rank_math/frontend/breadcrumb/items', function( array $items ): array {
	if ( ! get_query_var( 'anime_upcoming' ) ) {
		return $items;
	}

	return [
		[
			'text' => '首頁',
			'url'  => home_url( '/' ),
		],
		[
			'text' => '動漫列表',
			'url'  => home_url( '/anime/' ),
		],
		[
			'text' => '動畫化確定・製作進行中作品',
			'url'  => home_url( '/upcoming-anime/' ),
		],
	];
} );

/* ============================================================
 * 3.2. /upcoming-anime/ — 允許 anime_upcoming query var
 * ============================================================ */
add_filter( 'query_vars', function( array $vars ): array {
	if ( ! in_array( 'anime_upcoming', $vars, true ) ) {
		$vars[] = 'anime_upcoming';
	}

	return $vars;
} );

/* ============================================================
 * 3.3. /upcoming-anime/ — 篩選製作決定但尚未播出
 * ============================================================ */
add_action( 'pre_get_posts', function( WP_Query $q ): void {
	if ( is_admin() || ! $q->is_main_query() ) {
		return;
	}

	if ( ! $q->get( 'anime_upcoming' ) ) {
		return;
	}

	$q->set( 'post_type',      'anime' );
	$q->set( 'post_status',    'publish' );
	$q->set( 'posts_per_page', 24 );
	$q->set( 'meta_key',       'anime_popularity' );
	$q->set( 'orderby',        'meta_value_num' );
	$q->set( 'order',          'DESC' );

	// 只篩選 NOT_YET_RELEASED，開播日篩選由 posts_where 處理。
	$q->set( 'meta_query', [
		[
			'key'     => 'anime_status',
			'value'   => 'NOT_YET_RELEASED',
			'compare' => '=',
		],
	] );
}, 1 );

/* ============================================================
 * 3.4. /upcoming-anime/ — SQL 排除 60 天內開播新番
 * ============================================================ */
add_filter( 'posts_where', function( string $where, WP_Query $q ): string {
	if ( is_admin() || ! $q->is_main_query() ) {
		return $where;
	}

	if ( ! $q->get( 'anime_upcoming' ) ) {
		return $where;
	}

	global $wpdb;

	$cutoff = date( 'Ymd', strtotime( '+60 days' ) );

	// 排除「有開播日 且 開播日 <= cutoff」的作品。
	// 也就是保留「無開播日」或「開播日 > cutoff」的作品。
	$where .= $wpdb->prepare(
		" AND ( {$wpdb->posts}.ID NOT IN (
			SELECT post_id FROM {$wpdb->postmeta}
			WHERE meta_key = 'anime_start_date'
			  AND meta_value != ''
			  AND meta_value <= %s
		) )",
		$cutoff
	);

	return $where;
}, 10, 2 );

function anime_sync_register_post_types(): void {

	register_post_type( 'anime', [
		'labels' => [
			'name'          => '動畫',
			'singular_name' => '動畫',
			'add_new'       => '新增動畫',
			'add_new_item'  => '新增動畫',
			'edit_item'     => '編輯動畫',
			'view_item'     => '檢視動畫',
			'search_items'  => '搜尋動畫',
			'not_found'     => '找不到動畫',
			'all_items'     => '所有動畫',
			'menu_name'     => '動畫',
		],
		'public'            => true,
		'has_archive'       => 'anime',
		'show_in_rest'      => true,
		'show_in_nav_menus' => true,
		'show_ui'           => true,
		'menu_icon'         => 'dashicons-format-video',
		'menu_position'     => 5,
		'supports'          => [ 'title', 'editor', 'thumbnail', 'custom-fields', 'comments' ],
		'taxonomies'        => [ 'post_tag' ],
		'capability_type'   => 'post',
		'map_meta_cap'      => true,
		'rewrite'           => [
			'slug'       => 'anime',
			'with_front' => false,
		],
	] );

	anime_sync_register_hidden_cpt( 'manga', '漫畫', 'dashicons-book',     6, true );
	anime_sync_register_hidden_cpt( 'novel', '小說', 'dashicons-book-alt', 7 );
	anime_sync_register_hidden_cpt( 'game',  '遊戲', 'dashicons-games',    8 );
	anime_sync_register_hidden_cpt( 'music', '音樂', 'dashicons-format-audio', 9 );

	// [v1.5.1 / 2026-07-29] 角色留言影子 post
	// 影子 post用途：給 single-character.php 的 wpDiscuz 留言與
	// [wxacg_correction_form] shortcode 提供一篇真正的 WP Post 當掛勾點。
	// public => true 是讓 wpDiscuz 後台設定頁面能列出此 CPT 並勾選。
	// 其他旗標將前台存取路徑、搜尋、選單都藏起來。
	if ( ! post_type_exists( 'asa_char_comments' ) ) {
		register_post_type( 'asa_char_comments', [
			'label'               => '角色留言掛載',
			'public'              => true,
			'publicly_queryable'  => false,
			'exclude_from_search' => true,
			'show_ui'             => true,
			'show_in_menu'        => false,
			'show_in_nav_menus'   => false,
			'show_in_admin_bar'   => false,
			'show_in_rest'        => false,
			'has_archive'         => false,
			'rewrite'             => false,
			'query_var'           => false,
			'supports'            => [ 'title', 'comments' ],
		] );
	}
}

function anime_sync_register_hidden_cpt(
	string $slug,
	string $label,
	string $menu_icon,
	int $position,
	bool $public = false
): void {
	register_post_type( $slug, [
		'labels' => [
			'name'          => $label,
			'singular_name' => $label,
			'add_new'       => '新增' . $label,
			'add_new_item'  => '新增' . $label,
			'edit_item'     => '編輯' . $label,
			'view_item'     => '檢視' . $label,
			'search_items'  => '搜尋' . $label,
			'not_found'     => '找不到' . $label,
			'all_items'     => '所有' . $label,
			'menu_name'     => $label,
		],
		'public'            => $public,
		'has_archive'       => $public ? $slug : false,
		'show_in_rest'      => $public,
		'show_in_nav_menus' => $public,
		'show_ui'           => $public,
		'menu_icon'         => $menu_icon,
		'menu_position'     => $position,
		'supports'          => [ 'title', 'editor', 'thumbnail', 'custom-fields', 'comments' ],
		'taxonomies'        => [ 'post_tag' ],
		'capability_type'   => 'post',
		'map_meta_cap'      => true,
		'rewrite'           => [
			'slug'       => $slug,
			'with_front' => false,
		],
	] );
}

function anime_sync_register_taxonomies(): void {

	register_taxonomy( 'genre', [ 'anime' ], [
		'labels' => [
			'name'          => '類型',
			'singular_name' => '類型',
			'search_items'  => '搜尋類型',
			'all_items'     => '所有類型',
			'edit_item'     => '編輯類型',
			'add_new_item'  => '新增類型',
		],
		'hierarchical'      => true,
		'show_in_rest'      => true,
		'show_in_nav_menus' => true,
		'show_admin_column' => true,
		'rewrite'           => [
			'slug'       => 'genre',
			'with_front' => false,
		],
	] );

	register_taxonomy( 'anime_season_tax', [ 'anime' ], [
		'labels' => [
			'name'          => '播出季度',
			'singular_name' => '季度',
			'search_items'  => '搜尋季度',
			'all_items'     => '所有季度',
			'edit_item'     => '編輯季度',
			'add_new_item'  => '新增季度',
		],
		'hierarchical'      => true,
		'show_in_rest'      => true,
		'show_in_nav_menus' => true,
		'show_admin_column' => true,
		'rewrite'           => [
			'slug'       => 'season',
			'with_front' => false,
		],
	] );

	register_taxonomy( 'anime_format_tax', [ 'anime' ], [
		'labels' => [
			'name'          => '動畫格式',
			'singular_name' => '格式',
			'search_items'  => '搜尋格式',
			'all_items'     => '所有格式',
			'edit_item'     => '編輯格式',
			'add_new_item'  => '新增格式',
		],
		'hierarchical'      => true,
		'show_in_rest'      => true,
		'show_in_nav_menus' => true,
		'show_admin_column' => true,
		'rewrite'           => [
			'slug'       => 'format',
			'with_front' => false,
		],
	] );

	register_taxonomy(
		'anime_series_tax',
		explode( ',', ANIME_SYNC_PRO_CPTS ),
		[
			'labels' => [
				'name'          => '系列',
				'singular_name' => '系列',
				'search_items'  => '搜尋系列',
				'all_items'     => '所有系列',
				'edit_item'     => '編輯系列',
				'add_new_item'  => '新增系列',
				'new_item_name' => '新系列名稱',
				'menu_name'     => '系列',
			],
			'hierarchical'      => false,
			'show_in_rest'      => true,
			'show_in_nav_menus' => true,
			'show_admin_column' => true,
			'rewrite'           => [
				'slug'       => 'series',
				'with_front' => false,
			],
		]
	);

	register_taxonomy( 'anime_studio_tax', [ 'anime' ], [
		'labels' => [
			'name'          => '製作公司',
			'singular_name' => '製作公司',
			'search_items'  => '搜尋製作公司',
			'all_items'     => '所有製作公司',
			'edit_item'     => '編輯製作公司',
			'add_new_item'  => '新增製作公司',
			'new_item_name' => '新製作公司名稱',
			'menu_name'     => '製作公司',
		],
		'hierarchical'      => false,
		'show_in_rest'      => true,
		'show_in_nav_menus' => true,
		'show_admin_column' => true,
		'rewrite'           => [
			'slug'       => 'studio',
			'with_front' => false,
		],
	] );
}

/* ============================================================
 * 3.5. 圖片尺寸最佳化
 * ============================================================ */
if ( ANIME_SYNC_DISABLE_LARGE_SIZES ) {

	add_filter( 'intermediate_image_sizes_advanced', function ( array $sizes ): array {
		unset( $sizes['medium_large'], $sizes['1536x1536'], $sizes['2048x2048'] );

		return $sizes;
	} );

	add_filter( 'wp_calculate_image_srcset', function ( $sources ) {
		if ( ! is_array( $sources ) ) {
			return $sources;
		}

		foreach ( [ 768, 1536, 2048 ] as $w ) {
			unset( $sources[ $w ] );
		}

		return $sources;
	} );

	add_filter( 'image_size_names_choose', function ( array $sizes ): array {
		unset( $sizes['medium_large'], $sizes['1536x1536'], $sizes['2048x2048'] );

		return $sizes;
	} );
}

/* ============================================================
 * 4. 啟用 Hook
 * ============================================================ */
register_activation_hook( __FILE__, function (): void {

	if ( class_exists( 'Anime_Sync_Installer' ) ) {
		( new Anime_Sync_Installer() )->activate();
	}

	if ( class_exists( 'Anime_Sync_Cron_Manager' ) ) {
		Anime_Sync_Cron_Manager::activate();
	}

	if ( class_exists( 'Anime_Sync_User_Status_Cron' ) ) {
		Anime_Sync_User_Status_Cron::schedule();
	}

	if ( class_exists( 'Anime_Sync_Manga_Wiki_Cron' ) ) {
		Anime_Sync_Manga_Wiki_Cron::schedule();
	}

	update_option( 'anime_sync_flush_rewrite', 1 );
} );

/* ============================================================
 * 5. 停用 Hook
 * ============================================================ */
register_deactivation_hook( __FILE__, function (): void {

	if ( class_exists( 'Anime_Sync_Cron_Manager' ) ) {
		Anime_Sync_Cron_Manager::deactivate();
	}

	if ( class_exists( 'Anime_Sync_User_Status_Cron' ) ) {
		Anime_Sync_User_Status_Cron::unschedule();
	}

	if ( class_exists( 'Anime_Sync_Manga_Wiki_Cron' ) ) {
		Anime_Sync_Manga_Wiki_Cron::unschedule();
	}

	if ( class_exists( 'Anime_Sync_Installer' ) ) {
		( new Anime_Sync_Installer() )->deactivate();
	}

	flush_rewrite_rules();
} );

/* ============================================================
 * 6. 載入外掛核心（plugins_loaded）
 * ============================================================ */
add_action( 'plugins_loaded', function (): void {

	// ------------------------------------------------------
	// 操作紀錄（誰發布/修改/刪除了幾篇動畫）
	// v1.4.8 新增。不依賴 ACF，放在最外層確保任何情境
	// （後台／前台／REST）觸發 transition_post_status 都會被記錄。
	// ------------------------------------------------------
	if ( class_exists( 'Anime_Sync_Activity_Logger' ) ) {
		new Anime_Sync_Activity_Logger();
	}

	if ( class_exists( 'Anime_Sync_Editorial_Routing' ) ) {
		new Anime_Sync_Editorial_Routing();
	}

	if ( class_exists( 'Anime_Sync_Rating_Manager' ) ) {
		new Anime_Sync_Rating_Manager();
	}

	if ( class_exists( 'Anime_Sync_User_Status_Manager' ) ) {
		new Anime_Sync_User_Status_Manager();
	}

	if ( class_exists( 'Anime_Sync_User_Status_Cron' ) ) {
		new Anime_Sync_User_Status_Cron();
	}

	if ( class_exists( 'Anime_Sync_Manga_Wiki_Cron' ) ) {
		new Anime_Sync_Manga_Wiki_Cron();
	}

	if ( class_exists( 'Anime_Sync_Installer' ) ) {
		( new Anime_Sync_Installer() )->maybe_upgrade();
	}

	if ( ! class_exists( 'ACF' ) ) {
		if ( is_admin() ) {
			add_action( 'admin_notices', function (): void {
				echo '<div class="notice notice-warning"><p>';
				echo '<strong>Anime Sync Pro</strong>：未偵測到 ';
				echo '<a href="https://www.advancedcustomfields.com/" target="_blank" rel="noopener">Advanced Custom Fields</a>';
				echo '，匯入 / 同步 / 後台管理功能將停用。前端評分與追蹤狀態系統仍可使用。';
				echo '</p></div>';
			} );
		}

		return;
	}

	if ( class_exists( 'Anime_Sync_ACF_Fields' ) ) {
		new Anime_Sync_ACF_Fields();
	}
		
    if ( class_exists( 'Anime_Sync_YouTube_Playlist_Sync' ) ) {
		new Anime_Sync_YouTube_Playlist_Sync();
	}


	if ( class_exists( 'Anime_Sync_YourAnimes_Fetcher' ) ) {
		new Anime_Sync_YourAnimes_Fetcher();
	}

	if ( class_exists( 'Anime_Sync_Frontend' ) ) {
		new Anime_Sync_Frontend();
	}

	// ------------------------------------------------------
	// 系列總覽頁 /series/（v1.4.9 新增）
	// 前後台都要註冊 rewrite / template_include，
	// 因此放在 is_admin() 判斷之外，確保前台也生效。
	// ------------------------------------------------------
	if ( class_exists( 'Anime_Sync_Series_Index' ) ) {
		Anime_Sync_Series_Index::init();
	}

	// ------------------------------------------------------
	// 角色/聲優 獨立實體頁 /person/ /character/（v1.5.0 新增）
	// 同 Series_Index,前後台都要註冊 rewrite / template_include,
	// 放在 is_admin() 判斷之外,確保前台也生效。
	// ------------------------------------------------------
	if ( class_exists( 'Anime_Sync_Entity_Routing' ) ) {
		Anime_Sync_Entity_Routing::init();
	}

	if ( is_admin() || ( defined( 'DOING_CRON' ) && DOING_CRON ) ) {

		$rate_limiter = class_exists( 'Anime_Sync_Rate_Limiter' )
			? Anime_Sync_Rate_Limiter::get_instance()
			: null;

		$id_mapper = class_exists( 'Anime_Sync_ID_Mapper' )
			? new Anime_Sync_ID_Mapper( $rate_limiter )
			: null;

		$converter = class_exists( 'Anime_Sync_CN_Converter' )
			? new Anime_Sync_CN_Converter()
			: null;

		$api_handler = class_exists( 'Anime_Sync_API_Handler' )
			? new Anime_Sync_API_Handler( $rate_limiter, $id_mapper )
			: null;

		$import_manager = ( $api_handler && $converter && class_exists( 'Anime_Sync_Import_Manager' ) )
			? new Anime_Sync_Import_Manager( $api_handler, $converter )
			: null;

		// ------------------------------------------------------
		// 漫畫匯入管理器（共用同一個 api_handler / converter 實例）
		// v1.3.2 新增
		// ------------------------------------------------------
		$manga_import_manager = ( $api_handler && $converter && class_exists( 'Anime_Sync_Manga_Import_Manager' ) )
			? new Anime_Sync_Manga_Import_Manager( $api_handler, $converter )
			: null;

		if ( is_admin() && class_exists( 'Anime_Sync_Admin' ) ) {
			new Anime_Sync_Admin( $import_manager );
		}

		// ------------------------------------------------------
		// 漫畫後台匯入入口
		// v1.3.2 新增
		// ------------------------------------------------------
		if ( is_admin() && $manga_import_manager && class_exists( 'Anime_Sync_Manga_Admin' ) ) {
			new Anime_Sync_Manga_Admin( $manga_import_manager );
		}

		if ( $import_manager && class_exists( 'Anime_Sync_Cron_Manager' ) ) {
			new Anime_Sync_Cron_Manager( $import_manager );
		}

		if ( is_admin() && class_exists( 'Anime_Sync_Custom_Post_Type' ) ) {
			new Anime_Sync_Custom_Post_Type();
		}

		add_action(
			'anime_sync_enrich_post',
			function ( int $post_id ) use ( $import_manager ): void {

				if ( ! $import_manager ) {
					update_post_meta( $post_id, '_enrich_failed', current_time( 'mysql' ) );

					if ( class_exists( 'Anime_Sync_Error_Logger' ) ) {
						Anime_Sync_Error_Logger::error(
							"Enrich skipped for post {$post_id}: ImportManager unavailable (dependency missing)",
							[
								'post_id' => $post_id,
							]
						);
					}

					return;
				}

				if ( get_post_meta( $post_id, '_enriched_at', true ) ) {
					return;
				}

				$result = $import_manager->enrich_single( $post_id );

				if ( ! is_wp_error( $result ) ) {
					delete_post_meta( $post_id, '_enrich_retry' );

					// ------------------------------------------------------
					// ★ [1.5.1] enrich 完成後排程獨立的角色/聲優攤平任務，
					// 不同步執行，避免角色多的作品拖長本次 cron 執行時間、
					// 甚至被伺服器 max_execution_time 中斷。
					//
					// 冪等設計（見 class-entity-migrator.php），重跑不會
					// 產生重複資料，失敗也不影響 enrich 本身的結果。
					// ------------------------------------------------------
					if ( class_exists( 'Anime_Sync_Entity_Migrator' )
						&& ! wp_next_scheduled( 'anime_sync_migrate_entities', [ $post_id ] )
					) {
						wp_schedule_single_event( time() + 30, 'anime_sync_migrate_entities', [ $post_id ] );
					}

					return;
				}

				$retry_count = (int) get_post_meta( $post_id, '_enrich_retry', true );
				$max_retries = 3;

				if ( $retry_count < $max_retries ) {
					$retry_count++;

					update_post_meta( $post_id, '_enrich_retry', $retry_count );

					$delay = HOUR_IN_SECONDS * ( 4 ** ( $retry_count - 1 ) );

					wp_schedule_single_event(
						time() + $delay,
						'anime_sync_enrich_post',
						[
							$post_id,
						]
					);
				} else {
					update_post_meta( $post_id, '_enrich_failed', current_time( 'mysql' ) );
				}

				if ( class_exists( 'Anime_Sync_Error_Logger' ) ) {
					Anime_Sync_Error_Logger::error(
						"Enrich failed for post {$post_id}: " . $result->get_error_message(),
						[
							'post_id'     => $post_id,
							'retry'       => $retry_count,
							'max_retries' => $max_retries,
							'error_code'  => $result->get_error_code(),
						]
					);
				}
			}
		);

		// ------------------------------------------------------
		// ★ [1.5.1] 角色/聲優攤平獨立任務。
		//
		// 與 enrich 分開排程執行，避免角色數多的作品拖長 enrich
		// 本身的 cron 執行時間。內建逾時保護：抓伺服器
		// max_execution_time 並保留 10 秒緩衝，逾時就中止並由
		// Anime_Sync_Entity_Migrator::run() 自動排程接續處理
		// 剩餘角色，不會因超時被中斷導致資料寫到一半。
		// ------------------------------------------------------
		add_action(
			'anime_sync_migrate_entities',
			function ( int $post_id ): void {

				if ( ! class_exists( 'Anime_Sync_Entity_Migrator' ) ) {
					return;
				}

				$max_exec = (int) ini_get( 'max_execution_time' );
				// 有設定執行時間上限時，保留 10 秒緩衝；
				// 無限制（0）時保守抓 50 秒，避免長時間佔用 cron worker。
				$budget   = $max_exec > 0 ? max( 10, $max_exec - 10 ) : 50;
				$deadline = time() + $budget;

				try {
					( new Anime_Sync_Entity_Migrator() )->run( [
						'post_id'  => $post_id,
						'deadline' => $deadline,
					] );
				} catch ( \Throwable $e ) {
					if ( class_exists( 'Anime_Sync_Error_Logger' ) ) {
						Anime_Sync_Error_Logger::error(
							"Entity migration failed for post {$post_id}: " . $e->getMessage(),
							[ 'post_id' => $post_id ]
						);
					}
				}
			}
		);
	}
} );

/* ============================================================
 * 7. Init priority 99：rewrite flush + taxonomy seed
 * ============================================================ */
add_action( 'init', function (): void {

	if ( get_option( 'anime_sync_flush_rewrite' ) ) {
		flush_rewrite_rules();
		delete_option( 'anime_sync_flush_rewrite' );
	}

	$stored_version = get_option( 'anime_sync_pro_version', '0.0.0' );

	if ( version_compare( $stored_version, ANIME_SYNC_PRO_VERSION, '<' ) ) {
		update_option( 'anime_sync_flush_rewrite', 1 );
		update_option( 'anime_sync_pro_version', ANIME_SYNC_PRO_VERSION );
	}

	if ( class_exists( 'Anime_Sync_Installer' ) ) {
		( new Anime_Sync_Installer() )->run_pending_seed();
	}

}, 99 );

/* ============================================================
 * 8. 同步 post_title → anime_title_chinese
 * ============================================================ */
add_action( 'save_post_anime', function ( int $post_id, WP_Post $post, bool $update ): void {

	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}

	if ( wp_is_post_revision( $post_id ) ) {
		return;
	}

	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}

	if ( in_array( $post->post_status, [ 'auto-draft', 'inherit', 'trash' ], true ) ) {
		return;
	}

	$new_title = trim( $post->post_title );

	if ( $new_title === '' ) {
		return;
	}

	$current_meta = get_post_meta( $post_id, 'anime_title_chinese', true );

	if ( $current_meta === '' || $current_meta === null ) {
		update_post_meta( $post_id, 'anime_title_chinese', $new_title );
	}

}, 20, 3 );

/* ============================================================
 * 8.1. 同步 post_title → anime_title_chinese（漫畫）
 *      漫畫沿用 anime_title_chinese 這個 key,cron 靠它查維基,
 *      所以後台改標題時要一併更新,避免抓錯維基頁面。
 * ============================================================ */
add_action( 'save_post_manga', function ( int $post_id, WP_Post $post, bool $update ): void {

	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}

	if ( wp_is_post_revision( $post_id ) ) {
		return;
	}

	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}

	if ( in_array( $post->post_status, [ 'auto-draft', 'inherit', 'trash' ], true ) ) {
		return;
	}

	$new_title = trim( $post->post_title );

	if ( $new_title === '' ) {
		return;
	}

	$current_meta = get_post_meta( $post_id, 'anime_title_chinese', true );

	if ( $current_meta === '' || $current_meta === null ) {
		update_post_meta( $post_id, 'anime_title_chinese', $new_title );
	}

}, 20, 3 );

/* ============================================================
 * 8.2. ★ [1.5.1] 人工整理 CAST/STAFF JSON（翻譯校對）存檔後，
 *      自動重新排程角色/聲優攤平任務，讓 /person/ /character/
 *      頁面同步顯示最新譯名，不需要記得手動下
 *      wp anime migrate-entities 指令。
 *
 *      只在文章已有 anime_cast_json 或 anime_staff_json 內容時
 *      才排程，避免空白文章也被排程浪費資源。冪等設計，
 *      重複排程 / 重複執行都不會產生重複資料。
 * ============================================================ */
add_action( 'save_post_anime', function ( int $post_id, WP_Post $post, bool $update ): void {

	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}

	if ( wp_is_post_revision( $post_id ) ) {
		return;
	}

	if ( in_array( $post->post_status, [ 'auto-draft', 'inherit', 'trash' ], true ) ) {
		return;
	}

	if ( ! class_exists( 'Anime_Sync_Entity_Migrator' ) ) {
		return;
	}

	$has_cast  = ! empty( get_post_meta( $post_id, 'anime_cast_json', true ) );
	$has_staff = ! empty( get_post_meta( $post_id, 'anime_staff_json', true ) );

	if ( ! $has_cast && ! $has_staff ) {
		return;
	}

	if ( ! wp_next_scheduled( 'anime_sync_migrate_entities', [ $post_id ] ) ) {
		wp_schedule_single_event( time() + 30, 'anime_sync_migrate_entities', [ $post_id ] );
	}

}, 20, 3 );