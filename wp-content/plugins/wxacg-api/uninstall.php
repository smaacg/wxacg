<?php
/**
 * 解除安裝清理
 *
 * 注意：本外掛不創建資料表，僅清除快取與 option。
 *
 * @package WxacgApi
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// 清除 Gemini slug 翻譯快取
global $wpdb;
$wpdb->query(
    "DELETE FROM {$wpdb->options}
     WHERE option_name LIKE '\_transient\_smacg\_api\_slug\_%'
        OR option_name LIKE '\_transient\_timeout\_smacg\_api\_slug\_%'"
);

// 清除啟用標記
delete_option( 'wxacg_api_activated_at' );
