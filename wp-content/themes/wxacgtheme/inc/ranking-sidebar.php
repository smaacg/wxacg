<?php
/**
 * 微笑動漫 — 排行頁側欄：本週新上榜 / 本週排名變動
 * 路徑：/blocksy-child/inc/ranking-sidebar.php
 * 版本：1.0.0 (2026-08-15)
 *
 * 這兩張卡片顯示的是「站內資料」，不隨主榜的平台頁籤切換而變化
 * （外部平台不提供歷史排名，算不出變動）。
 *
 * REST：
 *   GET weixiaoacg/v1/ranking/site/new     → 本週首次獲得評分的作品
 *   GET weixiaoacg/v1/ranking/site/movers  → 與上週快照相比名次變動最大的作品
 *
 * 資料來源：
 *   - 新上榜：直接查 anime_ratings 的 MIN(created_at)，不需歷史資料，
 *            所以上線當天就有內容。
 *   - 排名變動：需要「上週的名次」，而這個資訊無法回溯計算，
 *            必須先由排程逐週記錄。因此第一週只會回空陣列，
 *            要等第二次快照之後才有東西可比。
 *
 * 快照儲存：
 *   option wxacg_site_rank_snapshots = [ 'YYYYWW' => [ anime_id => 名次 ], ... ]
 *   只留最近 WXACG_RANK_SNAPSHOT_KEEP 週。每週 50 筆整數，資料量極小，
 *   不值得為此另開資料表。
 */

defined( 'ABSPATH' ) || exit;

/** 側欄每張卡片顯示幾筆 */
const WXACG_RANK_SIDEBAR_LIMIT = 5;

/** 快照取前幾名 */
const WXACG_RANK_SNAPSHOT_SIZE = 50;

/** 保留幾週的快照 */
const WXACG_RANK_SNAPSHOT_KEEP = 3;

/** 快照存放的 option 名稱 */
const WXACG_RANK_SNAPSHOT_OPTION = 'wxacg_site_rank_snapshots';

/* ============================================================
 * 共用：取得目前站內排行
 * ------------------------------------------------------------
 * 直接內部呼叫外掛既有的 /ranking/site，
 * 這樣貝葉斯加權、post_type 過濾等邏輯只有一份，不會兩邊各算各的。
 * ============================================================ */

function wxacg_sidebar_current_ranking( int $limit = WXACG_RANK_SNAPSHOT_SIZE ): array {
	$request = new WP_REST_Request( 'GET', '/weixiaoacg/v1/ranking/site' );
	$request->set_param( 'limit', max( 1, min( 50, $limit ) ) );
	$request->set_param( 'rank_type', 'rating' );
	$request->set_param( 'post_type', 'anime' );

	$response = rest_do_request( $request );

	if ( $response->is_error() ) {
		return [];
	}

	$data = $response->get_data();

	return is_array( $data ) ? $data : [];
}

/**
 * 評分資料表名稱；表不存在時回空字串（外掛未啟用或尚未建表）。
 */
function wxacg_sidebar_ratings_table(): string {
	global $wpdb;

	static $table = null;

	if ( $table !== null ) {
		return $table;
	}

	$name   = $wpdb->prefix . 'anime_ratings';
	$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $name ) );
	$table  = ( $exists === $name ) ? $name : '';

	return $table;
}

/* ============================================================
 * 本週新上榜
 * ------------------------------------------------------------
 * 定義：該作品的「第一筆評分」落在最近 7 天內。
 * 用 MIN(created_at) 判斷，所以只會抓到真正首次進榜的作品，
 * 舊作品新增評分不會被誤判為新上榜。
 * ============================================================ */

