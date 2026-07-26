<?php
namespace WXACG\Gamification;

defined( 'ABSPATH' ) || exit;

/**
 * Level System — 等級計算 + 6 階會員稱號
 *
 * 公式（v2.7.0 起）：level = floor( sqrt( exp / 50 ) )，上限 Lv.200
 * 反推：exp  = level² × 50
 *
 *   Lv.1   → 50 EXP
 *   Lv.10  → 5,000 EXP（一轉解鎖）
 *   Lv.30  → 45,000 EXP（二轉）
 *   Lv.70  → 245,000 EXP（三轉）
 *   Lv.120 → 720,000 EXP（四轉）
 *   Lv.200 → 2,000,000 EXP（究極）
 *
 * 6 階會員稱號（Level 區間不變，僅 min_exp 隨公式更新）：
 *   Lv.1-9    🌱 新進會員
 *   Lv.10-29  🌿 新客
 *   Lv.30-69  📺 常客
 *   Lv.70-119 🎬 熟客
 *   Lv.120-199 👑 VIP
 *   Lv.200    💎 黑卡會員
 *
 * @since 2.0.0 (2026-05-15) sqrt 公式 + 6 階會員稱號
 * @since 2.7.0 (2026-05-22) DIVISOR 由 5 改為 50，註冊 100 EXP 不再直接跳 Lv.4
 * @since 2.7.1 (2026-05-22)
 *   - Fix BugE：grant_exp() 簽名擴充為 ($uid, $amount, $reason, $args)
 *     並把 $args 透傳給 Gamipress_Bridge::award_exp()
 *     避免 legacy 函式 smacg_grant_exp() 的第 4 參數被吞掉
 */
class Level_System {

    /** 等級公式除數（v2.7.0 由 5 改為 50） */
    const DIVISOR = 50;

    private static $instance = null;

