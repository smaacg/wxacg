<?php
/**
 * 每日簽到面板（視覺化）
 *
 * 站上的簽到是「登入即自動計算」，不需要手動點擊——這點刻意不改：
 * 229 位會員已經累積了連續紀錄，改成手動點擊等於讓所有人歸零重來，
 * 而且忘記點就斷簽，反而要再做補簽機制來補救。
 *
 * 這支只負責把已經發生的事呈現出來：目前連續幾天、本輪 7 天走到哪、
 * 距離下一個里程碑還差幾天。獎勵結構完全沿用既有的 streak_7 / streak_30，
 * 不新增也不修改 EXP 規則。
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

    /**
     * 渲染簽到面板。
     *
     * @param int $uid 會員 ID。
     */
    public static function render( int $uid ): void {
        if ( $uid <= 0 ) {
            return;
        }

        $s = self::get_status( $uid );

        // 兩個里程碑各自的 EXP，直接讀規則避免與實際發放的數字說法不一
        $rules = Exp_Config::rules();
        $exp7  = (int) ( $rules['streak_7']['exp']  ?? 100 );
        $exp30 = (int) ( $rules['streak_30']['exp'] ?? 500 );
        ?>
        <div class="wxci">

            <div class="wxci-head">
                <div class="wxci-title">
                    <i class="fa-solid fa-fire" aria-hidden="true"></i> 每日簽到
                </div>
                <div class="wxci-streak">
                    已連續簽到 <b><?php echo (int) $s['streak']; ?></b> 天
                    <?php if ( $s['streak'] > self::BIG_CYCLE ) : ?>
                        <?php
                        // 超過一大輪的人要看得出自己走過幾輪，否則格子重頭
                        // 開始會讓人以為進度被歸零
                        $rounds = (int) floor( $s['streak'] / self::BIG_CYCLE );
                        ?>
                        <span class="wxci-rounds">已完成 <?php echo $rounds; ?> 輪</span>
                    <?php endif; ?>
                    <?php if ( $s['today_done'] ) : ?>
                        <span class="wxci-done">今日已簽到</span>
                    <?php endif; ?>
                </div>
            </div>

            <p class="wxci-note">
                登入就自動簽到，不用另外點擊。連續 <?php echo (int) self::CYCLE; ?> 天可得
                <b>+<?php echo $exp7; ?> EXP</b>，連續 <?php echo (int) self::BIG_CYCLE; ?> 天可得
                <b>+<?php echo $exp30; ?> EXP</b>，之後每滿一輪再發一次。
            </p>

            <?php
            /*
             * 畫 30 格而不是 7 格。
             *
             * 原本只畫一輪 7 格，但實際會員已經連續 75 天——看到「第 5 格
             * ／共 7 格」完全反映不出累積的量。改成對齊 streak_30 這個大
             * 週期，中間每 7 天標一個小獎勵，進度感才出得來。
             *
             * 不做成巴哈的 4 週 28 格：那對應的是他們的獎勵結構，而本站
             * 的循環是 7 與 30，畫 28 會讓第 30 天那個大獎沒有位置。
             */
            ?>
            <ol class="wxci-cells" aria-label="連續簽到進度（30 天為一大輪）">
                <?php for ( $i = 1; $i <= self::BIG_CYCLE; $i++ ) :
                    $filled   = ( $i <= $s['big_pos'] );
                    $is_big   = ( $i === self::BIG_CYCLE );
                    $is_small = ( ! $is_big && $i % self::CYCLE === 0 );
                    ?>
                    <li class="wxci-cell<?php
                        echo $filled ? ' is-done' : '';
                        echo $is_big ? ' is-bigbonus' : ( $is_small ? ' is-bonus' : '' );
                    ?>" title="<?php
                        if ( $is_big )        { echo esc_attr( '第 30 天：大獎勵' ); }
                        elseif ( $is_small )  { echo esc_attr( '第 ' . $i . ' 天：連續獎勵' ); }
                        else                  { echo esc_attr( '第 ' . $i . ' 天' ); }
                    ?>">
                        <span class="wxci-cell-day"><?php echo (int) $i; ?></span>
                        <span class="wxci-cell-icon">
                            <?php if ( $is_big ) : ?>
                                💎
                            <?php elseif ( $is_small ) : ?>
                                🎁
                            <?php elseif ( $filled ) : ?>
                                <i class="fa-solid fa-check" aria-hidden="true"></i>
                            <?php else : ?>
                                ·
                            <?php endif; ?>
                        </span>
                    </li>
                <?php endfor; ?>
            </ol>

            <p class="wxci-next">
                <?php if ( $s['streak'] === 0 ) : ?>
                    今天登入就會開始累積連續天數。
                <?php elseif ( $s['to_next7'] === 0 ) : ?>
                    本輪已完成，明天開始新的一輪。
                <?php else : ?>
                    再 <b><?php echo (int) $s['to_next7']; ?></b> 天可得 +<?php echo $exp7; ?> EXP<?php
                    if ( $s['to_next30'] > 0 && $s['to_next30'] <= 10 ) {
                        printf( '，再 %d 天可得 +%d EXP', (int) $s['to_next30'], $exp30 );
                    }
                    ?>。
                <?php endif; ?>
            </p>

        </div>
        <?php
    }
}
