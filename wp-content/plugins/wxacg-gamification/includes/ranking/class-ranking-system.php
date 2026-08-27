<?php
namespace WXACG\Gamification;

defined( 'ABSPATH' ) || exit;

/**
 * 排行榜核心
 *
 * 提供 4 種排行榜：
 *   exp_total    - 累積 EXP
 *   exp_monthly  - 當月 EXP（依 wp_smacg_monthly_exp 表）
 *   followers    - 粉絲數（依 wp_wxacg_follows 表）
 *   badges       - 徽章數（依 GamiPress earnings）
 *
 * Hook：
 *   smacg_exp_awarded → record_monthly_exp()
 *
 * @since 1.0.0
 * @version 1.3.0 (2026-05-22)
 *   - Fix BugB：exp_total 改讀 _gamipress_{slug}_points_awarded（A 累計）
 *     原本讀 _gamipress_{slug}_points（B 餘額）會造成：
 *       1) Gamipress_Bridge::get_user_exp() v2.6.5 已改讀 A，會員中心顯示 A
 *       2) 排行榜卻顯示 B，兩處數字長期不一致
 *       3) Bug A 連帶污染 B 後，排行榜分數更不可信
 *     現統一改讀 A，與會員中心、等級系統、徽章解鎖條件保持同源。
 *
 * @version 1.2.0 (2026-05-20)
 *   - Fix: 所有 $type / $uid 入口加上強制型別轉換，避免 array 被當字串造成
 *          "Array to string conversion" warning（line 92 區）
 *   - Fix: $r['extra'] 解析前先檢查型別，避免非字串時 json_decode 警告
 *   - Fix: $r['score'] 強制轉 int，避免 COUNT(*) 回傳字串造成下游問題
 */
class Ranking_System {

    private static $instance = null;

    public static function instance() {
        if ( self::$instance === null ) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        add_action( 'smacg_exp_awarded', [ __CLASS__, 'record_monthly_exp' ], 10, 4 );
    }

    /* ==========================================================
     * 月累積：聽 smacg_exp_awarded 寫入 monthly_exp 表
     * ========================================================== */
    public static function record_monthly_exp( $uid, $amount, $reason = '', $args = [] ) {
        $uid    = (int) $uid;
        $amount = (int) $amount;
        if ( $uid <= 0 || $amount <= 0 ) return;

        global $wpdb;
        $table = $wpdb->prefix . 'smacg_monthly_exp';
        $ym    = current_time( 'Ym' );

        $wpdb->query( $wpdb->prepare(
            "INSERT INTO {$table} (user_id, ym, exp_amount, updated_at)
             VALUES (%d, %s, %d, NOW())
             ON DUPLICATE KEY UPDATE exp_amount = exp_amount + VALUES(exp_amount), updated_at = NOW()",
            $uid, $ym, $amount
        ) );
    }

