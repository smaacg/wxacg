<?php
/**
 * 微笑動漫 Child Theme — functions.php
 *
 * @version 2.29.1 (2026-08-13)
 *
 * Changelog:
 *  2.29.1
 *   - 補上漫畫頁 thin → noindex（原本只擋廣告、沒 noindex，薄漫畫頁照常被收錄）：
 *       · 新增 wxacg_is_thin_manga_page()，判定精神與 anime 一致。
 *       · 掛進 rank_math/frontend/robots（is_singular('manga')）動態 noindex,follow。
 *       · 背景重算同時掃描 manga，sitemap 一併排除薄漫畫頁。
 *       · 新增 save_post_manga 觸發背景重算。
 *
 *  2.29.0
 *   - 收緊 Anime Thin 判定以符合 AdSense 內容政策（缺乏價值內容複審）：
 *       · 純簡介不再單獨解除空洞；短簡介＝Google 眼中的 auto-generated thin。
 *       · 短評需「經人工審核」（有審核者）才算獨立編輯價值，純 AI 草稿不算，
 *         避免大量未把關 AI 內容撐起收錄頁數。人審一篇即自動回到索引。
 *       · 簡介需達 WXACG_THIN_SYNOPSIS_RICH（預設 300）字且有社群訊號才算充實。
 *   - 門檻抽為常數 WXACG_THIN_SYNOPSIS_RICH／WXACG_THIN_EDITORIAL_MIN，便於微調。
 *   - 修正 sitemap 間歇性「無法擷取／錯誤」：thin anime 清單改為持久 option ＋
 *     背景 WP-Cron 每 6 小時重算，前台只讀現成結果，永不在 Googlebot 抓取
 *     sitemap 當下觸發全站掃描（原本 ~1,300 篇 × 重查詢會撞 PHP 逾時 → 500）。
 *     內容異動改排 5 分鐘 debounce 背景重算，取代舊的刪 transient inline 重掃。
 *
 *  2.28.5
 *   - P0-A：文章數少於 3 篇的標籤及製作公司分類頁設為 noindex, follow。
 *   - 同步從 Rank Math XML Sitemap 排除低文章數分類頁。
 *
 *  2.28.4
 *   - 關閉 Rank Math Frontend Stats Bar。
 *   - 修正 ACF Closure 使用 __FUNCTION__ 無法解除 Hook。
 *   - 移除互相衝突的 jQuery defer 規則。
 *   - 分離 Anime noindex 與 AdSense 顯示資格。
 *   - 統一 AdSense 主腳本及 AdBlock 提示判斷。
 *   - 角色／人物頁缺少 Thin 旗標時保守停用廣告。
 *   - Thin taxonomy 同步排除 Rank Math Sitemap。
 *   - 隨機動漫排除 Thin Anime。
 *   - 補全留言及文章異動的 Thin Cache 清除。
 *   - 移除全域 style／iframe KSES 放寬。
 *   - 強化 REST Meta 權限與後台表單安全。
 *
 * @package weixiaoacg
 */

defined( 'ABSPATH' ) || exit;

/* ============================================================
 * 基本常數
 * ============================================================ */

if ( ! defined( 'weixiaoacg_VERSION' ) ) {
	define( 'weixiaoacg_VERSION', '2.29.1' );
}

if ( ! defined( 'weixiaoacg_THEME_URL' ) ) {
	define( 'weixiaoacg_THEME_URL', get_stylesheet_directory_uri() );
}

if ( ! defined( 'weixiaoacg_THEME_DIR' ) ) {
	define( 'weixiaoacg_THEME_DIR', get_stylesheet_directory() );
}

if ( ! defined( 'WXACG_FOLLOW_DAILY_LIMIT' ) ) {
	define( 'WXACG_FOLLOW_DAILY_LIMIT', 200 );
}
if ( ! defined( 'WXACG_FOLLOW_COOLDOWN' ) ) {
	define( 'WXACG_FOLLOW_COOLDOWN', 1 );
}

if ( ! defined( 'SMACG_REGISTER_IP_DAILY_LIMIT' ) ) {
	define( 'SMACG_REGISTER_IP_DAILY_LIMIT', 3 );
}
if ( ! defined( 'SMACG_REGISTER_COOLDOWN' ) ) {
	define( 'SMACG_REGISTER_COOLDOWN', 60 );
}
if ( ! defined( 'SMACG_REGISTER_PW_MIN_LEN' ) ) {
	define( 'SMACG_REGISTER_PW_MIN_LEN', 8 );
}
if ( ! defined( 'SMACG_REGISTER_USERNAME_MIN' ) ) {
	define( 'SMACG_REGISTER_USERNAME_MIN', 3 );
}
if ( ! defined( 'SMACG_REGISTER_USERNAME_MAX' ) ) {
	define( 'SMACG_REGISTER_USERNAME_MAX', 20 );
}
if ( ! defined( 'SMACG_REGISTER_VERIFY_EXPIRE' ) ) {
	define( 'SMACG_REGISTER_VERIFY_EXPIRE', DAY_IN_SECONDS );
}
if ( ! defined( 'SMACG_REGISTER_UNVERIFIED_TTL' ) ) {
	define( 'SMACG_REGISTER_UNVERIFIED_TTL', 7 * DAY_IN_SECONDS );
}

if ( ! defined( 'WXACG_BADGE_SLUG' ) ) {
	define( 'WXACG_BADGE_SLUG', 'badge' );
}
if ( ! defined( 'WXACG_EVENT_CPT' ) ) {
	define( 'WXACG_EVENT_CPT', 'wxacg_season_event' );
}
if ( ! defined( 'WXACG_ADSENSE_CLIENT' ) ) {
	define( 'WXACG_ADSENSE_CLIENT', 'ca-pub-3709514691049766' );
}

/**
 * Header 裝飾橫幅的圖片網址。
 *
 * 回傳空字串＝停用，header 維持原本的純毛玻璃外觀，且相關的 CSS／JS
 * 也不會被載入（見 inc/setup-enqueue.php）。要啟用就把網址填進來，
 * 換圖只要改這一處。
 *
 * 建議規格：2560×400、深色調；左 18%／中 30-55%／右 78-100% 需保持
 * 低對比，因為那三段分別被 logo、搜尋列、使用者圖示蓋住。
 *
 * @return string
 */
function wxacg_header_banner_url(): string {
	/*
	 * 2560×200（12.8:1）。這個比例是刻意的：header 可視高度約 155px，
	 * 先前的 2560×400 有四成高度會被裁掉，換成扁長版才能完整顯示。
	 *
	 * 這是「不含腳踏車」的版本 —— 腳踏車另外做成前景層（見
	 * wxacg_header_banner_fg_url()），兩層以不同速度位移產生景深。
	 * 若要退回單層，把網址換成 202608top.webp（含腳踏車）並把前景層留空。
	 */
	$url = 'https://weixiaoacg.com/wp-content/uploads/2026/08/banner_2560x200_no_bike.webp';

	/**
	 * 允許以外掛或子主題覆寫橫幅來源（例如依季節輪播）。
	 *
	 * @param string $url 目前的橫幅網址。
	 */
	return (string) apply_filters( 'wxacg/header_banner_url', $url );
}

/**
 * 站台品牌 logo 圖片網址（header 與 footer 共用）。
 *
 * ★ 為什麼要抽成函式
 *   原本 header.php 與 footer.php 各自寫死一份網址，換 logo 時只改了
 *   header，footer 就停在舊圖（2026/06/112.png）——而 footer 的註解還
 *   寫著「與 header.php 一致」，程式與註解不符，實際頁面兩處長得不一樣。
 *   集中在這裡之後，換圖只要改這一處。
 *
 * ★ 為什麼用 300×288 縮圖而非原圖（沿用 header.php 原本的說明）
 *   原圖是 1860×1784、122 KB，但實際只顯示 62px 高（見 --logo-img-h）；
 *   header 每一頁都會載入，掛原圖等於每個請求多背 117 KB。
 *   300×288 為 15 KB，相對顯示尺寸仍有 4.6 倍解析度，高解析螢幕不會糊。
 *
 * @return string
 */
function wxacg_brand_logo_url(): string {
	$url = 'https://weixiaoacg.com/wp-content/uploads/2026/08/wxacglogo-300x288.webp';

	/**
	 * 允許以外掛或子主題覆寫品牌 logo（例如節慶版本）。
	 *
	 * @param string $url 目前的 logo 網址。
	 */
	return (string) apply_filters( 'wxacg/brand_logo_url', $url );
}

/**
 * Header 橫幅的「前景層」圖片網址。
 *
 * 這一層會以比背景更大的幅度隨滑鼠位移，藉此產生景深 —— 移動幅度越大，
 * 視覺上越靠近觀看者。回傳空字串即停用，橫幅退回單層。
 *
 * ★ 圖必須與背景層同尺寸（2560×200）且帶透明通道，物件放在它在畫面上
 *   應該出現的位置。這樣兩層可以套用完全相同的 background-size 與
 *   background-position，對齊由「尺寸相同」本身保證，不必在 CSS 裡換算
 *   百分比 —— 換算一旦有誤差，位移時會非常明顯。
 *
 *   目前這張是把 bicycle_layer_from_banner.png（500×500 去背）合成到
 *   2560×200 透明畫布上產生的，位置取自含／不含腳踏車兩張橫幅的像素比對
 *   （x 364~590、y 68~198），對位驗證 76.4% 吻合。
 *
 * @return string
 */
function wxacg_header_banner_fg_url(): string {
	$url = 'https://weixiaoacg.com/wp-content/uploads/2026/08/bicycle_layer_2560x200.webp';

	/**
	 * 允許覆寫前景層來源。
	 *
	 * @param string $url 目前的前景層網址。
	 */
	return (string) apply_filters( 'wxacg/header_banner_fg_url', $url );
}

const WEIXIAOACG_ID_CATS  = [ 'announcement', 'news' ];
const WEIXIAOACG_LLM_CATS = [ 'review', 'feature' ];

/* ============================================================
 * Rank Math 前台統計列
 * ============================================================ */

add_filter( 'rank_math/analytics/frontend_stats', '__return_false' );

/* ============================================================
 * 主題核心載入
 * ============================================================ */

$wxacg_inc_dir = weixiaoacg_THEME_DIR . '/inc/';

foreach (
	[
		'setup-theme',
		'class-nav-walker',
		'setup-enqueue',
		'bangumi-loader',
	] as $wxacg_required_file
) {
	$wxacg_path = $wxacg_inc_dir . $wxacg_required_file . '.php';

	if ( file_exists( $wxacg_path ) ) {
		require_once $wxacg_path;
	}
}

foreach (
	[
		'image-optimizer',
		'ajax-news-filter',
		'search-hot',
		'ranking-feed',
		'ranking-sidebar',
	] as $wxacg_optional_file
) {
	$wxacg_path = $wxacg_inc_dir . $wxacg_optional_file . '.php';

	if ( file_exists( $wxacg_path ) ) {
		require_once $wxacg_path;
	}
}

unset(
	$wxacg_inc_dir,
	$wxacg_required_file,
	$wxacg_optional_file,
	$wxacg_path
);

/* ============================================================
 * 外掛狀態檢查
 * ============================================================ */

function wxacg_required_plugins_notice(): void {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}

	$missing = [];

	if ( ! defined( 'WXACG_GAMIFY_VERSION' ) ) {
		$missing[] = 'SMACG Gamification';
	}
	if ( ! defined( 'WXACG_API_VERSION' ) ) {
		$missing[] = 'SMACG API';
	}
	if ( ! defined( 'WXACG_MEMBERS_VERSION' ) ) {
		$missing[] = 'SMACG Members';
	}

	if ( empty( $missing ) ) {
		return;
	}

	echo '<div class="notice notice-error"><p><strong>weixiaoacg 主題：</strong>';
	echo '以下外掛未啟用，相關功能將停用：<code>';
	echo esc_html( implode( '、', $missing ) );
	echo '</code>。請至 <a href="';
	echo esc_url( admin_url( 'plugins.php' ) );
	echo '">外掛頁</a>啟用。</p></div>';
}
add_action( 'admin_notices', 'wxacg_required_plugins_notice' );

