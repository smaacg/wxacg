<?php
/**
 * Bangumi 模組載入器
 *
 * @package weixiaoacg
 * @version 1.5.0 (2026-06-21)
 * - sitemap 端點改名為 /bangumi-feed.xml（原 /bangumi-sitemap.xml 會被
 *   Rank Math 攔截所有 *sitemap.xml 請求而 404）。同時保留舊網址 301 轉新址。
 * - parse_ym() 新增「年+月+新番表」主關鍵字 label（命中「2026年7月新番表」），
 *   並新增 season_label（季節說法）與 season_key（修正主題色抓取）。
 * - 自動 flush 改到 wp_loaded（確保所有 rewrite rule 都註冊完才 flush）。
 * v1.4.1 / v1.4.0 / v1.3.0 ...（沿用先前 changelog）
 */

defined( 'ABSPATH' ) || exit;

/* ============================================================
 * 1. Rewrite 規則
 * ============================================================ */
add_action( 'init', function () {
	add_rewrite_rule( '^bangumi/?$', 'index.php?bangumi_view=current', 'top' );
	add_rewrite_rule( '^bangumi/archive/?$', 'index.php?bangumi_view=archive', 'top' );
	add_rewrite_rule( '^bangumi/([0-9]{6})/?$', 'index.php?bangumi_view=season&bangumi_ym=$matches[1]', 'top' );

	// 自訂 sitemap 端點：改名避開 Rank Math 對 *sitemap.xml 的攔截
	add_rewrite_rule( '^bangumi-feed\.xml$', 'index.php?bangumi_sitemap=1', 'top' );
} );

add_filter( 'query_vars', function ( $vars ) {
	$vars[] = 'bangumi_view';
	$vars[] = 'bangumi_ym';
	$vars[] = 'bangumi_sitemap';
	return $vars;
} );

/* 啟用主題時自動 flush（雙保險） */
add_action( 'after_switch_theme', function () {
	flush_rewrite_rules();
} );

/* ============================================================
 * 1.1 Rewrite 版本自動 flush
 *   修改任何 rewrite rule 時，記得改 SMACG_BANGUMI_REWRITE_VERSION 的值，
 *   下次有請求進站會自動 flush 一次（不必手動進後台按永久連結）。
 *   注意：改用 wp_loaded（此時所有 add_rewrite_rule 一定都註冊完了），
 *   比掛在 init 更可靠。
 * ============================================================ */
define( 'SMACG_BANGUMI_REWRITE_VERSION', '2026-06-21-feed' );

add_action( 'wp_loaded', function () {
	$stored = get_option( 'smacg_bangumi_rewrite_version', '' );
	if ( $stored !== SMACG_BANGUMI_REWRITE_VERSION ) {
		flush_rewrite_rules();
		update_option( 'smacg_bangumi_rewrite_version', SMACG_BANGUMI_REWRITE_VERSION );
	}
} );

/* ============================================================
 * 2. 路由：template_redirect → include page-bangumi*.php
 * ============================================================ */
add_action( 'template_redirect', function () {

	$view = get_query_var( 'bangumi_view' );
	if ( ! $view ) return;

	status_header( 200 );
	nocache_headers();

	if ( $view === 'current' ) {
		add_filter( 'rank_math/frontend/disable_integration', '__return_true' );
		remove_all_actions( 'rank_math/head' );
		wxacg_bangumi_restore_title_tag();

		$tpl = get_stylesheet_directory() . '/page-bangumi-index.php';
		if ( file_exists( $tpl ) ) { include $tpl; exit; }

		wp_safe_redirect( home_url( '/bangumi/' . smacg_bangumi_current_ym() . '/' ), 302 );
		exit;
	}

	if ( $view === 'archive' ) {
		add_filter( 'rank_math/frontend/disable_integration', '__return_true' );
		remove_all_actions( 'rank_math/head' );
		wxacg_bangumi_restore_title_tag();

		$tpl = get_stylesheet_directory() . '/page-bangumi-archive.php';
		if ( file_exists( $tpl ) ) { include $tpl; exit; }
	}

	if ( $view === 'season' ) {
		$ym   = (string) get_query_var( 'bangumi_ym' );
		$norm = smacg_bangumi_normalize_ym( $ym );

		if ( $norm !== $ym ) {
			wp_safe_redirect( home_url( "/bangumi/{$norm}/" ), 301 );
			exit;
		}

		add_filter( 'rank_math/frontend/disable_integration', '__return_true' );
		remove_all_actions( 'rank_math/head' );
		wxacg_bangumi_restore_title_tag();

		$tpl = get_stylesheet_directory() . '/page-bangumi.php';
		if ( file_exists( $tpl ) ) { include $tpl; exit; }
	}

}, 0 );

