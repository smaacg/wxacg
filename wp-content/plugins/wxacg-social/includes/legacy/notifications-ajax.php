<?php
/**
 * Notifications System — AJAX endpoints
 *
 * @package weixiaoacg
 * @version 1.1.0 (2026-05-16)
 *
 * v1.1.0 變更：
 *   - Bug #5 修正：mysql2date('U',...) 改用 get_gmt_from_date 避免時區錯位
 *
 * Endpoints（皆需登入）：
 *   smacg_notif_unread_count
 *   smacg_notif_list
 *   smacg_notif_mark_read
 *   smacg_notif_mark_all_read
 *   smacg_notif_delete
 *
 * Nonce: smacg_notif_nonce
 */
defined( 'ABSPATH' ) || exit;

/* ============================================================
   未登入回應
   ============================================================ */
function smacg_notif_ajax_require_login() {
	wp_send_json_error( [
		'code'    => 'login_required',
		'message' => '請先登入',
	], 401 );
}
add_action( 'wp_ajax_nopriv_smacg_notif_unread_count',  'smacg_notif_ajax_require_login' );
add_action( 'wp_ajax_nopriv_smacg_notif_list',          'smacg_notif_ajax_require_login' );
add_action( 'wp_ajax_nopriv_smacg_notif_mark_read',     'smacg_notif_ajax_require_login' );
add_action( 'wp_ajax_nopriv_smacg_notif_mark_all_read', 'smacg_notif_ajax_require_login' );
add_action( 'wp_ajax_nopriv_smacg_notif_delete',        'smacg_notif_ajax_require_login' );

/* ============================================================
   共用 nonce 驗證
   ============================================================ */
function smacg_notif_verify_nonce() {
	$nonce = isset( $_REQUEST['nonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['nonce'] ) ) : '';
	if ( ! wp_verify_nonce( $nonce, 'smacg_notif_nonce' ) ) {
		wp_send_json_error( [ 'message' => '安全驗證失敗' ], 403 );
	}
}

/* ============================================================
   渲染輔助：把 DB row 變成前端友善格式
   ============================================================ */
function smacg_notif_format_for_api( $row ) {
	$data = is_array( $row['data'] ) ? $row['data'] : [];

	// Actor 頭像與名稱
	$actor_avatar = '';
	$actor_name   = '';
	if ( ! empty( $row['actor_id'] ) ) {
		$actor_avatar = get_avatar_url( $row['actor_id'], [ 'size' => 64 ] );
		$au = get_userdata( $row['actor_id'] );
		if ( $au ) {
			$actor_name = $au->display_name ?: $au->user_login;
			$aid = (int) get_user_meta( $row['actor_id'], 'smacg_avatar_id', true );
			if ( $aid && wp_attachment_is_image( $aid ) ) {
				$img = wp_get_attachment_image_src( $aid, 'thumbnail' );
				if ( $img ) $actor_avatar = $img[0];
			}
		}
	}

	// 時間（v1.1.0 修正：created_at 是本地時間字串，要先轉成 UTC timestamp）
	$ts = (int) strtotime( get_gmt_from_date( $row['created_at'] ) . ' UTC' );
	$time_diff = $ts ? human_time_diff( $ts, time() ) . '前' : '';

	return [
		'id'           => (int) $row['id'],
		'type'         => $row['type'],
		'title'        => $data['title']   ?? '',
		'excerpt'      => $data['excerpt'] ?? '',
		'url'          => $data['url']     ?? '',
		'icon'         => $data['icon']    ?? 'fa-bell',
		'actor_name'   => $actor_name,
		'actor_avatar' => $actor_avatar,
		'is_read'      => (int) $row['is_read'],
		'created_at'   => $row['created_at'],
		'time_diff'    => $time_diff,
	];
}

/* ============================================================
   未讀數
   ============================================================ */
add_action( 'wp_ajax_smacg_notif_unread_count', function() {
	smacg_notif_verify_nonce();
	$uid = get_current_user_id();
	wp_send_json_success( [
		'unread' => smacg_get_unread_count( $uid ),
	] );
} );

/* ============================================================
   清單
   ============================================================ */
add_action( 'wp_ajax_smacg_notif_list', function() {
	smacg_notif_verify_nonce();
	$uid = get_current_user_id();

	$limit       = isset( $_REQUEST['limit'] )  ? absint( $_REQUEST['limit'] )  : 10;
	$offset      = isset( $_REQUEST['offset'] ) ? absint( $_REQUEST['offset'] ) : 0;
	$unread_only = ! empty( $_REQUEST['unread_only'] );
	$type        = isset( $_REQUEST['type'] ) ? sanitize_key( $_REQUEST['type'] ) : '';

	$rows = smacg_get_notifications( $uid, [
		'limit'       => $limit,
		'offset'      => $offset,
		'unread_only' => $unread_only,
		'type'        => $type,
	] );

	$items = array_map( 'smacg_notif_format_for_api', $rows );

	wp_send_json_success( [
		'items'  => $items,
		'unread' => smacg_get_unread_count( $uid ),
		'count'  => count( $items ),
	] );
} );

/* ============================================================
   標記單筆已讀
   ============================================================ */
add_action( 'wp_ajax_smacg_notif_mark_read', function() {
	smacg_notif_verify_nonce();
	$uid = get_current_user_id();
	$id  = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
	if ( ! $id ) {
		wp_send_json_error( [ 'message' => 'ID 無效' ], 400 );
	}

	$ok = smacg_mark_notification_read( $id, $uid );
	if ( ! $ok ) {
		wp_send_json_error( [ 'message' => '標記失敗（可能已是已讀或非本人通知）' ], 400 );
	}
	wp_send_json_success( [
		'unread' => smacg_get_unread_count( $uid ),
	] );
} );

/* ============================================================
   全部標記已讀
   ============================================================ */
add_action( 'wp_ajax_smacg_notif_mark_all_read', function() {
	smacg_notif_verify_nonce();
	$uid = get_current_user_id();
	$n = smacg_mark_all_read( $uid );
	wp_send_json_success( [
		'updated' => (int) $n,
		'unread'  => smacg_get_unread_count( $uid ),
	] );
} );

/* ============================================================
   刪除單筆
   ============================================================ */
add_action( 'wp_ajax_smacg_notif_delete', function() {
	smacg_notif_verify_nonce();
	$uid = get_current_user_id();
	$id  = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
	if ( ! $id ) {
		wp_send_json_error( [ 'message' => 'ID 無效' ], 400 );
	}

	$ok = smacg_delete_notification( $id, $uid );
	if ( ! $ok ) {
		wp_send_json_error( [ 'message' => '刪除失敗' ], 400 );
	}
	wp_send_json_success( [
		'unread' => smacg_get_unread_count( $uid ),
	] );
} );