/* ============================================================
 * 搜尋分類篩選
 * ============================================================ */

function wxacg_filter_search_post_types( WP_Query $query ): void {
	if (
		is_admin() ||
		! $query->is_main_query() ||
		! $query->is_search()
	) {
		return;
	}

	$stype = isset( $_GET['stype'] )
		? sanitize_key( wp_unslash( $_GET['stype'] ) )
		: '';

	if ( 'db' === $stype ) {
		$query->set( 'post_type', [ 'anime', 'manga' ] );
	} elseif ( 'post' === $stype ) {
		$query->set( 'post_type', [ 'post' ] );
	}
}
add_action( 'pre_get_posts', 'wxacg_filter_search_post_types' );

/* ============================================================
 * Anime 關聯文章
 * ============================================================ */

function smacg_get_anime_articles_count( int $anime_post_id, string $category ): int {
	if (
		$anime_post_id <= 0 ||
		! in_array( $category, WEIXIAOACG_LLM_CATS, true )
	) {
		return 0;
	}

	$cache_key = 'smacg_anime_art_cnt_' . $anime_post_id . '_' . $category;
	$cached    = get_transient( $cache_key );

	if ( false !== $cached ) {
		return (int) $cached;
	}

	global $wpdb;

	$needle_int = '%' . $wpdb->esc_like( 'i:' . $anime_post_id . ';' ) . '%';
	$needle_str = '%' . $wpdb->esc_like( '"' . $anime_post_id . '"' ) . '%';
	$needle_raw = (string) $anime_post_id;

	$sql = $wpdb->prepare(
		"SELECT DISTINCT p.ID, pm.meta_value
		FROM {$wpdb->posts} p
		INNER JOIN {$wpdb->postmeta} pm
			ON pm.post_id = p.ID
			AND pm.meta_key = 'related_anime'
		INNER JOIN {$wpdb->term_relationships} tr
			ON tr.object_id = p.ID
		INNER JOIN {$wpdb->term_taxonomy} tt
			ON tt.term_taxonomy_id = tr.term_taxonomy_id
			AND tt.taxonomy = 'category'
		INNER JOIN {$wpdb->terms} t
			ON t.term_id = tt.term_id
			AND t.slug = %s
		WHERE p.post_type = 'post'
			AND p.post_status = 'publish'
			AND (
				pm.meta_value LIKE %s
				OR pm.meta_value LIKE %s
				OR pm.meta_value = %s
			)",
		$category,
		$needle_int,
		$needle_str,
		$needle_raw
	);

	$rows  = $wpdb->get_results( $sql );
	$count = 0;
	$seen  = [];

	if ( is_array( $rows ) ) {
		foreach ( $rows as $row ) {
			$post_id = (int) $row->ID;

			if ( isset( $seen[ $post_id ] ) ) {
				continue;
			}

			$value = maybe_unserialize( $row->meta_value );

			if ( is_array( $value ) ) {
				$ids = array_map( 'intval', array_values( $value ) );

				if ( in_array( $anime_post_id, $ids, true ) ) {
					$seen[ $post_id ] = true;
					$count++;
				}
			} elseif ( (int) $value === $anime_post_id ) {
				$seen[ $post_id ] = true;
				$count++;
			}
		}
	}

	set_transient( $cache_key, $count, HOUR_IN_SECONDS );

	return $count;
}

function wxacg_filter_related_anime_articles( WP_Query $query ): void {
	if ( is_admin() || ! $query->is_main_query() ) {
		return;
	}

	$related = isset( $_GET['related_anime'] )
		? absint( $_GET['related_anime'] )
		: 0;

	if ( $related <= 0 ) {
		return;
	}

	$category = (string) $query->get( 'category_name' );

	if ( ! in_array( $category, WEIXIAOACG_LLM_CATS, true ) ) {
		return;
	}

	$meta_query   = (array) $query->get( 'meta_query' );
	$meta_query[] = [
		'relation' => 'OR',
		[
			'key'     => 'related_anime',
			'value'   => 'i:' . $related . ';',
			'compare' => 'LIKE',
		],
		[
			'key'     => 'related_anime',
			'value'   => '"' . $related . '"',
			'compare' => 'LIKE',
		],
		[
			'key'     => 'related_anime',
			'value'   => (string) $related,
			'compare' => '=',
		],
	];

	$query->set( 'meta_query', $meta_query );
}
add_action( 'pre_get_posts', 'wxacg_filter_related_anime_articles' );

function wxacg_normalize_related_anime_ids( $value ): array {
	$value = maybe_unserialize( $value );

	if ( is_array( $value ) ) {
		return array_values(
			array_filter(
				array_unique( array_map( 'absint', $value ) )
			)
		);
	}

	$id = absint( $value );

	return $id > 0 ? [ $id ] : [];
}

function wxacg_clear_article_relation_cache( int $post_id ): void {
	if (
		wp_is_post_revision( $post_id ) ||
		wp_is_post_autosave( $post_id )
	) {
		return;
	}

	$new_ids = wxacg_normalize_related_anime_ids(
		get_post_meta( $post_id, 'related_anime', true )
	);

	$old_ids = wxacg_normalize_related_anime_ids(
		get_post_meta( $post_id, '_related_anime_prev', true )
	);

	$all_ids = array_unique( array_merge( $new_ids, $old_ids ) );

	foreach ( $all_ids as $anime_id ) {
		delete_transient( 'smacg_anime_art_cnt_' . $anime_id . '_review' );
		delete_transient( 'smacg_anime_art_cnt_' . $anime_id . '_feature' );
	}

	update_post_meta( $post_id, '_related_anime_prev', $new_ids );
	wxacg_schedule_thin_rebuild();
}
add_action( 'save_post_post', 'wxacg_clear_article_relation_cache', 30 );

function wxacg_clear_deleted_article_relation_cache( int $post_id ): void {
	if ( 'post' !== get_post_type( $post_id ) ) {
		return;
	}

	$ids = wxacg_normalize_related_anime_ids(
		get_post_meta( $post_id, 'related_anime', true )
	);

	foreach ( $ids as $anime_id ) {
		delete_transient( 'smacg_anime_art_cnt_' . $anime_id . '_review' );
		delete_transient( 'smacg_anime_art_cnt_' . $anime_id . '_feature' );
	}

	wxacg_schedule_thin_rebuild();
}
add_action( 'before_delete_post', 'wxacg_clear_deleted_article_relation_cache' );

/* ============================================================
 * 內容品質 Helper
 * ============================================================ */

function wxacg_plain_text( $value ): string {
	$value = wp_strip_all_tags( (string) $value );
	$value = html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	$value = preg_replace( '/\s+/u', ' ', $value );

	return trim( is_string( $value ) ? $value : '' );
}

function wxacg_text_length( $value ): int {
	$value = wxacg_plain_text( $value );

	if ( function_exists( 'mb_strlen' ) ) {
		return (int) mb_strlen( $value, 'UTF-8' );
	}

	return strlen( $value );
}

/* ============================================================
 * Anime Thin Content
 * ============================================================ */

/**
 * Thin 判定門檻（可調）。
 *
 * WXACG_THIN_SYNOPSIS_RICH：簡介達此字數「且」有社群訊號才算充實；
 *                           純簡介（尤其自動匯入的短簡介）不再單獨解除空洞，
 *                           以符合 AdSense「auto-generated / thin content」政策。
 * WXACG_THIN_EDITORIAL_MIN：人工／AI 短評達此字數即視為獨立編輯價值，可單獨解除。
 * WXACG_ADSENSE_EDITORIAL_MIN：短評達此字數（且經人工審核）才取得廣告資格。
 *                           刻意高於 THIN 門檻：能被收錄不代表足以放廣告。
 *                           ⚠ 撰寫工具給的建議字數必須 ≥ 此值，否則照建議寫出來的
 *                             短評解除得了 noindex、卻永遠拿不到廣告資格。
 */
if ( ! defined( 'WXACG_THIN_SYNOPSIS_RICH' ) ) {
	define( 'WXACG_THIN_SYNOPSIS_RICH', 300 );
}

if ( ! defined( 'WXACG_THIN_EDITORIAL_MIN' ) ) {
	define( 'WXACG_THIN_EDITORIAL_MIN', 120 );
}

if ( ! defined( 'WXACG_ADSENSE_EDITORIAL_MIN' ) ) {
	define( 'WXACG_ADSENSE_EDITORIAL_MIN', 180 );
}

