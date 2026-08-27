<?php
/**
 * 每日簽到狀態
 *
 * 站上的簽到是「登入即自動計算」，不需要手動點擊——這點刻意不改：
 * 229 位會員已經累積了連續紀錄，改成手動點擊等於讓所有人歸零重來，
 * 而且忘記點就斷簽，反而要再做補簽機制來補救。
 *
 * 這支只負責算出目前狀態：連續幾天、本輪 7 天走到哪、距離下一個里程碑
 * 還差幾天。獎勵結構完全沿用既有的 streak_7 / streak_30，不新增也不修改
 * EXP 規則。
 *
 * 原本還有一個 render() 會把這些畫在會員中心，簽到改到頭像選單的彈窗
 * （class-checkin-modal.php）之後就沒有呼叫者了，連同 member.css 裡的
 * .wxci* 樣式一起移除。現在只剩 get_status()，由 Checkin_Modal 使用。
 *
 * 資料來源（由 class-daily-login-fallback.php 與 class-exp-events.php 寫入）：
 *   smacg_login_streak     目前連續天數
 *   smacg_last_login_date  最後登入日（Ymd，例如 20260827）
 *   smacg_streak_gen       斷簽代次
 *
 * @package WXACG_Gamification
 */

namespace WXACG\Gamification;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Checkin_Panel {

    /** 一輪的天數，與 streak_7 的發放週期一致 */
    const CYCLE = 7;

    /** 大里程碑，與 streak_30 一致 */
    const BIG_CYCLE = 30;

    /**
     * 取得某位會員的簽到狀態。
     *
     * @param int $uid 會員 ID。
     * @return array {
     *     @type int  $streak       目前連續天數
     *     @type bool $today_done   今天是否已計入
     *     @type int  $cycle_pos    本輪走到第幾格（1..7）
     *     @type int  $to_next7     距離下一個 7 天里程碑還差幾天
     *     @type int  $to_next30    距離下一個 30 天里程碑還差幾天
     * }
     */
    public static function get_status( int $uid ): array {
        $streak = max( 0, (int) get_user_meta( $uid, 'smacg_login_streak', true ) );
        $last   = (string) get_user_meta( $uid, 'smacg_last_login_date', true );
        $today  = current_time( 'Ymd' );

        /*
         * 斷簽判定：最後登入既不是今天也不是昨天，代表連續已中斷，但
         * smacg_login_streak 要等下次登入才會被重設。面板若照舊值顯示，
         * 使用者會看到一個早就作廢的天數——與簽到榜的處理一致。
         */
        $yesterday = gmdate( 'Ymd', strtotime( '-1 day', current_time( 'timestamp' ) ) );
        if ( $last !== $today && $last !== $yesterday ) {
            $streak = 0;
        }

        $today_done = ( $last === $today );

        /*
         * 兩個週期的位置。
         *
         * % 為 0 時代表剛好走完整輪，要顯示成最後一格而不是第 0 格
         * （streak=7 是本輪第 7 格、streak=30 是大輪第 30 格）。
         */
        $cycle_pos = $streak > 0
            ? ( ( $streak % self::CYCLE ) === 0 ? self::CYCLE : $streak % self::CYCLE )
            : 0;

        $big_pos = $streak > 0
            ? ( ( $streak % self::BIG_CYCLE ) === 0 ? self::BIG_CYCLE : $streak % self::BIG_CYCLE )
            : 0;

        return [
            'streak'     => $streak,
            'today_done' => $today_done,
            'cycle_pos'  => $cycle_pos,
            'big_pos'    => $big_pos,
            'to_next7'   => $streak > 0 ? ( self::CYCLE - $cycle_pos ) : self::CYCLE,
            'to_next30'  => $streak > 0 ? ( self::BIG_CYCLE - $big_pos ) : self::BIG_CYCLE,
        ];
    }
}
