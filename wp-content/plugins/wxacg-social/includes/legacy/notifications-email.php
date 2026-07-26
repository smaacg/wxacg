<?php
/**
 * Notifications Email Digest System
 *
 * @version 1.2.0 (2026-05-26)
 *
 * v1.2.0 變更：
 *   - $type_label 補上 'rank_up' => '段位升級'
 *   - $allowed_types 補上 'rank_up'，使段位通知偏好可以正常儲存
 *
 * v1.1.0 變更：
 *   - Bug #1 修正：偏好欄位統一為扁平結構
 *   - Bug #2 修正：移除無效的 register_deactivation_hook（清除 cron 改由 Deactivator 處理）
 *   - AJAX 儲存改為呼叫 smacg_update_notification_prefs()
 *
 * 功能：
 * 1. WP-Cron 每日/每週 Email 摘要（台北時間 20:00 寄送）
 * 2. AJAX 端點：smacg_notif_save_prefs（儲存通知偏好）
 * 3. 管理員測試 URL：/wp-admin/?smacg_notif_test_digest=daily|weekly
 *
 * @package SMACG_Social
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/* =========================================================
 * 1. 自訂 Cron 排程（weekly）
 * ========================================================= */
add_filter( 'cron_schedules', function( $schedules ) {
    if ( ! isset( $schedules['weekly'] ) ) {
        $schedules['weekly'] = array(
            'interval' => WEEK_IN_SECONDS,
            'display'  => __( 'Once Weekly', 'wxacg-social' ),
        );
    }
    return $schedules;
} );

/* =========================================================
 * 2. 註冊 Cron（台北 20:00 寄送）
 * ========================================================= */
add_action( 'init', 'smacg_notif_schedule_email_cron' );
function smacg_notif_schedule_email_cron() {
    $tz_tw   = new DateTimeZone( 'Asia/Taipei' );
    $now_tw  = new DateTime( 'now', $tz_tw );
    $next_tw = new DateTime( 'today 20:00', $tz_tw );
    if ( $next_tw <= $now_tw ) {
        $next_tw->modify( '+1 day' );
    }
    $next_utc = $next_tw->getTimestamp();

    if ( ! wp_next_scheduled( 'smacg_notif_email_daily' ) ) {
        wp_schedule_event( $next_utc, 'daily', 'smacg_notif_email_daily' );
    }
    if ( ! wp_next_scheduled( 'smacg_notif_email_weekly' ) ) {
        $monday_tw = new DateTime( 'next monday 20:00', $tz_tw );
        wp_schedule_event( $monday_tw->getTimestamp(), 'weekly', 'smacg_notif_email_weekly' );
    }
}

/* =========================================================
 * 3. Cron Handlers
 * ========================================================= */
add_action( 'smacg_notif_email_daily',  function() { smacg_notif_send_digest( 'daily' );  } );
add_action( 'smacg_notif_email_weekly', function() { smacg_notif_send_digest( 'weekly' ); } );

/* =========================================================
 * 4. 寄送摘要主邏輯
 * ========================================================= */
