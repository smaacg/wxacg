<?php
/**
 * Follow System — 追蹤系統核心
 *
 * @package weixiaoacg
 * @version 1.2.0 (2026-05-26)
 *
 * v1.2.0 變更：
 *   - 【Bug G 修正】daily limit 從 transient 改為 user_meta 儲存。
 *     原因：transient 在啟用 Redis / Memcached / LiteSpeed Object Cache
 *     時實際寫入快取後端，後台「清除全部快取」、Redis FLUSHALL、
 *     Object Cache 重啟皆會直接歸零計數，使用者可繞過 200/天上限。
 *     user_meta 永久寫入 wp_usermeta，無此風險。
 *     - 新 key：user_meta 'wxacg_follow_daily' = [ 'date' => 'YYYYMMDD', 'count' => N ]
 *     - 過期靠當日 date 比對：日期變了視為 0，舊資料保留待 GC
 *     - 提供 wxacg_cleanup_follow_daily_meta() 一次性清理用（uninstall 已覆蓋）
 *
 * v1.1.0 變更：
 *   - Bug #3 修正：daily limit 改用 transient 計數器（被 v1.2.0 取代）
 *   - Bug #4 修正：時區比對改用 current_time('Y-m-d 00:00:00')
 *
 * 提供功能：
 * - wp_wxacg_follows 資料表自動建立 / 升級（dbDelta）
 * - wxacg_follow_user( $follower, $following )
 * - wxacg_unfollow_user( $follower, $following )
 * - wxacg_is_following( $follower, $following )
 * - smacg_get_following_count( $user_id )
 * - smacg_get_followers_count( $user_id )
 * - wxacg_get_following_ids( $user_id, $limit, $offset )
 * - wxacg_get_followers_ids( $user_id, $limit, $offset )
 *
 * Action hooks：
 * - do_action( 'smacg_user_followed',   $follower_id, $following_id )
 * - do_action( 'smacg_user_unfollowed', $follower_id, $following_id )
 *
 * 限制：
 * - 不能追蹤自己
 * - 不能追蹤未啟用 / 不存在的使用者
 * - UNIQUE(follower_id, following_id) 防止重複追蹤
 * - 單日上限 WXACG_FOLLOW_DAILY_LIMIT（預設 200）— user_meta 計數
 * - 連點冷卻 WXACG_FOLLOW_COOLDOWN 秒（預設 1）— transient cooldown
 *   （cooldown 是 1 秒短期防快點，被快取清除無傷害，保留用 transient）
 */
defined( 'ABSPATH' ) || exit;

/* ============================================================
   資料表名稱 helper
   ============================================================ */
function wxacg_follows_table() {
	global $wpdb;
	return $wpdb->prefix . 'wxacg_follows';
}

/* ============================================================
   資料表安裝 / 升級
   ============================================================ */
define( 'WXACG_FOLLOWS_DB_VERSION', '1.0.0' );

function wxacg_follows_install() {
	global $wpdb;

	$installed = get_option( 'wxacg_follows_db_version' );
	if ( $installed === WXACG_FOLLOWS_DB_VERSION ) {
		return;
	}

	$table   = wxacg_follows_table();
	$charset = $wpdb->get_charset_collate();

	$sql = "CREATE TABLE {$table} (
		id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
		follower_id BIGINT(20) UNSIGNED NOT NULL,
		following_id BIGINT(20) UNSIGNED NOT NULL,
		created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
		PRIMARY KEY  (id),
		UNIQUE KEY uniq_pair (follower_id, following_id),
		KEY idx_following (following_id),
		KEY idx_follower_created (follower_id, created_at)
	) {$charset};";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql );

	update_option( 'wxacg_follows_db_version', WXACG_FOLLOWS_DB_VERSION );
}
add_action( 'admin_init', 'wxacg_follows_install' );
add_action( 'init', 'wxacg_follows_install', 5 );

/* ============================================================
   核心：追蹤
   ============================================================ */
