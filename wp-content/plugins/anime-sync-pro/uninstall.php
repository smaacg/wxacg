<?php
/**
 * Uninstall Script
 * 刪除插件時執行，清除所有插件資料
 *
 * @package Anime_Sync_Pro
 *
 * 修正紀錄：
 *   - [Fix] 補刪 anime_ratings / anime_user_status / anime_user_status_stats
 *           三張表（先前僅刪 anime_review_queue + anime_sync_logs）
 *   - [Fix] 補齊遺漏的 options（retention/debug/delete旗標/seed標記/last_*）
 *   - [Fix] transient 清除改為 wp_cache 相容（Redis/Memcached 環境下
 *           單純 DELETE FROM options 不會清掉外部物件快取）
 *   - [Fix] 尊重 anime_sync_delete_on_uninstall 旗標：
 *           預設 0 = 保留資料；僅當站方明確設為 1 才真正刪除
 */

// 安全檢查：確保由 WordPress 呼叫
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

global $wpdb;

// ========================================
// 旗標：是否真的刪除資料
// 預設 0（保留），僅當使用者在設定頁開啟才清除
// ========================================
$should_delete = (int) get_option( 'anime_sync_delete_on_uninstall', 0 ) === 1;

if ( ! $should_delete ) {
    // 不刪除任何資料，僅清除執行期快取後結束
    if ( function_exists( 'wp_cache_flush' ) ) {
        wp_cache_flush();
    }
    return;
}

// ========================================
// 刪除自訂資料表（與 class-installer.php create_tables() 完全對應）
// ========================================
$tables = array(
    $wpdb->prefix . 'anime_review_queue',
    $wpdb->prefix . 'anime_sync_logs',
    $wpdb->prefix . 'anime_ratings',
    $wpdb->prefix . 'anime_user_status',
    $wpdb->prefix . 'anime_user_status_stats',
);

foreach ( $tables as $table ) {
    // 表名來自常數前綴 + 字面字串，無使用者輸入，無 SQL injection 風險
    $wpdb->query( "DROP TABLE IF EXISTS {$table}" );
}

// ========================================
// 刪除插件選項
// ========================================
$options = array(
    // 版本 / 安裝資訊
    'anime_sync_version',
    'anime_sync_activated_at',
    'anime_sync_last_upgrade_at',
    'anime_sync_last_upgrade_from',
    'anime_sync_flush_rewrite',
    // 功能設定
    'anime_sync_cn_method',
    'anime_sync_image_method',
    'anime_sync_cdn_provider',
    'anime_sync_cdn_base_url',
    'anime_sync_ecpay_enabled',
    'anime_sync_ecpay_merchant_id',
    'anime_sync_api_delay',
    'anime_sync_batch_size',
    'anime_sync_log_email_notify',
    'anime_sync_log_retention_days',
    'anime_sync_debug_mode',
    'anime_sync_delete_on_uninstall',
    'anime_sync_daily_hour',
    // 站台 / UA 設定
    'anime_sync_site_name',
    'anime_sync_site_url',
    // 排程 last-run 記錄
    'anime_sync_last_daily_run',
    'anime_sync_last_weekly_cleanup',
    'anime_sync_last_themes_episodes_run',
    // taxonomy seed 標記
    'weixiaoacg_taxonomy_v5_done',
);

foreach ( $options as $option ) {
    delete_option( $option );
}

// ========================================
// 刪除所有 Transient 快取
// 物件快取相容：先用 WP API 刪已知前綴的 transient，
// 再以 DELETE 清掉資料庫殘留（無物件快取的站台）
// ========================================
$transient_like = $wpdb->get_col(
    "SELECT option_name FROM {$wpdb->options}
     WHERE option_name LIKE '\_transient\_anime_sync%'
        OR option_name LIKE '\_transient\_asp\_%'
        OR option_name LIKE '\_transient\_weixiaoacg%'"
);

foreach ( $transient_like as $opt_name ) {
    // 去掉 _transient_ 前綴後用 delete_transient（會同步清物件快取）
    $key = preg_replace( '/^_transient_(timeout_)?/', '', $opt_name );
    if ( $key !== '' ) {
        delete_transient( $key );
    }
}

// 再清資料庫殘留（涵蓋 timeout 行與無物件快取情境）
$wpdb->query(
    "DELETE FROM {$wpdb->options}
     WHERE option_name LIKE '\_transient\_anime_sync%'
        OR option_name LIKE '\_transient\_timeout\_anime_sync%'
        OR option_name LIKE '\_transient\_asp\_%'
        OR option_name LIKE '\_transient\_timeout\_asp\_%'
        OR option_name LIKE '\_transient\_weixiaoacg%'
        OR option_name LIKE '\_transient\_timeout\_weixiaoacg%'"
);

// ========================================
// 刪除 Anime 文章 Meta（可選：保留文章本體）
// 注意：以下程式碼會刪除所有 anime 文章的自訂欄位
// 若希望保留文章內容，請將此區塊保持註解
// ========================================
/*
$anime_posts = get_posts( array(
    'post_type'      => 'anime',
    'posts_per_page' => -1,
    'post_status'    => 'any',
    'fields'         => 'ids',
) );

foreach ( $anime_posts as $post_id ) {
    $meta_keys = array(
        'anime_id_anilist', 'anime_id_mal', 'anime_id_bangumi',
        'anime_title_romaji', 'anime_title_english', 'anime_title_native',
        'anime_title_chinese_traditional', 'anime_season', 'anime_year',
        'anime_episodes', 'anime_duration', 'anime_status', 'anime_format',
        'anime_source', 'anime_score_anilist', 'anime_score_mal',
        'anime_score_bangumi', 'anime_popularity', 'anime_synopsis_tw',
        'anime_synopsis_en', 'anime_cover_url', 'anime_banner_url',
        'anime_studios', 'anime_genres', 'anime_openings', 'anime_endings',
        'anime_characters', 'anime_streaming_platforms', 'anime_last_sync',
        'anime_sync_status', 'anime_locked_fields',
    );

    foreach ( $meta_keys as $key ) {
        delete_post_meta( $post_id, $key );
    }
}
*/

// ========================================
// 清除上傳目錄（可選）
// 注意：以下程式碼會刪除插件建立的上傳目錄
// 預設不執行，避免誤刪圖片
// ========================================
/*
$upload_dir = wp_upload_dir();
$dirs_to_remove = array(
    $upload_dir['basedir'] . '/anime-covers',
    $upload_dir['basedir'] . '/anime-sync-cache',
    $upload_dir['basedir'] . '/anime-sync-pro',
);

function anime_sync_remove_dir( $dir ) {
    if ( ! is_dir( $dir ) ) {
        return;
    }
    $files = array_diff( scandir( $dir ), array( '.', '..' ) );
    foreach ( $files as $file ) {
        $path = $dir . '/' . $file;
        is_dir( $path ) ? anime_sync_remove_dir( $path ) : unlink( $path );
    }
    rmdir( $dir );
}

foreach ( $dirs_to_remove as $dir ) {
    anime_sync_remove_dir( $dir );
}
*/

// 清除 WP 物件快取
if ( function_exists( 'wp_cache_flush' ) ) {
    wp_cache_flush();
}
