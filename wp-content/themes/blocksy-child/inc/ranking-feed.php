<?php
/**
 * 微笑動漫 — 外部平台排行榜（伺服器端抓取 + 快取）
 * 路徑：/blocksy-child/inc/ranking-feed.php
 * 版本：1.0.0 (2026-08-15)
 *
 * 背景：
 *   原本 AniList / Jikan / Bangumi 三支 API 全在 ranking.js 由瀏覽器直接呼叫，
 *   造成三個問題：
 *     1. 排行內容不在 HTML 裡，搜尋引擎抓到的是空容器（SEO 零貢獻）
 *     2. 每位訪客都要等三次跨國 API 往返
 *     3. Jikan 有嚴格速率限制（3 req/s、60 req/min），流量一大就會有人載入失敗
 *   改為伺服器端抓取後，外部請求從「每位訪客各打一次」變成「全站每小時一次」。
 *
 * REST：
 *   GET weixiaoacg/v1/ranking/external?platform=anilist&period=weekly
 *     → { platform, period, updated, items: [...] }
 *
 * 快取：
 *   transient wxacg_rank_v{版本}_{platform}_{period} = { items: [...], time: 時間戳 }
 *   - 未滿 WXACG_RANKING_TTL 直接用
 *   - 過期則重新抓取；抓取失敗時回傳上一份舊資料（stale-while-error），
 *     避免外部 API 暫時故障就讓整頁空白
 *   - 另有 WP-Cron 定時預熱，讓訪客幾乎不會撞到冷啟動
 *
 * 注意：
 *   站內榜（platform=site）不走這裡，它本來就是伺服器端 REST
 *   （anime-sync-pro/includes/class-rating-manager.php 的 /ranking/site）。
 */

defined( 'ABSPATH' ) || exit;

/**
 * 快取結構版本。
 *
 * 只要項目欄位或正規化／在地化邏輯有異動就 +1，
 * 讓舊格式的快取因為鍵名不同而自動失效。
 *
 * 沒有這個版本號的話，改完邏輯得等舊快取自然過期（最長一週）才看得到效果，
 * v1.0.0 上線後補上在地化時就踩過這個坑。
 *
 * 2 — 加入繁中在地化（titleZh 換站內標題、url 改站內連結、新增 extId/internal）
 * 3 — MAL 改走官方 API（api.myanimelist.net），Jikan 降為備援
 */
const WXACG_RANKING_CACHE_VER = 3;

/** 資料視為新鮮的秒數 */
const WXACG_RANKING_TTL = HOUR_IN_SECONDS;

/** 舊資料最長保留多久（供外部 API 故障時墊檔） */
const WXACG_RANKING_KEEP = WEEK_IN_SECONDS;

/** 每個榜取幾筆（與前端原本的 limit=20 一致） */
const WXACG_RANKING_LIMIT = 20;

/** 對外請求的 User-Agent（Bangumi 要求要能識別來源） */
const WXACG_RANKING_UA = 'weixiaoacg/1.0 (+https://weixiaoacg.com)';

/** 允許的平台（site 不在此列，見檔頭說明） */
function wxacg_ranking_platforms(): array {
	return [ 'anilist', 'mal', 'bangumi' ];
}

/** 允許的期間 */
function wxacg_ranking_periods(): array {
	return [ 'daily', 'weekly', 'monthly', 'all' ];
}

function wxacg_ranking_norm_platform( $platform ): string {
	$platform = sanitize_key( (string) $platform );

	return in_array( $platform, wxacg_ranking_platforms(), true )
		? $platform
		: 'anilist';
}

function wxacg_ranking_norm_period( $period ): string {
	$period = sanitize_key( (string) $period );

	return in_array( $period, wxacg_ranking_periods(), true )
		? $period
		: 'weekly';
}

/* ============================================================
 * 取得排行（含快取與 stale-while-error）
 * ============================================================ */

/**
 * 快取鍵：帶結構版本，邏輯改版時舊資料自動失效。
 */
function wxacg_ranking_cache_key( string $platform, string $period ): string {
	return 'wxacg_rank_v' . WXACG_RANKING_CACHE_VER
		. '_' . wxacg_ranking_norm_platform( $platform )
		. '_' . wxacg_ranking_norm_period( $period );
}

