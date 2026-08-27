<?php
/**
 * 每日簽到月曆（逐日紀錄）
 *
 * 為什麼需要新的儲存：
 * 站上原本沒有逐日簽到歷史。看起來很像歷史的 smacg_exp_daily_login_YYYYMMDD
 * 其實是「每日上限」的鎖，class-exp-events.php 的 daily_reset() 每天會把
 * 2～32 天前的全部刪掉，只留得住昨天和今天，撐不起月曆。
 *
 * 儲存格式：每人每月一筆 user_meta
 *   key   wxacg_checkin_YYYYMM
 *   value 31 字元字串，第 N 字元對應該月第 N 天，'1' 有簽到、'0' 沒有
 * 231 位會員一年約 2,772 筆，比每天一筆（84,000 筆）省兩個數量級。
 *
 * 歷史回填：
 * 逐日紀錄雖然被刪光了，但 smacg_login_streak 本身就是歷史——連續 75 天
 * 代表最後登入日往前推 75 天每天都有簽到。首次讀取時據此回填一次，使用者
 * 不會看到一片空白的月曆。回填只寫「推導得出來」的那段，不猜測更早的日子。
 *
 * 這支不發任何 EXP、不碰 smacg_login_streak，純粹記錄與顯示。
 *
 * @package WXACG_Gamification
 */

namespace WXACG\Gamification;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Checkin_Calendar {

    /** 月份紀錄的 meta key 前綴 */
    const META_PREFIX = 'wxacg_checkin_';

    /** 回填完成旗標，避免每次讀取都重算 */
    const BACKFILL_META = 'wxacg_checkin_backfilled';

    /**
     * 回填上限（天）。
     *
     * streak 理論上可以無限長，沒有上限的話一個老帳號會一次寫進幾十筆
     * 月份 meta。目前站上最長 75 天，400 天足夠涵蓋且不至於失控。
     */
    const BACKFILL_MAX_DAYS = 400;

    /**
     * 掛載。
     */
    public static function init(): void {
        add_action( 'wxacg_daily_checkin', [ __CLASS__, 'mark_today' ], 10, 1 );
    }

    /**
     * 簽到完成 → 標記今天。
     *
     * @param int $uid 會員 ID。
     */
    public static function mark_today( $uid ): void {
        $uid = (int) $uid;
        if ( $uid <= 0 ) {
            return;
        }
        self::mark_day( $uid, current_time( 'Ymd' ) );
    }

    /**
     * 標記某一天為已簽到。
     *
     * @param int    $uid 會員 ID。
     * @param string $ymd 日期（Ymd，例如 20260827）。
     */
    public static function mark_day( int $uid, string $ymd ): void {
        if ( ! preg_match( '/^\d{8}$/', $ymd ) ) {
            return;
        }

        $ym  = substr( $ymd, 0, 6 );
        $day = (int) substr( $ymd, 6, 2 );

        if ( $day < 1 || $day > 31 ) {
            return;
        }

        $key = self::META_PREFIX . $ym;
        $map = (string) get_user_meta( $uid, $key, true );

        // 舊值可能不存在或長度不足，一律補滿 31 位再改
        $map = str_pad( $map, 31, '0' );
        if ( strlen( $map ) > 31 ) {
            $map = substr( $map, 0, 31 );
        }

        if ( $map[ $day - 1 ] === '1' ) {
            return; // 已標記，不必再寫
        }

        $map[ $day - 1 ] = '1';
        update_user_meta( $uid, $key, $map );
    }

    /**
     * 取得某月的簽到情形。
     *
     * @param int    $uid 會員 ID。
     * @param string $ym  月份（Ym，例如 202608）。
     * @return array 1-based：[ 1 => true/false, 2 => ..., ... ]
     */
    public static function get_month( int $uid, string $ym ): array {
        self::maybe_backfill( $uid );

        $map  = str_pad( (string) get_user_meta( $uid, self::META_PREFIX . $ym, true ), 31, '0' );
        $days = (int) gmdate( 't', strtotime( $ym . '01' ) );

        $out = [];
        for ( $d = 1; $d <= $days; $d++ ) {
            $out[ $d ] = ( $map[ $d - 1 ] === '1' );
        }
        return $out;
    }

