<?php
namespace WXACG\Gamification;

defined( 'ABSPATH' ) || exit;

/**
 * Anniversary Cron — 每日掃描生日 / 註冊週年
 *
 * 排程：每日 06:00（由 Activator 註冊 wp_schedule_event）
 * 動作：
 *   - 生日當天 → do_action('smacg_birthday_today', $uid)
 *   - 註冊週年 → do_action('smacg_anniversary_today', $uid, $years)
 *
 * 使用者必須先設定 user_meta:
 *   - smacg_birthday：'MM-DD' 或 'YYYY-MM-DD' 格式
 *
 * 註冊週年依 user_registered 欄位計算。
 *
 * @since 2.7.0
 * @version 2.7.1 (2026-05-22)
 *   - Fix BugG：原 run() 每天全量分批掃描所有使用者（10 萬人 = 200 批 SQL +
 *               100k 次 get_user_meta + 100k 次 strtotime），單次 cron
 *               極易 timeout。
 *               現改用 SQL 直接篩選符合條件的使用者：
 *                 1) 生日：wp_usermeta WHERE meta_key='smacg_birthday'
 *                          AND (meta_value=MM-DD OR meta_value LIKE %-MM-DD)
 *                 2) 週年：wp_users WHERE DATE_FORMAT(user_registered,'%m-%d')=MM-DD
 *                          AND YEAR(user_registered) < 今年
 *               複雜度從 O(N) 降為 O(K)，K = 今天生日 / 週年的人數，
 *               通常每日只有數十至數百人，cron 執行時間從分鐘級降到秒級。
 */
class Anniversary_Cron {

    private static $instance = null;
    const HOOK = 'smacg_anniversary_daily';

    public static function instance() {
        if ( self::$instance === null ) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        add_action( self::HOOK, [ __CLASS__, 'run' ] );
    }

    /**
     * 由 cron 觸發
     *
     * ★ v2.7.1：改用 SQL 直接撈當日生日 / 週年的使用者
     */
    public static function run() {
        global $wpdb;

        $today_md = current_time( 'm-d' );   // 'MM-DD'
        $today_y  = (int) current_time( 'Y' );

        /* ================================================================
         * 1. 生日掃描（SQL 一次性撈出當日生日的人）
         *
         * 接受兩種 meta_value 格式：
         *   - 'MM-DD'（5 字元）
         *   - 'YYYY-MM-DD'（10 字元）
         * ================================================================ */
        $birthday_uids = $wpdb->get_col( $wpdb->prepare(
            "SELECT user_id
               FROM {$wpdb->usermeta}
              WHERE meta_key = %s
                AND (
                      meta_value = %s
                   OR meta_value LIKE %s
                )",
            'smacg_birthday',
            $today_md,                     // 'MM-DD'
            '%-' . $today_md               // '%-MM-DD' 匹配 'YYYY-MM-DD'
        ) );

        $birthday_uids = array_map( 'intval', $birthday_uids ?: [] );
        $birthday_uids = array_filter( $birthday_uids, fn( $u ) => $u > 0 );

        foreach ( $birthday_uids as $uid ) {
            do_action( 'smacg_birthday_today', $uid );
        }

        /* ================================================================
         * 2. 註冊週年掃描（SQL DATE_FORMAT 比對 + YEAR < 今年）
         *
         * 排除註冊當年的人（必須滿至少 1 週年才觸發）
         * ================================================================ */
        $anniversary_rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT ID, YEAR(user_registered) AS reg_year
               FROM {$wpdb->users}
              WHERE DATE_FORMAT(user_registered, %s) = %s
                AND YEAR(user_registered) < %d",
            '%m-%d',
            $today_md,
            $today_y
        ), ARRAY_A );

        $total_anniversary = 0;
        foreach ( (array) $anniversary_rows as $row ) {
            $uid      = (int) $row['ID'];
            $reg_year = (int) $row['reg_year'];
            if ( $uid <= 0 || $reg_year <= 0 ) continue;

            $years = $today_y - $reg_year;
            if ( $years < 1 ) continue;

            do_action( 'smacg_anniversary_today', $uid, $years );
            $total_anniversary++;
        }

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( sprintf(
                '[SMACG] Anniversary cron (SQL mode): %d birthday + %d anniversary triggered',
                count( $birthday_uids ),
                $total_anniversary
            ) );
        }
    }

    /**
     * 排程（由 Activator 與啟用檢查共用）
     */
    public static function schedule() {
        if ( ! wp_next_scheduled( self::HOOK ) ) {
            // 隔天 06:00 開始
            $ts = strtotime( 'tomorrow 06:00:00', current_time( 'timestamp' ) );
            wp_schedule_event( $ts, 'daily', self::HOOK );
        }
    }

    public static function unschedule() {
        $ts = wp_next_scheduled( self::HOOK );
        if ( $ts ) wp_unschedule_event( $ts, self::HOOK );
    }
}