    public static function instance() {
        if ( self::$instance === null ) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {}

    /* ==========================================================
     * 1. EXP <-> Level
     * ========================================================== */

    /**
     * 由 EXP 算 Level
     */
    public static function calc_level_from_exp( $exp ) {
        $exp = max( 0, (int) $exp );
        if ( $exp < self::DIVISOR ) return 1;
        $lv = (int) floor( sqrt( $exp / self::DIVISOR ) );
        return min( WXACG_MAX_LEVEL, max( 1, $lv ) );
    }

    /**
     * 由 Level 反推所需累計 EXP
     */
    public static function level_to_exp( $level ) {
        $level = max( 1, (int) $level );
        return $level * $level * self::DIVISOR;
    }

    /**
     * Lv.1~200 累計 EXP 表
     */
    public static function get_level_table() {
        static $table = null;
        if ( $table !== null ) return $table;

        $table = [];
        for ( $lv = 1; $lv <= WXACG_MAX_LEVEL; $lv++ ) {
            $table[ $lv ] = self::level_to_exp( $lv );
        }
        return $table;
    }

    /* ==========================================================
     * 2. 6 階會員稱號
     * ========================================================== */

    /**
     * 取得指定等級的稱號資料
     *
     * @return array { tier:int(1-6), key:string, title:string, icon:string, color:string }
     */
    public static function get_tier( $level ) {
        $level = (int) $level;

        if ( $level >= 200 ) return [ 'tier' => 6, 'key' => 'black',   'title' => '黑卡會員', 'icon' => '💎', 'color' => '#1a1a1a' ];
        if ( $level >= 120 ) return [ 'tier' => 5, 'key' => 'vip',     'title' => 'VIP',      'icon' => '👑', 'color' => '#b8860b' ];
        if ( $level >= 70  ) return [ 'tier' => 4, 'key' => 'expert',  'title' => '熟客',     'icon' => '🎬', 'color' => '#6a4c93' ];
        if ( $level >= 30  ) return [ 'tier' => 3, 'key' => 'regular', 'title' => '常客',     'icon' => '📺', 'color' => '#3a86ff' ];
        if ( $level >= 10  ) return [ 'tier' => 2, 'key' => 'newcomer','title' => '新客',     'icon' => '🌿', 'color' => '#06a77d' ];
        return                       [ 'tier' => 1, 'key' => 'rookie',  'title' => '新進會員', 'icon' => '🌱', 'color' => '#8d99ae' ];
    }

    /** 6 階完整表（給 /level-guide/ 用） */
    public static function get_all_tiers() {
        return [
            [ 'tier' => 1, 'key' => 'rookie',   'title' => '新進會員', 'icon' => '🌱', 'color' => '#8d99ae', 'min_level' => 1,   'min_exp' => 50       ],
            [ 'tier' => 2, 'key' => 'newcomer', 'title' => '新客',     'icon' => '🌿', 'color' => '#06a77d', 'min_level' => 10,  'min_exp' => 5000     ],
            [ 'tier' => 3, 'key' => 'regular',  'title' => '常客',     'icon' => '📺', 'color' => '#3a86ff', 'min_level' => 30,  'min_exp' => 45000    ],
            [ 'tier' => 4, 'key' => 'expert',   'title' => '熟客',     'icon' => '🎬', 'color' => '#6a4c93', 'min_level' => 70,  'min_exp' => 245000   ],
            [ 'tier' => 5, 'key' => 'vip',      'title' => 'VIP',      'icon' => '👑', 'color' => '#b8860b', 'min_level' => 120, 'min_exp' => 720000   ],
            [ 'tier' => 6, 'key' => 'black',    'title' => '黑卡會員', 'icon' => '💎', 'color' => '#1a1a1a', 'min_level' => 200, 'min_exp' => 2000000  ],
        ];
    }

    /* ==========================================================
     * 3. 對外 API：取得用戶完整等級資訊
     * ========================================================== */

    public static function get_user_level( $uid ) {
        $uid = (int) $uid;
        if ( $uid <= 0 ) return self::empty_struct();

        $exp   = (int) Gamipress_Bridge::get_user_exp( $uid );
        $level = self::calc_level_from_exp( $exp );
        $tier  = self::get_tier( $level );

        $cur_floor  = self::level_to_exp( $level );
        $next_floor = self::level_to_exp( min( $level + 1, WXACG_MAX_LEVEL ) );
        $is_max     = ( $level >= WXACG_MAX_LEVEL );

        $in_lv_exp = max( 0, $exp - $cur_floor );
        $lv_total  = max( 1, $next_floor - $cur_floor );
        $percent   = $is_max ? 100 : min( 100, (int) floor( $in_lv_exp / $lv_total * 100 ) );

        return [
            'user_id'         => $uid,
            'exp'             => $exp,
            'level'           => $level,
            'tier'            => (int) $tier['tier'],
            'tier_key'        => $tier['key'],
            'title'           => $tier['title'],
            'icon'            => $tier['icon'],
            'color'           => $tier['color'],
            'cur_floor'       => $cur_floor,
            'next_floor'      => $next_floor,
            'in_level_exp'    => $in_lv_exp,
            'level_total_exp' => $lv_total,
            'percent'         => $percent,
            'to_next'         => $is_max ? 0 : max( 0, $next_floor - $exp ),
            'is_max'          => $is_max,
            'badge_count'     => (int) Gamipress_Bridge::get_user_badge_count( $uid ),
        ];
    }

    private static function empty_struct() {
        return [
            'user_id'         => 0,
            'exp'             => 0,
            'level'           => 1,
            'tier'            => 1,
            'tier_key'        => 'rookie',
            'title'           => '新進會員',
            'icon'            => '🌱',
            'color'           => '#8d99ae',
            'cur_floor'       => 50,
            'next_floor'      => 200,
            'in_level_exp'    => 0,
            'level_total_exp' => 150,
            'percent'         => 0,
            'to_next'         => 50,
            'is_max'          => false,
            'badge_count'     => 0,
        ];
    }

    /* ==========================================================
     * 4. 主動發放 EXP（API 風格，回傳詳細結果）
     * ========================================================== */

    /**
     * ★ v2.7.1 BugE：簽名擴充為 ($uid, $amount, $reason, $args)
     *
     * legacy 函式 smacg_grant_exp() 接受 4 個參數，但原本 grant_exp() 只簽 3 個，
     * 導致 $args（含 admin_id / achievement_id 等）被丟棄。
     * 現補上 $args 並透傳給 Gamipress_Bridge::award_exp()。
     *
     * @param int    $uid
     * @param int    $amount
     * @param string $reason
     * @param array  $args   透傳給 award_exp()，可含 admin_id / achievement_id / 任意 hook context
     * @return array         success / awarded / exp_before / exp_after / lv_before / lv_after / leveled_up / milestones
     */
    public static function grant_exp( $uid, $amount, $reason = '', $args = [] ) {
        $uid    = (int) $uid;
        $amount = (int) $amount;

        $result = [
            'success'    => false,
            'awarded'    => 0,
            'exp_before' => 0,
            'exp_after'  => 0,
            'lv_before'  => 0,
            'lv_after'   => 0,
            'leveled_up' => false,
            'milestones' => [],
        ];

        if ( $uid <= 0 || $amount <= 0 ) {
            $result['reason'] = 'invalid_args';
            return $result;
        }

        $before_exp = Gamipress_Bridge::get_user_exp( $uid );
        $before_lv  = self::calc_level_from_exp( $before_exp );

        // ★ v2.7.1：透傳 $args 給 bridge
        $ok = Gamipress_Bridge::award_exp(
            $uid,
            $amount,
            $reason ?: 'smacg_grant_exp',
            is_array( $args ) ? $args : []
        );
        if ( ! $ok ) {
            $result['reason'] = 'gamipress_failed';
            return $result;
        }

        $after_exp = Gamipress_Bridge::get_user_exp( $uid );
        $after_lv  = self::calc_level_from_exp( $after_exp );

        $result['success']    = true;
        $result['awarded']    = $amount;
        $result['exp_before'] = $before_exp;
        $result['exp_after']  = $after_exp;
        $result['lv_before']  = $before_lv;
        $result['lv_after']   = $after_lv;
        $result['leveled_up'] = ( $after_lv > $before_lv );

        if ( $result['leveled_up'] ) {
            /* 偵測里程碑跨越 */
            $milestones = Career_Jobs::milestones();
            foreach ( $milestones as $stage => $m ) {
                if ( $before_lv < $m['level'] && $after_lv >= $m['level'] ) {
                    $result['milestones'][] = [
                        'stage' => $stage,
                        'level' => $m['level'],
                        'label' => $m['label'],
                        'icon'  => $m['icon'],
                    ];
                }
            }

            /* 觸發升等 hook（Exp_Events 會接） */
            Exp_Events::handle_level_up( $uid, $before_lv, $after_lv );
            do_action( 'smacg_user_leveled_up', $uid, $before_lv, $after_lv, $result );
        }

        return $result;
    }
}
