<?php
/**
 * User Status Cron
 *
 * @package Anime_Sync_Pro
 * @version 1.2.0
 *
 * Changelog:
 *   1.2.0 — [Optimize] 排程優化（配合主外掛 v1.2.2）
 *     - [USC-O1] 新增 schedule() 靜態方法，供 register_activation_hook 呼叫。
 *                排程設定改由外掛啟用時一次性執行，不再每頁 init 重複呼叫
 *                wp_next_scheduled()（每頁多一次 DB/快取查詢）。
 *     - [USC-O1] __construct() 移除 add_action('init', maybe_schedule)，
 *                maybe_schedule() 保留為 public 以維持向下相容
 *                （既有環境升級後若排程已存在不受影響）。
 *
 *   1.1.0 — Stats 重算優化
 *     - [USC-H1] recalc_stats() 改 INSERT...ON DUPLICATE KEY UPDATE，
 *                不再 TRUNCATE，避免重算期間 get_ranking() 撈到空表。
 *     - [USC-H1] 新增孤兒列清理：刪除主表已沒紀錄但 stats 表還留著的 anime_id。
 *     - [USC-M1] flush_ranking_cache() 改用 wp_cache_flush_group()，
 *                避免 limit 不在白名單時 cache 殘留。
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Anime_Sync_User_Status_Cron {

    const HOOK     = 'anime_sync_recalc_user_status_stats';
    const SCHEDULE = 'asp_every_15_minutes';

    public function __construct() {
        add_filter( 'cron_schedules', [ $this, 'add_schedule' ] );
        add_action( self::HOOK, [ $this, 'recalc_stats' ] );

        // ✅ [v1.2.0] 移除 add_action('init', maybe_schedule)。
        //    排程改由 register_activation_hook 呼叫 self::schedule() 靜態方法設定，
        //    不再每頁請求都執行 wp_next_scheduled() 查詢。
        //    既有環境排程已存在時不受任何影響。

        add_action( 'wp_ajax_asp_recalc_user_status_stats', [ $this, 'ajax_manual_recalc' ] );
    }

    // =========================================================================
    // 排程間隔
    // =========================================================================

    public function add_schedule( array $schedules ): array {
        $schedules[ self::SCHEDULE ] = [
            'interval' => 15 * MINUTE_IN_SECONDS,
            'display'  => 'Every 15 minutes (Anime Sync)',
        ];
        return $schedules;
    }

    // =========================================================================
    // 排程設定
    // =========================================================================

    /**
     * ✅ [v1.2.0] 靜態方法，由 register_activation_hook 呼叫。
     * 外掛啟用時一次性設定排程，取代原本每頁 init 執行的 maybe_schedule()。
     */
    public static function schedule(): void {
        if ( ! wp_next_scheduled( self::HOOK ) ) {
            wp_schedule_event( time() + 60, self::SCHEDULE, self::HOOK );
        }
    }

    /**
     * 保留為 public，維持向下相容。
     * 若有外部程式碼直接呼叫此方法仍可正常運作。
     * 新版不再由 init hook 自動呼叫。
     */
    public function maybe_schedule(): void {
        if ( ! wp_next_scheduled( self::HOOK ) ) {
            wp_schedule_event( time() + 60, self::SCHEDULE, self::HOOK );
        }
    }

    /**
     * 停用時取消排程。
     */
    public static function unschedule(): void {
        $ts = wp_next_scheduled( self::HOOK );
        if ( $ts ) {
            wp_unschedule_event( $ts, self::HOOK );
        }
    }

    // =========================================================================
    // 統計重算
    // =========================================================================

    public function recalc_stats(): int {
        global $wpdb;

        $main_table  = $wpdb->prefix . 'anime_user_status';
        $stats_table = $wpdb->prefix . 'anime_user_status_stats';

        $start_time = microtime( true );

        // [USC-H1] INSERT ... ON DUPLICATE KEY UPDATE
        // 不再 TRUNCATE，避免重算期間排行榜撈到空表。
        $sql = "
            INSERT INTO {$stats_table}
                (anime_id, want_count, watching_count, completed_count,
                 dropped_count, favorited_count, total_count)
            SELECT
                anime_id,
                SUM(CASE WHEN status = 0 THEN 1 ELSE 0 END) AS want_count,
                SUM(CASE WHEN status = 1 THEN 1 ELSE 0 END) AS watching_count,
                SUM(CASE WHEN status = 2 THEN 1 ELSE 0 END) AS completed_count,
                SUM(CASE WHEN status = 3 THEN 1 ELSE 0 END) AS dropped_count,
                SUM(favorited)                               AS favorited_count,
                COUNT(*)                                     AS total_count
            FROM {$main_table}
            GROUP BY anime_id
            ON DUPLICATE KEY UPDATE
                want_count      = VALUES(want_count),
                watching_count  = VALUES(watching_count),
                completed_count = VALUES(completed_count),
                dropped_count   = VALUES(dropped_count),
                favorited_count = VALUES(favorited_count),
                total_count     = VALUES(total_count)
        ";

        $rows_affected = $wpdb->query( $sql );

        // [USC-H1] 清掉主表已沒紀錄但 stats 表還留著的孤兒列
        // （該動畫被所有使用者移除後，stats 應同步刪除）
        $wpdb->query( "
            DELETE s FROM {$stats_table} s
            LEFT JOIN {$main_table} m ON s.anime_id = m.anime_id
            WHERE m.anime_id IS NULL
        " );

        $duration = round( microtime( true ) - $start_time, 3 );

        $this->flush_ranking_cache();

        if ( class_exists( 'Anime_Sync_Error_Logger' ) ) {
            Anime_Sync_Error_Logger::info( 'User status stats recalculated', [
                'rows_affected' => (int) $rows_affected,
                'duration_sec'  => $duration,
            ] );
        }

        return (int) $rows_affected;
    }

    // =========================================================================
    // Ajax 手動觸發
    // =========================================================================

    public function ajax_manual_recalc(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Permission denied' ], 403 );
        }
        check_ajax_referer( 'asp_recalc_stats', 'nonce' );

        $count = $this->recalc_stats();
        wp_send_json_success( [
            'message' => "Recalculated {$count} anime stats",
            'count'   => $count,
        ] );
    }

    // =========================================================================
    // 快取清除
    // =========================================================================

    /**
     * [USC-M1] 清除排行榜快取。
     *
     * 優先使用 wp_cache_flush_group() 一次清光整個 group（WP 6.1+），
     * 避免 get_ranking() 使用非白名單 limit 時 cache 殘留。
     * WordPress < 6.1 fallback 為逐一刪除常見組合。
     */
    private function flush_ranking_cache(): void {
        if ( function_exists( 'wp_cache_flush_group' ) ) {
            wp_cache_flush_group( 'anime_user_status' );
            return;
        }

        // WordPress < 6.1 fallback
        $types  = [ 'favorited', 'watching', 'completed', 'want', 'dropped', 'total' ];
        $limits = [ 5, 10, 15, 20, 25, 30, 50, 100 ];
        foreach ( $types as $t ) {
            foreach ( $limits as $l ) {
                wp_cache_delete( "us_rank_{$t}_{$l}", 'anime_user_status' );
            }
        }
    }
}
