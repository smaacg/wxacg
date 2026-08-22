<?php
namespace WXACG\Gamification;

defined( 'ABSPATH' ) || exit;

/**
 * EXP 規則表
 *
 * 規則欄位：
 *   exp           - 給予的累計 EXP（A）
 *   season_score  - 同時寫入的賽季積分（C），v2.7.0 起與 exp 解耦
 *                   未設則 fallback 成 exp 值（保留舊行為）
 *   cap_type      - 'once' | 'daily' | null
 *   cap_key       - user_meta key 字首
 *   daily_max     - daily cap 一天上限次數，未設預設 1
 *
 * 透過 filter 'smacg_exp_rules' 可被擴充。
 *
 * v2.8.0 (2026-06-07)
 *   - 5倍調整：daily_login 10→50、comment_post 5→25、rating_add exp 3→15
 *     season 5→25、streak_7 100→500、streak_30 500→2500
 *   - 新增 wpForo 來源：forum_topic（發主題）、forum_reply（回覆）
 *
 * v2.7.2 (2026-05-23)
 *   - streak_7 / streak_30 改為循環式（cap_type=null）
 *     去重交由 on_login() 的 dedupe_key（含 gen + cycle）
 *   - streak_7 賽季積分 0 → 100；streak_30 賽季積分 0 → 500
 *     C 方案：里程碑 EXP 與賽季積分同步給高額，鼓勵長期連續登入
 *
 * v2.7.0 (2026-05-22)
 *   - 全規則新增 season_score 欄位（與 EXP 解耦）
 *   - 註冊 / 連續登入 / 個人資料 / 生日 / 週年 → season_score = 0
 *     避免一次性大額獎勵直接刷掉賽季排行
 *   - 新增管道：read_post / share_post / favorite_add
 *               profile_avatar / profile_bio / profile_birthday / profile_complete
 *               birthday / anniversary
 *   - daily_max：read_post=5、share_post=3
 */
class Exp_Config {

    private static $rules = null;

