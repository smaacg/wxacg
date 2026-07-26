<?php
namespace WXACG\Gamification;

defined( 'ABSPATH' ) || exit;

/**
 * Daily Login Fallback
 *
 * 問題背景：
 *   原本 daily_login EXP 只在 wp_login hook 觸發，但 WordPress
 *   logged-in cookie 預設 14 天（記住我），大多數使用者不會每天
 *   重新登入 → 連續好幾天都拿不到每日登入獎勵 + streak 永遠累積不到 7/30 天。
 *
 * 解法：
 *   在 init hook 偵測「已登入 + 今天還沒領 daily_login」時，補一次發放。
 *   完全沿用 Exp_Events::award_with_cap() 的 cap 機制（daily cap = 1 次/日），
 *   並把 streak 計算 + 循環獎勵的完整邏輯複製過來。
 *
 * 觸發頻率控制：
 *   - 用 transient 限制每個 user 一天只執行一次完整檢查
 *   - 只在前台 page view 觸發（非 admin、非 AJAX、非 cron、非 REST）
 *
 * 與 Exp_Events::on_login() 的關係：
 *   - 兩者都呼叫 award_with_cap('daily_login')，daily cap 負責去重
 *   - streak 計算邏輯完全對齊 on_login() v2.7.2（含 gen + cycle dedupe）
 *   - 兩條路徑同一天只會有一個真正執行（先到先得）：
 *       a) 使用者有重新登入 → wp_login → on_login() 先執行
 *       b) 使用者 cookie 持續有效 → init → maybe_award() 執行
 *     另一條會因為 last_login_date 已是今天而直接 return
 *
 * @since 1.0.0 (2026-05-23)
 * @version 1.1.0 (2026-05-23)
 *   - streak 計算改為循環式（對齊 Exp_Events v2.7.2 C 方案）
 *   - 引入 smacg_streak_gen（斷簽代次），dedupe_key 含 gen + cycle
 *   - 每滿 7 天 → streak_7（+100 EXP / +100 賽季分）
 *   - 每滿 30 天 → streak_30（+500 EXP / +500 賽季分）
 *   - 斷簽歸 1 後 gen +1，新代次的 dedupe_key 不同 → 可重新領取
 */
class Daily_Login_Fallback {

    private static $instance = null;

    public static function instance() {
        if ( self::$instance === null ) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        add_action( 'init', [ __CLASS__, 'maybe_award' ], 30 );
    }

    public static function maybe_award() {
        // 只在前台 page view 觸發
        if ( is_admin() )                                   return;
        if ( wp_doing_ajax() )                              return;
        if ( wp_doing_cron() )                              return;
        if ( defined( 'REST_REQUEST' ) && REST_REQUEST )    return;
        if ( ! is_user_logged_in() )                        return;

        $uid = get_current_user_id();
        if ( $uid <= 0 ) return;

        // 每使用者每天只執行一次重邏輯
        $today = current_time( 'Ymd' );
        $tkey  = 'smacg_dlf_checked_' . $uid . '_' . $today;
        if ( get_transient( $tkey ) ) return;
        set_transient( $tkey, 1, DAY_IN_SECONDS );

        // 1) daily_login（award_with_cap 內建 daily cap，今天領過就 return false）
        Exp_Events::award_with_cap( $uid, 'daily_login' );

        // 2) streak 計算（與 Exp_Events::on_login v2.7.2 邏輯一致）
        self::update_streak( $uid );
    }

    /**
     * Streak 計算 + 循環里程碑獎勵
     *
     * 從 Exp_Events::on_login() 完整複製過來，確保兩條路徑行為一致。
     * 唯一差別：on_login() 因為已經先 daily_login 過，這邊只負責 streak。
     *
     * @param int $uid
     */
    private static function update_streak( $uid ) {
        $today  = current_time( 'Ymd' );
        $last   = (string) get_user_meta( $uid, 'smacg_last_login_date', true );

        // 若今天已經更新過 streak，跳過
        if ( $last === $today ) return;

        $streak    = (int) get_user_meta( $uid, 'smacg_login_streak', true );
        $yesterday = date( 'Ymd', strtotime( '-1 day', current_time( 'timestamp' ) ) );

        if ( $last === $yesterday ) {
            // 昨天有登 → 累積
            $streak += 1;
        } else {
            // 斷簽（或首次登入）
            $streak = 1;
            if ( $last !== '' ) {
                // 真正的斷簽（非首次）→ 代次 +1
                $gen = (int) get_user_meta( $uid, 'smacg_streak_gen', true );
                update_user_meta( $uid, 'smacg_streak_gen', $gen + 1 );

                if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                    error_log( sprintf(
                        '[SMACG] DLF: streak broken for uid=%d (last=%s, today=%s) → gen=%d',
                        $uid, $last, $today, $gen + 1
                    ) );
                }
            }
        }

        update_user_meta( $uid, 'smacg_last_login_date', $today );
        update_user_meta( $uid, 'smacg_login_streak',    $streak );

        $gen = (int) get_user_meta( $uid, 'smacg_streak_gen', true );

        // 每滿 7 天 → streak_7
        if ( $streak > 0 && $streak % 7 === 0 ) {
            $cycle = (int) ( $streak / 7 );
            Exp_Events::award_with_cap( $uid, 'streak_7', [
                'dedupe_key' => sprintf( 'smacg_exp_streak7_g%d_c%d', $gen, $cycle ),
                'reason'     => sprintf( '連續登入 %d 天', $streak ),
            ] );
            do_action( 'smacg_streak_milestone', $uid, 7, $cycle, $gen );
        }

        // 每滿 30 天 → streak_30
        if ( $streak > 0 && $streak % 30 === 0 ) {
            $cycle = (int) ( $streak / 30 );
            Exp_Events::award_with_cap( $uid, 'streak_30', [
                'dedupe_key' => sprintf( 'smacg_exp_streak30_g%d_c%d', $gen, $cycle ),
                'reason'     => sprintf( '連續登入 %d 天', $streak ),
            ] );
            do_action( 'smacg_streak_milestone', $uid, 30, $cycle, $gen );
        }

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( sprintf(
                '[SMACG] DLF: streak updated for uid=%d, streak=%d, gen=%d',
                $uid, $streak, $gen
            ) );
        }
    }
}