function smacg_notif_send_digest( $frequency = 'daily' ) {
    global $wpdb;
    $table = $wpdb->prefix . 'wxacg_notifications';

    $needle = ( $frequency === 'weekly' )
        ? 's:12:"email_digest";s:6:"weekly"'
        : 's:12:"email_digest";s:5:"daily"';

    $user_ids = $wpdb->get_col( $wpdb->prepare(
        "SELECT user_id FROM {$wpdb->usermeta}
         WHERE meta_key = %s AND meta_value LIKE %s",
        'smacg_notification_prefs',
        '%' . $wpdb->esc_like( $needle ) . '%'
    ) );

    if ( empty( $user_ids ) ) {
        return 0;
    }

    $hours_ago = ( $frequency === 'weekly' ) ? 168 : 24;
    $since     = gmdate( 'Y-m-d H:i:s', time() - $hours_ago * HOUR_IN_SECONDS );

    /* v1.2.0：補上 rank_up 標籤 */
    $type_label = array(
        'follow'        => '新追蹤者',
        'comment_reply' => '留言回覆',
        'rating'        => '評分互動',
        'level_up'      => '等級提升',
        'rank_up'       => '段位升級',
        'badge'         => '徽章解鎖',
        'system'        => '系統公告',
    );

    $sent = 0;
    foreach ( $user_ids as $uid ) {
        $uid = (int) $uid;
        if ( ! $uid ) continue;

        $prefs = smacg_get_notification_prefs( $uid );
        if ( ( $prefs['email_digest'] ?? 'off' ) !== $frequency ) continue;

        $user = get_userdata( $uid );
        if ( ! $user || empty( $user->user_email ) ) continue;

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT type, COUNT(*) AS cnt
             FROM {$table}
             WHERE user_id = %d AND created_at >= %s
             GROUP BY type",
            $uid, $since
        ) );

        if ( empty( $rows ) ) continue;

        $lines = array();
        $total = 0;
        foreach ( $rows as $r ) {
            $key = $r->type . '_email';
            if ( empty( $prefs[ $key ] ) ) continue;

            $label   = $type_label[ $r->type ] ?? $r->type;
            $lines[] = "・{$label}：{$r->cnt} 則";
            $total  += (int) $r->cnt;
        }

        if ( $total === 0 ) continue;

        $site    = get_bloginfo( 'name' );
        $mc_url  = function_exists( 'wxacg_get_member_center_url' )
            ? wxacg_get_member_center_url()
            : home_url( '/mc/' );
        $period  = ( $frequency === 'weekly' ) ? '本週' : '今日';
        $subject = sprintf( '[%s] %s共有 %d 則新通知', $site, $period, $total );

        $body  = "Hi {$user->display_name}，\n\n";
        $body .= "{$period}您在 {$site} 的通知摘要：\n\n";
        $body .= implode( "\n", $lines ) . "\n\n";
        $body .= "👉 前往會員中心查看：{$mc_url}?tab=notifications\n\n";
        $body .= "──\n";
        $body .= "若您不想再收到摘要信，請至會員中心 → 設定 → 通知偏好 → 將「Email 摘要」改為「不寄送」。\n";
        $body .= "{$site}\n";

        $headers = array( 'Content-Type: text/plain; charset=UTF-8' );
        if ( wp_mail( $user->user_email, $subject, $body, $headers ) ) {
            $sent++;
        }
    }

    if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
        error_log( "[SMACG Notif] {$frequency} digest sent: {$sent} users" );
    }

    return $sent;
}

/* =========================================================
 * 5. AJAX：儲存通知偏好
 * ========================================================= */
add_action( 'wp_ajax_smacg_notif_save_prefs', 'smacg_ajax_notif_save_prefs' );
function smacg_ajax_notif_save_prefs() {
    if ( ! is_user_logged_in() ) {
        wp_send_json_error( array( 'message' => '請先登入' ), 401 );
    }
    check_ajax_referer( 'smacg_notif_save_prefs', 'nonce' );

    $uid   = get_current_user_id();
    $input = isset( $_POST['prefs'] ) ? (array) $_POST['prefs'] : array();

    /* v1.2.0：補上 rank_up */
    $allowed_types = array( 'follow', 'comment_reply', 'rating', 'level_up', 'rank_up', 'badge', 'system' );

    $clean = array();
    foreach ( $allowed_types as $t ) {
        $clean[ $t . '_site' ]  = ! empty( $input[ $t ]['site'] )  ? 1 : 0;
        $clean[ $t . '_email' ] = ! empty( $input[ $t ]['email'] ) ? 1 : 0;
    }

    $digest = isset( $input['email_digest'] ) ? sanitize_text_field( $input['email_digest'] ) : 'off';
    if ( ! in_array( $digest, array( 'off', 'daily', 'weekly' ), true ) ) {
        $digest = 'off';
    }
    $clean['email_digest'] = $digest;

    if ( function_exists( 'smacg_update_notification_prefs' ) ) {
        smacg_update_notification_prefs( $uid, $clean );
    } else {
        wp_send_json_error( array( 'message' => '通知系統未載入' ), 500 );
    }

    wp_send_json_success( array(
        'message' => '已儲存',
        'prefs'   => smacg_get_notification_prefs( $uid ),
    ) );
}

/* =========================================================
 * 6. 管理員測試入口
 * ========================================================= */
add_action( 'admin_init', function() {
    if ( ! current_user_can( 'manage_options' ) ) return;
    if ( empty( $_GET['smacg_notif_test_digest'] ) ) return;

    $freq = sanitize_text_field( $_GET['smacg_notif_test_digest'] );
    if ( ! in_array( $freq, array( 'daily', 'weekly' ), true ) ) return;

    $sent = smacg_notif_send_digest( $freq );
    wp_die( sprintf( '✅ Test digest (%s) executed. Emails sent: %d', esc_html( $freq ), (int) $sent ) );
} );