function wxacg_sidebar_get_new_entries( int $limit = WXACG_RANK_SIDEBAR_LIMIT ): array {
	global $wpdb;

	$table = wxacg_sidebar_ratings_table();

	if ( $table === '' ) {
		return [];
	}

	/*
	 * anime_ratings.created_at 由 class-rating-manager.php 以
	 * current_time('mysql') 寫入，存的是「站點時區」的時間字串。
	 * 這裡必須用 wp_date() 產生同樣時區的比較基準，
	 * 若用 gmdate() 會固定差一個時區偏移（台灣為 8 小時），
	 * 導致邊界附近的評分被算錯。
	 */
	$since = wp_date( 'Y-m-d H:i:s', time() - WEEK_IN_SECONDS );

	$rows = $wpdb->get_results( $wpdb->prepare(
		"SELECT anime_id,
		        MIN(created_at) AS first_rated,
		        COUNT(*)        AS vote_count,
		        AVG(score_overall) AS avg_overall
		 FROM {$table}
		 GROUP BY anime_id
		 HAVING first_rated >= %s
		 ORDER BY first_rated DESC
		 LIMIT %d",
		$since,
		max( 1, $limit * 3 ) // 多撈一些，因為下面還要過濾非公開文章
	) );

	if ( empty( $rows ) ) {
		return [];
	}

	$items = [];

	foreach ( $rows as $row ) {
		$post = get_post( (int) $row->anime_id );

		if ( ! $post || $post->post_status !== 'publish' || $post->post_type !== 'anime' ) {
			continue;
		}

		$items[] = [
			'anime_id'   => (int) $row->anime_id,
			'title'      => get_post_meta( $post->ID, 'anime_title_chinese', true ) ?: $post->post_title,
			'cover'      => (string) get_post_meta( $post->ID, 'anime_cover_image', true ),
			'url'        => get_permalink( $post->ID ),
			'score'      => round( (float) $row->avg_overall, 1 ),
			'vote_count' => (int) $row->vote_count,
		];

		if ( count( $items ) >= $limit ) {
			break;
		}
	}

	return $items;
}

/* ============================================================
 * 每週快照
 * ============================================================ */

/**
 * 目前週次代碼，例如 202633。
 *
 * 用站點時區判定「第幾週」，與讀者認知的「本週」一致
 * （也與 created_at 的時區基準相同）。
 */
function wxacg_sidebar_week_key( int $timestamp = 0 ): string {
	return wp_date( 'oW', $timestamp ?: time() );
}

function wxacg_sidebar_get_snapshots(): array {
	$stored = get_option( WXACG_RANK_SNAPSHOT_OPTION, [] );

	return is_array( $stored ) ? $stored : [];
}

/**
 * 記錄本週排行快照。
 *
 * 同一週重複執行會覆蓋（以最後一次為準），不會累積多筆。
 */
function wxacg_sidebar_take_snapshot(): void {
	$ranking = wxacg_sidebar_current_ranking();

	if ( empty( $ranking ) ) {
		// 沒撈到資料就不要寫入空快照，否則下週比對會誤判成「全部都是新進榜」。
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[wxacg-ranking-sidebar] 快照略過：目前排行為空' );
		}

		return;
	}

	$positions = [];
	$rank      = 0;

	foreach ( $ranking as $item ) {
		$anime_id = (int) ( $item['anime_id'] ?? 0 );

		if ( $anime_id <= 0 ) {
			continue;
		}

		$rank++;
		$positions[ $anime_id ] = $rank;
	}

	if ( empty( $positions ) ) {
		return;
	}

	$snapshots = wxacg_sidebar_get_snapshots();
	$snapshots[ wxacg_sidebar_week_key() ] = $positions;

	// 只留最近幾週，避免 option 無限膨脹。
	krsort( $snapshots );
	$snapshots = array_slice( $snapshots, 0, WXACG_RANK_SNAPSHOT_KEEP, true );

	update_option( WXACG_RANK_SNAPSHOT_OPTION, $snapshots, false );
}

add_action( 'wxacg_rank_snapshot_cron', 'wxacg_sidebar_take_snapshot' );

add_action( 'wp', function () {
	if ( ! wp_next_scheduled( 'wxacg_rank_snapshot_cron' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'weekly', 'wxacg_rank_snapshot_cron' );
	}
} );

add_action( 'switch_theme', function () {
	wp_clear_scheduled_hook( 'wxacg_rank_snapshot_cron' );
} );

/**
 * WordPress 5.4 起內建 weekly 排程；舊版本自行補上。
 */
add_filter( 'cron_schedules', function ( $schedules ) {
	if ( ! isset( $schedules['weekly'] ) ) {
		$schedules['weekly'] = [
			'interval' => WEEK_IN_SECONDS,
			'display'  => '每週一次',
		];
	}

	return $schedules;
} );

/* ============================================================
 * 本週排名變動
 * ------------------------------------------------------------
 * 拿「目前名次」跟「最近一次快照的名次」相比。
 * 沒有任何快照時回空陣列——名次變動無法回溯計算，
 * 必須等排程累積至少一次紀錄。
 * ============================================================ */

