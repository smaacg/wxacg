<?php
/**
 * Notifications System — 通知中心核心
 *
 * @package weixiaoacg
 * @version 1.2.0 (2026-05-23)
 *
 * v1.2.0 變更：
 *   - 新增 `rank_up` 通知類型（段位「大段」升級）
 *   - 對應偏好：rank_up_site（預設開）/ rank_up_email（預設關）
 *
 * v1.1.0 變更：
 *   - 預設偏好調整：站內全開、Email 全關、digest='off'
 *   - 刪除無效的 register_activation_hook(__FILE__,...)
 *   - wxacg_should_notify() 對 email channel 加 force 支援
 *
 * 通知類型（type 欄位）：
 *   follow          - 被追蹤
 *   comment_reply   - 留言被回覆
 *   rating          - 動畫被評分
 *   badge           - 解鎖徽章
 *   level_up        - 等級提升
 *   rank_up         - 段位升級（大段：鐵→銅、銀→金 ...）★ v1.2.0
 *   system          - 系統公告
 */
defined( 'ABSPATH' ) || exit;

/* ============================================================
   常數
   ============================================================ */
define( 'SMACG_NOTIF_DB_VERSION',     '1.0.0' );
define( 'SMACG_NOTIF_RETENTION_DAYS', 30 );

/* ============================================================
   資料表名稱
   ============================================================ */
function wxacg_notifications_table() {
	global $wpdb;
	return $wpdb->prefix . 'wxacg_notifications';
}

/* ============================================================
   資料表安裝
   ============================================================ */
function wxacg_notifications_install() {
	global $wpdb;

	$installed = get_option( 'smacg_notif_db_version' );
	if ( $installed === SMACG_NOTIF_DB_VERSION ) {
		return;
	}

	$table   = wxacg_notifications_table();
	$charset = $wpdb->get_charset_collate();

	$sql = "CREATE TABLE {$table} (
		id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
		user_id BIGINT(20) UNSIGNED NOT NULL,
		type VARCHAR(32) NOT NULL,
		actor_id BIGINT(20) UNSIGNED NULL,
		object_type VARCHAR(32) NULL,
		object_id BIGINT(20) UNSIGNED NULL,
		data TEXT NULL,
		is_read TINYINT(1) NOT NULL DEFAULT 0,
		created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
		PRIMARY KEY  (id),
		KEY idx_user_read (user_id, is_read, created_at),
		KEY idx_created (created_at),
		KEY idx_user_type (user_id, type)
	) {$charset};";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql );

	update_option( 'smacg_notif_db_version', SMACG_NOTIF_DB_VERSION );
}
add_action( 'admin_init', 'wxacg_notifications_install' );
add_action( 'init', 'wxacg_notifications_install', 5 );

/* ============================================================
   通知偏好預設值
   ------------------------------------------------------------
   v1.2.0：新增 rank_up_site / rank_up_email
   ============================================================ */
function smacg_get_notification_prefs_defaults() {
	/*
	 * 清單的唯一來源是 legacy/notification-types.php。
	 *
	 * 這裡原本是寫死的第二份，與 wxacg-members 設定頁的 $types 各自維護，
	 * 兩處不同步不會報錯：只加這裡→會員關不掉；只加設定頁→通知靜默不送。
	 * 後者實際發生過（event_ended 從上線以來一則都沒送出去）。
	 *
	 * ★ 這裡自己 require，不依賴 class-plugin.php 的載入順序。
	 *   class-activator.php 會在啟用流程單獨 require 本檔，若那條路徑上
	 *   註冊表還沒載入，本函式就會回傳一份缺了所有類型的偏好，
	 *   而 wxacg_should_notify() 對每個類型都會回 false——
	 *   全站通知靜默停擺，而且完全無聲。
	 */
	require_once __DIR__ . '/notification-types.php';

	$defaults = [];

	foreach ( wxacg_notification_types_configurable() as $key => $type ) {
		$defaults[ $key . '_site' ]  = isset( $type['site'] ) ? (int) $type['site'] : 1;
		$defaults[ $key . '_email' ] = isset( $type['email'] ) ? (int) $type['email'] : 0;
	}

	// Email 摘要頻率不屬於任何單一類型，維持獨立。
	$defaults['email_digest'] = 'off';

	return $defaults;
}

function smacg_get_notification_prefs( $user_id ) {
	$user_id = absint( $user_id );
	if ( ! $user_id ) {
		return smacg_get_notification_prefs_defaults();
	}

	$stored = get_user_meta( $user_id, 'smacg_notification_prefs', true );
	if ( ! is_array( $stored ) ) {
		$stored = [];
	}

	return array_merge( smacg_get_notification_prefs_defaults(), $stored );
}

