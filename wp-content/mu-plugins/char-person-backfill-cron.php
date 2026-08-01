<?php
/**
 * Plugin Name: 角色/聲優自動回補 (wp-cron)
 * Description: 借用 wp-cron 每5分鐘補一批 BGM 資料，避免手動與 hPanel Cron 失效問題。
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

// ===== 開關：一次只開一個 =====
// 'characters' = 補角色； 'persons' = 補聲優； 'off' = 停止
define( 'MY_BACKFILL_MODE', 'characters' );

// 每批處理幾筆（BGM 有每秒限速，一批約60筆，5分鐘內跑得完）
define( 'MY_BACKFILL_BATCH', 60 );

// ===== 註冊每5分鐘的排程 =====
add_filter( 'cron_schedules', function ( $schedules ) {
    $schedules['my_every5min'] = array(
        'interval' => 300,
        'display'  => '每5分鐘 (回補)',
    );
    return $schedules;
} );

add_action( 'init', function () {
    if ( ! wp_next_scheduled( 'my_backfill_event' ) ) {
        wp_schedule_event( time() + 60, 'my_every5min', 'my_backfill_event' );
    }
} );

// ===== 實際執行 =====
add_action( 'my_backfill_event', function () {

    if ( MY_BACKFILL_MODE === 'off' ) { return; }

    // 上鎖，避免上一批還沒跑完又重疊
    if ( get_transient( 'my_backfill_lock' ) ) { return; }
    set_transient( 'my_backfill_lock', 1, 290 );

    if ( ! class_exists( 'Anime_Sync_Entity_Migrator' ) ) {
        delete_transient( 'my_backfill_lock' );
        error_log( 'my_backfill: Anime_Sync_Entity_Migrator 尚未載入' );
        return;
    }

    $migrator = new Anime_Sync_Entity_Migrator();
    $args = array( 'limit' => (int) MY_BACKFILL_BATCH );

    try {
        if ( MY_BACKFILL_MODE === 'characters' ) {
            $stats = $migrator->backfill_characters( $args );
        } else {
            $stats = $migrator->backfill_persons( $args );
        }
        error_log( 'my_backfill 完成 模式=' . MY_BACKFILL_MODE . ' : ' . wp_json_encode( $stats ) );
    } catch ( \Throwable $e ) {
        error_log( 'my_backfill 例外: ' . $e->getMessage() );
    }

    delete_transient( 'my_backfill_lock' );
} );
