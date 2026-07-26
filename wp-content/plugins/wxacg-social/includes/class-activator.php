<?php
/**
 * SMACG Social — Activator
 *
 * 啟用時建立資料表與排程 cron。
 * 注意：follow-system.php 與 notifications-system.php 內部也有 dbDelta 自我建表邏輯，
 *       這裡再呼叫一次是為了確保啟用當下立即生效，不必等 admin_init / init。
 */

namespace WXACG\Social;

defined( 'ABSPATH' ) || exit;

final class Activator {
    public static function run(): void {

        // 記錄啟用時間
        if ( ! get_option( 'wxacg_social_activated_at' ) ) {
            update_option( 'wxacg_social_activated_at', time() );
        }

        // 記錄版本
        update_option( 'wxacg_social_version', WXACG_SOCIAL_VERSION );

        // 載入並執行建表
        $legacy = WXACG_SOCIAL_DIR . 'includes/legacy/';

        if ( file_exists( $legacy . 'follow-system.php' ) ) {
            require_once $legacy . 'follow-system.php';
            if ( function_exists( 'wxacg_follows_install' ) ) {
                wxacg_follows_install();
            }
        }

        if ( file_exists( $legacy . 'notifications-system.php' ) ) {
            require_once $legacy . 'notifications-system.php';
            if ( function_exists( 'wxacg_notifications_install' ) ) {
                wxacg_notifications_install();
            }
        }

        // 排程 cron（如果還沒排程的話）
        if ( ! wp_next_scheduled( 'wxacg_notifications_daily_purge' ) ) {
            $tomorrow_3am = strtotime( 'tomorrow 03:00:00 ' . wp_timezone_string() );
            wp_schedule_event( $tomorrow_3am, 'daily', 'wxacg_notifications_daily_purge' );
        }
    }
}