function wxacg_is_thin_anime_page( int $post_id ): bool {
	if (
		$post_id <= 0 ||
		'anime' !== get_post_type( $post_id )
	) {
		return false;
	}

	$synopsis = (string) get_post_meta(
		$post_id,
		'anime_synopsis_chinese',
		true
	);

	if ( '' === trim( $synopsis ) ) {
		$synopsis = (string) get_post_meta(
			$post_id,
			'anime_synopsis',
			true
		);
	}

	$synopsis_length = wxacg_text_length( $synopsis );

	// 優先讀新欄位（AI 批次工具寫入），fallback 舊欄位（共 129 筆舊資料）。
	$editorial_raw  = get_post_meta( $post_id, 'anime_editor_summary', true );
	$editorial_note = is_string( $editorial_raw ) ? $editorial_raw : '';

	if ( '' === trim( $editorial_note ) ) {
		$editorial_note = (string) get_post_meta(
			$post_id,
			'anime_editorial_note',
			true
		);
	}

	$editorial_length = wxacg_text_length( $editorial_note );

	$score_count = (int) get_post_meta(
		$post_id,
		'anime_score_site_count',
		true
	);

	if ( $score_count <= 0 ) {
		$score_count = (int) get_post_meta(
			$post_id,
			'smacg_site_score_count',
			true
		);
	}

	$comment_count = (int) get_comments_number( $post_id );

	$feature_count = smacg_get_anime_articles_count(
		$post_id,
		'feature'
	);

	$review_count = smacg_get_anime_articles_count(
		$post_id,
		'review'
	);

	/*
	 * v2.29.0 收緊 thin 判定以符合 AdSense 內容政策：
	 *   - 純簡介不再單獨解除空洞（短簡介＝Google 眼中的 auto-generated thin）。
	 *   - 短評需「經人工審核」（有審核者）才算獨立編輯價值；純 AI 草稿不算，
	 *     避免大量未經人工把關的 AI 短評撐起收錄頁數、反被判低品質。
	 *   - 簡介需達 WXACG_THIN_SYNOPSIS_RICH 字「且」有社群訊號才算充實。
	 * 與 wxacg_is_anime_adsense_eligible() 的「需審核者」邏輯對齊。
	 *
	 * v2.30.0 新增「已驗證台灣串流平台資訊」作為第四種解除條件：
	 *   anime_tw_streaming / anime_tw_distributor(_custom) 皆為純人工欄位
	 *   （YourAnimes 半自動帶入時也只在空白時填、不覆蓋人工值），
	 *   AniList/Bangumi 不提供，屬於本站對台灣讀者的在地查證資訊，
	 *   非單純聚合轉載。
	 *
	 * v2.30.1 新增「原創 FAQ」作為第五種解除條件：
	 *   anime_faq_json 欄位說明明載「完全人工輸入」，無任何自動寫入路徑，
	 *   且撰寫規則要求多來源查證後原創改寫、不得照抄，比對外部聚合資料
	 *   更接近短評等級的原創價值；門檻與前台實際渲染 FAQPage schema 一致，
	 *   至少 1 筆 q/a 皆非空即算數。
	 */
	$author_id = (int) get_post_meta(
		$post_id,
		'anime_editorial_author_id',
		true
	);

	$legacy_author = wxacg_plain_text(
		get_post_meta(
			$post_id,
			'anime_editorial_author',
			true
		)
	);

	$has_author = $author_id > 0 || '' !== $legacy_author;

	$tw_platforms = get_post_meta( $post_id, 'anime_tw_streaming', true );
	$tw_platforms = is_array( $tw_platforms ) ? array_filter( $tw_platforms ) : [];

	$tw_distributor        = wxacg_plain_text( get_post_meta( $post_id, 'anime_tw_distributor', true ) );
	$tw_distributor_custom = wxacg_plain_text( get_post_meta( $post_id, 'anime_tw_distributor_custom', true ) );

	$faq_items = json_decode( (string) get_post_meta( $post_id, 'anime_faq_json', true ), true );
	$has_faq   = false;

	if ( is_array( $faq_items ) ) {
		foreach ( $faq_items as $faq_item ) {
			if (
				is_array( $faq_item ) &&
				'' !== trim( wxacg_plain_text( $faq_item['q'] ?? '' ) ) &&
				'' !== trim( wxacg_plain_text( $faq_item['a'] ?? '' ) )
			) {
				$has_faq = true;
				break;
			}
		}
	}

	$has_reviewed_editorial    = ( $editorial_length >= WXACG_THIN_EDITORIAL_MIN ) && $has_author;
	$has_rich_synopsis         = $synopsis_length >= WXACG_THIN_SYNOPSIS_RICH;
	$has_original              = $feature_count > 0 || $review_count > 0;
	$has_community             = $score_count > 0 || $comment_count > 0;
	$has_verified_tw_streaming = ! empty( $tw_platforms ) || '' !== $tw_distributor || '' !== $tw_distributor_custom;

	$is_valuable =
		$has_reviewed_editorial ||
		$has_original ||
		$has_verified_tw_streaming ||
		$has_faq ||
		( $has_rich_synopsis && $has_community );

	/*
	 * v2.31.0：未播出作品的專屬解除條件。
	 *
	 * 上面五個條件，未播出作品幾乎必然一個都碰不到——
	 * 台灣授權還沒宣布、資訊太少寫不出 FAQ、沒播無從撰寫短評、
	 * 沒人評分因此沒有社群訊號、官方簡介初期也通常很短。
	 * 結果是新番在「觀眾正開始搜尋」的宣傳期完全不被索引，
	 * 等資料補齊解除 noindex 時排名早已被別人拿走。
	 *
	 * 但也不能無條件放行：只有標題、其餘皆空的頁面收錄了也沒有意義。
	 * 因此改以「對想查這部新番的人有沒有實質資訊」為準，
	 * 下列皆為查證得到的事實、不需要看過作品：
	 *   · 確定的播出季度或首播日期
	 *   · 製作公司
	 *   · STAFF 或 CAST 名單
	 *   · PV 預告片
	 * 滿足其中三項即視為具查詢價值。
	 *
	 * ⚠ 這裡只放寬「索引」。廣告資格由 wxacg_is_anime_adsense_eligible()
	 *   另外把關（需短評 180 字＋審核者、或內文 500 字、或站內原創文章），
	 *   未播出頁面因此會被 Google 收錄，但不會顯示廣告。
	 *
	 * 作品開播後 anime_status 改變，判定自動回到上面五條，
	 * 該補的資訊仍然要補。
	 */
	if ( ! $is_valuable ) {
		$status = wxacg_plain_text( get_post_meta( $post_id, 'anime_status', true ) );

		if ( 'NOT_YET_RELEASED' === $status ) {
			$upcoming_signals = 0;

			// 1. 播出時程：季度或首播日期任一即可
			$season_year = (int) get_post_meta( $post_id, 'anime_season_year', true );
			$start_date  = wxacg_plain_text( get_post_meta( $post_id, 'anime_start_date', true ) );

			if ( $season_year > 0 || '' !== $start_date ) {
				$upcoming_signals++;
			}

			// 2. 製作公司
			if ( '' !== wxacg_plain_text( get_post_meta( $post_id, 'anime_studios', true ) ) ) {
				$upcoming_signals++;
			}

			// 3. STAFF 或 CAST 名單（任一有資料即可）
			foreach ( [ 'anime_staff_json', 'anime_cast_json' ] as $list_key ) {
				$decoded = json_decode( (string) get_post_meta( $post_id, $list_key, true ), true );

				if ( is_array( $decoded ) && ! empty( $decoded ) ) {
					$upcoming_signals++;
					break;
				}
			}

			// 4. PV 預告片
			if ( '' !== wxacg_plain_text( get_post_meta( $post_id, 'anime_trailer_url', true ) ) ) {
				$upcoming_signals++;
			}

			if ( $upcoming_signals >= 3 ) {
				$is_valuable = true;
			}
		}
	}

	return ! $is_valuable;
}

/**
 * 漫畫 Thin 判定（v2.29.0 新增）。
 *
 * 原本漫畫頁的 thin 只用於「擋廣告版位」、沒有掛 noindex，
 * 造成薄漫畫頁照常被 Google 收錄，形成收斂缺口。此函式與 anime 同一套
 * 精神：純短簡介不足以解除空洞，須有原創單行本整理、夠長簡介，或
 * 「簡介＋社群互動」才算有價值。掛進 rank_math/frontend/robots 與
 * sitemap 排除（見 wxacg_rebuild_thin_anime_ids）。
 */
function wxacg_is_thin_manga_page( int $post_id ): bool {
	if (
		$post_id <= 0 ||
		'manga' !== get_post_type( $post_id )
	) {
		return false;
	}

	$synopsis = (string) get_post_meta( $post_id, 'anime_synopsis_chinese', true );

	if ( '' === trim( $synopsis ) ) {
		$synopsis = (string) get_post_field( 'post_content', $post_id );
	}

	$synopsis_length = wxacg_text_length( $synopsis );

	// 原創單行本整理（manga_volumes_summary）屬於獨立編輯價值。
	$has_volumes = '' !== trim( (string) get_post_meta( $post_id, 'manga_volumes_summary', true ) );

	$site_count = (int) get_post_meta( $post_id, 'anime_score_site_count', true );

	if ( $site_count <= 0 ) {
		$site_count = (int) get_post_meta( $post_id, 'smacg_site_score_count', true );
	}

	$has_community = $site_count > 0 || get_comments_number( $post_id ) > 0;

	$is_valuable =
		$has_volumes ||
		$synopsis_length >= WXACG_THIN_SYNOPSIS_RICH ||
		( $has_community && $synopsis_length >= WXACG_THIN_EDITORIAL_MIN );

	return ! $is_valuable;
}

/* ============================================================
 * Anime AdSense 資格
 * ============================================================ */

function wxacg_is_anime_adsense_eligible( int $post_id ): bool {
	if (
		$post_id <= 0 ||
		'anime' !== get_post_type( $post_id ) ||
		'publish' !== get_post_status( $post_id )
	) {
		return false;
	}

	if ( wxacg_is_thin_anime_page( $post_id ) ) {
		return false;
	}

	// 優先讀新欄位（AI 批次工具寫入），fallback 舊欄位（共 129 筆舊資料）。
	$editorial_raw  = get_post_meta( $post_id, 'anime_editor_summary', true );
	$editorial_note = is_string( $editorial_raw ) ? $editorial_raw : '';

	if ( '' === trim( $editorial_note ) ) {
		$editorial_note = (string) get_post_meta(
			$post_id,
			'anime_editorial_note',
			true
		);
	}

	$editorial_length = wxacg_text_length( $editorial_note );

	$author_id = (int) get_post_meta(
		$post_id,
		'anime_editorial_author_id',
		true
	);

	$legacy_author = wxacg_plain_text(
		get_post_meta(
			$post_id,
			'anime_editorial_author',
			true
		)
	);

	$has_author = $author_id > 0 || '' !== $legacy_author;

	$post_content_length = wxacg_text_length(
		get_post_field( 'post_content', $post_id )
	);

	$feature_count = smacg_get_anime_articles_count(
		$post_id,
		'feature'
	);

	$review_count = smacg_get_anime_articles_count(
		$post_id,
		'review'
	);

	$eligible = (
		( $editorial_length >= WXACG_ADSENSE_EDITORIAL_MIN && $has_author ) ||
		$post_content_length >= 500 ||
		$feature_count > 0 ||
		$review_count > 0
	);

	return (bool) apply_filters(
		'wxacg/anime_adsense_eligible',
		$eligible,
		$post_id,
		[
			'editorial_length'    => $editorial_length,
			'has_author'          => $has_author,
			'post_content_length' => $post_content_length,
			'feature_count'       => $feature_count,
			'review_count'        => $review_count,
		]
	);
}

/* ============================================================
 * Taxonomy Thin 判斷
 * ============================================================ */

/**
 * 需要執行低文章數 noindex 的 Taxonomy。
 *
 * post_tag：WordPress 文章標籤。
 * anime_studio_tax：製作公司 Taxonomy。
 *
 * @return string[]
 */
function wxacg_get_thin_term_taxonomies(): array {
	$taxonomies = [
		'post_tag',
		'anime_studio_tax',
	];

	$taxonomies = (array) apply_filters(
		'wxacg/thin_term_taxonomies',
		$taxonomies
	);

	return array_values(
		array_filter(
			array_unique(
				array_map(
					'sanitize_key',
					$taxonomies
				)
			)
		)
	);
}

/**
 * 取得分類頁最低收錄文章數。
 */
function wxacg_get_term_index_minimum_count(): int {
	return max(
		1,
		(int) apply_filters(
			'wxacg/term_index_minimum_count',
			3
		)
	);
}

/**
 * 判斷標籤／製作公司分類頁是否低於收錄門檻。
 */
function wxacg_is_thin_term( WP_Term $term ): bool {
	if (
		! in_array(
			$term->taxonomy,
			wxacg_get_thin_term_taxonomies(),
			true
		)
	) {
		return false;
	}

	return (int) $term->count <
		wxacg_get_term_index_minimum_count();
}

/* ============================================================
 * Rank Math robots
 * ============================================================ */

function wxacg_rank_math_robots_filter( array $robots ): array {
	if ( is_paged() ) {
		$robots['index']  = 'noindex';
		$robots['follow'] = 'follow';

		unset(
			$robots['noarchive'],
			$robots['nosnippet']
		);

		return $robots;
	}

	/*
	 * Ultimate Member 的功能頁（登入／註冊／密碼重設／帳號設定／登出）。
	 *
	 * 這些頁面的內容就是一組表單，沒有任何可供搜尋的價值；使用者從
	 * Google 搜到「登入頁」對他毫無意義，他要的是動漫內容。收錄它們
	 * 只會稀釋站台在 Google 眼中的主題——一個動漫資訊站，索引裡卻混著
	 * 登入、註冊、密碼重設。
	 *
	 * 用 follow 而非 nofollow：不收錄這一頁，但讓爬蟲繼續沿著頁面上的
	 * 連結（頁首／頁尾）爬下去，不阻斷路徑。
	 *
	 * account／logout 目前是 302 導向、本來就進不了索引，一併列入是
	 * 保險：日後若導向行為改變，不必再回來補。
	 * 會員目錄 members 已由 wxacg_redirect_um_members_page() 301 導向。
	 */
	if ( is_page() ) {
		$page_id = get_queried_object_id();
		$um_core = $page_id ? get_post_meta( $page_id, '_um_core', true ) : '';

		if ( in_array( $um_core, [ 'login', 'register', 'password-reset', 'account', 'logout' ], true ) ) {
			$robots['index']  = 'noindex';
			$robots['follow'] = 'follow';

			unset(
				$robots['noarchive'],
				$robots['nosnippet']
			);

			return $robots;
		}
	}

	if ( is_singular( 'anime' ) ) {
		$post_id = get_queried_object_id();

		if (
			$post_id > 0 &&
			wxacg_is_thin_anime_page( $post_id )
		) {
			$robots['index']  = 'noindex';
			$robots['follow'] = 'follow';
		}

		return $robots;
	}

	if ( is_singular( 'manga' ) ) {
		$post_id = get_queried_object_id();

		if (
			$post_id > 0 &&
			wxacg_is_thin_manga_page( $post_id )
		) {
			$robots['index']  = 'noindex';
			$robots['follow'] = 'follow';
		}

		return $robots;
	}

	if ( is_tax() || is_category() || is_tag() ) {
		$term = get_queried_object();

		if (
			$term instanceof WP_Term &&
			wxacg_is_thin_term( $term )
		) {
			$robots['index']  = 'noindex';
			$robots['follow'] = 'follow';

			unset(
				$robots['noarchive'],
				$robots['nosnippet']
			);
		}
	}

	return $robots;
}
add_filter(
	'rank_math/frontend/robots',
	'wxacg_rank_math_robots_filter',
	20
);

