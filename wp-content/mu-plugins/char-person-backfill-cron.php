<?php
/**
 * Plugin Name: 角色/聲優自動回補 (wp-cron + shell)
 * Description: 每5分鐘用 wp-cron 觸發 shell 呼叫 WP-CLI 補一批 BGM 資料。
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

// 'characters' = 補角色； 'persons' = 補聲優； 'off' = 停止
define( 'MY_BACKFILL_MODE', 'characters' );
define( 'MY_BACKFILL_BATCH', 60 );
define( 'MY_WP_PATH', '/home/u393305917/domains/weixiaoacg.com/public_html' );
define( 'MY_WP_BIN', '/usr/local/bin/wp' );

add_filter( 'cron_schedules', function ( $s ) {
    $s['my_every5min'] = array( 'interval' => 300, 'display' => '每5分鐘 (回補)' );
    return $s;
} );

add_action( 'init', function () {
    if ( ! wp_next_scheduled( 'my_backfill_event' ) ) {
        wp_schedule_event( time() + 60, 'my_every5min', 'my_backfill_event' );
    }
} );

add_action( 'my_backfill_event', 'my_run_backfill_shell' );

function my_run_backfill_shell() {
    if ( MY_BACKFILL_MODE === 'off' ) { return; }
    if ( get_transient( 'my_backfill_lock' ) ) { return; }
    set_transient( 'my_backfill_lock', 1, 290 );

    $cmd_name = ( MY_BACKFILL_MODE === 'persons' )
        ? 'backfill-persons'
        : 'backfill-characters';

    $batch = (int) MY_BACKFILL_BATCH;
    $wp    = escapeshellarg( MY_WP_BIN );
    $path  = escapeshellarg( MY_WP_PATH );

    $cmd = "cd {$path} && nice -n 19 {$wp} anime {$cmd_name} --limit={$batch} "
         . ">> /home/u393305917/backfill-mu.log 2>&1";

    if ( function_exists( 'shell_exec' ) ) {
        shell_exec( $cmd );
    } else {
        error_log( 'my_backfill: shell_exec 被停用' );
    }

    delete_transient( 'my_backfill_lock' );
}