    /**
     * 依 streak 回填歷史，每位會員只做一次。
     *
     * 推導依據：smacg_login_streak = N 代表 smacg_last_login_date 往前數
     * N 天（含當天）每天都有簽到。這是 streak 的定義，不是猜測。
     *
     * @param int $uid 會員 ID。
     */
    private static function maybe_backfill( int $uid ): void {
        if ( get_user_meta( $uid, self::BACKFILL_META, true ) ) {
            return;
        }

        // 先立旗標：即使下面沒東西可補，也不需要每次讀取都重跑
        update_user_meta( $uid, self::BACKFILL_META, current_time( 'mysql' ) );

        $streak = (int) get_user_meta( $uid, 'smacg_login_streak', true );
        $last   = (string) get_user_meta( $uid, 'smacg_last_login_date', true );

        if ( $streak <= 0 || ! preg_match( '/^\d{8}$/', $last ) ) {
            return;
        }

        $streak = min( $streak, self::BACKFILL_MAX_DAYS );
        $ts     = strtotime( $last );
        if ( ! $ts ) {
            return;
        }

        for ( $i = 0; $i < $streak; $i++ ) {
            self::mark_day( $uid, gmdate( 'Ymd', strtotime( "-{$i} days", $ts ) ) );
        }
    }

    /**
     * 渲染本月月曆。
     *
     * @param int    $uid 會員 ID。
     * @param string $ym  月份（Ym），預設本月。
     */
    public static function render( int $uid, string $ym = '' ): void {
        if ( $uid <= 0 ) {
            return;
        }

        if ( $ym === '' || ! preg_match( '/^\d{6}$/', $ym ) ) {
            $ym = current_time( 'Ym' );
        }

        $days     = self::get_month( $uid, $ym );
        $total    = count( $days );
        $done     = count( array_filter( $days ) );
        $today    = current_time( 'Ymd' );
        $today_d  = ( substr( $today, 0, 6 ) === $ym ) ? (int) substr( $today, 6, 2 ) : 0;

        // 該月 1 號是星期幾（0=日），決定前面要空幾格
        $first_dow = (int) gmdate( 'w', strtotime( $ym . '01' ) );

        $month_label = sprintf( '%d 年 %d 月', (int) substr( $ym, 0, 4 ), (int) substr( $ym, 4, 2 ) );
        ?>
        <div class="wxcc">
            <div class="wxcc-head">
                <div class="wxcc-title">
                    <i class="fa-solid fa-calendar-check" aria-hidden="true"></i>
                    <?php echo esc_html( $month_label ); ?>簽到
                </div>
                <div class="wxcc-count">
                    本月 <b><?php echo (int) $done; ?></b> / <?php echo (int) $total; ?> 天
                </div>
            </div>

            <ol class="wxcc-grid" aria-label="<?php echo esc_attr( $month_label ); ?>簽到紀錄">
                <?php for ( $i = 0; $i < 7; $i++ ) : ?>
                    <li class="wxcc-dow" aria-hidden="true"><?php echo esc_html( [ '日', '一', '二', '三', '四', '五', '六' ][ $i ] ); ?></li>
                <?php endfor; ?>

                <?php for ( $i = 0; $i < $first_dow; $i++ ) : ?>
                    <li class="wxcc-pad" aria-hidden="true"></li>
                <?php endfor; ?>

                <?php foreach ( $days as $d => $ok ) :
                    $cls = 'wxcc-day';
                    if ( $ok )               { $cls .= ' is-done'; }
                    if ( $d === $today_d )   { $cls .= ' is-today'; }
                    /* 未來的日子淡化，避免看起來像「漏簽」 */
                    if ( $today_d > 0 && $d > $today_d ) { $cls .= ' is-future'; }
                    ?>
                    <li class="<?php echo esc_attr( $cls ); ?>">
                        <span class="wxcc-num"><?php echo (int) $d; ?></span>
                    </li>
                <?php endforeach; ?>
            </ol>

            <?php
            /*
             * 回填說明只在「本月有紀錄但更早的月份完全沒有」時出現，
             * 讓使用者知道為什麼看不到更久以前的紀錄，而不是以為資料掉了。
             */
            $prev_ym  = gmdate( 'Ym', strtotime( $ym . '01 -1 month' ) );
            $has_prev = (string) get_user_meta( $uid, self::META_PREFIX . $prev_ym, true ) !== '';
            ?>
            <?php if ( ! $has_prev ) : ?>
                <p class="wxcc-note">
                    逐日紀錄自本次功能上線後開始保存，更早的部分依連續天數回推，可能不完整。
                </p>
            <?php endif; ?>
        </div>
        <?php
    }
}
