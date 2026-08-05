<?php
/**
 * Plugin Name: 角色/聲優自動回補 (wp-cron, BGM有什麼補什麼版)
 * Description: 每5分鐘補一批 BGM 資料；本機(LocalWP)自動停用，只在正式站執行。只有 BGM 完全抓不到資料的 bgm_id 才記入跳過名單。
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

/* ===== 環境守門：只在「正式站」執行，本機(LocalWP)一律靜默 ===== */
if ( ! my_backfill_is_production() ) {
    return;
}
function my_backfill_is_production(): bool {
    if ( function_exists( 'wp_get_environment_type' ) && wp_get_environment_type() === 'local' ) {
        return false;
    }
    if ( defined( 'WP_ENVIRONMENT_TYPE' ) && WP_ENVIRONMENT_TYPE === 'local' ) {
        return false;
    }
    if ( strpos( __FILE__, '/home/u393305917/domains/weixiaoacg.com/public_html' ) === false ) {
        return false;
    }
    return true;
}

/* ===== 開關：'characters' / 'persons' / 'off' ===== */
define( 'MY_BACKFILL_MODE', 'persons' );
define( 'MY_BACKFILL_BATCH', 60 );

add_filter( 'cron_schedules', function ( $s ) {
    $s['my_every5min'] = [ 'interval' => 300, 'display' => '每5分鐘 (回補)' ];
    return $s;
} );

add_action( 'init', function () {
    if ( ! wp_next_scheduled( 'my_backfill_event' ) ) {
        wp_schedule_event( time() + 60, 'my_every5min', 'my_backfill_event' );
    }
    // 一次性：清掉先前用舊(只看summary)邏輯誤判的聲優跳過名單
    if ( ! get_option( 'my_backfill_persons_skip_reset_done' ) ) {
        delete_option( 'my_backfill_skip_persons' );
        update_option( 'my_backfill_persons_skip_reset_done', 1, false );
    }
} );

add_action( 'my_backfill_event', 'my_run_backfill_job' );

function my_run_backfill_job() {
    global $wpdb;

    if ( MY_BACKFILL_MODE === 'off' ) { return; }
    if ( get_transient( 'my_backfill_lock' ) ) { return; }
    set_transient( 'my_backfill_lock', 1, 290 );

    if ( ! class_exists( 'Anime_Sync_Entity_Migrator' ) ) {
        delete_transient( 'my_backfill_lock' );
        error_log( 'my_backfill: Anime_Sync_Entity_Migrator 尚未載入' );
        return;
    }

    $migrator = new Anime_Sync_Entity_Migrator();
    $batch    = (int) MY_BACKFILL_BATCH;
    $mode     = MY_BACKFILL_MODE;

    if ( $mode === 'persons' ) {
        $table    = $wpdb->prefix . 'anime_persons';
        $method   = 'backfill_persons';
        $skip_key = 'my_backfill_skip_persons';
        // 聲優：這些欄位任一為空就撈來補
        $where_missing = "( summary IS NULL OR summary = ''
                         OR name_original IS NULL OR name_original = ''
                         OR birthday IS NULL OR birthday = ''
                         OR height IS NULL OR height = ''
                         OR infobox_json IS NULL OR infobox_json = '' )";
    } else {
        $table    = $wpdb->prefix . 'anime_characters';
        $method   = 'backfill_characters';
        $skip_key = 'my_backfill_skip_chars';
        // 角色：這些欄位任一為空就撈來補
        $where_missing = "( summary IS NULL OR summary = ''
                         OR name_cn IS NULL OR name_cn = ''
                         OR infobox_json IS NULL OR infobox_json = '' )";
    }

    $skip = get_option( $skip_key, [] );
    if ( ! is_array( $skip ) ) { $skip = []; }

    $not_in = '';
    if ( ! empty( $skip ) ) {
        $skip_ints = array_map( 'intval', $skip );
        $not_in = ' AND bgm_id NOT IN (' . implode( ',', $skip_ints ) . ')';
    }

    $ids = $wpdb->get_col(
        "SELECT bgm_id FROM {$table}
         WHERE bgm_id > 0 AND {$where_missing} {$not_in}
         LIMIT {$batch}"
    );

    if ( empty( $ids ) ) {
        update_option( 'my_backfill_last',
            gmdate( 'Y-m-d H:i:s' ) . ' | ' . $table . ' 沒有待補的了 (跳過名單 ' . count( $skip ) . ' 筆)' );
        delete_transient( 'my_backfill_lock' );
        return;
    }

    $updated  = 0;
    $no_data  = 0;
    foreach ( $ids as $id ) {
        $id = (int) $id;
        $r = [ 'updated' => 0, 'failed' => 0 ];
        try {
            $r = $migrator->{$method}( [ 'bgm_id' => $id, 'force' => true ] );
        } catch ( \Throwable $e ) {
            error_log( 'my_backfill 例外 id=' . $id . ' : ' . $e->getMessage() );
        }
        // 判斷：有補到任何資料就算成功；整筆 BGM 抓不到(failed>0 且 updated=0)才跳過
        if ( ! empty( $r['updated'] ) && $r['updated'] > 0 ) {
            $updated++;
        } elseif ( ! empty( $r['failed'] ) && $r['failed'] > 0 ) {
            $skip[]  = $id;
            $no_data++;
        }
        // 若 updated=0 且 failed=0（no_change，資料已齊），不跳過、下次不會再撈到(因欄位已滿)
    }

    $skip = array_values( array_unique( array_map( 'intval', $skip ) ) );
    update_option( $skip_key, $skip, false );

    update_option( 'my_backfill_last',
        gmdate( 'Y-m-d H:i:s' ) . ' | ' . $table
        . ' 這批 ' . count( $ids ) . ' 筆，實補=' . $updated
        . '，BGM無資料跳過=' . $no_data
        . '，跳過名單累計=' . count( $skip ) );

    delete_transient( 'my_backfill_lock' );
}
