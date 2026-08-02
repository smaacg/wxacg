<?php
/**
 * Plugin Name: 角色/聲優自動回補 (wp-cron, 跳過空資料版)
 * Description: 每5分鐘補一批 BGM 資料；補完仍無 summary 的 bgm_id 會被記入跳過名單，避免死循環。
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