/**
 * @param string $platform anilist | mal | bangumi
 * @param string $period   daily | weekly | monthly | all
 * @param bool   $force    true 時忽略新鮮度強制重抓（供 Cron 預熱用）
 * @return array 正規化後的項目陣列
 */
function wxacg_ranking_get( string $platform, string $period, bool $force = false ): array {
	$platform = wxacg_ranking_norm_platform( $platform );
	$period   = wxacg_ranking_norm_period( $period );

	$key    = wxacg_ranking_cache_key( $platform, $period );
	$stored = get_transient( $key );

	$has_stored = is_array( $stored ) && ! empty( $stored['items'] );

	if ( ! $force && $has_stored ) {
		$age = time() - (int) ( $stored['time'] ?? 0 );

		if ( $age < WXACG_RANKING_TTL ) {
			return $stored['items'];
		}
	}

	$items = wxacg_ranking_fetch( $platform, $period );
	$items = wxacg_ranking_localize( $items, $platform );

	if ( ! empty( $items ) ) {
		set_transient(
			$key,
			[
				'items' => $items,
				'time'  => time(),
			],
			WXACG_RANKING_KEEP
		);

		return $items;
	}

	// 抓取失敗 → 用上一份舊資料墊檔，總比整頁空白好。
	if ( $has_stored ) {
		return $stored['items'];
	}

	return [];
}

/**
 * 取得該筆快取的更新時間（給前端顯示「最後更新」）。
 */
function wxacg_ranking_updated_at( string $platform, string $period ): int {
	$stored = get_transient( wxacg_ranking_cache_key( $platform, $period ) );

	return is_array( $stored ) ? (int) ( $stored['time'] ?? 0 ) : 0;
}

/**
 * 依平台分派抓取。
 */
function wxacg_ranking_fetch( string $platform, string $period ): array {
	switch ( $platform ) {
		case 'mal':
			return wxacg_ranking_fetch_mal( $period );

		case 'bangumi':
			return wxacg_ranking_fetch_bangumi( $period );

		case 'anilist':
		default:
			return wxacg_ranking_fetch_anilist( $period );
	}
}

/**
 * 統一的錯誤記錄：不吞掉失敗，但也不讓前台壞掉。
 */
function wxacg_ranking_log_error( string $platform, string $message ): void {
	if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
		error_log( sprintf( '[wxacg-ranking] %s 抓取失敗：%s', $platform, $message ) );
	}
}

/* ============================================================
 * AniList（GraphQL）
 * ------------------------------------------------------------
 * 查詢語句與正規化欄位沿用原本 ranking.js 的 fetchAniList()，
 * 確保前端渲染邏輯不需要改動。
 * ============================================================ */