/* ============================================================
 * Thin Anime Sitemap
 * ============================================================ */

/**
 * thin anime ID 清單。
 *
 * v2.29.0：改為持久 option ＋ 背景 WP-Cron 重算，前台請求「只讀」現成結果、
 * 永不 inline 掃描。避免 Rank Math 產生 sitemap（Googlebot 抓取）時觸發
 * 全站 ~1,300 篇 anime × 重查詢的掃描而逾時，導致 sitemap「無法擷取／錯誤」。
 */
function wxacg_get_thin_anime_ids(): array {
	$stored = get_option( 'wxacg_thin_anime_ids', null );

	if ( is_array( $stored ) ) {
		return array_map( 'intval', $stored );
	}

	// 首次部署：option 尚未建立 → 排背景重算，先回空集合（safe：暫不排除）。
	if ( ! wp_next_scheduled( 'wxacg_thin_rebuild_now' ) ) {
		wp_schedule_single_event( time(), 'wxacg_thin_rebuild_now' );
	}

	return [];
}

/**
 * 背景重算 thin anime 清單並寫入 option（只由 WP-Cron／debounce 事件呼叫）。
 */
function wxacg_rebuild_thin_anime_ids(): array {
	// 併發鎖：避免定時事件與即時事件重疊執行重掃描。
	if ( get_transient( 'wxacg_thin_rebuild_lock' ) ) {
		$existing = get_option( 'wxacg_thin_anime_ids', [] );

		return is_array( $existing ) ? array_map( 'intval', $existing ) : [];
	}

	set_transient( 'wxacg_thin_rebuild_lock', 1, 10 * MINUTE_IN_SECONDS );

	if ( function_exists( 'set_time_limit' ) ) {
		@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	}

	global $wpdb;

	$thin_ids = [];

	// Anime。
	$anime_ids = array_map(
		'intval',
		(array) $wpdb->get_col(
			"SELECT ID
			FROM {$wpdb->posts}
			WHERE post_type = 'anime'
				AND post_status = 'publish'"
		)
	);

	if ( ! empty( $anime_ids ) ) {
		_prime_post_caches( $anime_ids, false, true );

		foreach ( $anime_ids as $anime_id ) {
			if ( wxacg_is_thin_anime_page( $anime_id ) ) {
				$thin_ids[] = $anime_id;
			}
		}
	}

	// Manga（同樣排除薄頁，避免 sitemap 收薄漫畫）。
	$manga_ids = array_map(
		'intval',
		(array) $wpdb->get_col(
			"SELECT ID
			FROM {$wpdb->posts}
			WHERE post_type = 'manga'
				AND post_status = 'publish'"
		)
	);

	if ( ! empty( $manga_ids ) ) {
		_prime_post_caches( $manga_ids, false, true );

		foreach ( $manga_ids as $manga_id ) {
			if ( wxacg_is_thin_manga_page( $manga_id ) ) {
				$thin_ids[] = $manga_id;
			}
		}
	}

	update_option( 'wxacg_thin_anime_ids', $thin_ids, false );
	delete_transient( 'wxacg_thin_rebuild_lock' );

	return $thin_ids;
}
add_action( 'wxacg_thin_rebuild_cron', 'wxacg_rebuild_thin_anime_ids' );
add_action( 'wxacg_thin_rebuild_now', 'wxacg_rebuild_thin_anime_ids' );

/**
 * 內容異動後排一次 debounce 背景重算（5 分鐘內多次異動合併成一次）。
 * 取代舊的「刪 transient → 下次前台請求 inline 重掃描」行為。
 */
function wxacg_schedule_thin_rebuild(): void {
	if ( ! wp_next_scheduled( 'wxacg_thin_rebuild_now' ) ) {
		wp_schedule_single_event(
			time() + 5 * MINUTE_IN_SECONDS,
			'wxacg_thin_rebuild_now'
		);
	}
}

/**
 * 自訂 6 小時排程。
 */
add_filter( 'cron_schedules', function ( $schedules ) {
	if ( ! isset( $schedules['wxacg_6h'] ) ) {
		$schedules['wxacg_6h'] = [
			'interval' => 6 * HOUR_IN_SECONDS,
			'display'  => 'Every 6 hours (weixiaoacg thin rebuild)',
		];
	}

	return $schedules;
} );

/**
 * 註冊每 6 小時的定時重算事件。
 */
add_action( 'init', function () {
	if ( ! wp_next_scheduled( 'wxacg_thin_rebuild_cron' ) ) {
		wp_schedule_event(
			time() + HOUR_IN_SECONDS,
			'wxacg_6h',
			'wxacg_thin_rebuild_cron'
		);
	}
} );

/**
 * 停用主題時清除排程，避免殘留 cron。
 */
add_action( 'switch_theme', function () {
	wp_clear_scheduled_hook( 'wxacg_thin_rebuild_cron' );
	wp_clear_scheduled_hook( 'wxacg_thin_rebuild_now' );
} );

function wxacg_rank_math_exclude_thin_anime( $exclude_ids ): array {
	return array_values(
		array_unique(
			array_merge(
				array_map( 'intval', (array) $exclude_ids ),
				wxacg_get_thin_anime_ids()
			)
		)
	);
}
add_filter(
	'rank_math/sitemap/exclude_posts',
	'wxacg_rank_math_exclude_thin_anime'
);

/*
 * Taxonomy Sitemap 排除。
 * $type 可為 user、post、term。
 */
function wxacg_rank_math_filter_sitemap_entry(
	$url,
	string $type,
	$object
) {
	if (
		'term' === $type &&
		$object instanceof WP_Term &&
		wxacg_is_thin_term( $object )
	) {
		return false;
	}

	return $url;
}
add_filter(
	'rank_math/sitemap/entry',
	'wxacg_rank_math_filter_sitemap_entry',
	10,
	3
);

/* ============================================================
 * Thin Cache 清除
 * ============================================================ */

function wxacg_clear_thin_anime_cache(): void {
	wxacg_schedule_thin_rebuild();
}

add_action( 'save_post_anime', 'wxacg_clear_thin_anime_cache', 50 );
add_action( 'save_post_manga', 'wxacg_clear_thin_anime_cache', 50 );
add_action( 'save_post_post', 'wxacg_clear_thin_anime_cache', 50 );
add_action( 'wp_insert_comment', 'wxacg_clear_thin_anime_cache', 50 );
add_action( 'edit_comment', 'wxacg_clear_thin_anime_cache', 50 );
add_action( 'deleted_comment', 'wxacg_clear_thin_anime_cache', 50 );
add_action( 'trashed_comment', 'wxacg_clear_thin_anime_cache', 50 );
add_action( 'untrashed_comment', 'wxacg_clear_thin_anime_cache', 50 );
add_action( 'transition_comment_status', 'wxacg_clear_thin_anime_cache', 50 );
add_action( 'acf/save_post', 'wxacg_clear_thin_anime_cache', 100 );

function wxacg_clear_term_quality_cache(): void {
	wxacg_schedule_thin_rebuild();
}
add_action( 'created_term', 'wxacg_clear_term_quality_cache' );
add_action( 'edited_term', 'wxacg_clear_term_quality_cache' );
add_action( 'delete_term', 'wxacg_clear_term_quality_cache' );
add_action( 'set_object_terms', 'wxacg_clear_term_quality_cache' );

/* ============================================================
 * AdSense 統一資格
 * ============================================================ */

function wxacg_is_virtual_entity_page(): bool {
	$character_id = absint( get_query_var( 'asa_character_id' ) );
	$person_id    = absint( get_query_var( 'asa_person_id' ) );

	return $character_id > 0 || $person_id > 0;
}

function wxacg_can_load_adsense(): bool {
	if (
		is_admin() ||
		wp_doing_ajax() ||
		is_user_logged_in() ||
		is_preview() ||
		is_feed() ||
		is_search() ||
		is_404() ||
		is_paged()
	) {
		return false;
	}

	if ( is_singular( 'anime' ) ) {
		$post_id = get_queried_object_id();

		return (
			$post_id > 0 &&
			wxacg_is_anime_adsense_eligible( $post_id )
		);
	}

	if ( wxacg_is_virtual_entity_page() ) {
		if ( ! array_key_exists( 'asa_page_is_thin', $GLOBALS ) ) {
			return false;
		}

		return empty( $GLOBALS['asa_page_is_thin'] );
	}

	if ( is_tax() || is_category() || is_tag() ) {
		$term = get_queried_object();

		if (
			$term instanceof WP_Term &&
			wxacg_is_thin_term( $term )
		) {
			return false;
		}
	}

	return (bool) apply_filters(
		'wxacg/can_load_adsense',
		true
	);
}

function wxacg_output_adsense_script(): void {
	if ( ! wxacg_can_load_adsense() ) {
		return;
	}

	printf(
		'<script async src="%s" crossorigin="anonymous"></script>' . "\n",
		esc_url(
			'https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client=' .
			WXACG_ADSENSE_CLIENT
		)
	);
}
add_action( 'wp_head', 'wxacg_output_adsense_script', 5 );

/* ============================================================
 * AdBlock 提示
 * ============================================================ */

function wxacg_output_adblock_notice(): void {
	if ( ! wxacg_can_load_adsense() ) {
		return;
	}

	$adcheck_url = content_url( 'uploads/adcheck/adsbygoogle.js' );
	?>
	<div
		id="adblock-notice"
		role="dialog"
		aria-live="polite"
		aria-label="廣告封鎖提示"
		style="display:none;position:fixed;bottom:0;left:0;right:0;background:linear-gradient(135deg,#0f0c29,#302b63,#24243e);color:#fff;padding:20px 32px;z-index:99999;box-shadow:0 -4px 20px rgba(0,0,0,.6);"
	>
		<div style="max-width:800px;margin:0 auto;display:flex;align-items:center;gap:20px;flex-wrap:wrap;justify-content:center;text-align:center;">
			<div aria-hidden="true" style="font-size:36px;">🌸</div>

			<div style="flex:1;min-width:260px;">
				<div style="font-size:17px;font-weight:bold;margin-bottom:6px;">
					嗨，我們偵測到您使用了廣告封鎖器
				</div>

				<div style="font-size:13px;line-height:1.8;color:#ccc;">
					微笑動漫由熱愛動漫的編輯團隊經營，持續整理番劇資訊與播出資料。<br>
					我們不使用彈窗、插頁或全螢幕廣告，只保留較不干擾閱讀的廣告。<br>
					若您願意支持本站，請將
					<strong style="color:#7ec8e3;">weixiaoacg.com</strong>
					加入白名單。
				</div>
			</div>

			<div style="display:flex;flex-direction:column;gap:8px;">
				<button
					type="button"
					class="wxacg-close-adblock"
					style="padding:10px 22px;background:linear-gradient(135deg,#e94560,#c0392b);border:none;border-radius:6px;color:#fff;cursor:pointer;font-size:14px;font-weight:bold;white-space:nowrap;"
				>
					❤️ 關閉提示
				</button>

				<button
					type="button"
					class="wxacg-close-adblock"
					style="padding:10px 22px;background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.3);border-radius:6px;color:#ccc;cursor:pointer;font-size:13px;white-space:nowrap;"
				>
					暫時關閉
				</button>
			</div>
		</div>
	</div>

	<script>
	(function () {
		'use strict';

		var notice = document.getElementById('adblock-notice');

		if (!notice) {
			return;
		}

		try {
			if (sessionStorage.getItem('wxacg_adblock_closed') === '1') {
				return;
			}
		} catch (e) {}

		document.querySelectorAll('.wxacg-close-adblock').forEach(function (button) {
			button.addEventListener('click', function () {
				notice.style.display = 'none';

				try {
					sessionStorage.setItem('wxacg_adblock_closed', '1');
				} catch (e) {}
			});
		});

		var script = document.createElement('script');
		script.src = <?php echo wp_json_encode( esc_url_raw( $adcheck_url ) ); ?> + '?t=' + Date.now();
		script.async = true;

		script.onerror = function () {
			notice.style.display = 'block';
		};

		document.head.appendChild(script);
	})();
	</script>
	<?php
}
add_action( 'wp_footer', 'wxacg_output_adblock_notice', 90 );