/* ============================================================
 * 2.1 舊 sitemap 網址 301 → 新網址（保留既有外連/收錄）
 * ============================================================ */
add_action( 'template_redirect', function () {
	$req = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH ) : '';
	if ( $req === '/bangumi-sitemap.xml' ) {
		wp_safe_redirect( home_url( '/bangumi-feed.xml' ), 301 );
		exit;
	}
}, 0 );

/* ============================================================
 * 4. 季度時間計算 helpers
 * ============================================================ */

/** 取目前所在季度的代表月（YYYYMM；月份只會是 01/04/07/10） */
function smacg_bangumi_current_ym(): string {
	$y = (int) date( 'Y' );
	$m = (int) date( 'n' );
	$season_m = (int) ( floor( ( $m - 1 ) / 3 ) * 3 + 1 );
	return sprintf( '%04d%02d', $y, $season_m );
}

/** 把任意 YYYYMM 正規化為當季代表月（如 202605 → 202604） */
function smacg_bangumi_normalize_ym( string $ym ): string {
	if ( ! preg_match( '/^(\d{4})(\d{2})$/', $ym, $m ) ) return smacg_bangumi_current_ym();
	$y  = (int) $m[1];
	$mn = (int) $m[2];
	if ( $mn < 1 || $mn > 12 ) return smacg_bangumi_current_ym();
	$season_m = (int) ( floor( ( $mn - 1 ) / 3 ) * 3 + 1 );
	return sprintf( '%04d%02d', $y, $season_m );
}

/** 解析 YYYYMM 為季度資訊 */
function smacg_bangumi_parse_ym( string $ym ): array {
	$ym = smacg_bangumi_normalize_ym( $ym );
	$y  = (int) substr( $ym, 0, 4 );
	$mn = (int) substr( $ym, 4, 2 );

	$map = [
		1  => [ 'season' => 'WINTER', 'zh' => '冬', 'en' => 'Winter' ],
		4  => [ 'season' => 'SPRING', 'zh' => '春', 'en' => 'Spring' ],
		7  => [ 'season' => 'SUMMER', 'zh' => '夏', 'en' => 'Summer' ],
		10 => [ 'season' => 'FALL',   'zh' => '秋', 'en' => 'Autumn' ],
	];

	$info = $map[ $mn ];

	return [
		'ym'           => $ym,
		'year'         => $y,
		'month'        => $mn,
		'season'       => $info['season'],
		'season_key'   => $info['season'],   // 供 page-bangumi.php 主題色使用
		'season_zh'    => $info['zh'],
		'season_en'    => $info['en'],
		// 主關鍵字：命中「2026年7月新番表」
		'label'        => sprintf( '%d年%d月新番表', $y, $mn ),
		// 副標：季節說法，命中「2026夏季新番」
		'season_label' => sprintf( '%d年%s季新番', $y, $info['zh'] ),
	];
}

/** 季度位移（±1 表示後/前一季） */
function smacg_bangumi_shift_ym( string $ym, int $delta ): string {
	$info = smacg_bangumi_parse_ym( $ym );
	$idx  = $info['year'] * 4 + (int) ( ( $info['month'] - 1 ) / 3 );
	$idx += $delta;
	$y  = (int) floor( $idx / 4 );
	$mn = ( $idx % 4 ) * 3 + 1;
	return sprintf( '%04d%02d', $y, $mn );
}