function wxacg_ranking_fetch_anilist( string $period ): array {
	$sort = in_array( $period, [ 'daily', 'weekly', 'monthly' ], true )
		? 'TRENDING_DESC'
		: 'SCORE_DESC';

	$query = 'query ($sort: [MediaSort], $perPage: Int) {
		Page(perPage: $perPage) {
			media(type: ANIME, sort: $sort, status_in: [RELEASING, FINISHED]) {
				id
				title { romaji native userPreferred }
				coverImage { large }
				averageScore
				popularity
				genres
				seasonYear
				season
			}
		}
	}';

	$response = wp_remote_post( 'https://graphql.anilist.co', [
		'timeout'    => 10,
		'user-agent' => WXACG_RANKING_UA,
		'headers'    => [
			'Content-Type' => 'application/json',
			'Accept'       => 'application/json',
		],
		'body'       => wp_json_encode( [
			'query'     => $query,
			'variables' => [
				'sort'    => [ $sort ],
				'perPage' => WXACG_RANKING_LIMIT,
			],
		] ),
	] );

	if ( is_wp_error( $response ) ) {
		wxacg_ranking_log_error( 'anilist', $response->get_error_message() );
		return [];
	}

	$code = (int) wp_remote_retrieve_response_code( $response );

	if ( $code !== 200 ) {
		wxacg_ranking_log_error( 'anilist', 'HTTP ' . $code );
		return [];
	}

	$json = json_decode( wp_remote_retrieve_body( $response ), true );
	$list = $json['data']['Page']['media'] ?? [];

	if ( ! is_array( $list ) ) {
		return [];
	}

	$season_map = [
		'WINTER' => '冬季',
		'SPRING' => '春季',
		'SUMMER' => '夏季',
		'FALL'   => '秋季',
	];

	$items = [];

	foreach ( $list as $i => $m ) {
		if ( ! is_array( $m ) ) {
			continue;
		}

		$score = isset( $m['averageScore'] ) && $m['averageScore']
			? number_format( (float) $m['averageScore'] / 10, 1 )
			: null;

		$year = '';
		if ( ! empty( $m['seasonYear'] ) ) {
			$season = $m['season'] ?? '';
			$year   = $season
				? $m['seasonYear'] . ' ' . ( $season_map[ $season ] ?? '' )
				: (string) $m['seasonYear'];
		}

		$items[] = [
			'rank'      => count( $items ) + 1,
			'titleZh'   => (string) ( $m['title']['userPreferred'] ?? $m['title']['romaji'] ?? '' ),
			'titleJp'   => (string) ( $m['title']['native'] ?? '' ),
			'cover'     => (string) ( $m['coverImage']['large'] ?? '' ),
			'score'     => $score,
			'scoredBy'  => (int) ( $m['popularity'] ?? 0 ),
			'genres'    => array_slice( (array) ( $m['genres'] ?? [] ), 0, 3 ),
			'year'      => trim( $year ),
			'anilistId' => (int) ( $m['id'] ?? 0 ),
			'extId'     => (int) ( $m['id'] ?? 0 ),
			'isSite'    => false,
			'url'       => 'https://anilist.co/anime/' . (int) ( $m['id'] ?? 0 ),
		];
	}

	return $items;
}

/* ============================================================
 * MyAnimeList（Jikan v4）
 * ============================================================ */

/**
 * MAL 官方 API 的 Client ID。
 *
 * 優先讀 wp-config.php 的常數（該檔已被 .gitignore 排除，不會進版控），
 * 其次讀 option，兩者皆無則回空字串 → 自動退回 Jikan，不會壞掉。
 *
 * wp-config.php 設定方式（兩種名稱皆可，優先用有前綴的）：
 *   define( 'WXACG_MAL_CLIENT_ID', '你的 Client ID' );
 *   define( 'MAL_CLIENT_ID',       '你的 Client ID' );
 *
 * 之所以兩種都接受：無前綴的 MAL_CLIENT_ID 比較直覺、實務上已經有人這樣設，
 * 但通用名稱有和其他外掛撞名的風險，故仍以有前綴者為準。
 */
function wxacg_ranking_mal_client_id(): string {
	if ( defined( 'WXACG_MAL_CLIENT_ID' ) && WXACG_MAL_CLIENT_ID ) {
		return trim( (string) WXACG_MAL_CLIENT_ID );
	}

	if ( defined( 'MAL_CLIENT_ID' ) && MAL_CLIENT_ID ) {
		return trim( (string) MAL_CLIENT_ID );
	}

	return trim( (string) get_option( 'wxacg_mal_client_id', '' ) );
}

function wxacg_ranking_fetch_mal( string $period ): array {
	// 有設定 Client ID 就走官方 API。
	if ( wxacg_ranking_mal_client_id() !== '' ) {
		$items = wxacg_ranking_fetch_mal_official( $period );

		if ( ! empty( $items ) ) {
			return $items;
		}

		wxacg_ranking_log_error( 'mal', '官方 API 取得失敗，改用 Jikan' );
	}

	$filter = '';

	if ( $period === 'daily' || $period === 'weekly' ) {
		$filter = 'airing';
	} elseif ( $period === 'monthly' ) {
		$filter = 'bypopularity';
	}

	$items = wxacg_ranking_fetch_mal_request( $filter );

	/*
	 * Jikan 對「有帶 filter」的請求會回源 MyAnimeList，
	 * MAL 不穩時整批回 504（實測連 page=1 這種參數都會失敗），
	 * 但不帶參數的總榜有快取、通常仍可用。
	 * 與其讓整個頁籤空白，不如退而求其次顯示總榜。
	 */
	if ( empty( $items ) && $filter !== '' ) {
		wxacg_ranking_log_error(
			'mal',
			sprintf( 'filter=%s 取得失敗，降級為不帶 filter 的總榜', $filter )
		);

		$items = wxacg_ranking_fetch_mal_request( '' );
	}

	return $items;
}