/* ============================================================
 * 隨機動漫
 * ============================================================ */

function wxacg_random_anime_redirect(): void {
	// 注意：不套用 wxacg_get_thin_anime_ids() 排除清單。
	// 那份清單是給「要不要顯示 AdSense 廣告 / SEO noindex」用的，
	// 跟「值不值得被隨機抽到」是兩回事；套用會讓可抽池子被收得過窄
	// （曾經只剩 32 / 1374 篇，導致一直抽到類似結果）。
	$args = [
		'post_type'        => 'anime',
		'post_status'      => 'publish',
		'posts_per_page'   => 1,
		'orderby'          => 'rand',
		'fields'           => 'ids',
		'no_found_rows'    => true,
		'suppress_filters' => true,
	];

	$ids = get_posts( $args );

	if ( ! empty( $ids[0] ) ) {
		// 給成就系統掛勾（初次抽動漫），只在真的抽到動漫時觸發。
		do_action( 'wxacg_random_anime_used', get_current_user_id() );

		wp_safe_redirect(
			get_permalink( (int) $ids[0] ),
			302
		);
		exit;
	}

	wp_safe_redirect( home_url( '/anime/' ), 302 );
	exit;
}

add_action(
	'wp_ajax_wxacg_random_anime',
	'wxacg_random_anime_redirect'
);
add_action(
	'wp_ajax_nopriv_wxacg_random_anime',
	'wxacg_random_anime_redirect'
);

function wxacg_legacy_random_anime_redirect(): void {
	if (
		is_admin() ||
		empty( $_GET['wxacg_random_anime'] )
	) {
		return;
	}

	wxacg_random_anime_redirect();
}
add_action(
	'template_redirect',
	'wxacg_legacy_random_anime_redirect'
);

/* ============================================================
 * wpForo 的會員連結 → 導向站上的公開檔案頁 /u/{nicename}/
 * ============================================================
 * 論壇裡點使用者名稱會連到 /participant/{nicename}/（wpForo 自己的
 * 會員資料頁），但站上另有 /u/{nicename}/ 的公開檔案，內容豐富得多
 * （追番清單、評分、成就、追蹤關係）。同一個人有兩個檔案頁，使用者
 * 會困惑，SEO 上也是兩份高度相似的頁面在互相稀釋。
 *
 * 用 wpForo 自己的 wpforo_member_profile_url filter 從源頭改寫，而不是
 * 用 JS 事後替換 DOM 上的連結——後者對爬蟲無效，使用者按右鍵複製連結
 * 拿到的也還是舊網址。
 *
 * 只改 profile 主頁：wpForo 的其他分頁（activity、subscriptions 等）
 * 是論壇專屬功能，站上的公開檔案沒有對應內容，一併改過去會變成死連結。
 */
function wxacg_wpforo_profile_url_to_public( $url, $user, $template = 'profile' ) {
	if ( $template !== 'profile' ) {
		return $url;
	}

	$nicename = '';
	if ( is_array( $user ) && ! empty( $user['user_nicename'] ) ) {
		$nicename = (string) $user['user_nicename'];
	} elseif ( is_object( $user ) && ! empty( $user->user_nicename ) ) {
		$nicename = (string) $user->user_nicename;
	}

	if ( $nicename === '' ) {
		return $url;
	}

	$slug = defined( 'SMACG_PUBLIC_PROFILE_SLUG' ) ? SMACG_PUBLIC_PROFILE_SLUG : 'u';

	return home_url( '/' . $slug . '/' . rawurlencode( $nicename ) . '/' );
}
add_filter( 'wpforo_member_profile_url', 'wxacg_wpforo_profile_url_to_public', 10, 3 );

/* ============================================================
 * wpForo 改用內建的深色配色（wpf-dark）
 *
 * ★ 為什麼要這樣做
 *   wpForo 的配色由「設定 → Styles → Color Style」決定，站上目前是
 *   default（淺色）。wpForo 會把它輸出成 #wpforo-wrap 的 class：
 *       functions-template.php:352  'wpf-' . wpforo_setting('styles','color_style')
 *   所以現在掛的是 wpf-default。
 *
 *   問題是我們的網站是深色的，於是 wpforo-override.css 一路用
 *   !important 去蓋淺色佈景。實測線上仍有 149 條 wpForo 規則帶著
 *   亮色底沒被蓋到 —— 蓋不完的，因為那是在跟整套淺色配色打架。
 *   典型漏網的例子：
 *       .wpf-tools      linear-gradient(90deg, #eee, #fff)  ← 點開「工具」就是一塊白
 *       .wpf-acp-*      #F5F5F5                              ← 發文面板
 *       .wpf-popover    #f5f5f5
 *
 *   而 wpForo 其實內建了深色配色，共 229 條 .wpf-dark 規則，我們完全
 *   沒用到。切過去之後那 149 個破口由 wpForo 自己補，我們的 override
 *   只要負責品牌色與毛玻璃。
 *
 * ★ 為什麼用 filter 而不是去後台改設定
 *   後台改設定是寫進資料庫，不會進版控，之後也看不出是誰改的、為什麼改。
 *   用 filter 改成程式碼，走一般的 git push 部署，要還原就是移除這段。
 *
 * ★ 注意
 *   wpforo_get_body_classes 同時餵給 #wpforo-wrap 的 class 與 <body> 的
 *   body_class（functions-template.php:368 與 372）。這是預期行為 ——
 *   wpForo 有 `body.wpf-dark #wpforo-dialog ...` 這類規則要靠 body 上的
 *   class 才會生效。
 * ============================================================ */
function wxacg_wpforo_force_dark_color_style( $classes ) {
	if ( ! is_array( $classes ) ) {
		return $classes;
	}

	// 移除 wpForo 依設定產生的配色 class（wpf-default／wpf-grey／wpf-red …），
	// 只清掉配色那一種，其餘 wpf-* 前綴的 class（wpf-auth、wpf-guest、
	// wpf-theme-2026、wpf-boardid-0 …）都必須留著。
	$color_styles = array( 'default', 'red', 'green', 'orange', 'grey', 'dark' );
	foreach ( $classes as $i => $class ) {
		if ( in_array( $class, array_map( function ( $c ) { return 'wpf-' . $c; }, $color_styles ), true ) ) {
			unset( $classes[ $i ] );
		}
	}

	$classes[] = 'wpf-dark';

	return array_values( $classes );
}
add_filter( 'wpforo_get_body_classes', 'wxacg_wpforo_force_dark_color_style' );

/* ============================================================
 * Ultimate Member 的 /members/ 目錄頁 → 導向會員排行榜
 * ============================================================
 * 該頁內容是 [ultimatemember form_id="21"]，實際輸出是一段英文的
 * 空結果（「We are sorry. We cannot find any users…」），且 Rank Math
 * 判定為 index, follow——一個可被 Google 收錄、內容空白又語言不符的
 * 孤兒頁（站內沒有任何連結指向它，也不在 sitemap 裡）。
 *
 * 用 301 導向既有的會員排行榜，而不是直接刪頁：
 *   - 已被收錄的網址若變 404，累積的權重會直接丟掉
 *   - UM 內部若有連結指向會員目錄，導向排行榜是語意最接近的去處
 *
 * 以 _um_core meta 判斷而非寫死頁面 ID：UM 重建核心頁時 ID 會變，
 * 寫死會在某次重建後靜默失效。
 */
function wxacg_redirect_um_members_page(): void {
	if ( is_admin() || ! is_page() ) {
		return;
	}

	$page_id = get_queried_object_id();
	if ( ! $page_id ) {
		return;
	}

	if ( get_post_meta( $page_id, '_um_core', true ) !== 'members' ) {
		return;
	}

	wp_safe_redirect( home_url( '/ranking-users/' ), 301 );
	exit;
}
add_action( 'template_redirect', 'wxacg_redirect_um_members_page' );

/* ============================================================
 * 熱門標籤
 * ============================================================ */

function smacg_register_popular_tags_page(): void {
	add_theme_page(
		'熱門標籤',
		'熱門標籤',
		'manage_options',
		'smacg-popular-tags',
		'smacg_popular_tags_page'
	);
}
add_action( 'admin_menu', 'smacg_register_popular_tags_page' );

function smacg_popular_tags_page(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( '權限不足。', 'weixiaoacg' ) );
	}

	if (
		isset( $_POST['smacg_popular_tags_nonce'] ) &&
		wp_verify_nonce(
			sanitize_text_field(
				wp_unslash( $_POST['smacg_popular_tags_nonce'] )
			),
			'smacg_save_popular_tags'
		)
	) {
		$raw = isset( $_POST['smacg_popular_tags'] )
			? sanitize_textarea_field(
				wp_unslash( $_POST['smacg_popular_tags'] )
			)
			: '';

		$lines = array_values(
			array_filter(
				array_map(
					'trim',
					preg_split( '/\R/u', $raw )
				)
			)
		);

		update_option( 'smacg_popular_tags', $lines );

		echo '<div class="notice notice-success"><p>已儲存。</p></div>';
	}

	$current = get_option( 'smacg_popular_tags', [] );
	$value   = is_array( $current )
		? implode( "\n", $current )
		: '';
	?>
	<div class="wrap">
		<h1>熱門標籤管理</h1>
		<p>每行輸入一個標籤名稱或 slug。</p>

		<form method="post">
			<?php
			wp_nonce_field(
				'smacg_save_popular_tags',
				'smacg_popular_tags_nonce'
			);
			?>

			<textarea
				name="smacg_popular_tags"
				rows="20"
				cols="50"
				style="width:400px;font-family:monospace;"
			><?php echo esc_textarea( $value ); ?></textarea>

			<?php submit_button( '儲存熱門標籤' ); ?>
		</form>

		<hr>

		<h2>站內標籤使用量前 30 名</h2>

		<?php
		$stats = get_tags(
			[
				'orderby'    => 'count',
				'order'      => 'DESC',
				'number'     => 30,
				'hide_empty' => true,
			]
		);

		if ( $stats ) {
			echo '<ul style="columns:3;">';

			foreach ( $stats as $tag ) {
				printf(
					'<li><code>%s</code>（%d 篇）</li>',
					esc_html( $tag->name ),
					(int) $tag->count
				);
			}

			echo '</ul>';
		}
		?>
	</div>
	<?php
}

/* ============================================================
 * Email 驗證
 * ============================================================ */

function smacg_user_needs_email_verify( int $user_id ): bool {
	if ( $user_id <= 0 ) {
		return false;
	}

	if (
		1 === (int) get_user_meta(
			$user_id,
			'smacg_email_verified',
			true
		)
	) {
		return false;
	}

	$token = (string) get_user_meta(
		$user_id,
		'smacg_email_verify_token',
		true
	);

	$register_time = (int) get_user_meta(
		$user_id,
		'smacg_register_time',
		true
	);

	if ( '' === $token && $register_time <= 0 ) {
		update_user_meta(
			$user_id,
			'smacg_email_verified',
			1
		);

		return false;
	}

	return true;
}

function smacg_push_email_verify_notification( int $user_id ): void {
	if (
		! function_exists( 'wxacg_create_notification' ) ||
		! smacg_user_needs_email_verify( $user_id )
	) {
		return;
	}

	$transient_key = 'smacg_verify_notif_' . $user_id;

	if ( get_transient( $transient_key ) ) {
		return;
	}

	$user = get_userdata( $user_id );

	if ( ! $user instanceof WP_User ) {
		return;
	}

	wxacg_create_notification(
		[
			'user_id'     => $user_id,
			'type'        => 'system',
			'object_type' => 'user',
			'object_id'   => $user_id,
			'data'        => [
				'kind'    => 'email_verify',
				'title'   => '請驗證您的電子郵件',
				'excerpt' => '完成驗證即可使用完整會員功能。驗證信已寄至 ' .
					$user->user_email,
				'url'     => home_url(
					'/mc/?action=resend_verify'
				),
				'icon'    => '📧',
			],
			'force'       => true,
		]
	);

	set_transient(
		$transient_key,
		1,
		DAY_IN_SECONDS
	);
}