    public static function rules() {
        if ( self::$rules !== null ) return self::$rules;

        self::$rules = [

            /* ───── 帳號類 ───── */
            'register'        => [ 'exp' => 100, 'season_score' => 0,   'cap_type' => 'once', 'cap_key' => 'register' ],

            // v2.8.0（5倍）：每滿 7 天 → +500 EXP / +500 賽季分；每滿 30 天 → +2500 EXP / +2500 賽季分
            // 去重交由 on_login() dedupe_key（gen + cycle）
            'streak_7'        => [ 'exp' => 500,  'season_score' => 500,  'cap_type' => null,   'cap_key' => null ],
            'streak_30'       => [ 'exp' => 2500, 'season_score' => 2500, 'cap_type' => null,   'cap_key' => null ],

            'birthday'        => [ 'exp' => 50,  'season_score' => 0,   'cap_type' => null,    'cap_key' => null ],
            'anniversary'     => [ 'exp' => 100, 'season_score' => 0,   'cap_type' => null,    'cap_key' => null ],

            /* ───── 個人資料完善（一次性，不計賽季積分） ───── */
            'profile_avatar'   => [ 'exp' => 10, 'season_score' => 0,   'cap_type' => 'once',  'cap_key' => 'profile_avatar' ],
            'profile_bio'      => [ 'exp' => 10, 'season_score' => 0,   'cap_type' => 'once',  'cap_key' => 'profile_bio' ],
            'profile_birthday' => [ 'exp' => 10, 'season_score' => 0,   'cap_type' => 'once',  'cap_key' => 'profile_birthday' ],
            'profile_complete' => [ 'exp' => 30, 'season_score' => 0,   'cap_type' => 'once',  'cap_key' => 'profile_complete' ],

            /* ───── 每日登入（5倍：10→50） ───── */
            'daily_login'     => [ 'exp' => 50,  'season_score' => 50,  'cap_type' => 'daily', 'cap_key' => 'login' ],

            /* ───── 內容互動 ───── */
            'read_post'       => [ 'exp' => 3,   'season_score' => 5,   'cap_type' => 'daily', 'cap_key' => 'read_post',  'daily_max' => 5 ],
            'share_post'      => [ 'exp' => 5,   'season_score' => 5,   'cap_type' => 'daily', 'cap_key' => 'share_post', 'daily_max' => 3 ],
            // 5倍：5 → 25
            'comment_post'    => [ 'exp' => 25,  'season_score' => 25,  'cap_type' => 'daily', 'cap_key' => 'comment' ],
            /*
             * 長評（自建評論系統的 long 軌，後端門檻 80 字起）。
             * 短評一句話吐槽即可送出，長評要寫滿 80 字，投入差距明顯，
             * 因此獨立給分。刻意不調降 comment_post——只加不減，避免既有
             * 使用者感覺獎勵被縮水。
             *
             * 與 forum_topic 同為 100：長評要看完作品才寫得出來，投入不比
             * 開一篇論壇主題少，且是站上的原創內容。
             * cap_key 獨立，短評與長評各有一次每日額度（兩種都寫 = 125 EXP）。
             */
            'review_long'     => [ 'exp' => 100, 'season_score' => 100, 'cap_type' => 'daily', 'cap_key' => 'review_long' ],

            /* ───── 社交 ───── */
            'follow_action'   => [ 'exp' => 2,   'season_score' => 2,   'cap_type' => 'daily', 'cap_key' => 'follow' ],
            'followed_by'     => [ 'exp' => 5,   'season_score' => 3,   'cap_type' => 'daily', 'cap_key' => 'followed' ],

            /* ───── anime-sync-pro 觀看記錄 ───── */
            'watchlist_add'      => [ 'exp' => 1,  'season_score' => 2,   'cap_type' => 'daily', 'cap_key' => 'wl_add' ],
            'watchlist_complete' => [ 'exp' => 8,  'season_score' => 10,  'cap_type' => null,    'cap_key' => null ],
            // 5倍：exp 3→15、season 5→25
            'rating_add'         => [ 'exp' => 15, 'season_score' => 25,  'cap_type' => 'daily', 'cap_key' => 'rating' ],
            'favorite_add'       => [ 'exp' => 2,  'season_score' => 2,   'cap_type' => 'daily', 'cap_key' => 'favorite', 'daily_max' => 5 ],

            /* ───── wpForo 論壇（v2.8.0 新增） ───── */
            // 發新主題：每日最多 3 次給分；回覆：每日最多 5 次給分
            'forum_topic'     => [ 'exp' => 100, 'season_score' => 100, 'cap_type' => 'daily', 'cap_key' => 'forum_topic', 'daily_max' => 3 ],
            'forum_reply'     => [ 'exp' => 25,  'season_score' => 25,  'cap_type' => 'daily', 'cap_key' => 'forum_reply', 'daily_max' => 5 ],

            /* ───── 徽章解鎖（預設值；個別徽章可用 _smacg_badge_exp 覆蓋） ───── */
            'badge_unlock'    => [ 'exp' => 20,  'season_score' => 10,  'cap_type' => null,    'cap_key' => null ],
        ];

        self::$rules = apply_filters( 'smacg_exp_rules', self::$rules );
        return self::$rules;
    }

    public static function get( $action_key ) {
        $rules = self::rules();
        return $rules[ $action_key ] ?? null;
    }

    /**
     * 取得單一規則的賽季積分（fallback：未設則用 exp 值，相容舊規則）
     */
    public static function get_season_score( $action_key, $exp_fallback = 0 ) {
        $rule = self::get( $action_key );
        if ( ! $rule ) return 0;
        return isset( $rule['season_score'] ) ? (int) $rule['season_score'] : (int) $exp_fallback;
    }
}
