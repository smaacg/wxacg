<?php
/**
 * SMACG Social — Uninstall
 *
 * 設計原則：
 *   - 預設「保留資料表」（follow 關係與通知歷史可能仍有價值）
 *   - 僅清除外掛自身選項
 *   - 若要徹底清除，請定義：
 *       define( 'WXACG_SOCIAL_PURGE_ON_UNINSTALL', true );
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

/* 永遠清除：外掛自身選項與快取 */
delete_option( 'wxacg_social_version' );
delete_option( 'wxacg_social_activated_at' );
delete_option( 'wxacg_follows_db_version' );
delete_option( 'smacg_notif_db_version' );

/* 清除 cron */
wp_clear_scheduled_hook( 'wxacg_notifications_daily_purge' );

/* 危險區：清除資料表與所有使用者資料 */
if ( defined( 'WXACG_SOCIAL_PURGE_ON_UNINSTALL' ) && WXACG_SOCIAL_PURGE_ON_UNINSTALL ) {

    // 刪除資料表
    $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wxacg_follows" );
    $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wxacg_notifications" );

    // 刪除通知偏好 user_meta
    $wpdb->delete( $wpdb->usermeta, [ 'meta_key' => 'smacg_notification_prefs' ], [ '%s' ] );

    // 刪除追蹤 transient（防刷 cooldown）
    $wpdb->query(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_smacg\\_follow\\_cd\\_%'"
    );
    $wpdb->query(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_timeout\\_smacg\\_follow\\_cd\\_%'"
    );
}