function smacg_schedule_verify_notification( int $user_id ): void {
	if (
		! wp_next_scheduled(
			'smacg_delayed_verify_notif',
			[ $user_id ]
		)
	) {
		wp_schedule_single_event(
			time() + 2,
			'smacg_delayed_verify_notif',
			[ $user_id ]
		);
	}
}
add_action(
	'user_register',
	'smacg_schedule_verify_notification',
	20
);

function smacg_login_verify_notification(
	string $username,
	WP_User $user
): void {
	smacg_push_email_verify_notification( (int) $user->ID );
}
add_action(
	'wp_login',
	'smacg_login_verify_notification',
	20,
	2
);

add_action(
	'smacg_delayed_verify_notif',
	'smacg_push_email_verify_notification'
);

function smacg_resend_verify_footer_script(): void {
	if ( ! is_user_logged_in() ) {
		return;
	}

	$action = isset( $_GET['action'] )
		? sanitize_key( wp_unslash( $_GET['action'] ) )
		: '';

	if ( 'resend_verify' !== $action ) {
		return;
	}

	$user_id = get_current_user_id();

	if ( ! smacg_user_needs_email_verify( $user_id ) ) {
		return;
	}

	$nonce    = wp_create_nonce( 'smacg_resend_verify' );
	$ajax_url = admin_url( 'admin-ajax.php' );
	$mc_url   = home_url( '/mc/' );
	?>
	<script>
	(function () {
		'use strict';

		var flagKey = 'smacg_resend_done_<?php echo (int) $user_id; ?>';

		try {
			if (sessionStorage.getItem(flagKey) === '1') {
				history.replaceState({}, '', <?php echo wp_json_encode( $mc_url ); ?>);
				return;
			}

			sessionStorage.setItem(flagKey, '1');
		} catch (e) {}

		history.replaceState({}, '', <?php echo wp_json_encode( $mc_url ); ?>);

		var data = new FormData();
		data.append('action', 'smacg_resend_verify_email');
		data.append('nonce', <?php echo wp_json_encode( $nonce ); ?>);

		fetch(
			<?php echo wp_json_encode( $ajax_url ); ?>,
			{
				method: 'POST',
				body: data,
				credentials: 'same-origin'
			}
		)
		.then(function (response) {
			return response.json();
		})
		.then(function (response) {
			var message = response &&
				response.data &&
				response.data.msg
				? response.data.msg
				: '操作完成';

			alert(
				(response && response.success ? '✅ ' : '❌ ') +
				message +
				'\n（也請檢查垃圾郵件夾）'
			);
		})
		.catch(function () {
			alert('❌ 網路錯誤，請稍後再試');
		});
	})();
	</script>
	<?php
}
add_action(
	'wp_footer',
	'smacg_resend_verify_footer_script',
	99
);

/* ============================================================
 * Footer CSS
 * ============================================================ */

function wxacg_enqueue_footer_styles(): void {
	$relative = '/assets/css/footer.css';
	$absolute = weixiaoacg_THEME_DIR . $relative;

	if ( ! file_exists( $absolute ) ) {
		return;
	}

	wp_enqueue_style(
		'smacg-footer',
		weixiaoacg_THEME_URL . $relative,
		[],
		(string) filemtime( $absolute )
	);
}
add_action(
	'wp_enqueue_scripts',
	'wxacg_enqueue_footer_styles',
	20
);

/* ============================================================
 * 登出
 * ============================================================ */

function smacg_ajax_get_logout_url(): void {
	if ( ! is_user_logged_in() ) {
		wp_send_json_error(
			[ 'message' => '尚未登入' ],
			401
		);
	}

	$url = html_entity_decode(
		wp_logout_url( home_url( '/' ) ),
		ENT_QUOTES,
		'UTF-8'
	);

	wp_send_json_success( [ 'url' => $url ] );
}
add_action(
	'wp_ajax_smacg_get_logout_url',
	'smacg_ajax_get_logout_url'
);

function smacg_logout_footer_script(): void {
	if ( ! is_user_logged_in() ) {
		return;
	}
	?>
	<script>
	(function () {
		'use strict';

		var link = document.getElementById('smacg-logout-link');

		if (!link) {
			return;
		}

		link.addEventListener('click', function (event) {
			event.preventDefault();

			fetch(
				<?php echo wp_json_encode(
					admin_url( 'admin-ajax.php?action=smacg_get_logout_url' )
				); ?>,
				{
					credentials: 'same-origin'
				}
			)
			.then(function (response) {
				return response.json();
			})
			.then(function (response) {
				if (
					response &&
					response.success &&
					response.data &&
					response.data.url
				) {
					window.location.href = response.data.url;
					return;
				}

				window.location.href = link.href;
			})
			.catch(function () {
				window.location.href = link.href;
			});
		});
	})();
	</script>
	<?php
}
add_action(
	'wp_footer',
	'smacg_logout_footer_script',
	100
);

/* ============================================================
 * 登入使用者停用 PWA Service Worker
 * ============================================================ */

function wxacg_disable_pwa_for_logged_in_users(): void {
	if ( ! is_user_logged_in() ) {
		return;
	}

	wp_dequeue_script( 'pwa-for-wp' );
	wp_deregister_script( 'pwa-for-wp' );
}
add_action(
	'wp_enqueue_scripts',
	'wxacg_disable_pwa_for_logged_in_users',
	99
);

function wxacg_unregister_service_workers(): void {
	if ( ! is_user_logged_in() ) {
		return;
	}
	?>
	<script>
	if ('serviceWorker' in navigator) {
		navigator.serviceWorker.getRegistrations().then(function (registrations) {
			registrations.forEach(function (registration) {
				registration.unregister();
			});
		});
	}
	</script>
	<?php
}
add_action(
	'wp_footer',
	'wxacg_unregister_service_workers',
	100
);

/* ============================================================
 * 關閉 wp-login.php 註冊入口
 * ============================================================ */

function wxacg_disable_wp_login_registration(): void {
	if (
		'GET' !== strtoupper(
			(string) ( $_SERVER['REQUEST_METHOD'] ?? 'GET' )
		)
	) {
		return;
	}

	$action = isset( $_REQUEST['action'] )
		? sanitize_key( wp_unslash( $_REQUEST['action'] ) )
		: '';

	if ( 'register' === $action ) {
		wp_safe_redirect( home_url( '/' ) );
		exit;
	}
}
add_action(
	'login_init',
	'wxacg_disable_wp_login_registration'
);

/* ============================================================
 * wpDiscuz 使用者連結
 * ============================================================ */

