<?php
/**
 * SMACG Members — Activator
 */

namespace WXACG\Members;

defined( 'ABSPATH' ) || exit;

final class Activator {
    public static function run(): void {
        // 記錄啟用時間
        if ( ! get_option( 'wxacg_members_activated_at' ) ) {
            update_option( 'wxacg_members_activated_at', time() );
        }

        // 記錄版本
        update_option( 'wxacg_members_version', WXACG_MEMBERS_VERSION );

        // 清除 member center URL 快取（避免從主題切換到外掛時殘留舊路徑）
        wp_cache_delete( 'smacg_mc_url', 'smacg' );

        // 啟用時不 flush_rewrite_rules，因為本外掛不註冊 CPT 或 rewrite rules
        // （member-render 走 page template，不需要 rewrite）
    }
}