    /* ==========================================================
     * 對外 API：取得排行榜資料（分頁）
     * ========================================================== */
    public static function get( $type, $page = 1, $per_page = 20 ) {
        // ★ Fix: 強制 $type 為字串，防止 array 透過 $_POST['type'][]=xxx 注入
        $type = is_string( $type ) ? $type : '';
        if ( ! in_array( $type, WXACG_RANKING_TYPES, true ) ) {
            return [ 'items' => [], 'total' => 0, 'page' => 1, 'per_page' => (int) $per_page ];
        }
        $page     = max( 1, (int) $page );
        $per_page = max( 1, min( 100, (int) $per_page ) );
        $offset   = ( $page - 1 ) * $per_page;

        global $wpdb;
        $table = $wpdb->prefix . 'smacg_rankings';

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT rank_pos, user_id, score, extra
             FROM {$table}
             WHERE rank_type = %s
             ORDER BY rank_pos ASC
             LIMIT %d OFFSET %d",
            $type, $per_page, $offset
        ), ARRAY_A );

        $total = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE rank_type = %s", $type
        ) );

        $items = [];
        foreach ( (array) $rows as $r ) {
            $uid = (int) $r['user_id'];
            $u   = get_userdata( $uid );

            /* ★ Fix #4 (2026-05-18)：改用 wxacg_get_user_level_info() */
            $info      = function_exists( 'wxacg_get_user_level_info' ) ? wxacg_get_user_level_info( $uid ) : null;
            $job_title = function_exists( 'smacg_get_user_job_title' )  ? smacg_get_user_job_title( $uid )  : '';

            // job_title 可能回傳 array（Career_Jobs::user_title()）也可能回傳字串（compat 函式）
            // ★ Fix: 統一轉成字串顯示用，避免 Array to string conversion
            if ( is_array( $job_title ) ) {
                $job_title = (string) ( $job_title['title_name'] ?? '' );
            } else {
                $job_title = (string) $job_title;
            }

            // ★ Fix: extra 解析前先檢查
            $extra = null;
            if ( ! empty( $r['extra'] ) && is_string( $r['extra'] ) ) {
                $decoded = json_decode( $r['extra'], true );
                if ( is_array( $decoded ) ) $extra = $decoded;
            }

            $items[] = [
                'rank'         => (int) $r['rank_pos'],
                'user_id'      => $uid,
                'score'        => (int) $r['score'],
                'display_name' => $u ? (string) $u->display_name : '(已刪除)',
                'avatar'       => get_avatar_url( $uid, [ 'size' => 96 ] ),
                'profile_url'  => function_exists( 'wxacg_get_public_profile_url' ) ? wxacg_get_public_profile_url( $uid ) : '',
                'level'        => is_array( $info ) ? (int) ( $info['level'] ?? 1 ) : 1,
                'job_title'    => $job_title,
                'extra'        => $extra,
            ];
        }

        return [ 'items' => $items, 'total' => $total, 'page' => $page, 'per_page' => $per_page ];
    }

    /* ==========================================================
     * 取得單一用戶在某排行榜的名次（沒有則 null）
     * ========================================================== */
    public static function user_position( $type, $uid ) {
        $type = is_string( $type ) ? $type : '';
        if ( ! in_array( $type, WXACG_RANKING_TYPES, true ) ) return null;
        $uid = (int) $uid;
        if ( $uid <= 0 ) return null;

        global $wpdb;
        $table = $wpdb->prefix . 'smacg_rankings';
        $pos   = $wpdb->get_var( $wpdb->prepare(
            "SELECT rank_pos FROM {$table} WHERE rank_type = %s AND user_id = %d",
            $type, $uid
        ) );
        return $pos ? (int) $pos : null;
    }

    /* ==========================================================
     * 重算排行榜（全量）
     * ========================================================== */
    public static function rebuild_all() {
        $count = 0;
        foreach ( WXACG_RANKING_TYPES as $type ) {
            $count += self::rebuild_type( $type );
        }
        update_option( 'smacg_ranking_last_rebuild', current_time( 'mysql' ) );
        return $count;
    }

    public static function rebuild_type( $type ) {
        $type = is_string( $type ) ? $type : '';
        if ( ! in_array( $type, WXACG_RANKING_TYPES, true ) ) return 0;

        // rank_season / rank_last_season 不寫入 wp_smacg_rankings，跳過
        if ( $type === 'rank_season' || $type === 'rank_last_season' ) return 0;

        global $wpdb;
        $table = $wpdb->prefix . 'smacg_rankings';
        $rows  = self::compute( $type, WXACG_RANKING_TOP_N );

        /* 清掉舊紀錄 */
        $wpdb->delete( $table, [ 'rank_type' => $type ], [ '%s' ] );

        $now      = current_time( 'mysql' );
        $inserted = 0;
        foreach ( $rows as $i => $r ) {
            $extra_raw = isset( $r['extra'] ) ? $r['extra'] : null;
            $extra_str = null;
            if ( is_array( $extra_raw ) || is_object( $extra_raw ) ) {
                $extra_str = wp_json_encode( $extra_raw );
            } elseif ( is_string( $extra_raw ) ) {
                $extra_str = $extra_raw;
            }

            $wpdb->insert( $table, [
                'rank_type'  => $type,
                'rank_pos'   => $i + 1,
                'user_id'    => (int) $r['user_id'],
                'score'      => (int) $r['score'],
                'extra'      => $extra_str,
                'updated_at' => $now,
            ], [ '%s', '%d', '%d', '%d', '%s', '%s' ] );
            $inserted++;
        }
        return $inserted;
    }

    /* ==========================================================
     * 計算單一排行榜
     * ========================================================== */
    public static function compute( $type, $limit = 100 ) {
        global $wpdb;
        $type        = is_string( $type ) ? $type : '';
        $limit       = (int) $limit;
        $excluded    = self::excluded_user_ids();
        $excluded_in = empty( $excluded ) ? '0' : implode( ',', array_map( 'intval', $excluded ) );

        switch ( $type ) {
            case 'exp_total':
                /**
                 * ★ v1.3.0 BugB：改讀 _gamipress_{slug}_points_awarded（A 累計）
                 *
                 * 原本讀 _gamipress_{slug}_points（B 餘額）的問題：
                 *   1) Gamipress_Bridge::get_user_exp() v2.6.5 已改讀 A
                 *      會員中心、等級徽章顯示的 EXP 都是 A
                 *   2) 排行榜卻讀 B，兩處數字長期不一致
                 *   3) Bug A 污染 B 之後（log 記錄 +0/負值），B 已不可信
                 *
                 * 現統一改讀 A，A 由 GamiPress 在 award_points 時自動累加，
                 * 永遠不會因為 deduct 而下降，與「等級只升不降」的設計一致。
                 */
                $meta_key = '_gamipress_' . WXACG_EXP_SLUG . '_points_awarded';
                $sql = $wpdb->prepare( "
                    SELECT user_id, CAST(meta_value AS UNSIGNED) AS score
                    FROM {$wpdb->usermeta}
                    WHERE meta_key = %s
                      AND user_id NOT IN ($excluded_in)
                    ORDER BY score DESC
                    LIMIT %d
                ", $meta_key, $limit );
                $rows = $wpdb->get_results( $sql, ARRAY_A );
                return $rows ?: [];

            case 'exp_monthly':
                $ym  = current_time( 'Ym' );
                $tbl = $wpdb->prefix . 'smacg_monthly_exp';
                $sql = $wpdb->prepare( "
                    SELECT user_id, exp_amount AS score
                    FROM {$tbl}
                    WHERE ym = %s
                      AND user_id NOT IN ($excluded_in)
                    ORDER BY score DESC
                    LIMIT %d
                ", $ym, $limit );
                $rows = $wpdb->get_results( $sql, ARRAY_A );
                return $rows ?: [];

case 'followers':
    $tbl = $wpdb->prefix . 'wxacg_follows';
    if ( $wpdb->get_var( "SHOW TABLES LIKE '$tbl'" ) !== $tbl ) return [];
    $sql = $wpdb->prepare( "
        SELECT following_id AS user_id, COUNT(*) AS score
        FROM {$tbl}
        WHERE following_id NOT IN ($excluded_in)
        GROUP BY following_id
        ORDER BY score DESC
        LIMIT %d
    ", $limit );
    $rows = $wpdb->get_results( $sql, ARRAY_A );
    return $rows ?: [];

            /*
             * 簽到榜——依連續登入天數排名。
             *
             * 直接讀既有的 smacg_login_streak：站上早就有每日登入與連續
             * 天數的計算（見 class-daily-login-fallback.php），229 位會員
             * 都已有紀錄，不需要另做一套簽到機制再從零開始累積。
             *
             * 排除已斷簽的人：last_login_date 距今超過 1 天代表連續已中斷，
             * 但 streak 值要等下次登入才會被重設。不擋的話榜上會留著一堆
             * 早就斷簽、數字卻停在高點的舊紀錄。
             */
            case 'login_streak':
                /*
                 * 日期格式必須是 Ymd（20260827）而不是 Y-m-d。
                 * smacg_last_login_date 由 class-daily-login-fallback.php 以
                 * current_time('Ymd') 寫入——用帶連字號的格式比對會永遠不
                 * 匹配，榜單整片空白。實測時才發現，語法檢查抓不到這種錯。
                 */
                $today     = current_time( 'Ymd' );
                $yesterday = gmdate( 'Ymd', strtotime( '-1 day', current_time( 'timestamp' ) ) );

                $sql = $wpdb->prepare( "
                    SELECT s.user_id, CAST(s.meta_value AS UNSIGNED) AS score
                    FROM {$wpdb->usermeta} s
                    INNER JOIN {$wpdb->usermeta} d
                            ON d.user_id = s.user_id
                           AND d.meta_key = 'smacg_last_login_date'
                    WHERE s.meta_key = 'smacg_login_streak'
                      AND CAST(s.meta_value AS UNSIGNED) > 0
                      AND d.meta_value IN (%s, %s)
                      AND s.user_id NOT IN ($excluded_in)
                    ORDER BY score DESC
                    LIMIT %d
                ", $today, $yesterday, $limit );

                $rows = $wpdb->get_results( $sql, ARRAY_A );
                return $rows ?: [];


            case 'achievements':
                $earnings_tbl = $wpdb->prefix . 'gamipress_user_earnings';
                if ( $wpdb->get_var( "SHOW TABLES LIKE '$earnings_tbl'" ) === $earnings_tbl ) {
                    $sql = $wpdb->prepare( "
                        SELECT user_id, COUNT(*) AS score
                        FROM {$earnings_tbl}
                        WHERE post_type = %s
                          AND user_id NOT IN ($excluded_in)
                        GROUP BY user_id
                        ORDER BY score DESC
                        LIMIT %d
                    ", WXACG_BADGE_SLUG, $limit );
                } else {
                    $sql = $wpdb->prepare( "
                        SELECT user_id, COUNT(*) AS score
                        FROM {$wpdb->usermeta}
                        WHERE meta_key = '_gamipress_achievements'
                          AND user_id NOT IN ($excluded_in)
                        GROUP BY user_id
                        ORDER BY score DESC
                        LIMIT %d
                    ", $limit );
                }
                $rows = $wpdb->get_results( $sql, ARRAY_A );
                return $rows ?: [];
        }
        return [];
    }

    /* ==========================================================
     * 隱私：被排除的用戶 ID
     * ========================================================== */
    public static function excluded_user_ids() {
        global $wpdb;
        $rows = $wpdb->get_col( $wpdb->prepare(
            "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value = '0'",
            WXACG_RANKING_META_KEY
        ) );
        return array_map( 'intval', $rows ?: [] );
    }

    /* ==========================================================
     * 清理：刪除 N 個月前的 monthly_exp 列
     * ========================================================== */
    public static function purge_old_monthly( $keep_months = 13 ) {
        global $wpdb;
        $tbl    = $wpdb->prefix . 'smacg_monthly_exp';
        $cutoff = date( 'Ym', strtotime( "-{$keep_months} months", current_time( 'timestamp' ) ) );

        $deleted = $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$tbl} WHERE ym < %s", $cutoff
        ) );
        return (int) $deleted;
    }
}