function smacg_update_notification_prefs( $user_id, $partial ) {
	$user_id = absint( $user_id );
	if ( ! $user_id || ! is_array( $partial ) ) {
		return false;
	}

	$current = smacg_get_notification_prefs( $user_id );
	$valid_keys = array_keys( smacg_get_notification_prefs_defaults() );

	$clean = [];
	foreach ( $partial as $k => $v ) {
		if ( ! in_array( $k, $valid_keys, true ) ) continue;
		if ( $k === 'email_digest' ) {
			$clean[ $k ] = in_array( $v, [ 'off', 'daily', 'weekly' ], true ) ? $v : 'off';
		} else {
			$clean[ $k ] = $v ? 1 : 0;
		}
	}

	$merged = array_merge( $current, $clean );
	return update_user_meta( $user_id, 'smacg_notification_prefs', $merged );
}

function wxacg_should_notify( $user_id, $type, $channel = 'site' ) {
	$prefs = smacg_get_notification_prefs( $user_id );
	$key   = $type . '_' . $channel;
	return ! empty( $prefs[ $key ] );
}

/* ============================================================
   新註冊使用者：寫入預設偏好
   ============================================================ */
add_action( 'user_register', function( $user_id ) {
	if ( ! get_user_meta( $user_id, 'smacg_notification_prefs', true ) ) {
		update_user_meta( $user_id, 'smacg_notification_prefs', smacg_get_notification_prefs_defaults() );
	}
} );

/* ============================================================
   建立通知
   ============================================================ */
function wxacg_create_notification( $args ) {
	global $wpdb;

	$args = wp_parse_args( $args, [
		'user_id'     => 0,
		'type'        => '',
		'actor_id'    => null,
		'object_type' => null,
		'object_id'   => null,
		'data'        => null,
		'force'       => false,
		'force_email' => false,
	] );

	$user_id = absint( $args['user_id'] );
	$type    = sanitize_key( $args['type'] );

	if ( ! get_userdata( $user_id ) ) {
		return new WP_Error( 'smacg_notif_no_user', '收件人不存在' );
	}

	if ( ! $user_id || ! $type ) {
		return new WP_Error( 'smacg_notif_invalid', '收件人或類型缺失' );
	}

	if ( $args['actor_id'] && (int) $args['actor_id'] === $user_id ) {
		return new WP_Error( 'smacg_notif_self', '不通知自己' );
	}

	if ( ! $args['force'] && ! wxacg_should_notify( $user_id, $type, 'site' ) ) {
		return new WP_Error( 'smacg_notif_disabled', '使用者已關閉此類通知' );
	}

	$data_json = null;
	if ( is_array( $args['data'] ) && ! empty( $args['data'] ) ) {
		$data_json = wp_json_encode( $args['data'] );
	}

	$ok = $wpdb->insert(
		wxacg_notifications_table(),
		[
			'user_id'     => $user_id,
			'type'        => $type,
			'actor_id'    => $args['actor_id'] ? absint( $args['actor_id'] ) : null,
			'object_type' => $args['object_type'] ? sanitize_key( $args['object_type'] ) : null,
			'object_id'   => $args['object_id'] ? absint( $args['object_id'] ) : null,
			'data'        => $data_json,
			'is_read'     => 0,
			'created_at'  => current_time( 'mysql' ),
		],
		[ '%d', '%s', '%d', '%s', '%d', '%s', '%d', '%s' ]
	);

	if ( false === $ok ) {
		return new WP_Error( 'smacg_notif_db_failed', '寫入失敗' );
	}

	$notif_id = (int) $wpdb->insert_id;

	wxacg_clear_notification_cache( $user_id );

	do_action( 'smacg_notification_created', $notif_id, $user_id, $type, $args );

	return $notif_id;
}

/* ============================================================
   查詢通知
   ============================================================ */
function wxacg_get_notifications( $user_id, $args = [] ) {
	global $wpdb;

	$user_id = absint( $user_id );
	if ( ! $user_id ) return [];

	$args = wp_parse_args( $args, [
		'limit'       => 20,
		'offset'      => 0,
		'unread_only' => false,
		'type'        => '',
	] );

	$limit  = max( 1, min( 100, absint( $args['limit'] ) ) );
	$offset = max( 0, absint( $args['offset'] ) );

	$table = wxacg_notifications_table();
	$where = $wpdb->prepare( 'WHERE user_id = %d', $user_id );

	if ( $args['unread_only'] ) {
		$where .= ' AND is_read = 0';
	}
	if ( ! empty( $args['type'] ) ) {
		$where .= $wpdb->prepare( ' AND type = %s', sanitize_key( $args['type'] ) );
	}

	$sql = "SELECT * FROM {$table} {$where} ORDER BY created_at DESC LIMIT %d OFFSET %d";
	$rows = $wpdb->get_results(
		$wpdb->prepare( $sql, $limit, $offset ),
		ARRAY_A
	);

	if ( ! $rows ) return [];

	foreach ( $rows as &$r ) {
		$r['data']     = ! empty( $r['data'] ) ? json_decode( $r['data'], true ) : [];
		$r['id']       = (int) $r['id'];
		$r['user_id']  = (int) $r['user_id'];
		$r['actor_id'] = $r['actor_id']  ? (int) $r['actor_id']  : null;
		$r['object_id']= $r['object_id'] ? (int) $r['object_id'] : null;
		$r['is_read']  = (int) $r['is_read'];
	}
	unset( $r );

	return $rows;
}

