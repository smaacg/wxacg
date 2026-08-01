<?php
/**
 * Plugin Name: 角色/聲優自動回補 (wp-cron)
 * Description: 每5分鐘用 wp-cron 逐筆補 summary 為空的角色/聲優，避開 height/weight 死循環。
 * Version: 2.0
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

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