/* ============================================================
 * 5. SEO helpers
 * ============================================================ */

/**
 * 取回 <title> 的輸出權。
 *
 * Rank Math 在建構子裡把 _wp_render_title_tag 從 wp_head 搬到自己的
 * rank_math/head（見 seo-by-rank-math/includes/frontend/class-head.php:72-73），
 * 因此路由那邊為了自行接管 SEO 而下的 remove_all_actions('rank_math/head')
 * 會把 <title> 一起清掉——WP 那份已被 Rank Math 移走、Rank Math 那份又被
 * 我們移除，結果整頁沒有標題。/bangumi、/bangumi/archive、/bangumi/{ym}
 * 三個頁面都受影響。
 *
 * 這裡把它掛回 wp_head；標題內容仍由各模板的 pre_get_document_title
 * （優先度 99）決定，與原設計一致。
 */
function wxacg_bangumi_restore_title_tag(): void {
	if ( ! has_action( 'wp_head', '_wp_render_title_tag' ) ) {
		add_action( 'wp_head', '_wp_render_title_tag', 1 );
	}
}

function smacg_bangumi_render_meta( array $ctx ): void {
	echo "\n<!-- Bangumi SEO -->\n";
	echo '<meta name="description" content="' . esc_attr( $ctx['description'] ) . '">' . "\n";
	echo '<meta name="robots" content="index, follow, max-image-preview:large, max-snippet:-1">' . "\n";
	echo '<link rel="canonical" href="' . esc_url( $ctx['canonical'] ) . '">' . "\n";
	echo '<link rel="alternate" hreflang="zh-Hant-TW" href="' . esc_url( $ctx['canonical'] ) . '">' . "\n";
	echo '<link rel="alternate" hreflang="x-default" href="' . esc_url( $ctx['canonical'] ) . '">' . "\n";
}

function smacg_bangumi_render_og( array $ctx ): void {
	echo '<meta property="og:type" content="website">' . "\n";
	echo '<meta property="og:locale" content="zh_TW">' . "\n";
	echo '<meta property="og:site_name" content="微笑動漫">' . "\n";
	echo '<meta property="og:title" content="' . esc_attr( $ctx['title'] ) . '">' . "\n";
	echo '<meta property="og:description" content="' . esc_attr( $ctx['description'] ) . '">' . "\n";
	echo '<meta property="og:url" content="' . esc_url( $ctx['canonical'] ) . '">' . "\n";

	if ( ! empty( $ctx['og_image'] ) ) {
		echo '<meta property="og:image" content="' . esc_url( $ctx['og_image'] ) . '">' . "\n";
		echo '<meta property="og:image:alt" content="' . esc_attr( $ctx['title'] ) . '">' . "\n";
	}

	echo '<meta name="twitter:card" content="summary_large_image">' . "\n";
	echo '<meta name="twitter:title" content="' . esc_attr( $ctx['title'] ) . '">' . "\n";
	echo '<meta name="twitter:description" content="' . esc_attr( $ctx['description'] ) . '">' . "\n";

	if ( ! empty( $ctx['og_image'] ) ) {
		echo '<meta name="twitter:image" content="' . esc_url( $ctx['og_image'] ) . '">' . "\n";
	}
}

/**
 * 找出某一季對應的「完整報導」專題文章。
 *
 * ★ 關聯方式沿用站上既有的慣例，不另發明。
 *   2026 年 7 月那篇（post 1269）的作法是：分類「專題」＋ 標籤「2026年7月新番」。
 *   照這個規則反查就好——使用者寫新的一季時只要照樣下標籤，這裡自動接上，
 *   不需要另外維護一份對照表，也不會有忘記更新的問題。
 *
 * 查詢結果快取 12 小時：這是每次載入新番表頁都會跑的查詢，而專題文章
 * 一季只會發一篇，沒有即時性需求。
 *
 * @param string $ym 例如 '202610'。
 * @return array{url:string,title:string}|null 找不到回 null。
 */
