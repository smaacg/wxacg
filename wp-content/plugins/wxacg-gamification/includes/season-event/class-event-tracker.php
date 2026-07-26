<?php
namespace WXACG\Gamification;

defined( 'ABSPATH' ) || exit;

/**
 * 季賽進度追蹤（搬自 theme/inc/season-event-tracker.php）
 *
 * 監聽各種業務事件，依「進行中活動」的 action_type 將進度寫入 wp_smacg_event_progress 表；
 * 達標時即時觸發 settle_one()，並寫入 reached_at + awarded_at。
 *
 * @version 1.2.0 (2026-05-28)
 *   - 賽季活動完成「預設不發通知」：賽季積分屬於背景累積，段位升級才通知用戶
 *     （段位升級通知由 wxacg-social/includes/legacy/notifications-events.php 的
 *      rank_up action 處理，觸發來源為 Rank_Season::maybe_fire_tier_up）
 *   - 保留後門：CPT 後台手動設 _smacg_event_force_notify = 1 時仍會發通知，
 *     便於將來舉辦「需要儀式感」的特殊活動（例如限定徽章活動）
 *   - 保留 do_action( 'smacg_event_completed' )，其他模組仍可 hook
 *
 * @version 1.1.0 (2026-05-18)
 *   - Fix #12：settle_one() 改用「先 UPDATE awarded_at WHERE awarded_at IS NULL」
 *               搭配 $wpdb->rows_affected 判斷，避免極端並發下雙發獎勵。
 */
class Event_Tracker {

    private static $instance = null;

    public static function instance() {
        if ( self::$instance === null ) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        /* 留言 */
        add_action( 'comment_post', function ( $cid, $approved, $data ) {
            if ( $approved !== 1 ) return;
            $uid = (int) ( $data['user_id'] ?? 0 );
            if ( $uid > 0 ) self::bump( $uid, 'comment', 1 );
        }, 30, 3 );

        /* 追蹤 */
        add_action( 'smacg_user_followed', function ( $follower_id, $followee_id ) {
            if ( $follower_id > 0 ) self::bump( $follower_id, 'follow', 1 );
        }, 30, 2 );

        /* anime-sync-pro 觀看 */
        add_action( 'smacg_watchlist_added', function ( $uid ) {
            self::bump( $uid, 'watchlist_add', 1 );
        }, 30 );
        add_action( 'smacg_watchlist_completed', function ( $uid ) {
            self::bump( $uid, 'watchlist_complete', 1 );
        }, 30 );
        add_action( 'smacg_rating_added', function ( $uid ) {
            self::bump( $uid, 'rating', 1 );
        }, 30 );

        /* EXP 賺取（給 exp_earned 類型活動用） */
        add_action( 'smacg_exp_awarded', function ( $uid, $amount ) {
            self::bump( $uid, 'exp_earned', (int) $amount );
        }, 30, 2 );
    }

    /* ==========================================================
     * 核心：對所有「進行中、action_type 符合」的活動 +delta 進度
     * ========================================================== */
    public static function bump( $uid, $action_type, $delta = 1 ) {
        $uid   = (int) $uid;
        $delta = (int) $delta;
        if ( $uid <= 0 || $delta <= 0 ) return;

        $events = Event_CPT::get_active_events();
        foreach ( $events as $ev ) {
            if ( $ev['action_type'] !== $action_type ) continue;
            self::update_progress( $ev['id'], $uid, $delta, $ev );
        }
    }