function wxacg_follow_user( $follower_id, $following_id ) {
	global $wpdb;

	$follower_id  = absint( $follower_id );
	$following_id = absint( $following_id );

	if ( ! $follower_id || ! $following_id ) {
		return new WP_Error( 'smacg_invalid_user', '使用者 ID 無效' );
	}
	if ( $follower_id === $following_id ) {
		return new WP_Error( 'smacg_self_follow', '不能追蹤自己' );
	}
	if ( ! get_userdata( $following_id ) ) {
		return new WP_Error( 'smacg_target_not_found', '目標使用者不存在' );
	}

	// 連點冷卻（短期 1 秒、快取清除無傷害，繼續用 transient）
	$cd_key = 'wxacg_follow_cd_' . $follower_id . '_' . $following_id;
	if ( get_transient( $cd_key ) ) {
		return new WP_Error( 'wxacg_follow_cooldown', '操作太頻繁，請稍候' );
	}

	// 單日上限（user_meta，無法被快取清除繞過）
	$today_count = wxacg_get_follow_today_count( $follower_id );
	if ( $today_count >= WXACG_FOLLOW_DAILY_LIMIT ) {
		return new WP_Error(
			'wxacg_follow_daily_limit',
			sprintf( '今日追蹤已達上限 %d 人，明天再試', WXACG_FOLLOW_DAILY_LIMIT )
		);
	}

	// 已追蹤？
	if ( wxacg_is_following( $follower_id, $following_id ) ) {
		return new WP_Error( 'smacg_already_following', '已經在追蹤了' );
	}

	$table = wxacg_follows_table();
	$ok = $wpdb->insert(
		$table,
		[
			'follower_id'  => $follower_id,
			'following_id' => $following_id,
			'created_at'   => current_time( 'mysql' ),
		],
		[ '%d', '%d', '%s' ]
	);

	if ( false === $ok ) {
		return new WP_Error( 'smacg_db_insert_failed', '資料庫寫入失敗' );
	}

	// 成功後：增加 daily 計數、設定冷卻
	wxacg_increment_follow_today_count( $follower_id );
	set_transient( $cd_key, 1, WXACG_FOLLOW_COOLDOWN );

	wxacg_clear_follow_cache( $follower_id, $following_id );

	do_action( 'smacg_user_followed', $follower_id, $following_id );

	return true;
}

/**
 * 取消追蹤
 */
function wxacg_unfollow_user( $follower_id, $following_id ) {
	global $wpdb;

	$follower_id  = absint( $follower_id );
	$following_id = absint( $following_id );

	if ( ! $follower_id || ! $following_id ) {
		return new WP_Error( 'smacg_invalid_user', '使用者 ID 無效' );
	}

	$table = wxacg_follows_table();
	$deleted = $wpdb->delete(
		$table,
		[
			'follower_id'  => $follower_id,
			'following_id' => $following_id,
		],
		[ '%d', '%d' ]
	);

	if ( false === $deleted ) {
		return new WP_Error( 'smacg_db_delete_failed', '資料庫刪除失敗' );
	}
	if ( 0 === $deleted ) {
		return new WP_Error( 'smacg_not_following', '尚未追蹤' );
	}

	wxacg_clear_follow_cache( $follower_id, $following_id );

	do_action( 'smacg_user_unfollowed', $follower_id, $following_id );

	return true;
}

/* ============================================================
   查詢 helpers
   ============================================================ */
function wxacg_is_following( $follower_id, $following_id ) {
	global $wpdb;

	$follower_id  = absint( $follower_id );
	$following_id = absint( $following_id );
	if ( ! $follower_id || ! $following_id ) {
		return false;
	}

	$cache_key = "wxacg_is_following_{$follower_id}_{$following_id}";
	$cached    = wp_cache_get( $cache_key, 'wxacg_follows' );
	if ( false !== $cached ) {
		return (bool) $cached;
	}

	$table = wxacg_follows_table();
	$row   = $wpdb->get_var( $wpdb->prepare(
		"SELECT id FROM {$table} WHERE follower_id = %d AND following_id = %d LIMIT 1",
		$follower_id,
		$following_id
	) );

	$result = ! empty( $row );
	wp_cache_set( $cache_key, $result ? 1 : 0, 'wxacg_follows', 300 );

	return $result;
}

function smacg_get_following_count( $user_id ) {
	global $wpdb;

	$user_id = absint( $user_id );
	if ( ! $user_id ) {
		return 0;
	}

	$cache_key = "wxacg_following_count_{$user_id}";
	$cached    = wp_cache_get( $cache_key, 'wxacg_follows' );
	if ( false !== $cached ) {
		return (int) $cached;
	}

	$table = wxacg_follows_table();
	$count = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM {$table} WHERE follower_id = %d",
		$user_id
	) );

	wp_cache_set( $cache_key, $count, 'wxacg_follows', 300 );
	return $count;
}

function smacg_get_followers_count( $user_id ) {
	global $wpdb;

	$user_id = absint( $user_id );
	if ( ! $user_id ) {
		return 0;
	}

	$cache_key = "wxacg_followers_count_{$user_id}";
	$cached    = wp_cache_get( $cache_key, 'wxacg_follows' );
	if ( false !== $cached ) {
		return (int) $cached;
	}

	$table = wxacg_follows_table();
	$count = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM {$table} WHERE following_id = %d",
		$user_id
	) );

	wp_cache_set( $cache_key, $count, 'wxacg_follows', 300 );
	return $count;
}