/**
 * MyAnimeList 官方 API（api.myanimelist.net/v2）。
 *
 * 公開排行只需要 X-MAL-CLIENT-ID 標頭，不必跑 OAuth 流程。
 * 相較 Jikan 少一層非官方轉送，穩定度較好。
 *
 * ranking_type 對應：
 *   daily / weekly → airing        （放送中）
 *   monthly        → bypopularity  （人氣）
 *   all            → all           （總榜）
 */
function wxacg_ranking_fetch_mal_official( string $period ): array {
	$client_id = wxacg_ranking_mal_client_id();

	if ( $client_id === '' ) {
		return [];
	}

	$ranking_type = 'all';

	if ( $period === 'daily' || $period === 'weekly' ) {
		$ranking_type = 'airing';
	} elseif ( $period === 'monthly' ) {
		$ranking_type = 'bypopularity';
	}

	$endpoint = add_query_arg(
		[
			'ranking_type' => $ranking_type,
			'limit'        => WXACG_RANKING_LIMIT,
			'fields'       => 'id,title,main_picture,mean,num_scoring_users,'
				. 'start_season,genres,alternative_titles',
		],
		'https://api.myanimelist.net/v2/anime/ranking'
	);

	$response = wp_remote_get( $endpoint, [
		'timeout'    => 10,
		'user-agent' => WXACG_RANKING_UA,
		'headers'    => [
			'X-MAL-CLIENT-ID' => $client_id,
			'Accept'          => 'application/json',
		],
	] );

	if ( is_wp_error( $response ) ) {
		wxacg_ranking_log_error( 'mal-official', $response->get_error_message() );
		return [];
	}

	$code = (int) wp_remote_retrieve_response_code( $response );

	if ( $code !== 200 ) {
		wxacg_ranking_log_error( 'mal-official', 'HTTP ' . $code );
		return [];
	}

	$json = json_decode( wp_remote_retrieve_body( $response ), true );
	$list = $json['data'] ?? [];

	if ( ! is_array( $list ) ) {
		return [];
	}

	$season_map = [
		'winter' => '冬季',
		'spring' => '春季',
		'summer' => '夏季',
		'fall'   => '秋季',
	];

	$items = [];

	foreach ( $list as $row ) {
		$m = $row['node'] ?? null;

		if ( ! is_array( $m ) ) {
			continue;
		}

		$genres = [];
		foreach ( array_slice( (array) ( $m['genres'] ?? [] ), 0, 3 ) as $g ) {
			if ( ! empty( $g['name'] ) ) {
				$genres[] = (string) $g['name'];
			}
		}

		$year = '';
		if ( ! empty( $m['start_season']['year'] ) ) {
			$season = strtolower( (string) ( $m['start_season']['season'] ?? '' ) );
			$year   = $season
				? $m['start_season']['year'] . ' ' . ( $season_map[ $season ] ?? '' )
				: (string) $m['start_season']['year'];
		}

		$mal_id = (int) ( $m['id'] ?? 0 );

		$items[] = [
			'rank'      => count( $items ) + 1,
			'titleZh'   => (string) ( $m['title'] ?? '' ),
			'titleJp'   => (string) ( $m['alternative_titles']['ja'] ?? '' ),
			'cover'     => (string) (
				$m['main_picture']['large'] ?? $m['main_picture']['medium'] ?? ''
			),
			'score'     => ! empty( $m['mean'] )
				? number_format( (float) $m['mean'], 1 )
				: null,
			'scoredBy'  => (int) ( $m['num_scoring_users'] ?? 0 ),
			'genres'    => $genres,
			'year'      => trim( $year ),
			'anilistId' => null,
			'extId'     => $mal_id,
			'isSite'    => false,
			'url'       => 'https://myanimelist.net/anime/' . $mal_id,
		];
	}

	return $items;
}

