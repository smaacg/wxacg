<?php
/**
 * SMACG Social — Deactivator
 *
 * 停用時清除所有 cron，但保留資料表（資料寶貴）。
 *
 * @version 1.0.1 (2026-05-16)
 *   - Bug #2 修正：補上 email digest 兩個 cron 的清除
 */

namespace WXACG\Social;

defined( 'ABSPATH' ) || exit;

final class Deactivator {
    public static function run(): void {
        wp_clear_scheduled_hook( 'wxacg_notifications_daily_purge' );
        wp_clear_scheduled_hook( 'smacg_notif_email_daily' );
        wp_clear_scheduled_hook( 'smacg_notif_email_weekly' );
    }
}
