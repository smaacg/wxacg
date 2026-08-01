<?php
/**
 * Plugin Name: 角色/聲優自動回補 (wp-cron)
 * Description: 每5分鐘用 wp-cron 直接呼叫 migrator 補一批 BGM 資料。
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

// 'characters' = 補角色； 'persons' = 補聲優； 'off' = 停止
define( 'MY_BACKFILL_MODE', 'characters' );
define( 'MY_BACKFILL_BATCH', 60 );

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

function my_run_backfill_job() {
    if ( MY_BACKFILL_MODE === 'off' ) { return; }
    if ( get_transient( 'my_backfill_lock' ) ) { return; }
    set_transient( 'my_backfill_lock', 1, 290 );

    if ( ! class_exists( 'Anime_Sync_Entity_Migrator' ) ) {
        error_log( 'my_backfill: migrator class 未載入' );
        delete_transient( 'my_backfill_lock' );
        return;
    }

    $migrator = new Anime_Sync_Entity_Migrator();
    $args = array( 'limit' => (int) MY_BACKFILL_BATCH );

    try {
        if ( MY_BACKFILL_MODE === 'persons' ) {
            $stats = $migrator->backfill_persons( $args );
        } else {
            $stats = $migrator->backfill_characters( $args );
        }
        error_log( 'my_backfill 完成 模式=' . MY_BACKFILL_MODE . ' : ' . wp_json_encode( $stats ) );
        update_option( 'my_backfill_last', current_time( 'mysql' ) . ' | ' . wp_json_encode( $stats ), false );
    } catch ( \Throwable $e ) {
        error_log( 'my_backfill 例外: ' . $e->getMessage() );
    }

    delete_transient( 'my_backfill_lock' );
}