    private static function update_progress( $event_id, $uid, $delta, $ev ) {
        global $wpdb;
        $tbl = $wpdb->prefix . 'smacg_event_progress';
        $now = current_time( 'mysql' );

        /* 1) UPSERT 進度 */
        $wpdb->query( $wpdb->prepare(
            "INSERT INTO {$tbl} (event_id, user_id, progress, updated_at)
             VALUES (%d, %d, %d, %s)
             ON DUPLICATE KEY UPDATE progress = progress + VALUES(progress), updated_at = VALUES(updated_at)",
            $event_id, $uid, $delta, $now
        ) );

        /* 2) 讀回現況 */
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT progress, reached_at, awarded_at FROM {$tbl} WHERE event_id = %d AND user_id = %d",
            $event_id, $uid
        ), ARRAY_A );

        if ( ! $row ) return;
        $progress = (int) $row['progress'];

        /* 3) 達標 → 寫 reached_at + 立即結算 */
        if ( $progress >= (int) $ev['target'] && empty( $row['reached_at'] ) ) {
            $wpdb->update( $tbl,
                [ 'reached_at' => $now ],
                [ 'event_id' => $event_id, 'user_id' => $uid ],
                [ '%s' ], [ '%d', '%d' ]
            );
            self::settle_one( $event_id, $uid, $ev );
        }
    }

    /* ==========================================================
     * 立即結算（達標瞬間）：發 EXP + Badge + Title + 通知
     *
     * ★ Fix #12 (2026-05-18)：
     * 舊作法是「先 SELECT awarded_at 判斷是否為空 → 發獎 → UPDATE awarded_at」，
     * 在極端並發（例如同一秒兩個 request 同時讓 user 達標）下，兩個 process 可能
     * 都讀到 awarded_at = NULL，導致雙發獎勵。
     *
     * 新作法：先用「UPDATE awarded_at WHERE awarded_at IS NULL」做原子搶鎖，
     * 用 $wpdb->rows_affected 判斷自己是不是真的拿到鎖，沒拿到就 return。
     * 之後的 award_exp/award_badge/title/notification 都在搶到鎖後才執行。
     *
     * 副作用：如果之後 award_exp 失敗，awarded_at 已被寫入 → 這個 user 不會再
     * 被結算。這是刻意取捨：寧可漏發也不要雙發（漏發可手動補；雙發要手動扣回，
     * 而且使用者體感差）。
     * ========================================================== */
    public static function settle_one( $event_id, $uid, $ev = null ) {
        if ( ! $ev ) $ev = Event_CPT::get_meta( $event_id );
        if ( ! $ev ) return false;

        global $wpdb;
        $tbl = $wpdb->prefix . 'smacg_event_progress';
        $now = current_time( 'mysql' );

        /* ── 原子搶鎖：只有第一個成功 UPDATE 的 process 會拿到 rows_affected = 1 ── */
        $wpdb->query( $wpdb->prepare(
            "UPDATE {$tbl}
                SET awarded_at = %s
              WHERE event_id = %d
                AND user_id  = %d
                AND awarded_at IS NULL
                AND reached_at IS NOT NULL",
            $now, (int) $event_id, (int) $uid
        ) );

        if ( (int) $wpdb->rows_affected !== 1 ) {
            return false; // 已被另一個 process 結算 / 還沒達標 / 該列不存在
        }

        /* ── 以下為「我拿到鎖」之後才做的事 ── */

        /* EXP */
        if ( $ev['reward_exp'] > 0 ) {
            Gamipress_Bridge::award_exp( $uid, (int) $ev['reward_exp'], 'Event: ' . $ev['title'] );
        }
        /* Badge */
        if ( $ev['reward_badge'] > 0 ) {
            Gamipress_Bridge::award_badge( $uid, (int) $ev['reward_badge'] );
        }
        /* Title（user_meta smacg_event_titles 為 array） */
        if ( ! empty( $ev['reward_title'] ) ) {
            $titles = get_user_meta( $uid, 'smacg_event_titles', true );
            if ( ! is_array( $titles ) ) $titles = [];
            if ( ! in_array( $ev['reward_title'], $titles, true ) ) {
                $titles[] = $ev['reward_title'];
                update_user_meta( $uid, 'smacg_event_titles', $titles );
            }
        }

        /* ──────────────────────────────────────────────────────────
         * 通知（v1.2.0：預設靜默）
         *
         * 設計理由：
         *   賽季積分（Season Score）是「背景累積」型機制，每個活動完成都發通知
         *   會造成資訊噪音（例如「完成活動 +0 EXP」這種空洞訊息）。
         *   用戶真正在意的是「段位升級」（鐵 → 銅 → 銀），這由 rank_up 事件處理。
         *
         * 例外情況：
         *   若 CPT 後台手動勾選「強制通知」（_smacg_event_force_notify = 1），
         *   則仍會發通知，留給未來特殊活動使用（例如限定徽章、紀念稱號）。
         *
         * 後門啟用方式：
         *   在 wp-admin 編輯該活動 → 自訂欄位（Custom Fields）→ 新增
         *   key = _smacg_event_force_notify, value = 1
         * ────────────────────────────────────────────────────────── */
        $force_notify = (int) get_post_meta( (int) $event_id, '_smacg_event_force_notify', true ) === 1;

        if ( $force_notify && function_exists( 'wxacg_create_notification' ) ) {
            wxacg_create_notification( [
                'user_id'     => $uid,
                'type'        => 'event_completed',
                'object_type' => 'event',
                'object_id'   => $event_id,
                'data'        => [
                    'title'   => sprintf( '🎯 完成挑戰「%s」', $ev['title'] ),
                    'excerpt' => sprintf(
                        '獲得 +%d EXP%s%s',
                        $ev['reward_exp'],
                        $ev['reward_badge'] ? '、徽章' : '',
                        $ev['reward_title'] ? '、稱號「' . $ev['reward_title'] . '」' : ''
                    ),
                    'url'     => $ev['permalink'],
                    'icon'    => 'fa-bullseye',
                ],
                'force'       => true,
            ] );
        }

        /* 對外事件：其他模組仍可 hook（例如統計、排行榜刷新） */
        do_action( 'smacg_event_completed', $uid, $event_id, $ev );
        return true;
    }

    /* ==========================================================
     * 對外 API
     * ========================================================== */
    public static function get_progress( $event_id, $uid ) {
        global $wpdb;
        $tbl = $wpdb->prefix . 'smacg_event_progress';
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT progress, reached_at, awarded_at FROM {$tbl} WHERE event_id = %d AND user_id = %d",
            (int) $event_id, (int) $uid
        ), ARRAY_A );
        if ( ! $row ) return [ 'progress' => 0, 'reached' => false, 'awarded' => false ];
        return [
            'progress' => (int) $row['progress'],
            'reached'  => ! empty( $row['reached_at'] ),
            'awarded'  => ! empty( $row['awarded_at'] ),
        ];
    }

    public static function get_leaderboard( $event_id, $limit = 20 ) {
        global $wpdb;
        $tbl = $wpdb->prefix . 'smacg_event_progress';
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT user_id, progress, reached_at
             FROM {$tbl}
             WHERE event_id = %d
             ORDER BY progress DESC, reached_at ASC
             LIMIT %d",
            (int) $event_id, (int) $limit
        ), ARRAY_A );
        return $rows ?: [];
    }

    public static function get_progress_count( $event_id ) {
        global $wpdb;
        $tbl = $wpdb->prefix . 'smacg_event_progress';
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$tbl} WHERE event_id = %d AND reached_at IS NOT NULL",
            (int) $event_id
        ) );
    }
}