/**
 * 實際送出 Jikan 請求（官方 API 未設定或失敗時的備援）。
 *
 * @param string $filter airing | bypopularity | 空字串（總榜）
 */
function wxacg_ranking_fetch_mal_request( string $filter ): array {
	$endpoint = 'https://api.jikan.moe/v4/top/anime?limit=' . WXACG_RANKING_LIMIT;

	if ( $filter !== '' ) {
		$endpoint .= '&filter=' . rawurlencode( $filter );
	}

	$response = wp_remote_get( $endpoint, [
		'timeout'    => 10,
		'user-agent' => WXACG_RANKING_UA,
		'headers'    => [ 'Accept' => 'application/json' ],
	] );

	if ( is_wp_error( $response ) ) {
		wxacg_ranking_log_error( 'mal', $response->get_error_message() );
		return [];
	}

	$code = (int) wp_remote_retrieve_response_code( $response );

	if ( $code !== 200 ) {
		wxacg_ranking_log_error( 'mal', 'HTTP ' . $code );
		return [];
	}

	$json = json_decode( wp_remote_retrieve_body( $response ), true );
	$list = $json['data'] ?? [];

	if ( ! is_array( $list ) ) {
		return [];
	}

	$items = [];

	foreach ( $list as $m ) {
		if ( ! is_array( $m ) ) {
			continue;
		}

		$genres = [];
		foreach ( array_slice( (array) ( $m['genres'] ?? [] ), 0, 3 ) as $g ) {
			if ( ! empty( $g['name'] ) ) {
				$genres[] = (string) $g['name'];
			}
		}

		$items[] = [
			'rank'      => count( $items ) + 1,
			'titleZh'   => (string) ( $m['title'] ?? '' ),
			'titleJp'   => (string) ( $m['title_japanese'] ?? '' ),
			'cover'     => (string) (
				$m['images']['jpg']['large_image_url']
					?? $m['images']['jpg']['image_url']
					?? ''
			),
			'score'     => ! empty( $m['score'] )
				? number_format( (float) $m['score'], 1 )
				: null,
			'scoredBy'  => (int) ( $m['scored_by'] ?? 0 ),
			'genres'    => $genres,
			'year'      => ! empty( $m['year'] ) ? (string) $m['year'] : '',
			'anilistId' => null,
			'extId'     => (int) ( $m['mal_id'] ?? 0 ),
			'isSite'    => false,
			'url'       => (string) (
				$m['url'] ?? 'https://myanimelist.net/anime/' . (int) ( $m['mal_id'] ?? 0 )
			),
		];
	}

	return $items;
}

/* ============================================================
 * Bangumi
 * ============================================================ */

function wxacg_ranking_fetch_bangumi( string $period ): array {
	$response = wp_remote_get(
		'https://api.bgm.tv/v0/subjects?type=2&sort=rank&limit=' . WXACG_RANKING_LIMIT,
		[
			'timeout'    => 10,
			'user-agent' => WXACG_RANKING_UA,
			'headers'    => [ 'Accept' => 'application/json' ],
		]
	);

	if ( is_wp_error( $response ) ) {
		wxacg_ranking_log_error( 'bangumi', $response->get_error_message() );
		return [];
	}

	$code = (int) wp_remote_retrieve_response_code( $response );

	if ( $code !== 200 ) {
		wxacg_ranking_log_error( 'bangumi', 'HTTP ' . $code );
		return [];
	}

	$json = json_decode( wp_remote_retrieve_body( $response ), true );
	$list = $json['data'] ?? [];

	if ( ! is_array( $list ) ) {
		return [];
	}

	$items = [];

	foreach ( $list as $m ) {
		if ( ! is_array( $m ) ) {
			continue;
		}

		$tags = [];
		foreach ( array_slice( (array) ( $m['tags'] ?? [] ), 0, 3 ) as $t ) {
			if ( ! empty( $t['name'] ) ) {
				$tags[] = (string) $t['name'];
			}
		}

		$items[] = [
			'rank'      => count( $items ) + 1,
			'titleZh'   => (string) ( $m['name_cn'] ?? $m['name'] ?? '' ),
			'titleJp'   => (string) ( $m['name'] ?? '' ),
			'cover'     => (string) ( $m['images']['large'] ?? $m['images']['common'] ?? '' ),
			'score'     => ! empty( $m['rating']['score'] )
				? number_format( (float) $m['rating']['score'], 1 )
				: null,
			'scoredBy'  => (int) ( $m['rating']['total'] ?? 0 ),
			'genres'    => $tags,
			'year'      => ! empty( $m['date'] ) ? substr( (string) $m['date'], 0, 4 ) : '',
			'anilistId' => null,
			'extId'     => (int) ( $m['id'] ?? 0 ),
			'isSite'    => false,
			'url'       => 'https://bgm.tv/subject/' . (int) ( $m['id'] ?? 0 ),
		];
	}

	return $items;
}

