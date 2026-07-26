<?php
/**
 * SMACG Members — Deactivator
 */

namespace WXACG\Members;

defined( 'ABSPATH' ) || exit;

final class Deactivator {
    public static function run(): void {
        // 清除 URL 快取
        wp_cache_delete( 'smacg_mc_url', 'smacg' );

        // 本外掛沒有自己排程 cron，僅留空 placeholder 方便未來擴充
    }
}
