<?php
namespace WXACG\Gamification;

defined( 'ABSPATH' ) || exit;

/**
 * 季賽結算（搬自 theme/inc/season-event-settle.php）
 *
 * Cron：
 *   smacg_event_settle_sweep  - 每 10 分鐘掃一次「已達標但未結算」的紀錄（補單）
 *   smacg_event_end_check     - 每小時掃一次「已結束但未公告」的活動
 */
class Event_Settle {

    private static $instance = null;

    public static function instance() {
        if ( self::$instance === null ) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        add_action( 'smacg_event_settle_sweep', [ __CLASS__, 'sweep' ] );
        add_action( 'smacg_event_end_check',    [ __CLASS__, 'end_check' ] );
    }

    /* ==========================================================
     * sweep：補發未結算的達標獎勵
     * ========================================================== */
    public static function sweep() {
        global $wpdb;
        $tbl = $wpdb->prefix . 'smacg_event_progress';

        $rows = $wpdb->get_results( "
            SELECT event_id, user_id
            FROM {$tbl}
            WHERE reached_at IS NOT NULL
              AND awarded_at IS NULL
            LIMIT 500
        ", ARRAY_A );

        if ( empty( $rows ) ) return;

        foreach ( $rows as $r ) {
            Event_Tracker::settle_one( (int) $r['event_id'], (int) $r['user_id'] );
        }

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( '[SMACG] Event sweep settled ' . count( $rows ) . ' rows' );
        }
    }

    /* ==========================================================
     * end_check：已結束但未公告的活動 → 寫 ended_flag + final_snapshot + 公告通知
     * ========================================================== */
    public static function end_check() {
        $now = current_time( 'mysql' );
        $q = new \WP_Query( [
            'post_type'      => WXACG_EVENT_CPT,
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'meta_query'     => [
                'relation' => 'AND',
                [ 'key' => '_smacg_event_ends_at', 'value' => $now, 'compare' => '<', 'type' => 'DATETIME' ],
                [
                    'relation' => 'OR',
                    [ 'key' => '_smacg_event_ended_flag', 'compare' => 'NOT EXISTS' ],
                    [ 'key' => '_smacg_event_ended_flag', 'value' => '1', 'compare' => '!=' ],
                ],
            ],
            'no_found_rows' => true,
        ] );

        foreach ( $q->posts as $p ) {
            self::finalize_event( $p->ID );
        }

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( '[SMACG] Event end_check processed ' . count( $q->posts ) . ' events' );
        }
    }

    /* ==========================================================
     * 結算單一活動：snapshot + flag + 公告
     * ========================================================== */
    public static function finalize_event( $event_id ) {
        $event_id = (int) $event_id;
        $ev = Event_CPT::get_meta( $event_id );
        if ( ! $ev ) return;

        /* 最後再做一次 sweep（保證 settle_one 跑完） */
        global $wpdb;
        $tbl = $wpdb->prefix . 'smacg_event_progress';
        $pending = $wpdb->get_results( $wpdb->prepare(
            "SELECT user_id FROM {$tbl} WHERE event_id = %d AND reached_at IS NOT NULL AND awarded_at IS NULL",
            $event_id
        ), ARRAY_A );
        foreach ( $pending as $r ) {
            Event_Tracker::settle_one( $event_id, (int) $r['user_id'], $ev );
        }

        /* Top N snapshot */
        $top = Event_Tracker::get_leaderboard( $event_id, 50 );
        $completed = Event_Tracker::get_progress_count( $event_id );

        update_post_meta( $event_id, '_smacg_event_final_snapshot', wp_json_encode( [
            'top'             => $top,
            'completed_count' => $completed,
            'finalized_at'    => current_time( 'mysql' ),
        ] ) );
        update_post_meta( $event_id, '_smacg_event_ended_flag', '1' );

        /* 公告：給所有「曾參與」的用戶通知 */
        if ( function_exists( 'wxacg_create_notification' ) ) {
            $participants = $wpdb->get_col( $wpdb->prepare(
                "SELECT DISTINCT user_id FROM {$tbl} WHERE event_id = %d",
                $event_id
            ) );
            foreach ( $participants as $uid ) {
                wxacg_create_notification( [
                    'user_id'     => (int) $uid,
                    'type'        => 'event_ended',
                    'object_type' => 'event',
                    'object_id'   => $event_id,
                    'data'        => [
                        'title'   => sprintf( '📢 活動「%s」已結束', $ev['title'] ),
                        'excerpt' => sprintf( '共 %d 人完成挑戰，前往查看最終排名', $completed ),
                        'url'     => $ev['permalink'],
                        'icon'    => 'fa-flag-checkered',
                    ],
                    'force'       => false,
                ] );
            }
        }

        do_action( 'smacg_event_finalized', $event_id, $ev );
    }
}