/* ============================================================
 * 在地化：外部排行 → 站內資料
 * ------------------------------------------------------------
 * 外部三個平台回傳的都不是繁體中文：
 *   AniList  userPreferred 實際上是羅馬字（Tensei Shitara Slime...）
 *   MAL      title 為英文／羅馬字
 *   Bangumi  name_cn 是簡體
 *
 * 這裡用各平台的 ID 去對應站內已收錄的作品，命中就換成：
 *   - 站內的繁體標題（anime_title_chinese）
 *   - 站內的封面
 *   - 站內的網址（原本每張卡片都連往站外，等於把權重送出去；
 *     改為內部連結後既留住使用者，也強化站內連結結構）
 *
 * 沒收錄的作品：Bangumi 走 OpenCC 簡轉繁，其餘維持原標題。
 * ============================================================ */

/** 各平台對應的站內 post meta 鍵 */
function wxacg_ranking_id_meta_key( string $platform ): string {
	$map = [
		'anilist' => 'anime_anilist_id',
		'mal'     => 'anime_mal_id',
		'bangumi' => 'anime_bangumi_id',
	];

	return $map[ $platform ] ?? '';
}

/**
 * 從排行項目取出該平台的外部 ID。
 *
 * 各抓取函式都會把原生 ID 存進 extId，不從網址反推——
 * Jikan 回傳的 url 常帶 slug（/anime/52991/Sousou_no_Frieren），
 * 用尾端數字比對會抓不到。
 */
function wxacg_ranking_extract_id( array $item, string $platform ): int {
	return (int) ( $item['extId'] ?? 0 );
}

/**
 * @param array  $items    抓取後的原始項目
 * @param string $platform anilist | mal | bangumi
 * @return array 已在地化的項目
 */
