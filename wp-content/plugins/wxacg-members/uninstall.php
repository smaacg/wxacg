<?php
/**
 * SMACG Members — Uninstall
 *
 * 設計原則：
 *   - 預設「不刪除使用者資料」（因為 user_meta 包含 anime_total_points、watchlist 等重要資料）
 *   - 僅清除外掛自身的選項與 transient
 *   - 若需要徹底清除使用者資料，請在 wp-config.php 定義：
 *       define( 'WXACG_MEMBERS_PURGE_ON_UNINSTALL', true );
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

/* 永遠清除：外掛自身選項 */
delete_option( 'wxacg_members_version' );
delete_option( 'wxacg_members_activated_at' );

/* 永遠清除：member center URL transient（member-functions.php 使用） */
wp_cache_delete( 'smacg_mc_url', 'smacg' );

/* 危險區：僅在明確 opt-in 時執行 */
if ( defined( 'WXACG_MEMBERS_PURGE_ON_UNINSTALL' ) && WXACG_MEMBERS_PURGE_ON_UNINSTALL ) {
    // 注意：這會刪除所有使用者的歷史記錄
    $meta_keys = [
        'weixiaoacg_user_level',
        'weixiaoacg_points',
        'anime_total_points',
        'anime_points_log',
    ];
    foreach ( $meta_keys as $key ) {
        $wpdb->delete( $wpdb->usermeta, [ 'meta_key' => $key ], [ '%s' ] );
    }

    // cooldown meta（以 smacg_cd_ 開頭）
    $wpdb->query(
        "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE 'smacg\\_cd\\_%'"
    );
}