function wxacg_wpdiscuz_user_link_script(): void {
	if ( ! is_singular() ) {
		return;
	}
	?>
	<script>
	(function () {
		'use strict';

		function rewriteUserLinks(root) {
			var scope = root || document;
			var links = scope.querySelectorAll
				? scope.querySelectorAll('a[href*="/user/"]')
				: [];

			links.forEach(function (link) {
				if (!link.closest('#wpdcom, .wpd-comment, .wpdiscuz-comment')) {
					return;
				}

				var href = link.getAttribute('href') || '';
				var match = href.match(/\/user\/([^\/?#]+)\/?/);

				if (match && match[1]) {
					link.setAttribute(
						'href',
						'/u/' + encodeURIComponent(
							decodeURIComponent(match[1])
						) + '/'
					);
				}
			});
		}

		document.addEventListener('DOMContentLoaded', function () {
			rewriteUserLinks(document);
		});

		var target = document.getElementById('wpdcom') || document.body;

		if (!target || typeof MutationObserver === 'undefined') {
			return;
		}

		var observer = new MutationObserver(function (mutations) {
			mutations.forEach(function (mutation) {
				mutation.addedNodes.forEach(function (node) {
					if (node.nodeType === 1) {
						rewriteUserLinks(node);
					}
				});
			});
		});

		observer.observe(
			target,
			{
				childList: true,
				subtree: true
			}
		);
	})();
	</script>
	<?php
}
add_action(
	'wp_footer',
	'wxacg_wpdiscuz_user_link_script',
	100
);


/* ============================================================
 * Nextend Social Login 使用者名稱
 * ============================================================ */

function wxacg_nsl_registration_username(
	array $user_data,
	$provider
): array {
	$email = isset( $user_data['email'] )
		? sanitize_email( $user_data['email'] )
		: '';

	$base = '';

	if ( $email && false !== strpos( $email, '@' ) ) {
		$email_parts = explode( '@', $email );
		$base        = sanitize_user(
			$email_parts[0],
			true
		);
	} elseif (
		is_object( $provider ) &&
		method_exists( $provider, 'getAuthUserData' )
	) {
		$base = sanitize_user(
			(string) $provider->getAuthUserData( 'name' ),
			true
		);
	}

	$base = strtolower( trim( $base ) );

	if ( '' === $base ) {
		return $user_data;
	}

	$username = $base;
	$index    = 1;

	while ( username_exists( $username ) ) {
		$username = $base . $index;
		$index++;

		if ( $index > 9999 ) {
			$username = $base . wp_rand( 10000, 99999 );
			break;
		}
	}

	$user_data['username'] = $username;

	return $user_data;
}
add_filter(
	'nsl_registration_user_data',
	'wxacg_nsl_registration_username',
	10,
	2
);

/* ============================================================
 * Rank Math Anime Description
 * ============================================================ */

function wxacg_anime_meta_description( $description ) {
	if ( ! is_singular( 'anime' ) ) {
		return $description;
	}

	if (
		is_string( $description ) &&
		'' !== trim( $description )
	) {
		return $description;
	}

	$post_id = get_queried_object_id();

	if ( $post_id <= 0 ) {
		return $description;
	}

	$synopsis = (string) get_post_meta(
		$post_id,
		'anime_synopsis_chinese',
		true
	);

	if ( '' === trim( $synopsis ) ) {
		$synopsis = (string) get_post_meta(
			$post_id,
			'anime_synopsis',
			true
		);
	}

	$synopsis = wxacg_plain_text( $synopsis );

	if ( '' === $synopsis ) {
		return $description;
	}

	$limit = 155;

	if (
		function_exists( 'mb_strlen' ) &&
		mb_strlen( $synopsis, 'UTF-8' ) > $limit
	) {
		return mb_substr(
			$synopsis,
			0,
			$limit,
			'UTF-8'
		) . '…';
	}

	if (
		! function_exists( 'mb_strlen' ) &&
		strlen( $synopsis ) > $limit
	) {
		return substr( $synopsis, 0, $limit ) . '…';
	}

	return $synopsis;
}
add_filter(
	'rank_math/frontend/description',
	'wxacg_anime_meta_description',
	20
);

/* ============================================================
 * STAFF Role
 * ============================================================ */

function wxacg_staff_role( $role ): string {
	static $map = [
		'脚本'       => '腳本',
		'指令碼'     => '腳本',
		'主题歌演出' => '主題歌演出',
		'音乐'       => '音樂',
		'动画制作'   => '動畫製作',
		'人物设定'   => '人物設定',
		'人物设计'   => '人物設定',
		'导演'       => '監督',
		'導演'       => '監督',
		'监督'       => '監督',
		'主题歌作曲' => '主題歌作曲',
		'音响监督'   => '音響監督',
		'主题歌作词' => '主題歌作詞',
		'系列构成'   => '系列構成',
		'制作'       => '製作',
	];

	$role = trim( (string) $role );

	if ( '' === $role ) {
		return '';
	}

	if ( isset( $map[ $role ] ) ) {
		return $map[ $role ];
	}

	if (
		class_exists( 'Anime_Sync_CN_Converter' ) &&
		method_exists(
			'Anime_Sync_CN_Converter',
			'static_convert'
		)
	) {
		$role = Anime_Sync_CN_Converter::static_convert(
			$role
		);

		$role = str_replace(
			'指令碼',
			'腳本',
			$role
		);
	}

	return $role;
}

/* ============================================================
 * 多語言搜尋 Blob
 * ============================================================ */

function wxacg_search_title_keys(): array {
	return [
		'anime_title_chinese',
		'anime_title_simplified',
		'anime_title_native',
		'anime_title_romaji',
		'anime_title_english',
	];
}

function wxacg_build_search_blob( int $post_id ): string {
	if (
		$post_id <= 0 ||
		! in_array(
			get_post_type( $post_id ),
			[ 'anime', 'manga' ],
			true
		)
	) {
		return '';
	}

	$parts = [];

	foreach ( wxacg_search_title_keys() as $key ) {
		$value = get_post_meta(
			$post_id,
			$key,
			true
		);

		if ( is_scalar( $value ) ) {
			$value = trim( (string) $value );

			if ( '' !== $value ) {
				$parts[] = $value;
			}
		}
	}

	$title = trim(
		(string) get_post_field(
			'post_title',
			$post_id
		)
	);

	if ( '' !== $title ) {
		$parts[] = $title;
	}

	$parts = array_values(
		array_unique( $parts )
	);

	if ( empty( $parts ) ) {
		delete_post_meta(
			$post_id,
			'_smacg_search_blob'
		);

		return '';
	}

	$blob = implode( ' | ', $parts );

	update_post_meta(
		$post_id,
		'_smacg_search_blob',
		$blob
	);

	return $blob;
}

function smacg_acf_rebuild_search_blob( $post_id ): void {
	if ( ! is_numeric( $post_id ) ) {
		return;
	}

	$post_id = (int) $post_id;

	if (
		! in_array(
			get_post_type( $post_id ),
			[ 'anime', 'manga' ],
			true
		)
	) {
		return;
	}

	wxacg_build_search_blob( $post_id );
}
add_action(
	'acf/save_post',
	'smacg_acf_rebuild_search_blob',
	30
);

function smacg_save_post_rebuild_search_blob(
	int $post_id,
	WP_Post $post
): void {
	if (
		wp_is_post_revision( $post_id ) ||
		wp_is_post_autosave( $post_id ) ||
		! in_array(
			$post->post_type,
			[ 'anime', 'manga' ],
			true
		)
	) {
		return;
	}

	wxacg_build_search_blob( $post_id );
}
add_action(
	'save_post_anime',
	'smacg_save_post_rebuild_search_blob',
	30,
	2
);
add_action(
	'save_post_manga',
	'smacg_save_post_rebuild_search_blob',
	30,
	2
);

function smacg_add_blob_to_search(
	$search,
	WP_Query $query
) {
	global $wpdb;

	if (
		is_admin() ||
		! $query->is_main_query() ||
		! $query->is_search() ||
		'' === trim( (string) $search )
	) {
		return $search;
	}

	$term = trim(
		(string) $query->get( 's' )
	);

	if ( '' === $term ) {
		return $search;
	}

	$like = '%' . $wpdb->esc_like( $term ) . '%';

	$blob_clause = $wpdb->prepare(
		"{$wpdb->posts}.ID IN (
			SELECT post_id
			FROM {$wpdb->postmeta}
			WHERE meta_key = '_smacg_search_blob'
			AND meta_value LIKE %s
		)",
		$like
	);

	$pattern = '/\(\(\(' .
		preg_quote( $wpdb->posts, '/' ) .
		'\.post_title\s+LIKE.*?\)\)\)/s';

	$replaced = preg_replace_callback(
		$pattern,
		static function ( array $matches ) use ( $blob_clause ): string {
			return '( ' .
				$matches[0] .
				' OR ' .
				$blob_clause .
				' )';
		},
		(string) $search,
		1,
		$count
	);

	if ( $count > 0 && is_string( $replaced ) ) {
		return $replaced;
	}

	return $search;
}
add_filter(
	'posts_search',
	'smacg_add_blob_to_search',
	10,
	2
);

function smacg_enable_blob_for_ajax_search(): void {
	add_filter(
		'posts_search',
		'smacg_ajax_search_blob_clause',
		10,
		2
	);
}
add_action(
	'wp_ajax_weixiaoacg_search',
	'smacg_enable_blob_for_ajax_search',
	1
);
add_action(
	'wp_ajax_nopriv_weixiaoacg_search',
	'smacg_enable_blob_for_ajax_search',
	1
);

function smacg_ajax_search_blob_clause(
	$search,
	WP_Query $query
) {
	global $wpdb;

	$term = trim(
		(string) $query->get( 's' )
	);

	if (
		'' === $term ||
		'' === trim( (string) $search )
	) {
		return $search;
	}

	$like = '%' . $wpdb->esc_like( $term ) . '%';

	$blob_clause = $wpdb->prepare(
		"{$wpdb->posts}.ID IN (
			SELECT post_id
			FROM {$wpdb->postmeta}
			WHERE meta_key = '_smacg_search_blob'
			AND meta_value LIKE %s
		)",
		$like
	);

	$pattern = '/\(\(\(' .
		preg_quote( $wpdb->posts, '/' ) .
		'\.post_title\s+LIKE.*?\)\)\)/s';

	$result = preg_replace_callback(
		$pattern,
		static function ( array $matches ) use ( $blob_clause ): string {
			return '( ' .
				$matches[0] .
				' OR ' .
				$blob_clause .
				' )';
		},
		(string) $search,
		1
	);

	return is_string( $result ) ? $result : $search;
}

/* ============================================================
 * 搜尋索引回填頁
 * ============================================================ */

function smacg_register_rebuild_search_blob_page(): void {
	add_theme_page(
		'回填搜尋索引',
		'回填搜尋索引',
		'manage_options',
		'smacg-rebuild-search-blob',
		'smacg_rebuild_search_blob_page'
	);
}
add_action(
	'admin_menu',
	'smacg_register_rebuild_search_blob_page'
);

function smacg_rebuild_search_blob_page(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( '權限不足。', 'weixiaoacg' ) );
	}

	if (
		isset( $_POST['smacg_rebuild_blob_nonce'] ) &&
		wp_verify_nonce(
			sanitize_text_field(
				wp_unslash( $_POST['smacg_rebuild_blob_nonce'] )
			),
			'smacg_rebuild_blob'
		)
	) {
		$ids = get_posts(
			[
				'post_type'        => [ 'anime', 'manga' ],
				'post_status'      => 'any',
				'posts_per_page'   => -1,
				'fields'           => 'ids',
				'no_found_rows'    => true,
				'suppress_filters' => true,
			]
		);

		$done = 0;

		foreach ( $ids as $post_id ) {
			wxacg_build_search_blob(
				(int) $post_id
			);

			$done++;
		}

		echo '<div class="notice notice-success"><p>已回填 <strong>';
		echo (int) $done;
		echo '</strong> 筆搜尋索引。</p></div>';
	}
	?>
	<div class="wrap">
		<h1>回填搜尋索引</h1>

		<p>
			重建 Anime 與 Manga 多語言標題搜尋索引。
		</p>

		<form method="post">
			<?php
			wp_nonce_field(
				'smacg_rebuild_blob',
				'smacg_rebuild_blob_nonce'
			);

			submit_button( '開始回填' );
			?>
		</form>
	</div>
	<?php
}

/* ============================================================
 * Anime 中文標題同步 post_title
 * ============================================================ */

function wxacg_sync_anime_chinese_title_to_post_title(
	$post_id
): void {
	if ( ! is_numeric( $post_id ) ) {
		return;
	}

	$post_id = (int) $post_id;

	if (
		$post_id <= 0 ||
		'anime' !== get_post_type( $post_id ) ||
		wp_is_post_revision( $post_id ) ||
		wp_is_post_autosave( $post_id )
	) {
		return;
	}

	$chinese_title = get_post_meta(
		$post_id,
		'anime_title_chinese',
		true
	);

	$chinese_title = is_scalar( $chinese_title )
		? trim( (string) $chinese_title )
		: '';

	if ( '' === $chinese_title ) {
		return;
	}

	$current_title = trim(
		(string) get_post_field(
			'post_title',
			$post_id
		)
	);

	if ( $current_title === $chinese_title ) {
		return;
	}

	remove_action(
		'acf/save_post',
		'wxacg_sync_anime_chinese_title_to_post_title',
		20
	);

	wp_update_post(
		[
			'ID'         => $post_id,
			'post_title' => wp_strip_all_tags(
				$chinese_title
			),
		]
	);

	add_action(
		'acf/save_post',
		'wxacg_sync_anime_chinese_title_to_post_title',
		20
	);
}
add_action(
	'acf/save_post',
	'wxacg_sync_anime_chinese_title_to_post_title',
	20
);

/* ============================================================
 * 角色頁 wpDiscuz 主查詢
 * ============================================================ */

function wxacg_prepare_character_comment_post(): void {
	$character_id = absint(
		get_query_var( 'asa_character_id' )
	);

	if ( $character_id <= 0 ) {
		return;
	}

	$posts = get_posts(
		[
			'post_type'      => 'asa_char_comments',
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'no_found_rows'  => true,
			'meta_query'     => [
				[
					'key'     => 'asa_character_bgm_id',
					'value'   => $character_id,
					'compare' => '=',
					'type'    => 'NUMERIC',
				],
			],
		]
	);

	if (
		empty( $posts ) ||
		! $posts[0] instanceof WP_Post
	) {
		return;
	}

	global $wp_query;

	$comment_post = $posts[0];

	$wp_query->is_singular       = true;
	$wp_query->is_single         = true;
	$wp_query->is_page           = false;
	$wp_query->is_404            = false;
	$wp_query->queried_object    = $comment_post;
	$wp_query->queried_object_id = $comment_post->ID;
}
add_action(
	'wp',
	'wxacg_prepare_character_comment_post',
	1
);

/* ============================================================
 * Font Awesome 預載
 * ============================================================ */

function wxacg_preload_fontawesome_fonts(): void {
	$base = weixiaoacg_THEME_URL .
		'/assets/fontawesome/webfonts/';

	$fonts = [
		'fa-solid-900.woff2',
		'fa-regular-400.woff2',
		'fa-brands-400.woff2',
	];

	foreach ( $fonts as $font ) {
		printf(
			'<link rel="preload" href="%s" as="font" type="font/woff2" crossorigin="anonymous">' . "\n",
			esc_url( $base . $font )
		);
	}
}
add_action(
	'wp_head',
	'wxacg_preload_fontawesome_fonts',
	1
);

/* ============================================================
 * jQuery 穩定性
 * ============================================================ */

function wxacg_keep_jquery_synchronous(
	string $tag,
	string $handle,
	string $src = ''
): string {
	if ( is_admin() ) {
		return $tag;
	}

	$protected_handles = [
		'jquery',
		'jquery-core',
		'jquery-migrate',
	];

	if (
		! in_array(
			$handle,
			$protected_handles,
			true
		)
	) {
		return $tag;
	}

	$result = preg_replace(
		'/\s(?:defer|async)(?:=(["\'])(?:defer|async)\1)?/i',
		'',
		$tag
	);

	return is_string( $result )
		? $result
		: $tag;
}
add_filter(
	'script_loader_tag',
	'wxacg_keep_jquery_synchronous',
	100,
	3
);

/* ============================================================
 * 手機底部導航
 * ============================================================ */