function wxacg_ranking_localize( array $items, string $platform ): array {
	if ( empty( $items ) ) {
		return $items;
	}

	$meta_key = wxacg_ranking_id_meta_key( $platform );

	// 先蒐集這批的外部 ID，一次查詢對應的站內作品（避免逐筆 N+1）。
	$ids = [];
	foreach ( $items as $item ) {
		$id = wxacg_ranking_extract_id( $item, $platform );
		if ( $id > 0 ) {
			$ids[] = $id;
		}
	}

	$matched = [];

	if ( $meta_key !== '' && ! empty( $ids ) ) {
		$posts = get_posts( [
			'post_type'              => 'anime',
			'post_status'            => 'publish',
			'posts_per_page'         => -1,
			'no_found_rows'          => true,
			'update_post_term_cache' => false,
			'meta_query'             => [
				[
					'key'     => $meta_key,
					'value'   => array_values( array_unique( $ids ) ),
					'compare' => 'IN',
					'type'    => 'NUMERIC',
				],
			],
		] );

		foreach ( $posts as $post ) {
			$ext_id = (int) get_post_meta( $post->ID, $meta_key, true );

			if ( $ext_id > 0 && ! isset( $matched[ $ext_id ] ) ) {
				$matched[ $ext_id ] = $post;
			}
		}
	}

	$has_converter = class_exists( 'Anime_Sync_CN_Converter' );

	foreach ( $items as &$item ) {
		$ext_id = wxacg_ranking_extract_id( $item, $platform );
		$post   = $ext_id > 0 ? ( $matched[ $ext_id ] ?? null ) : null;

		if ( $post ) {
			$zh = trim( (string) get_post_meta( $post->ID, 'anime_title_chinese', true ) );

			if ( $zh !== '' ) {
				// 原標題退居副標，維持「繁中主標 + 原文副標」的既有版面。
				if ( empty( $item['titleJp'] ) ) {
					$item['titleJp'] = $item['titleZh'];
				}

				$item['titleZh'] = $zh;
			}

			$cover = trim( (string) get_post_meta( $post->ID, 'anime_cover_image', true ) );

			if ( $cover !== '' ) {
				$item['cover'] = $cover;
			}

			$item['url']      = get_permalink( $post );
			$item['internal'] = true;

			continue;
		}

		// 站內沒收錄：Bangumi 的簡體標題至少轉成繁體。
		if ( $platform === 'bangumi' && $has_converter && ! empty( $item['titleZh'] ) ) {
			$item['titleZh'] = Anime_Sync_CN_Converter::static_convert( (string) $item['titleZh'] );
		}

		$item['internal'] = false;
	}
	unset( $item );

	return $items;
}

/* ============================================================
 * 伺服器端渲染
 * ------------------------------------------------------------
 * 只負責「外部平台」的卡片，站內榜仍由 ranking.js 處理
 * （站內榜多了 4 維分項與收藏／瀏覽等變體，留在前端避免兩邊都要維護）。
 *
 * 標記結構刻意與 ranking.js 的 rankRenderList() 對齊，
 * 讓使用者切換頁籤由 JS 接手重繪時不會有視覺跳動。
 * ============================================================ */

function wxacg_ranking_platform_color( string $platform ): string {
	$colors = [
		'anilist' => '#02a9ff',
		'mal'     => '#2e51a2',
		'bangumi' => '#f09199',
	];

	return $colors[ $platform ] ?? '#63a8ff';
}

/**
 * 輸出排行卡片 HTML。
 *
 * @param array  $items    wxacg_ranking_get() 的回傳值
 * @param string $platform 用於取色
 */
function wxacg_ranking_render_cards( array $items, string $platform ): string {
	if ( empty( $items ) ) {
		return '';
	}

	$color = wxacg_ranking_platform_color( $platform );
	$html  = '';

	foreach ( $items as $item ) {
		$rank = (int) ( $item['rank'] ?? 0 );

		$num_class = '';
		if ( $rank === 1 ) {
			$num_class = 'rank-card__num--top1';
		} elseif ( $rank === 2 ) {
			$num_class = 'rank-card__num--top2';
		} elseif ( $rank === 3 ) {
			$num_class = 'rank-card__num--top3';
		}

		$title_zh = (string) ( $item['titleZh'] ?? '' );
		$title_jp = (string) ( $item['titleJp'] ?? '' );

		$tags_html = '';
		foreach ( (array) ( $item['genres'] ?? [] ) as $g ) {
			$tags_html .= '<span class="rank-card__tag">' . esc_html( $g ) . '</span>';
		}

		if ( ! empty( $item['year'] ) ) {
			$tags_html .= '<span class="rank-card__tag rank-card__tag--year">'
				. esc_html( $item['year'] ) . '</span>';
		}

		$score_html = '';
		if ( ! empty( $item['score'] ) ) {
			$score_html = '<div class="rank-card__score" style="color:' . esc_attr( $color ) . ';">'
				. esc_html( $item['score'] ) . '</div>'
				. '<div class="rank-card__score-label">/ 10</div>';
		}

		$votes_html = '';
		if ( ! empty( $item['scoredBy'] ) ) {
			$votes_html = '<div class="rank-card__votes">'
				. esc_html( number_format_i18n( (int) $item['scoredBy'] ) )
				. ' 人評分</div>';
		}

		$cover_html = ! empty( $item['cover'] )
			? '<img src="' . esc_url( $item['cover'] ) . '" alt="'
				. esc_attr( $title_zh ) . '" loading="lazy" decoding="async">'
			: '<div class="rank-card__cover-fb">🎬</div>';

		// 已對應到站內作品的連內部頁面（同分頁開啟），其餘才開新視窗連往站外。
		$is_internal = ! empty( $item['internal'] );

		$html .= '<a class="rank-card" href="' . esc_url( $item['url'] ?? '#' ) . '"'
			. ( $is_internal ? '' : ' target="_blank" rel="noopener noreferrer"' ) . '>'
			. '<div class="rank-card__rank">'
			. ( $rank === 1 ? '<span class="rank-card__crown">👑</span>' : '' )
			. '<div class="rank-card__num ' . esc_attr( $num_class ) . '">' . $rank . '</div>'
			. '</div>'
			. '<div class="rank-card__cover">' . $cover_html . '</div>'
			. '<div class="rank-card__body">'
			. '<div class="rank-card__title">' . esc_html( $title_zh ) . '</div>'
			. ( ( $title_jp !== '' && $title_jp !== $title_zh )
				? '<div class="rank-card__native">' . esc_html( $title_jp ) . '</div>'
				: '' )
			. '<div class="rank-card__tags">' . $tags_html . '</div>'
			. '</div>'
			. '<div class="rank-card__meta">'
			. $score_html
			. $votes_html
			. '<div class="rank-card__action" style="color:' . esc_attr( $color ) . ';'
			. 'border-color:' . esc_attr( $color ) . '44;">詳情 →</div>'
			. '</div>'
			. '</a>';
	}

	return $html;
}

