<?php
/**
 * Plugin Name: 角色/聲優自動回補 (wp-cron, 跳過空資料版)
 * Description: 每5分鐘補一批 BGM 資料；本機(LocalWP)自動停用，只在正式站執行。補完仍無 summary 的 bgm_id 會記入跳過名單，避免死循環。
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

/* ===== 環境守門：只在「正式站」執行，本機(LocalWP)一律靜默 =====
 * 判斷不碰 $wpdb、不碰 home_url()，只用常數與檔案路徑，絕對安全、載入極早也不會出錯。
 */
if ( ! my_backfill_is_production() ) {
    return; // 本機：整支不載入任何 hook，不排程、不寫入、不查 DB、不報錯
}

function my_backfill_is_production(): bool {
    // 1. LocalWP 預設 WP_ENVIRONMENT_TYPE = 'local'
    if ( function_exists( 'wp_get_environment_type' ) && wp_get_environment_type() === 'local' ) {
        return false;
    }
    if ( defined( 'WP_ENVIRONMENT_TYPE' ) && WP_ENVIRONMENT_TYPE === 'local' ) {
        return false;
    }
    // 2. 檔案實體路徑必須是正式站路徑（LocalWP 的路徑一定不同，雙保險）
    if ( strpos( __FILE__, '/home/u393305917/domains/weixiaoacg.com/public_html' ) === false ) {
        return false;
    }
    return true;
}

/* ===== 開關：一次只開一個 =====
 * 'characters' = 補角色；'persons' = 補聲優；'off' = 停止
 */
define( 'MY_BACKFILL_MODE', 'characters' );
define( 'MY_BACKFILL_BATCH', 60 );
// 角色缺量降到這個值以下就自動切換去補聲優
define( 'MY_CHAR_DONE_THRESHOLD', 210 );

add_filter( 'cron_schedules', function ( $s ) {
    $s['my_every5min'] = [ 'interval' => 300, 'display' => '每5分鐘 (回補)' ];
    return $s;
} );

add_action( 'init', function () {
    if ( ! wp_next_scheduled( 'my_backfill_event' ) ) {
        wp_schedule_event( time() + 60, 'my_every5min', 'my_backfill_event' );
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

    // 決定要補角色還是聲優：角色缺量低於門檻就自動改補聲優
    $mode = MY_BACKFILL_MODE;
    if ( $mode === 'characters' ) {
        $char_left = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}anime_characters
             WHERE bgm_id > 0 AND (summary IS NULL OR summary = '')"
        );
        if ( $char_left <= (int) MY_CHAR_DONE_THRESHOLD ) {
            $mode = 'persons';
        }
    }

    if ( $mode === 'persons' ) {
        $table  = $wpdb->prefix . 'anime_persons';
        $method = 'backfill_persons';
        $skip_key = 'my_backfill_skip_persons';
    } else {
        $table  = $wpdb->prefix . 'anime_characters';
        $method = 'backfill_characters';
        $skip_key = 'my_backfill_skip_chars';
    }

    // 跳過名單（補過但來源就是沒 summary 的 bgm_id）
    $skip = get_option( $skip_key, [] );
    if ( ! is_array( $skip ) ) { $skip = []; }

    // 組出 NOT IN 條件
    $not_in = '';
    if ( ! empty( $skip ) ) {
        $skip_ints = array_map( 'intval', $skip );
        $not_in = ' AND bgm_id NOT IN (' . implode( ',', $skip_ints ) . ')';
    }

    $ids = $wpdb->get_col(
        "SELECT bgm_id FROM {$table}
         WHERE bgm_id > 0 AND (summary IS NULL OR summary = '') {$not_in}
         LIMIT {$batch}"
    );

    if ( empty( $ids ) ) {
        update_option( 'my_backfill_last',
            gmdate( 'Y-m-d H:i:s' ) . ' | ' . $table . ' 沒有待補的了 (跳過名單 ' . count( $skip ) . ' 筆)' );
        delete_transient( 'my_backfill_lock' );
        return;
    }

    $updated = 0;
    $new_skip = 0;
    foreach ( $ids as $id ) {
        $id = (int) $id;
        try {
            $migrator->{$method}( [ 'bgm_id' => $id, 'force' => true ] );
        } catch ( \Throwable $e ) {
            error_log( 'my_backfill 例外 id=' . $id . ' : ' . $e->getMessage() );
        }
        // 補完立即回查：summary 真的有內容才算數，否則加入跳過名單
        $len = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT LENGTH(summary) FROM {$table} WHERE bgm_id = %d", $id
        ) );
        if ( $len > 0 ) {
            $updated++;
        } else {
            $skip[] = $id;
            $new_skip++;
        }
    }

    // 存回跳過名單（去重）
    $skip = array_values( array_unique( array_map( 'intval', $skip ) ) );
    update_option( $skip_key, $skip, false );

    update_option( 'my_backfill_last',
        gmdate( 'Y-m-d H:i:s' ) . ' | ' . $table
        . ' 這批 ' . count( $ids ) . ' 筆，實補 summary=' . $updated
        . '，新增跳過=' . $new_skip
        . '，跳過名單累計=' . count( $skip ) );

    delete_transient( 'my_backfill_lock' );
}