function wxacg_sidebar_get_movers( int $limit = WXACG_RANK_SIDEBAR_LIMIT ): array {
	$snapshots = wxacg_sidebar_get_snapshots();

	if ( empty( $snapshots ) ) {
		return [];
	}

	// 取「不是本週」的最近一份快照當作比較基準。
	$current_week = wxacg_sidebar_week_key();

	krsort( $snapshots );

	$baseline = [];

	foreach ( $snapshots as $week => $positions ) {
		if ( (string) $week === $current_week ) {
			continue;
		}

		$baseline = is_array( $positions ) ? $positions : [];
		break;
	}

	if ( empty( $baseline ) ) {
		return [];
	}

	$ranking = wxacg_sidebar_current_ranking();

	if ( empty( $ranking ) ) {
		return [];
	}

	$movers = [];
	$rank   = 0;

	foreach ( $ranking as $item ) {
		$anime_id = (int) ( $item['anime_id'] ?? 0 );

		if ( $anime_id <= 0 ) {
			continue;
		}

		$rank++;

		// 上週不在榜上的作品屬於「新進榜」，交給另一張卡片呈現，這裡不重複。
		if ( ! isset( $baseline[ $anime_id ] ) ) {
			continue;
		}

		$delta = (int) $baseline[ $anime_id ] - $rank; // 正值代表名次上升

		if ( $delta === 0 ) {
			continue;
		}

		$movers[] = [
			'anime_id' => $anime_id,
			'title'    => (string) ( $item['title'] ?? '' ),
			'cover'    => (string) ( $item['cover'] ?? '' ),
			'url'      => (string) ( $item['url'] ?? '' ),
			'rank'     => $rank,
			'prevRank' => (int) $baseline[ $anime_id ],
			'delta'    => $delta,
		];
	}

	// 變動幅度大的排前面（升跌都算）。
	usort( $movers, fn( $a, $b ) => abs( $b['delta'] ) <=> abs( $a['delta'] ) );

	return array_slice( $movers, 0, $limit );
}

/* ============================================================
 * REST
 * ============================================================ */

add_action( 'rest_api_init', function () {
	$args = [
		'limit' => [
			'default'           => WXACG_RANK_SIDEBAR_LIMIT,
			'sanitize_callback' => 'absint',
		],
	];

	register_rest_route( 'weixiaoacg/v1', '/ranking/site/new', [
		'methods'             => 'GET',
		'permission_callback' => '__return_true',
		'callback'            => 'wxacg_sidebar_rest_new',
		'args'                => $args,
	] );

	register_rest_route( 'weixiaoacg/v1', '/ranking/site/movers', [
		'methods'             => 'GET',
		'permission_callback' => '__return_true',
		'callback'            => 'wxacg_sidebar_rest_movers',
		'args'                => $args,
	] );
} );

function wxacg_sidebar_rest_new( WP_REST_Request $request ) {
	$limit = max( 1, min( 20, (int) $request->get_param( 'limit' ) ) );

	$cache_key = 'wxacg_sb_new_' . $limit;
	$cached    = get_transient( $cache_key );

	if ( is_array( $cached ) ) {
		return rest_ensure_response( $cached );
	}

	$payload = [ 'items' => wxacg_sidebar_get_new_entries( $limit ) ];

	set_transient( $cache_key, $payload, 15 * MINUTE_IN_SECONDS );

	return rest_ensure_response( $payload );
}

function wxacg_sidebar_rest_movers( WP_REST_Request $request ) {
	$limit = max( 1, min( 20, (int) $request->get_param( 'limit' ) ) );

	$cache_key = 'wxacg_sb_movers_' . $limit;
	$cached    = get_transient( $cache_key );

	if ( is_array( $cached ) ) {
		return rest_ensure_response( $cached );
	}

	$snapshots = wxacg_sidebar_get_snapshots();

	$payload = [
		'items' => wxacg_sidebar_get_movers( $limit ),
		// 前端用這個旗標區分「還在累積資料」與「本週剛好沒有變動」。
		'ready' => count( $snapshots ) > 0,
	];

	set_transient( $cache_key, $payload, 15 * MINUTE_IN_SECONDS );

	return rest_ensure_response( $payload );
}