function wxacg_get_following_ids( $user_id, $limit = 20, $offset = 0 ) {
	global $wpdb;

	$user_id = absint( $user_id );
	$limit   = max( 1, min( 100, absint( $limit ) ) );
	$offset  = max( 0, absint( $offset ) );
	if ( ! $user_id ) {
		return [];
	}

	$table = wxacg_follows_table();
	$ids = $wpdb->get_col( $wpdb->prepare(
		"SELECT following_id FROM {$table}
		 WHERE follower_id = %d
		 ORDER BY created_at DESC
		 LIMIT %d OFFSET %d",
		$user_id, $limit, $offset
	) );

	return array_map( 'intval', $ids ?: [] );
}

function wxacg_get_followers_ids( $user_id, $limit = 20, $offset = 0 ) {
	global $wpdb;

	$user_id = absint( $user_id );
	$limit   = max( 1, min( 100, absint( $limit ) ) );
	$offset  = max( 0, absint( $offset ) );
	if ( ! $user_id ) {
		return [];
	}

	$table = wxacg_follows_table();
	$ids = $wpdb->get_col( $wpdb->prepare(
		"SELECT follower_id FROM {$table}
		 WHERE following_id = %d
		 ORDER BY created_at DESC
		 LIMIT %d OFFSET %d",
		$user_id, $limit, $offset
	) );

	return array_map( 'intval', $ids ?: [] );
}

/* ============================================================
   今日追蹤計數（防刷用）— v1.2.0：user_meta 儲存
   ------------------------------------------------------------
   user_meta key:  'wxacg_follow_daily'
   值結構:         [ 'date' => 'YYYYMMDD', 'count' => N ]
   日期變了視為 0（自動 reset），無需 cron 清理。
   舊資料只佔一筆 user_meta（單行 ≤ 50 bytes），可忽略。
   ============================================================ */
function wxacg_get_follow_today_count( $user_id ) {
	$user_id = absint( $user_id );
	if ( ! $user_id ) return 0;

	$today = current_time( 'Ymd' );
	$meta  = get_user_meta( $user_id, 'wxacg_follow_daily', true );

	if ( ! is_array( $meta ) || empty( $meta['date'] ) || $meta['date'] !== $today ) {
		return 0;
	}
	return (int) ( $meta['count'] ?? 0 );
}

function wxacg_increment_follow_today_count( $user_id ) {
	$user_id = absint( $user_id );
	if ( ! $user_id ) return;

	$today = current_time( 'Ymd' );
	$meta  = get_user_meta( $user_id, 'wxacg_follow_daily', true );

	if ( ! is_array( $meta ) || empty( $meta['date'] ) || $meta['date'] !== $today ) {
		$meta = [ 'date' => $today, 'count' => 0 ];
	}
	$meta['count'] = (int) ( $meta['count'] ?? 0 ) + 1;

	update_user_meta( $user_id, 'wxacg_follow_daily', $meta );
}

/**
 * （可選）一次性清理所有 user_meta daily 計數
 * 例如賽季重置、惡意刷分後 reset 用。uninstall.php 已涵蓋。
 */
function wxacg_cleanup_follow_daily_meta() {
	global $wpdb;
	return (int) $wpdb->delete(
		$wpdb->usermeta,
		[ 'meta_key' => 'wxacg_follow_daily' ],
		[ '%s' ]
	);
}

/* ============================================================
   快取清理
   ============================================================ */
function wxacg_clear_follow_cache( $follower_id, $following_id ) {
	wp_cache_delete( "wxacg_is_following_{$follower_id}_{$following_id}", 'wxacg_follows' );
	wp_cache_delete( "wxacg_following_count_{$follower_id}",              'wxacg_follows' );
	wp_cache_delete( "wxacg_followers_count_{$following_id}",             'wxacg_follows' );
}

/* ============================================================
   使用者刪除時清理追蹤記錄
   ============================================================ */
add_action( 'deleted_user', function( $user_id ) {
	global $wpdb;
	$table = wxacg_follows_table();
	$wpdb->delete( $table, [ 'follower_id'  => $user_id ], [ '%d' ] );
	$wpdb->delete( $table, [ 'following_id' => $user_id ], [ '%d' ] );
	delete_user_meta( $user_id, 'wxacg_follow_daily' );  // v1.2.0
} );
