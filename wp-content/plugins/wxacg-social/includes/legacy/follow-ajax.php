<?php
/**
 * Follow System — AJAX endpoints
 *
 * @package weixiaoacg
 * @version 1.0.0 (2026-05-13)
 *
 * Endpoints:
 * - wp_ajax_wxacg_follow            登入：追蹤
 * - wp_ajax_smacg_unfollow          登入：取消追蹤
 * - wp_ajax_nopriv_wxacg_follow     未登入：回傳需登入
 * - wp_ajax_nopriv_smacg_unfollow   未登入：回傳需登入
 *
 * 安全性：
 * - nonce: wxacg_follow_nonce
 * - 僅接受 POST
 * - target_id 必須為正整數
 */
defined( 'ABSPATH' ) || exit;

/* ============================================================
   未登入：統一回傳「請先登入」
   ============================================================ */
function wxacg_follow_ajax_require_login() {
	wp_send_json_error( [
		'code'    => 'login_required',
		'message' => '請先登入',
		'login'   => function_exists( 'um_get_core_page' )
			? um_get_core_page( 'login' )
			: wp_login_url(),
	], 401 );
}
add_action( 'wp_ajax_nopriv_wxacg_follow',       'wxacg_follow_ajax_require_login' );
add_action( 'wp_ajax_nopriv_smacg_unfollow',     'wxacg_follow_ajax_require_login' );

/* ============================================================
   共用：驗證 + 取得 target_id
   ============================================================ */
function wxacg_follow_ajax_validate() {
	if ( $_SERVER['REQUEST_METHOD'] !== 'POST' ) {
		wp_send_json_error( [ 'message' => '方法不允許' ], 405 );
	}

	$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
	if ( ! wp_verify_nonce( $nonce, 'wxacg_follow_nonce' ) ) {
		wp_send_json_error( [ 'message' => '安全驗證失敗，請重新整理頁面' ], 403 );
	}

	$target_id = isset( $_POST['target_id'] ) ? absint( $_POST['target_id'] ) : 0;
	if ( ! $target_id ) {
		wp_send_json_error( [ 'message' => '目標使用者無效' ], 400 );
	}

	return $target_id;
}

/* ============================================================
   追蹤
   ============================================================ */
add_action( 'wp_ajax_wxacg_follow', function () {
	$target_id   = wxacg_follow_ajax_validate();
	$follower_id = get_current_user_id();

	$result = wxacg_follow_user( $follower_id, $target_id );
	if ( is_wp_error( $result ) ) {
		wp_send_json_error( [
			'code'    => $result->get_error_code(),
			'message' => $result->get_error_message(),
		], 400 );
	}

	wp_send_json_success( [
		'message'         => '已追蹤',
		'is_following'    => true,
		'followers_count' => smacg_get_followers_count( $target_id ),
		'following_count' => smacg_get_following_count( $follower_id ),
	] );
} );

/* ============================================================
   取消追蹤
   ============================================================ */
add_action( 'wp_ajax_smacg_unfollow', function () {
	$target_id   = wxacg_follow_ajax_validate();
	$follower_id = get_current_user_id();

	$result = wxacg_unfollow_user( $follower_id, $target_id );
	if ( is_wp_error( $result ) ) {
		wp_send_json_error( [
			'code'    => $result->get_error_code(),
			'message' => $result->get_error_message(),
		], 400 );
	}

	wp_send_json_success( [
		'message'         => '已取消追蹤',
		'is_following'    => false,
		'followers_count' => smacg_get_followers_count( $target_id ),
		'following_count' => smacg_get_following_count( $follower_id ),
	] );
} );

/* ============================================================
   已移除：smacg_check_follow（查詢單筆追蹤狀態）
   ------------------------------------------------------------
   原設計供「前端 SPA 切換頁面時 re-check」，但本站未採用 SPA，
   追蹤狀態一律由伺服器端渲染時直接帶出，前端從未呼叫過這支。
   同檔的 wxacg_follow / smacg_unfollow 仍在使用中，不受影響。
   ============================================================ */
