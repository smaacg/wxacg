<?php
/**
 * Streaming Routing (串流平台總覽與各平台作品頁)
 * Path: wp-content/plugins/anime-sync-pro/includes/class-streaming-routing.php
 * Version: 1.0.2 (2026-09-03)
 *
 * Changelog:
 *   1.0.2 (2026-09-03)
 *     - [修正] 補上 description filter（1.0.1 只修了 title 與 canonical，
 *              description 仍沿用首頁的，18 頁描述與首頁一字不差）。
 *              meta / og / twitter 三個描述欄位各走各的路徑，都要掛。
 *   1.0.1 (2026-09-03)
 *     - [修正] 補上 canonical / og:url filter。虛擬頁沒有主查詢，WordPress
 *              判定為首頁，Rank Math 把 canonical 覆寫成首頁網址，等同告訴
 *              Google「這 18 頁是首頁的複本」，完全無法進索引。
 *   1.0.0 (2026-09-03)
 *     - [新增] /streaming/ 與 /streaming/{platform}/ 路由與模板。
 *
 * 功能：攔截 /streaming/ 與 /streaming/{platform}/，載入對應模板。
 *      平台清單來自 Anime_Sync_Streaming_Registry，作品來自
 *      anime_tw_streaming / anime_streaming 這兩個 meta。
 *
 * 設計對齊 class-series-index.php 與 class-entity-routing.php：
 *   - 靜態 class + __CLASS__ 註冊
 *   - rewrite 用 'top' 優先
 *   - template_include 先找子主題，再退回外掛內建
 *   - 不在檔尾 init()，由主外掛 plugins_loaded 統一呼叫
 *   - flush 靠主外掛版本號 +1 觸發（anime_sync_flush_rewrite flag）
 *
 * ★ 與 class-entity-routing.php 不同，這裡「不」加 noindex。
 *   人物／角色頁 noindex 是因為內容全來自 Bangumi、站上沒有原創觀點，
 *   三萬多頁聚合內容曾導致 AdSense 退件（見該檔 filter_entity_robots 註解）。
 *   平台頁的性質不同：它是站內資料的整理與比較，數量只有十幾頁，
 *   而且正是台灣使用者會搜尋的「XX 有哪些動畫可以看」。
 *   已實測 /series/（同樣機制的虛擬頁）robots 為 index，故 robots 不需額外處理。
 *
 *   ⚠ 1.0.1 更正：上面那次實測「只驗了 robots，沒驗 canonical」。
 *     robots 確實是 index，但 canonical 被覆寫成首頁網址，結果一樣進不了索引。
 *     驗證單一訊號就下結論是不夠的，詳見 filter_canonical() 的說明。
 *
 * @package Anime_Sync_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Anime_Sync_Streaming_Routing {

	const SLUG        = 'streaming';
	const QV_INDEX    = 'asp_streaming_index';
	const QV_PLATFORM = 'asp_streaming_platform';

	/**
	 * 單一平台要有自己的頁面所需的最少作品數。
	 *
	 * 只有幾部作品的平台做成獨立頁就是薄內容，對整站評價有害無益
	 * （人物頁那次的教訓）。不足門檻的平台仍會出現在總覽頁的列表，
	 * 只是不給獨立網址。
	 */
	const MIN_WORKS = 50;

	/** 平台作品數統計的快取 */
	/* v3：移除 exclusive 後結構又變了，換 key 讓舊快取自然失效 */
	const COUNT_CACHE_KEY = 'asp_streaming_counts_v3';
	const COUNT_CACHE_TTL = 6 * HOUR_IN_SECONDS;

	public static function init(): void {
		add_action( 'init',             [ __CLASS__, 'add_rewrite' ] );
		add_filter( 'query_vars',       [ __CLASS__, 'add_query_var' ] );
		add_filter( 'template_include', [ __CLASS__, 'load_template' ] );

		add_filter( 'pre_get_document_title',   [ __CLASS__, 'filter_title' ], 99 );
		add_filter( 'rank_math/frontend/title', [ __CLASS__, 'filter_title' ], 99 );

		add_filter( 'rank_math/frontend/canonical', [ __CLASS__, 'filter_canonical' ], 99 );
		add_filter( 'rank_math/opengraph/url',      [ __CLASS__, 'filter_canonical' ], 99 );

		/* 三個描述欄位各走各的路徑，og/twitter 不會繼承 meta description */
		add_filter( 'rank_math/frontend/description',           [ __CLASS__, 'filter_description' ], 99 );
		add_filter( 'rank_math/opengraph/facebook/description', [ __CLASS__, 'filter_description' ], 99 );
		add_filter( 'rank_math/opengraph/twitter/description',  [ __CLASS__, 'filter_description' ], 99 );
	}

	public static function add_rewrite(): void {
		// 單一平台要排在總覽之前，否則 /streaming/netflix/ 會被總覽規則吃掉
		add_rewrite_rule(
			'^' . self::SLUG . '/([a-z0-9_\-]+)/?$',
			'index.php?' . self::QV_PLATFORM . '=$matches[1]',
			'top'
		);
		add_rewrite_rule(
			'^' . self::SLUG . '/?$',
			'index.php?' . self::QV_INDEX . '=1',
			'top'
		);
	}

	public static function add_query_var( $vars ) {
		$vars[] = self::QV_INDEX;
		$vars[] = self::QV_PLATFORM;
		return $vars;
	}

	public static function load_template( $template ) {

		if ( (int) get_query_var( self::QV_INDEX ) === 1 ) {
			return self::resolve_template( 'streaming-index.php', $template );
		}

		$key = self::current_platform_key();
		if ( $key !== '' ) {
			return self::resolve_template( 'streaming-platform.php', $template );
		}

		// /streaming/{不存在的平台}/ → 交還給 WordPress 走 404
		if ( get_query_var( self::QV_PLATFORM ) !== '' ) {
			global $wp_query;
			$wp_query->set_404();
			status_header( 404 );
			nocache_headers();
		}

		return $template;
	}

	/** 目前請求的平台 key；不是平台頁或該平台不存在時回空字串 */
	public static function current_platform_key(): string {
		$raw = (string) get_query_var( self::QV_PLATFORM );
		if ( $raw === '' || ! class_exists( 'Anime_Sync_Streaming_Registry' ) ) {
			return '';
		}
		return Anime_Sync_Streaming_Registry::get( $raw ) ? $raw : '';
	}

	public static function is_streaming_page(): bool {
		return (int) get_query_var( self::QV_INDEX ) === 1 || self::current_platform_key() !== '';
	}

	/**
	 * 虛擬頁沒有主查詢可以取標題，不覆寫的話會顯示成首頁最新文章的標題
	 * （class-entity-routing.php 踩過這個坑）。
	 */
	public static function filter_title( $title ) {

		if ( ! self::is_streaming_page() ) {
			return $title;
		}

		$sep  = ' - ';
		$site = get_bloginfo( 'name' );
		$key  = self::current_platform_key();

		if ( $key !== '' ) {
			$p     = Anime_Sync_Streaming_Registry::get( $key );
			$label = $p['label'] ?? $key;
			return $label . ' 動畫線上看清單' . $sep . $site;
		}

		return '台灣動畫串流平台一覽' . $sep . $site;
	}

	/**
	 * canonical / og:url 指向該頁自己。
	 *
	 * 虛擬頁沒有主查詢，WordPress 判定為首頁（body class 為 home blog），
	 * Rank Math 因此在 class-paper.php 的 is_front_page() 分支把 canonical
	 * 覆寫成首頁網址。canonical 指向首頁＝告訴 Google「這頁是首頁的複本」，
	 * 這 18 頁（17 平台 + 總覽）永遠不會被收錄。
	 *
	 * 為什麼不改 is_home 這個根因：
	 *   Rank Math 用 Search / Singular / Blog / Archive / Error_404 五個條件
	 *   挑 Paper（目前命中 Blog）。把 is_home 設成 false 會五個全不成立，
	 *   Rank Math 拿不到任何 Paper，canonical 反而變成空的——站上的
	 *   /year-review/ 正是這個狀態。所以這個 filter 是必要手段，不是權宜之計。
	 *
	 * 寫法比照 class-subview-routing.php::filter_canonical()：
	 *   - 優先度 99，避免被其他外掛蓋掉
	 *   - 參數不宣告型別（Rank Math 的 canonical 預設值是 false）
	 *   - 取不到網址時退回原值，不回傳空字串
	 *
	 * og:url 另外掛一次：它雖然是從 get_canonical() 衍生的，
	 * 但既有實作沒有賭這件事，這裡照做。
	 */
	public static function filter_canonical( $canonical ) {

		if ( ! self::is_streaming_page() ) {
			return $canonical;
		}

		$key = self::current_platform_key();
		$url = $key !== '' ? self::platform_url( $key ) : self::index_url();

		return is_string( $url ) && '' !== $url ? $url : $canonical;
	}

	/**
	 * meta description / og:description / twitter:description。
	 *
	 * 與 title、canonical 同一個根因：虛擬頁被判定為首頁，Rank Math 於是
	 * 套用首頁的描述（class-opengraph.php 的 is_front_page() 分支直接讀
	 * titles.homepage_facebook_description），這 18 頁的描述因此與首頁一字不差。
	 *
	 * 三個欄位各走各的取值路徑，og:description 與 twitter:description
	 * 「不會」從 meta description 繼承，所以三個 hook 都要掛。
	 */
	public static function filter_description( $description ) {

		if ( ! self::is_streaming_page() ) {
			return $description;
		}

		$key    = self::current_platform_key();
		$counts = self::get_counts();

		if ( $key === '' ) {
			/*
			 * 平台名稱不寫死：取作品數前 6 名動態帶入，平台增減或改名都跟著走。
			 * 描述長度目標 120~160 字（Bing 網站掃描的建議值），列 6 個剛好落在區間內。
			 */
			$names = [];
			foreach ( array_slice( array_keys( $counts ), 0, 5 ) as $k ) {
				$p = Anime_Sync_Streaming_Registry::get( $k );
				if ( $p ) {
					$names[] = $p['label'] ?? $k;
				}
			}

			$list = $names ? implode( '、', $names ) . ' 等' : '';

			return self::trim_desc( sprintf(
				'台灣看得到的動畫串流平台一覽：%s%d 個平台各收錄哪些動畫可以合法線上看。'
				. '每個平台附完整作品清單、作品數與播出年份，資料持續同步更新，'
				. '實際上架狀況以平台公告為準。',
				$list,
				count( $counts )
			) );
		}

		$platform = Anime_Sync_Streaming_Registry::get( $key );
		$label    = $platform['label'] ?? $key;
		$total    = (int) ( $counts[ $key ] ?? 0 );

		return self::trim_desc( sprintf(
			'台灣 %1$s 有哪些動畫可以看？微笑動漫整理 %2$d 部在 %1$s 上架的動畫，'
			. '每部附封面、播出年份與集數，並說明該平台計費方式與常見問題。'
			. '收錄範圍涵蓋當季新番與過往作品，點卡片可查看完整作品資料。'
			. '資料持續同步更新，實際上架狀況以平台公告為準。',
			$label,
			$total
		) );
	}

	/**
	 * 描述長度安全上限。
	 *
	 * 平台名稱長度差很多（「Ani-One 羚邦集團 YouTube」比「Netflix」長三倍），
	 * 同一句模板組出來會從 117 字到 169 字都有，所以統一過一次上限。
	 * 共用 anime-seo-auto.php 的切字helper，切在標點上而不是硬斬。
	 */
	private static function trim_desc( string $desc ): string {
		return function_exists( 'wx_asp_trim_seo_desc' )
			? wx_asp_trim_seo_desc( $desc, 160 )
			: $desc;
	}

	/**
	 * 各平台的已發布作品數。
	 *
	 * 一次掃完所有 anime_tw_streaming 值再統計，不要對每個平台各跑一次
	 * meta_query——27 個平台就是 27 次全表掃描。
	 */
	public static function get_counts( bool $bypass_cache = false ): array {
		return self::get_stats( $bypass_cache )['counts'];
	}

	/**
	 * 各平台的統計：目前只有收錄數。
	 *
	 * 曾經一併算過「獨家數」（＝這部作品只標了這一個平台），已移除。
	 * 那個數字量到的不是授權事實而是我們的資料缺口：平台資料有兩個來源，
	 * AniList 的 externalLinks 只涵蓋國際平台（Bilibili、Netflix、Disney+…），
	 * 台灣平台（巴哈、Hami、MyVideo、friDay、LINE TV…）只能靠 YourAnimes 補。
	 * 作品若只被 AniList 涵蓋就會長得像「Bilibili 獨家」。
	 *
	 * 實測（2026-09）：被判為獨家的作品有 YourAnimes 資料的只有 10.1%，
	 * 非獨家是 40.8%；Bilibili 的「獨家」中 90.8% 根本沒有台灣平台資料。
	 * 獨家率也完全照來源分裂——AniList 系的 Bilibili 42%、Amazon 42%、
	 * Disney+ 29%，YourAnimes 系的巴哈 0.8%、Hami 1.1%。
	 * 真實授權狀況只能查外部權威來源，且逐季變動，本站無法自行推導。
	 *
	 * 回傳值保留 array{counts:…} 這層結構（而不是直接回陣列），
	 * 之後要再加別的統計時不必再改一次呼叫端。
	 *
	 * @return array{counts: array<string,int>}
	 */
	public static function get_stats( bool $bypass_cache = false ): array {

		if ( ! $bypass_cache ) {
			$cached = get_transient( self::COUNT_CACHE_KEY );
			if ( is_array( $cached ) && isset( $cached['counts'] ) ) {
				return $cached;
			}
		}

		global $wpdb;

		$rows = $wpdb->get_col(
			"SELECT mm.meta_value
			   FROM {$wpdb->postmeta} mm
			   JOIN {$wpdb->posts} p ON p.ID = mm.post_id
			  WHERE mm.meta_key = 'anime_tw_streaming'
			    AND p.post_type = 'anime'
			    AND p.post_status = 'publish'"
		);

		$counts = [];

		foreach ( (array) $rows as $raw ) {
			$keys = maybe_unserialize( $raw );
			if ( ! is_array( $keys ) ) {
				continue;
			}

			// 同一部作品可能重複標記同一平台，去重後才算得準
			$keys = array_values( array_unique( array_filter( array_map( 'trim', $keys ) ) ) );
			if ( ! $keys ) {
				continue;
			}

			foreach ( $keys as $k ) {
				$counts[ $k ] = ( $counts[ $k ] ?? 0 ) + 1;
			}
		}

		arsort( $counts );

		$stats = [ 'counts' => $counts ];
		set_transient( self::COUNT_CACHE_KEY, $stats, self::COUNT_CACHE_TTL );

		return $stats;
	}

	/** 該平台是否夠格擁有獨立頁面 */
	public static function has_own_page( string $key ): bool {
		$counts = self::get_counts();
		return ( $counts[ $key ] ?? 0 ) >= self::MIN_WORKS;
	}

	public static function platform_url( string $key ): string {
		return home_url( '/' . self::SLUG . '/' . $key . '/' );
	}

	public static function index_url(): string {
		return home_url( '/' . self::SLUG . '/' );
	}

	/** 圖示網址；沒有圖示回空字串 */
	public static function icon_url( string $icon ): string {
		if ( $icon === '' ) {
			return '';
		}
		return plugin_dir_url( dirname( __FILE__ ) ) . 'public/assets/img/providers/' . $icon;
	}

	private static function resolve_template( string $file, $fallback ) {
		$child = get_stylesheet_directory() . '/' . $file;
		if ( file_exists( $child ) ) {
			return $child;
		}
		$plugin = plugin_dir_path( __FILE__ ) . '../public/templates/' . $file;
		if ( file_exists( $plugin ) ) {
			return $plugin;
		}
		return $fallback;
	}
}