/* ============================================================
 * REST：給前端切換頁籤時使用
 * ============================================================ */

add_action( 'rest_api_init', function () {
	register_rest_route( 'weixiaoacg/v1', '/ranking/external', [
		'methods'             => 'GET',
		'permission_callback' => '__return_true',
		'callback'            => 'wxacg_ranking_rest_external',
		'args'                => [
			'platform' => [
				'default'           => 'anilist',
				'sanitize_callback' => 'sanitize_key',
			],
			'period'   => [
				'default'           => 'weekly',
				'sanitize_callback' => 'sanitize_key',
			],
		],
	] );
} );

function wxacg_ranking_rest_external( WP_REST_Request $request ) {
	$platform = wxacg_ranking_norm_platform( $request->get_param( 'platform' ) );
	$period   = wxacg_ranking_norm_period( $request->get_param( 'period' ) );

	$items = wxacg_ranking_get( $platform, $period );

	return rest_ensure_response( [
		'platform' => $platform,
		'period'   => $period,
		'updated'  => wxacg_ranking_updated_at( $platform, $period ),
		'items'    => $items,
	] );
}

/* ============================================================
 * WP-Cron 預熱
 * ------------------------------------------------------------
 * 讓快取由背景排程更新，訪客請求不必等待外部 API。
 * 只預熱前端預設會用到的組合，避免無謂的外部請求。
 * ============================================================ */

add_action( 'wp', function () {
	if ( ! wp_next_scheduled( 'wxacg_ranking_warm_cron' ) ) {
		wp_schedule_event( time() + 300, 'hourly', 'wxacg_ranking_warm_cron' );
	}
} );

add_action( 'switch_theme', function () {
	wp_clear_scheduled_hook( 'wxacg_ranking_warm_cron' );
} );

add_action( 'wxacg_ranking_warm_cron', 'wxacg_ranking_warm' );

function wxacg_ranking_warm(): void {
	// 併發鎖：避免排程與手動觸發重疊，對外部 API 連續請求。
	if ( get_transient( 'wxacg_ranking_warm_lock' ) ) {
		return;
	}

	set_transient( 'wxacg_ranking_warm_lock', 1, 10 * MINUTE_IN_SECONDS );

	foreach ( wxacg_ranking_platforms() as $platform ) {
		wxacg_ranking_get( $platform, 'weekly', true );

		// Jikan 限制 3 req/s，平台之間稍微間隔，避免被擋。
		sleep( 1 );
	}

	delete_transient( 'wxacg_ranking_warm_lock' );
}
