<?php
namespace WXACG\Gamification;

defined( 'ABSPATH' ) || exit;

/**
 * GamiPress 統一抽象層
 *
 * v2.6.6 (2026-05-22)
 *   - Fix BugA：award_exp() 預設 admin_id 由 1 改為 0
 *     避免被 gamipress_update_user_points() 視為「設定總額模式」
 *     觸發 $new_points = $new_points - $current_points 的減法邏輯，
 *     導致 log 記錄 +0 或負值（症狀：EXP 日誌出現「+0 設定生日」）
 *   - Fix BugA：log_type 由 'points_award' 改為 'points_earn'
 *     points_award 在 GamiPress 語意上代表「管理員手動授予」，
 *     一般自動發點應使用 points_earn，確保 get_exp_log() 與
 *     GamiPress 原生日誌查詢的分類一致
 *
 * v2.6.5 (2026-05-21)
 *   - Refactor：廢除 B（GamiPress balance / _gamipress_exp_points）概念
 *     EXP 改為「純累計」型，僅用於等級計算與徽章解鎖條件
 *     扣分需求請改走賽季積分 C（Rank_Season::add_score()）
 *   - Change：get_user_exp() 改讀 _gamipress_exp_points_awarded（A 累計值）
 *             不再呼叫 gamipress_get_user_points()（會回傳 B 餘額）
 *   - Change：deduct_exp() 停用，呼叫一律 return false 並寫入 debug log
 *             避免任何外部程式碼/外掛意外扣減 A 導致等級下降
 *   - Note：徽章相關方法（get_user_badge_* / award_badge）完全不受影響
 */
class Gamipress_Bridge {

    private static $instance = null;

    public static function instance() {
        if ( self::$instance === null ) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        add_action( 'admin_init', [ __CLASS__, 'maybe_show_admin_notice' ] );

        // 設定變動時清掉 transient
        $clear = function () { delete_transient( 'smacg_gamipress_setup_errors' ); };
        add_action( 'save_post_points-type',      $clear );
        add_action( 'save_post_achievement-type', $clear );
        add_action( 'activated_plugin',           $clear );
        add_action( 'deactivated_plugin',         $clear );
        add_action( 'switch_theme',               $clear );
    }

    /* ==========================================================
     * GamiPress 可用性
     * ========================================================== */
    public static function active() {
        return function_exists( 'gamipress_get_user_points' );
    }

    /* ==========================================================
     * EXP（純累計，僅用於等級計算）
     *
     * 資料來源：user_meta `_gamipress_exp_points_awarded`
     *   - 由 GamiPress 在每次 award_points 時自動累加
     *   - 永遠不會因為 deduct 而下降
     *   - 對應「層 A：累計 EXP」
     *
     * ★ 不再使用 gamipress_get_user_points()
     *   因為該函式回傳的是 `_gamipress_exp_points`（B 餘額），
     *   會被 GamiPress 內部或其他外掛的扣分機制修改，
     *   導致等級下降，這不符合本站「等級只升不降」的設計。
     * ========================================================== */
    public static function get_user_exp( $uid ) {
        $uid = (int) $uid;
        if ( $uid <= 0 ) return 0;

        if ( ! self::active() ) {
            return (int) get_user_meta( $uid, 'smacg_exp_fallback', true );
        }

        // ★ v2.6.5：改讀累計值（A），不再讀餘額（B）
        return (int) get_user_meta( $uid, '_gamipress_' . WXACG_EXP_SLUG . '_points_awarded', true );
    }

    public static function award_exp( $uid, $amount, $reason = '', $args = [] ) {
        $uid    = (int) $uid;
        $amount = (int) $amount;
        if ( $uid <= 0 || $amount <= 0 ) return false;

        if ( ! self::active() ) {
            $cur = (int) get_user_meta( $uid, 'smacg_exp_fallback', true );
            update_user_meta( $uid, 'smacg_exp_fallback', $cur + $amount );
            do_action( 'smacg_exp_awarded', $uid, $amount, $reason ?: 'fallback', $args );
            return true;
        }

        /**
         * ★ v2.6.6 BugA：admin_id 預設 0
         *
         * GamiPress 的 gamipress_update_user_points() 內部：
         *   if ( $admin_id ) {
         *       $new_points = $new_points - $current_points;
         *   }
         *
         * 當 admin_id !== 0 時，$new_points 會被當成「目標總額」處理，
         * 自動減去現有點數，導致 log 記錄到錯誤甚至 0 點數。
         *
         * 系統自動發點必須使用 admin_id = 0，這樣 $new_points 才是純粹的增量。
         *
         * 若呼叫端真的需要管理員手動設定總額模式，請明確傳入 args.admin_id。
         */
        $admin_id = isset( $args['admin_id'] ) ? (int) $args['admin_id'] : 0;
        $reason   = $reason ?: 'wxacg_award_exp';

        // GamiPress 內部會同時更新：
        //   - _gamipress_exp_points         (B 餘額，我們已不再讀)
        //   - _gamipress_exp_points_awarded (A 累計，我們唯一的真相來源)
        //   - _gamipress_exp_new_points     (上次變動量)
        $result = gamipress_award_points_to_user( $uid, $amount, WXACG_EXP_SLUG, [
            'admin_id'    => $admin_id,
            'reason'      => $reason,
            // ★ v2.6.6 BugA：log_type 改為 'points_earn'（系統自動發點）
            // 'points_award' 在 GamiPress 語意上是「管理員手動授予」
            'log_type'    => 'points_earn',
            'achievement' => isset( $args['achievement_id'] ) ? $args['achievement_id'] : 0,
        ] );

        do_action( 'smacg_exp_awarded', $uid, $amount, $reason, $args );
        return $result !== false;
    }