function smacg_bangumi_feature_post( string $ym ): ?array {

	if ( ! preg_match( '/^(\d{4})(\d{2})$/', $ym, $m ) ) {
		return null;
	}

	$cache_key = 'smacg_bgm_feature_' . $ym;
	$cached    = get_transient( $cache_key );

	if ( is_array( $cached ) ) {
		return $cached ?: null;
	}

	// 標籤名稱月份不補零：站上是「2026年7月新番」不是「2026年07月新番」
	$tag = sprintf( '%d年%d月新番', (int) $m[1], (int) $m[2] );

	$found = get_posts( [
		'post_type'        => 'post',
		'post_status'      => 'publish',
		'posts_per_page'   => 1,
		'category_name'    => 'feature',
		'tag'              => $tag,
		'orderby'          => 'date',
		'order'            => 'DESC',
		'no_found_rows'    => true,
		'suppress_filters' => false,
	] );

	$out = $found
		? [ 'url' => get_permalink( $found[0] ), 'title' => get_the_title( $found[0] ) ]
		: [];

	set_transient( $cache_key, $out, 12 * HOUR_IN_SECONDS );

	return $out ?: null;
}

/**
 * 新番表頁的 FAQ 結構化資料。
 *
 * ★ 為什麼要加
 *   這頁原本有 CollectionPage / ItemList / BreadcrumbList / TVSeries，
 *   對「傳統搜尋」已經夠了，但 FAQPage 是 0——而問答引擎（Google AI
 *   總覽、ChatGPT、Perplexity）在回答「2026年10月新番有哪些」「秋番
 *   什麼時候開播」「台灣哪裡看」這類問題時，找的正是這段標記。
 *
 * ★ 答案一律由實際資料算出來，不寫死
 *   數量、最早開播日、平台清單都從 $posts 現算。寫死的答案會在下一季
 *   變成錯的，而錯的結構化資料比沒有更糟——Google 會判定為誤導性標記。
 *
 * ★ 只回答資料答得出來的問題
 *   「哪幾部好看」這種需要判斷的問題不放進 FAQ，那屬於專題文章。
 *
 * @return array FAQPage 節點；資料不足時回傳空陣列（呼叫端會被 array_filter 濾掉）。
 */
