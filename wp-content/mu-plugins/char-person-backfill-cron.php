<?php
/**
 * Plugin Name: 角色/聲優自動回補 (wp-cron)
 * Description: 每5分鐘用 wp-cron 逐筆補 summary 為空的角色/聲優，避開 height/weight 死循環。
 * Version: 2.0
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

/* ===== 【雙重智慧安防：杜絕本地端狂戰卡頓與無數轉圈報錯災厄】 ===== */
# 1. 偵測若為本次 PC 開發或測試領空 (WP_ENVIRONMENT_TYPE為local、或網址含有 .local / .test / localhost / 127.0.0.1 等)，本機直接全體靜默打烊、釋收安養
if (
    (function_exists('wp_get_environment_type') && wp_get_environment_type() === 'local') ||
    (defined('WP_ENVIRONMENT_TYPE') && WP_ENVIRONMENT_TYPE === 'local') ||
    strpos(home_url(), '.local') !== false ||
    strpos(home_url(), '.test') !== false ||
    strpos(home_url(), 'localhost') !== false ||
    strpos(home_url(), '127.0.0.1') !== false
) {
    return; # 當下宣告封筆，後方的定時超負荷輪詢全面免出
}
# 2. 探究即便在遠海主網，為防傷亡仍須先經審，查證 wp_anime_characters 資料庫中含 name_cn 新欄才被核准闖關
global $wpdb;
if (empty($wpdb->get_results("SHOW COLUMNS FROM `wp_anime_characters` LIKE 'name_cn'"))) {
    return; # 若遭逢尚未蓋整齊之場域（含未擴建之分支站），當下即收兵避免釀災
}

/* ===== 開關：一次只開一個 =====
 * 'characters' = 補角色
 * 'persons'    = 補聲優
 * 'off'        = 停止
 */
define( 'MY_BACKFILL_MODE', 'characters' );

// 每批處理幾筆（每筆約1秒，60筆約1分鐘，5分鐘排程內跑得完）
define( 'MY_BACKFILL_BATCH', 60 );

/* ===== 註冊每5分鐘排程 ===== */
add_filter( 'cron_schedules', function ( $s ) {
    $s['my_every5min'] = array( 'interval' => 300, 'display' => '每5分鐘 (回補)' );
    return $s;
} );

add_action( 'init', function () {
    if ( ! wp_next_scheduled( 'my_backfill_event' ) ) {
        wp_schedule_event( time() + 60, 'my_every5min', 'my_backfill_event' );
    }
} );

add_action( 'my_backfill_event', 'my_run_backfill_job' );

/* ===== 實際執行 ===== */
function my_run_backfill_job() {
    global $wpdb;

    if ( MY_BACKFILL_MODE === 'off' ) { return; }
    if ( get_transient( 'my_backfill_lock' ) ) { return; }
    set_transient( 'my_backfill_lock', 1, 290 );

    if ( ! class_exists( 'Anime_Sync_Entity_Migrator' ) ) {
        error_log( 'my_backfill: migrator class 未載入' );
        delete_transient( 'my_backfill_lock' );
        return;
    }

    $migrator = new Anime_Sync_Entity_Migrator();
    $batch    = (int) MY_BACKFILL_BATCH;

    if ( MY_BACKFILL_MODE === 'persons' ) {
        $table  = $wpdb->prefix . 'anime_persons';
        $method = 'backfill_persons';
    } else {
        $table  = $wpdb->prefix . 'anime_characters';
        $method = 'backfill_characters';
    }

    // 關鍵：只撈 summary 真的為空的（避開 height/weight 造成的死循環）
    $ids = $wpdb->get_col(
        "SELECT bgm_id FROM {$table}
         WHERE bgm_id > 0 AND ( summary IS NULL OR summary = '' )
         LIMIT {$batch}"
    );

    if ( empty( $ids ) ) {
        update_option( 'my_backfill_last',
            current_time( 'mysql' ) . ' | ' . MY_BACKFILL_MODE . ' 沒有待補的了', false );
        delete_transient( 'my_backfill_lock' );
        return;
    }

    $updated = 0; $failed = 0;
    foreach ( $ids as $id ) {
        try {
            // 逐筆 bgm_id + force，避免被清單順序卡住
            $r = $migrator->{$method}( array( 'bgm_id' => (int) $id, 'force' => true ) );
            $updated += (int) ( $r['updated'] ?? 0 );
            $failed  += (int) ( $r['failed']  ?? 0 );
        } catch ( \Throwable $e ) {
            error_log( 'my_backfill id=' . $id . ' 例外: ' . $e->getMessage() );
        }
    }

    update_option( 'my_backfill_last',
        current_time( 'mysql' ) . " | {$table} 這批" . count( $ids )
        . "筆 updated={$updated} failed={$failed}", false );

    delete_transient( 'my_backfill_lock' );
}