function wxacg_get_unread_count( $user_id ) {
	global $wpdb;

	$user_id = absint( $user_id );
	if ( ! $user_id ) return 0;

	$cache_key = "smacg_unread_count_{$user_id}";
	$cached = wp_cache_get( $cache_key, 'wxacg_notifications' );
	if ( false !== $cached ) {
		return (int) $cached;
	}

	$table = wxacg_notifications_table();
	$count = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND is_read = 0",
		$user_id
	) );

	wp_cache_set( $cache_key, $count, 'wxacg_notifications', 60 );
	return $count;
}

/* ============================================================
   標記已讀
   ============================================================ */
function wxacg_mark_notification_read( $notif_id, $user_id ) {
	global $wpdb;

	$notif_id = absint( $notif_id );
	$user_id  = absint( $user_id );
	if ( ! $notif_id || ! $user_id ) return false;

	$updated = $wpdb->update(
		wxacg_notifications_table(),
		[ 'is_read' => 1 ],
		[ 'id' => $notif_id, 'user_id' => $user_id ],
		[ '%d' ], [ '%d', '%d' ]
	);

	if ( $updated ) {
		wxacg_clear_notification_cache( $user_id );
	}
	return (bool) $updated;
}

function wxacg_mark_all_read( $user_id ) {
	global $wpdb;

	$user_id = absint( $user_id );
	if ( ! $user_id ) return false;

	$updated = $wpdb->update(
		wxacg_notifications_table(),
		[ 'is_read' => 1 ],
		[ 'user_id' => $user_id, 'is_read' => 0 ],
		[ '%d' ], [ '%d', '%d' ]
	);

	if ( false !== $updated ) {
		wxacg_clear_notification_cache( $user_id );
	}
	return (int) $updated;
}

/* ============================================================
   刪除
   ============================================================ */
function wxacg_delete_notification( $notif_id, $user_id ) {
	global $wpdb;

	$notif_id = absint( $notif_id );
	$user_id  = absint( $user_id );
	if ( ! $notif_id || ! $user_id ) return false;

	$deleted = $wpdb->delete(
		wxacg_notifications_table(),
		[ 'id' => $notif_id, 'user_id' => $user_id ],
		[ '%d', '%d' ]
	);

	if ( $deleted ) {
		wxacg_clear_notification_cache( $user_id );
	}
	return (bool) $deleted;
}

function smacg_purge_old_notifications() {
	global $wpdb;

	$cutoff = gmdate( 'Y-m-d H:i:s', strtotime( '-' . SMACG_NOTIF_RETENTION_DAYS . ' days' ) );
	$table  = wxacg_notifications_table();

	return (int) $wpdb->query( $wpdb->prepare(
		"DELETE FROM {$table} WHERE created_at < %s",
		$cutoff
	) );
}

add_action( 'wxacg_notifications_daily_purge', 'smacg_purge_old_notifications' );

add_action( 'init', function() {
	if ( ! wp_next_scheduled( 'wxacg_notifications_daily_purge' ) ) {
		wp_schedule_event(
			strtotime( 'tomorrow 03:00:00 ' . wp_timezone_string() ),
			'daily',
			'wxacg_notifications_daily_purge'
		);
	}
} );

/* ============================================================
   快取清理
   ============================================================ */
function wxacg_clear_notification_cache( $user_id ) {
	wp_cache_delete( "smacg_unread_count_{$user_id}", 'wxacg_notifications' );
}

/* ============================================================
   使用者刪除時清理
   ============================================================ */
add_action( 'deleted_user', function( $user_id ) {
	global $wpdb;
	$table = wxacg_notifications_table();
	$wpdb->delete( $table, [ 'user_id'  => $user_id ], [ '%d' ] );
	$wpdb->delete( $table, [ 'actor_id' => $user_id ], [ '%d' ] );
	delete_user_meta( $user_id, 'smacg_notification_prefs' );
} );