    /**
     * ★ v2.6.5：EXP 扣分功能已廢除
     *
     * 本站 EXP 為純累計型（A），等級只升不降。
     * 任何呼叫 deduct_exp() 的程式碼一律無作用並回傳 false。
     *
     * 若你的需求是「扣賽季積分」，請改呼叫：
     *   \WXACG\Gamification\Rank_Season::add_score( $uid, -$amount );
     *
     * 若你的需求是「扣某種可消耗的虛擬貨幣」，請另建獨立的 points type，
     * 不要重用 'exp'。
     */
    public static function deduct_exp( $uid, $amount, $reason = '' ) {
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( sprintf(
                '[SMACG] Gamipress_Bridge::deduct_exp() ignored (EXP is cumulative-only since v2.6.5). uid=%d amount=%d reason=%s',
                (int) $uid,
                (int) $amount,
                (string) $reason
            ) );
        }
        return false;
    }

    /* ==========================================================
     * Points log
     * ========================================================== */
    public static function get_exp_log( $uid, $limit = 50 ) {
        global $wpdb;
        $uid   = (int) $uid;
        $limit = max( 1, min( 100, (int) $limit ) );

        if ( $uid <= 0 || ! self::active() ) return [];

        $logs_table = $wpdb->prefix . 'gamipress_logs';
        $meta_table = $wpdb->prefix . 'gamipress_logs_meta';

        if ( $wpdb->get_var( "SHOW TABLES LIKE '$logs_table'" ) !== $logs_table ) return [];

        $rows = $wpdb->get_results( $wpdb->prepare( "
            SELECT l.log_id, l.title, l.date, m1.meta_value AS points, m2.meta_value AS points_type, l.type
            FROM $logs_table l
            LEFT JOIN $meta_table m1 ON l.log_id = m1.log_id AND m1.meta_key = '_gamipress_points'
            LEFT JOIN $meta_table m2 ON l.log_id = m2.log_id AND m2.meta_key = '_gamipress_points_type'
            WHERE l.user_id = %d
              AND l.type IN ('points_award','points_deduct','points_earn','points_expend')
              AND m2.meta_value = %s
            ORDER BY l.date DESC
            LIMIT %d
        ", $uid, WXACG_EXP_SLUG, $limit ), ARRAY_A );

        if ( empty( $rows ) ) return [];

        $out = [];
        foreach ( $rows as $row ) {
            $is_deduct = in_array( $row['type'], [ 'points_deduct', 'points_expend' ], true );
            $value     = (int) $row['points'];
            $value     = $is_deduct ? -abs( $value ) : abs( $value );

            $out[] = [
                'created_at'   => $row['date'],
                'change_value' => $value,
                'reason'       => $row['title'] ?: '系統調整',
            ];
        }
        return $out;
    }

    /* ==========================================================
     * Badge（完全不受 v2.6.5 影響，原樣保留）
     * ========================================================== */
    public static function get_user_badge_ids( $uid ) {
        $uid = (int) $uid;
        if ( $uid <= 0 || ! self::active() ) return [];

        $earnings = gamipress_get_user_achievements( [
            'user_id'          => $uid,
            'achievement_type' => WXACG_BADGE_SLUG,
        ] );
        if ( empty( $earnings ) ) return [];
        return wp_list_pluck( $earnings, 'ID' );
    }

    public static function get_user_badge_count( $uid ) {
        return count( self::get_user_badge_ids( $uid ) );
    }

    public static function award_badge( $uid, $badge_post_id ) {
        $uid           = (int) $uid;
        $badge_post_id = (int) $badge_post_id;
        if ( $uid <= 0 || $badge_post_id <= 0 || ! self::active() ) return false;

        if ( function_exists( 'gamipress_award_achievement_to_user' ) ) {
            gamipress_award_achievement_to_user( $badge_post_id, $uid );
            do_action( 'smacg_badge_awarded', $uid, $badge_post_id );
            return true;
        }
        return false;
    }

    /* ==========================================================
     * 後台設定檢查
     * ========================================================== */
    public static function check_setup() {
        $errors = [];
        if ( ! self::active() ) {
            $errors[] = 'GamiPress 外掛尚未啟用';
            return $errors;
        }
        $exp_type = get_page_by_path( WXACG_EXP_SLUG, OBJECT, 'points-type' );
        if ( ! $exp_type ) {
            $errors[] = sprintf( '請至 GamiPress 後台建立 Points Type，slug = "%s"', WXACG_EXP_SLUG );
        }
        $badge_type = get_page_by_path( WXACG_BADGE_SLUG, OBJECT, 'achievement-type' );
        if ( ! $badge_type ) {
            $errors[] = sprintf( '請至 GamiPress 後台建立 Achievement Type，slug = "%s"', WXACG_BADGE_SLUG );
        }
        return $errors;
    }

    public static function maybe_show_admin_notice() {
        if ( ! current_user_can( 'manage_options' ) ) return;
        if ( ! is_admin() ) return;

        $errors = get_transient( 'smacg_gamipress_setup_errors' );
        if ( $errors === false ) {
            $errors = self::check_setup();
            set_transient( 'smacg_gamipress_setup_errors', $errors, HOUR_IN_SECONDS );
        }
        if ( empty( $errors ) ) return;

        add_action( 'admin_notices', function () use ( $errors ) {
            $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
            if ( ! $screen || ! in_array( $screen->id, [ 'dashboard', 'plugins', 'themes' ], true ) ) return;

            echo '<div class="notice notice-warning"><p><strong>SMACG Gamification 系統提示</strong></p><ul style="list-style:disc;margin-left:20px">';
            foreach ( $errors as $e ) {
                echo '<li>' . esc_html( $e ) . '</li>';
            }
            echo '</ul></div>';
        } );
    }
}