function smacg_bangumi_faq_schema( array $ctx, array $posts, string $canon ): array {

	if ( empty( $posts ) ) {
		return [];
	}

	$label = $ctx['label'];   // 2026年7月新番表

	/*
	 * 問句裡不要「表」字。
	 * 「2026年7月新番表在台灣哪裡看？」讀起來卡，也不是真實的搜尋語句；
	 * 「2026年7月新番在台灣哪裡看？」才是。$ctx 這一份沒有 season_label，
	 * 直接把結尾的「表」去掉最省事也最不會錯。
	 */
	$season = rtrim( $label, '表' );
	$total  = count( $posts );

	/* ---- 本季最早開播日 ----
	 *
	 * ★ 一定要排除跨季續播的舊日期。
	 *   新番表會把「這一季仍在播出」的作品一起列出，其中包含前幾季就開播、
	 *   一路播到現在的長番。直接取清單裡最小的日期，7 月的頁面會拿到
	 *   2025 年 10 月——實際輸出過「最早開播的作品在 10 月 20 日」這種
	 *   在 7 月頁面上明顯錯誤的答案（年份被格式化丟掉，更看不出問題）。
	 *
	 *   只算「本季當月一號之後」的開播日，才是這一季真正的新作檔期。
	 */
	$season_start = preg_match( '/^\d{6}$/', (string) ( $ctx['ym'] ?? '' ) )
		? $ctx['ym'] . '01'
		: '';

	$dates = [];
	foreach ( $posts as $p ) {
		$d = (string) ( $p['start_date'] ?? '' );

		if ( ! preg_match( '/^\d{8}$/', $d ) ) {
			continue;
		}

		if ( $season_start !== '' && $d < $season_start ) {
			continue;   // 跨季續播，不是本季的新開播
		}

		$dates[] = $d;
	}
	sort( $dates );

	/* ---- 台灣平台：統計實際出現過的，取最常見的幾個 ---- */
	$plat_count = [];
	foreach ( $posts as $p ) {
		$raw = $p['tw_platforms'] ?? '';
		$arr = is_array( $raw ) ? $raw : (array) maybe_unserialize( $raw );

		foreach ( $arr as $k ) {
			$k = trim( (string) $k );
			if ( $k !== '' ) {
				$plat_count[ $k ] = ( $plat_count[ $k ] ?? 0 ) + 1;
			}
		}
	}
	arsort( $plat_count );

	$plat_labels = [];
	if ( class_exists( 'Anime_Sync_Streaming_Registry' ) ) {
		foreach ( array_slice( array_keys( $plat_count ), 0, 6 ) as $k ) {
			$info          = Anime_Sync_Streaming_Registry::get( $k );
			$plat_labels[] = $info['label'] ?? $k;
		}
	}

	$qa = [];

	/* Q1：有哪些／幾部 */
	$qa[] = [
		$label . '有哪些動畫？共幾部？',
		sprintf(
			'%s共收錄 %d 部作品，本頁提供完整片單，可依開播日期、播出平台、作品類型與原作來源篩選，並支援作品名稱、配音與製作公司的即時搜尋。',
			$season,
			$total
		),
	];

	/* Q2：什麼時候開播 */
	if ( $dates ) {
		// 帶年份輸出：跨季的資料若混進來，至少一眼看得出不對，不會像只寫月日那樣無聲出錯
		$fmt = static fn( $d ) => sprintf(
			'%d 年 %d 月 %d 日',
			(int) substr( $d, 0, 4 ),
			(int) substr( $d, 4, 2 ),
			(int) substr( $d, 6, 2 )
		);

		$qa[] = [
			$season . '什麼時候開播？',
			sprintf(
				'本季最早開播的作品在 %s，共 %d 部已公布確切檔期。本頁的「時間表」檢視可依日期查看每天有哪些作品開播。',
				$fmt( $dates[0] ),
				count( $dates )
			),
		];
	}

	/* Q3：台灣哪裡看 —— 這題是本站的獨家價值，有資料才問 */
	if ( $plat_labels ) {
		$qa[] = [
			$season . '在台灣哪裡看？',
			sprintf(
				'本季作品在台灣主要可透過 %s 等平台合法觀看。每部作品的頁面均列出該作實際上架的平台與連結，本頁也可直接依平台篩選片單。',
				implode( '、', $plat_labels )
			),
		];
	}

	if ( count( $qa ) < 2 ) {
		return [];
	}

	$entities = [];
	foreach ( $qa as $i => [ $q, $a ] ) {
		$entities[] = [
			'@type'          => 'Question',
			'@id'            => $canon . '#faq-' . ( $i + 1 ),
			'name'           => $q,
			'acceptedAnswer' => [
				'@type' => 'Answer',
				'text'  => $a,
			],
		];
	}

	return [
		'@type'      => 'FAQPage',
		'@id'        => $canon . '#faq',
		'inLanguage' => 'zh-TW',
		'mainEntity' => $entities,
	];
}

