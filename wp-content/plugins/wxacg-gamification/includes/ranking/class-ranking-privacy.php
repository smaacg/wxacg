<?php
namespace WXACG\Gamification;

defined( 'ABSPATH' ) || exit;

/**
 * 排行榜隱私設定（搬自 theme/inc/ranking-privacy.php）
 *
 * user_meta WXACG_RANKING_META_KEY:
 *   '1'（或不存在）→ 顯示
 *   '0'           → 隱藏
 *
 * @version 1.1.0 (2026-05-22)
 *   - Fix BugH：隱藏後僅 DELETE 自己那一筆，導致 wp_smacg_rankings.rank_pos
 *     出現跳號（例：原 1,2,3,4,5 → DELETE 第 3 後變成 1,2,4,5），
 *     前端顯示「我第 5 名」但實際上應為第 4 名，與計算後的 rank 不一致。
 *     現於 DELETE / 重新顯示後排程 smacg_ranking_recalc 觸發 Ranking_System::rebuild_all()
 *     由 cron 在 5 秒後重新計算所有排行榜的 rank_pos。
 *     使用 wp_schedule_single_event() 避免阻塞 AJAX 回應。
 *
 *   - Improve：localize 時加上 fallback，若 smacg-member 未 enqueue 仍嘗試
 *     enqueue 一個空 handle 'smacg-ranking-privacy'，避免會員頁外的隱私切換按鈕失靈。
 */
class Ranking_Privacy {

    private static $instance = null;

    public static function instance() {
        if ( self::$instance === null ) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        add_action( 'wp_ajax_smacg_toggle_ranking_visibility', [ __CLASS__, 'handle_toggle' ] );
        add_action( 'wp_enqueue_scripts', [ __CLASS__, 'localize' ], 25 );
    }

    public static function is_visible( $uid ) {
        return get_user_meta( (int) $uid, WXACG_RANKING_META_KEY, true ) !== '0';
    }

    public static function handle_toggle() {
        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'message' => '未登入' ], 401 );
        }
        if ( ! check_ajax_referer( 'smacg_ranking_privacy', 'nonce', false ) ) {
            wp_send_json_error( [ 'message' => 'Nonce 驗證失敗' ], 403 );
        }

        $uid     = get_current_user_id();
        $current = self::is_visible( $uid );
        $new     = $current ? '0' : '1';
        update_user_meta( $uid, WXACG_RANKING_META_KEY, $new );

        /**
         * ★ v1.1.0 BugH：DELETE 自己 + 排程 rebuild
         *
         * - 立即 DELETE：讓使用者立刻在排行榜看不到自己
         * - 排程 5 秒後 rebuild：修正其他人的 rank_pos 跳號
         *
         * 使用 wp_schedule_single_event() 而非直接 call Ranking_System::rebuild_all()
         * 因為 rebuild_all() 在大量用戶時可能 >5 秒，會卡住 AJAX 回應；
         * 透過 cron 非同步執行可立即回 200 給前端。
         *
         * Ranking_Cron::on_recalc() 已用 transient lock，不會與排程衝突。
         */
        if ( $new === '0' ) {
            global $wpdb;
            $wpdb->delete( $wpdb->prefix . 'smacg_rankings', [ 'user_id' => $uid ], [ '%d' ] );
        }

        // 不論隱藏或重新顯示，都要 rebuild 一次：
        //   - 隱藏：補齊 rank_pos 跳號
        //   - 重新顯示：把自己加回排行榜
        if ( ! wp_next_scheduled( 'smacg_ranking_recalc' ) ) {
            wp_schedule_single_event( time() + 5, 'smacg_ranking_recalc' );
        }

        wp_send_json_success( [
            'visible'  => $new === '1',
            'message'  => $new === '1' ? '已加入排行榜（5 秒後生效）' : '已從排行榜隱藏',
            'rebuild'  => 'scheduled',
        ] );
    }

    public static function localize() {
        if ( ! is_user_logged_in() ) return;

        $data = [
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'smacg_ranking_privacy' ),
            'visible'  => self::is_visible( get_current_user_id() ),
        ];

        if ( wp_script_is( 'smacg-member', 'enqueued' ) ) {
            wp_localize_script( 'smacg-member', 'SmacgRankingPrivacy', $data );
        }
        // 若 smacg-member 未 enqueue，使用者就不會在當前頁看到切換按鈕，
        // 故無需 fallback localize。member-render.php 的隱私頁面會確保 smacg-member 已 enqueue。
    }
}