function wxacg_output_bottom_navigation(): void {
	$request_uri = isset( $_SERVER['REQUEST_URI'] )
		? sanitize_text_field(
			wp_unslash( $_SERVER['REQUEST_URI'] )
		)
		: '';

	$forum_active = (
		is_page( 'forum' ) ||
		false !== strpos( $request_uri, '/community/' ) ||
		false !== strpos( $request_uri, '/forum' )
	);
	?>
	<nav class="wx-bottom-nav" role="navigation" aria-label="底部導航">
		<a
			href="<?php echo esc_url( home_url( '/news/' ) ); ?>"
			class="wx-bottom-nav__item <?php echo is_singular( 'post' ) ? 'is-active' : ''; ?>"
		>
			<span class="wx-bottom-nav__icon" aria-hidden="true">📰</span>
			<span class="wx-bottom-nav__label">新聞</span>
		</a>

		<a
			href="<?php echo esc_url( home_url( '/bangumi/' ) ); ?>"
			class="wx-bottom-nav__item <?php echo is_page( 'bangumi' ) ? 'is-active' : ''; ?>"
		>
			<span class="wx-bottom-nav__icon" aria-hidden="true">🗓️</span>
			<span class="wx-bottom-nav__label">新番</span>
		</a>

		<a
			href="<?php echo esc_url(
				admin_url(
					'admin-ajax.php?action=wxacg_random_anime'
				)
			); ?>"
			rel="nofollow"
			class="wx-bottom-nav__item wx-bottom-nav__item--center"
		>
			<span class="wx-bottom-nav__icon-wrap" aria-hidden="true">
				<svg viewBox="0 0 24 24" fill="currentColor" width="26" height="26">
					<path d="M5 3h14a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2zm2 4a1 1 0 1 0 0 2 1 1 0 0 0 0-2zm10 0a1 1 0 1 0 0 2 1 1 0 0 0 0-2zm-5 4a1 1 0 1 0 0 2 1 1 0 0 0 0-2zM7 15a1 1 0 1 0 0 2 1 1 0 0 0 0-2zm10 0a1 1 0 1 0 0 2 1 1 0 0 0 0-2z"/>
				</svg>
			</span>
			<span class="wx-bottom-nav__label">抽動漫</span>
		</a>

		<a
			href="<?php echo esc_url( home_url( '/anime/' ) ); ?>"
			class="wx-bottom-nav__item <?php echo ( is_post_type_archive( 'anime' ) || is_singular( 'anime' ) ) ? 'is-active' : ''; ?>"
		>
			<span class="wx-bottom-nav__icon" aria-hidden="true">🎬</span>
			<span class="wx-bottom-nav__label">動漫</span>
		</a>

		<a
			href="<?php echo esc_url( home_url( '/forum/' ) ); ?>"
			class="wx-bottom-nav__item <?php echo $forum_active ? 'is-active' : ''; ?>"
		>
			<span class="wx-bottom-nav__icon" aria-hidden="true">💬</span>
			<span class="wx-bottom-nav__label">討論</span>
		</a>
	</nav>

	<style>
	.wx-bottom-nav {
		display: none;
	}

	@media (max-width: 768px) {
		.wx-bottom-nav {
			display: flex;
			position: fixed !important;
			bottom: 0 !important;
			left: 0 !important;
			right: 0 !important;
			width: 100% !important;
			z-index: 999999 !important;
			height: 72px;
			background: rgba(10, 14, 24, .88);
			backdrop-filter: blur(24px) saturate(180%);
			-webkit-backdrop-filter: blur(24px) saturate(180%);
			border-top: 1px solid rgba(255,255,255,.08);
			justify-content: space-around;
			align-items: stretch;
			padding-bottom: env(safe-area-inset-bottom, 0);
			box-shadow: 0 -4px 30px rgba(0,0,0,.45);
		}

		.wx-bottom-nav__item {
			flex: 1;
			display: flex;
			flex-direction: column;
			align-items: center;
			justify-content: center;
			gap: 5px;
			color: rgba(180,195,220,.55);
			font-size: 11px;
			font-weight: 600;
			text-decoration: none;
			transition: color .2s ease, transform .15s ease;
			padding: 8px 4px;
			position: relative;
		}

		.wx-bottom-nav__item:active {
			transform: scale(.90);
		}

		.wx-bottom-nav__item:hover,
		.wx-bottom-nav__item.is-active {
			color: #fff;
		}

		.wx-bottom-nav__item.is-active::before {
			content: "";
			position: absolute;
			top: 0;
			left: 50%;
			transform: translateX(-50%);
			width: 28px;
			height: 3px;
			background: linear-gradient(90deg,#63a8ff,#a663ff);
			border-radius: 0 0 4px 4px;
		}

		.wx-bottom-nav__icon {
			font-size: 24px;
			line-height: 1;
		}

		.wx-bottom-nav__label {
			font-size: 11px;
			font-weight: 600;
		}

		.wx-bottom-nav__item--center {
			color: #fff;
		}

		.wx-bottom-nav__item--center .wx-bottom-nav__icon-wrap {
			width: 54px;
			height: 54px;
			background: linear-gradient(135deg,#e11d48,#f97316);
			border-radius: 50%;
			margin-top: -24px;
			box-shadow:
				0 4px 22px rgba(225,29,72,.6),
				0 0 0 4px rgba(10,14,24,.88);
			display: flex;
			align-items: center;
			justify-content: center;
		}

		.wx-bottom-nav__item--center .wx-bottom-nav__label {
			margin-top: 2px;
			color: rgba(180,195,220,.75);
		}

		body {
			padding-bottom:
				calc(72px + env(safe-area-inset-bottom, 0)) !important;
		}
	}
	</style>
	<?php
}
add_action(
	'wp_footer',
	'wxacg_output_bottom_navigation',
	99
);

/* ============================================================
 * Rank Math REST Meta
 * ============================================================ */

function wxacg_rank_math_meta_auth(
	$allowed,
	string $meta_key,
	int $post_id
): bool {
	return (
		$post_id > 0 &&
		current_user_can( 'edit_post', $post_id )
	);
}

function wxacg_register_rank_math_rest_meta(): void {
	$meta_args = [
		'type'              => 'string',
		'single'            => true,
		'show_in_rest'      => true,
		'sanitize_callback' => 'sanitize_text_field',
		'auth_callback'     => 'wxacg_rank_math_meta_auth',
	];

	register_post_meta(
		'',
		'rank_math_focus_keyword',
		$meta_args
	);

	register_post_meta(
		'',
		'rank_math_description',
		$meta_args
	);
}
add_action(
	'rest_api_init',
	'wxacg_register_rank_math_rest_meta'
);

/* ============================================================
 * AI Glossary REST API
 * ============================================================ */

function wxacg_register_glossary_rest_route(): void {
	register_rest_route(
		'wxacg/v1',
		'/glossary',
		[
			'methods'             => WP_REST_Server::READABLE,
			'permission_callback' => '__return_true',
			'callback'            => 'wxacg_get_ai_glossary_delta',
			'args'                => [
				'since' => [
					'type'     => 'string',
					'required' => false,
				],
				'with_timestamp' => [
					'type'     => 'boolean',
					'required' => false,
				],
			],
		]
	);
}
add_action(
	'rest_api_init',
	'wxacg_register_glossary_rest_route'
);

function wxacg_get_ai_glossary_delta(
	WP_REST_Request $request
) {
	global $wpdb;

	$since_param = trim(
		(string) $request->get_param( 'since' )
	);

	$with_timestamp = rest_sanitize_boolean(
		$request->get_param( 'with_timestamp' )
	);

	$current_time = current_time( 'mysql' );
	$is_delta     = '' !== $since_param;
	$results      = [];

	if ( $is_delta ) {
		if ( is_numeric( $since_param ) ) {
			$timestamp = (int) $since_param;
		} else {
			$timestamp = strtotime( $since_param );
		}

		if ( false === $timestamp || $timestamp <= 0 ) {
			return new WP_Error(
				'invalid_since',
				'since 參數格式無效。',
				[ 'status' => 400 ]
			);
		}

		$since_gmt = gmdate(
			'Y-m-d H:i:s',
			$timestamp
		);

		$modified_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID
				FROM {$wpdb->posts}
				WHERE post_type = 'anime'
					AND post_status = 'publish'
					AND post_modified_gmt >= %s",
				$since_gmt
			)
		);

		$modified_ids = array_map(
			'intval',
			(array) $modified_ids
		);

		if ( empty( $modified_ids ) ) {
			return rest_ensure_response(
				[
					'status'        => 'up_to_date',
					'updated_count' => 0,
					'timestamp'     => $current_time,
					'data'          => new stdClass(),
				]
			);
		}

		$placeholders = implode(
			',',
			array_fill(
				0,
				count( $modified_ids ),
				'%d'
			)
		);

		$sql = $wpdb->prepare(
			"SELECT post_id, meta_key, meta_value
			FROM {$wpdb->postmeta}
			WHERE post_id IN ({$placeholders})
				AND meta_key IN (
					'anime_title_chinese',
					'anime_title_native',
					'anime_title_english',
					'anime_title_simplified'
				)
				AND meta_value != ''",
			...$modified_ids
		);

		$results = $wpdb->get_results( $sql );
	} else {
		$cached = get_transient(
			'wxacg_ai_glossary_raw_v2'
		);

		if ( is_array( $cached ) ) {
			if ( $with_timestamp ) {
				return rest_ensure_response(
					[
						'status'        => 'full_sync',
						'updated_count' => count( $cached ),
						'timestamp'     => $current_time,
						'data'          => $cached,
					]
				);
			}

			return rest_ensure_response( $cached );
		}

		$results = $wpdb->get_results(
			"SELECT pm.post_id, pm.meta_key, pm.meta_value
			FROM {$wpdb->postmeta} pm
			INNER JOIN {$wpdb->posts} p
				ON p.ID = pm.post_id
			WHERE p.post_type = 'anime'
				AND p.post_status = 'publish'
				AND pm.meta_key IN (
					'anime_title_chinese',
					'anime_title_native',
					'anime_title_english',
					'anime_title_simplified'
				)
				AND pm.meta_value != ''"
		);
	}

	$grouped  = [];
	$glossary = [];

	foreach ( (array) $results as $row ) {
		$post_id  = (int) $row->post_id;
		$meta_key = (string) $row->meta_key;
		$value    = trim( (string) $row->meta_value );

		if ( '' !== $value ) {
			$grouped[ $post_id ][ $meta_key ] = $value;
		}
	}

	foreach ( $grouped as $meta ) {
		$traditional = trim(
			(string) (
				$meta['anime_title_chinese'] ?? ''
			)
		);

		if ( '' === $traditional ) {
			continue;
		}

		foreach (
			[
				'anime_title_native',
				'anime_title_english',
				'anime_title_simplified',
			] as $source_key
		) {
			$source = trim(
				(string) (
					$meta[ $source_key ] ?? ''
				)
			);

			if (
				'' !== $source &&
				$source !== $traditional
			) {
				$glossary[ $source ] = $traditional;
			}
		}
	}

	if ( ! $is_delta ) {
		set_transient(
			'wxacg_ai_glossary_raw_v2',
			$glossary,
			12 * HOUR_IN_SECONDS
		);

		if ( ! $with_timestamp ) {
			return rest_ensure_response( $glossary );
		}
	}

	return rest_ensure_response(
		[
			'status'        => $is_delta
				? 'delta_sync'
				: 'full_sync',
			'updated_count' => count( $glossary ),
			'timestamp'     => $current_time,
			'data'          => empty( $glossary )
				? new stdClass()
				: $glossary,
		]
	);
}

function wxacg_clear_ai_glossary_cache(): void {
	delete_transient( 'wxacg_ai_glossary_raw_v2' );
}
add_action(
	'save_post_anime',
	'wxacg_clear_ai_glossary_cache',
	100
);
add_action(
	'deleted_post',
	'wxacg_clear_ai_glossary_cache',
	100
);
add_action(
	'trashed_post',
	'wxacg_clear_ai_glossary_cache',
	100
);
add_action(
	'untrashed_post',
	'wxacg_clear_ai_glossary_cache',
	100
);

/**
 * 「編輯與內容團隊」清單要排除的使用者。
 *
 * 團隊清單是動態抓「有發表過文章的使用者」產生的（見 page-about.php 與
 * page-join.php），官方帳號因此也會被列進去，看起來像團隊成員之一。
 * 但它是站方的發文帳號、不是人，列在團隊裡會誤導讀者。
 *
 * 兩個頁面共用這份清單，才不會改了一邊漏了另一邊。
 *
 * @return int[] 要排除的使用者 ID。
 */
function wxacg_team_excluded_user_ids(): array {

	// ID 5 = 顯示名「微笑動漫」的官方發文帳號
	return (array) apply_filters( 'wxacg_team_excluded_user_ids', array( 5 ) );
}