function smacg_bangumi_render_schema( array $ctx, array $posts ): void {
	$home    = home_url( '/' );
	$canon   = $ctx['canonical'];
	$list_id = $canon . '#itemlist';

	$items = [];
	$i = 1;

	foreach ( array_slice( $posts, 0, 30 ) as $p ) {

		$name = '';
		foreach ( [ 'title_cn', 'title_jp', 'title_romaji', 'title_en' ] as $k ) {
			if ( ! empty( $p[ $k ] ) ) { $name = $p[ $k ]; break; }
		}
		if ( $name === '' ) continue;

		$series = [
			'@type' => 'TVSeries',
			'name'  => $name,
			'url'   => $p['url'] ?? '',
		];

		$alts = [];
		foreach ( [ 'title_jp', 'title_en', 'title_romaji' ] as $k ) {
			if ( ! empty( $p[ $k ] ) && $p[ $k ] !== $name ) $alts[] = $p[ $k ];
		}
		if ( $alts ) $series['alternateName'] = array_values( array_unique( $alts ) );

		if ( ! empty( $p['cover'] ) )    $series['image']       = $p['cover'];
		if ( ! empty( $p['synopsis'] ) ) $series['description'] = mb_substr( wp_strip_all_tags( $p['synopsis'] ), 0, 300 );

		if ( ! empty( $p['na_ts'] ) ) {
			$series['startDate'] = gmdate( 'c', (int) $p['na_ts'] );
		} elseif ( ! empty( $p['start_date'] ) ) {
			$series['startDate'] = preg_replace( '/^(\d{4})(\d{2})(\d{2})$/', '$1-$2-$3', $p['start_date'] );
		}

		if ( ! empty( $p['start_date'] ) ) {
			$series['datePublished'] = preg_replace( '/^(\d{4})(\d{2})(\d{2})$/', '$1-$2-$3', $p['start_date'] );
		}

		if ( ! empty( $p['ep_total'] ) ) $series['numberOfEpisodes'] = (int) $p['ep_total'];
		if ( ! empty( $p['genres'] ) )   $series['genre']            = array_values( (array) $p['genres'] );

		if ( ! empty( $p['studios'] ) ) {
			$series['productionCompany'] = [ '@type' => 'Organization', 'name' => $p['studios'] ];
		}

		if ( ! empty( $p['staff'] ) && is_array( $p['staff'] ) ) {
			foreach ( $p['staff'] as $st ) {
				$role  = is_array( $st ) ? ( $st['role'] ?? '' ) : '';
				$pname = is_array( $st ) ? ( $st['name'] ?? '' ) : '';
				if ( $pname === '' ) continue;

				if ( empty( $series['director'] ) && preg_match( '/監督|導演|Director/iu', $role ) ) {
					$series['director'] = [ '@type' => 'Person', 'name' => $pname ];
				}
				if ( empty( $series['author'] ) && preg_match( '/原作|Original|Creator|Story/iu', $role ) ) {
					$series['author'] = [ '@type' => 'Person', 'name' => $pname ];
				}
				if ( ! empty( $series['director'] ) && ! empty( $series['author'] ) ) break;
			}
		}

		if ( ! empty( $p['official'] ) && preg_match( '#^https?://#i', $p['official'] ) ) {
			$series['sameAs'] = [ $p['official'] ];
		}

		$items[] = [
			'@type'    => 'ListItem',
			'position' => $i++,
			'item'     => $series,
		];
	}

	$total_items = isset( $ctx['total'] ) ? (int) $ctx['total'] : count( $items );

	$graph = [
		[
			'@type'       => 'CollectionPage',
			'@id'         => $canon . '#webpage',
			'url'         => $canon,
			'name'        => $ctx['title'],
			'description' => $ctx['description'],
			'inLanguage'  => 'zh-TW',
			'isPartOf'    => [ '@id' => $home . '#website' ],
			'mainEntity'  => [ '@id' => $list_id ],
			'breadcrumb'  => [ '@id' => $canon . '#breadcrumb' ],
		],
		[
			'@type'           => 'WebSite',
			'@id'             => $home . '#website',
			'url'             => $home,
			'name'            => '微笑動漫',
			'inLanguage'      => 'zh-TW',
			'potentialAction' => [
				'@type'       => 'SearchAction',
				'target'      => [
					'@type'       => 'EntryPoint',
					'urlTemplate' => $home . '?s={search_term_string}',
				],
				'query-input' => 'required name=search_term_string',
			],
		],
		[
			'@type'          => 'ItemList',
			'@id'            => $list_id,
			'name'           => $ctx['title'],
			'description'    => $ctx['description'],
			'url'            => $canon,
			'inLanguage'     => 'zh-TW',
			'numberOfItems'  => $total_items,
			'itemListElement'=> $items,
		],
		smacg_bangumi_faq_schema( $ctx, $posts, $canon ),
		[
			'@type'           => 'BreadcrumbList',
			'@id'             => $canon . '#breadcrumb',
			'itemListElement' => [
				[ '@type' => 'ListItem', 'position' => 1, 'name' => '首頁',   'item' => $home ],
				[ '@type' => 'ListItem', 'position' => 2, 'name' => '新番表', 'item' => home_url( '/bangumi/' ) ],
				[ '@type' => 'ListItem', 'position' => 3, 'name' => $ctx['label'], 'item' => $canon ],
			],
		],
	];

	// FAQ 在資料不足時回空陣列，濾掉才不會輸出一個沒有 @type 的空節點
	$graph = array_values( array_filter( $graph ) );

	echo '<script type="application/ld+json">'
		. wp_json_encode( [ '@context' => 'https://schema.org', '@graph' => $graph ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES )
		. '</script>' . "\n";
}

/* ============================================================
 * 6. 自訂 sitemap：/bangumi-feed.xml（過去 3 年 + 未來 1 年所有季度）
 * ============================================================ */
add_action( 'template_redirect', function () {
	if ( ! get_query_var( 'bangumi_sitemap' ) ) return;

	header( 'Content-Type: application/xml; charset=UTF-8' );
	echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
	echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

	// 入口 + 存檔
	echo '<url><loc>' . esc_url( home_url( '/bangumi/' ) ) . "</loc><changefreq>daily</changefreq><priority>0.9</priority></url>\n";
	echo '<url><loc>' . esc_url( home_url( '/bangumi/archive/' ) ) . "</loc><changefreq>weekly</changefreq><priority>0.6</priority></url>\n";

	$now_year = (int) date( 'Y' );
	for ( $y = $now_year - 3; $y <= $now_year + 1; $y++ ) {
		foreach ( [ '01', '04', '07', '10' ] as $m ) {
			echo '<url><loc>' . esc_url( home_url( "/bangumi/{$y}{$m}/" ) ) . "</loc><changefreq>weekly</changefreq><priority>0.8</priority></url>\n";
		}
	}

	echo '</urlset>';
	exit;
} );

/* 在 robots.txt 宣告自訂 sitemap（Google / Bing 會讀取） */
add_filter( 'robots_txt', function ( $output, $public ) {
	if ( '1' === (string) $public ) {
		$output .= "\nSitemap: " . home_url( '/bangumi-feed.xml' ) . "\n";
	}
	return $output;
}, 20, 2 );

/* ============================================================
 * 7. 條件式 enqueue（只在 /bangumi/* 載入）
 * ============================================================ */
add_action( 'wp_enqueue_scripts', function () {
	$is_bangumi = (bool) get_query_var( 'bangumi_view' );

	if ( ! $is_bangumi ) {
		$req = isset( $_SERVER['REQUEST_URI'] ) ? trim( (string) wp_parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH ), '/' ) : '';
		$is_bangumi = ( $req === 'bangumi' || strpos( $req, 'bangumi/' ) === 0 );
	}

	if ( ! $is_bangumi ) return;

	$theme_url = get_stylesheet_directory_uri();
	$theme_dir = get_stylesheet_directory();

	$css = $theme_dir . '/assets/css/bangumi.css';
	if ( file_exists( $css ) ) {
		wp_enqueue_style( 'smacg-bangumi', $theme_url . '/assets/css/bangumi.css', [], filemtime( $css ) );
	}

	$js = $theme_dir . '/assets/js/bangumi.js';
	if ( file_exists( $js ) ) {
		wp_enqueue_script( 'smacg-bangumi', $theme_url . '/assets/js/bangumi.js', [], filemtime( $js ), true );
	}
} );
