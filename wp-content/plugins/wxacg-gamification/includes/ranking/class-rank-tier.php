<?php
namespace WXACG\Gamification;

defined( 'ABSPATH' ) || exit;

/**
 * Rank Tier — TFT 段位計算（純函式，無狀態）
 *
 * 分數 → 段位（鐵～大師）
 * 名次 → 高階段位修正（前 50 = 菁英、前 200 = 宗師）
 *
 * @since 2.7.0 (2026-05-22) 方向 A：大師門檻 7,600 → 5,000
 * @since 2.7.1 (2026-05-22) 賽季區間改為動漫慣例：
 *                          春 4/1–6/30、夏 7/1–9/30、秋 10/1–12/31、冬 1/1–3/31
 *                          冬季賽 code 年份 = 該年 1‑3 月（不再跨年）
 *                          ⚠️ BC Break：舊 winter code（Y/12/1 – (Y+1)/2/28）
 *                          與新格式語意不同。開發階段資料表為空，無遷移需求。
 */
class Rank_Tier {

    /** 段位門檻表：[tier_key, division(IV~I 或 ''), min_score, label, color] */
    const TIERS = [
        [ 'iron',     'IV',  0,    '鐵 IV',     '#6b6b6b' ],
        [ 'iron',     'III', 80,   '鐵 III',    '#6b6b6b' ],
        [ 'iron',     'II',  160,  '鐵 II',     '#6b6b6b' ],
        [ 'iron',     'I',   240,  '鐵 I',      '#6b6b6b' ],
        [ 'bronze',   'IV',  300,  '銅 IV',     '#a97142' ],
        [ 'bronze',   'III', 380,  '銅 III',    '#a97142' ],
        [ 'bronze',   'II',  460,  '銅 II',     '#a97142' ],
        [ 'bronze',   'I',   540,  '銅 I',      '#a97142' ],
        [ 'silver',   'IV',  600,  '銀 IV',     '#b8b8b8' ],
        [ 'silver',   'III', 700,  '銀 III',    '#b8b8b8' ],
        [ 'silver',   'II',  800,  '銀 II',     '#b8b8b8' ],
        [ 'silver',   'I',   900,  '銀 I',      '#b8b8b8' ],
        [ 'gold',     'IV',  1000, '金 IV',     '#f0c040' ],
        [ 'gold',     'III', 1150, '金 III',    '#f0c040' ],
        [ 'gold',     'II',  1300, '金 II',     '#f0c040' ],
        [ 'gold',     'I',   1450, '金 I',      '#f0c040' ],
        [ 'platinum', 'IV',  1600, '白金 IV',   '#4ad6c0' ],
        [ 'platinum', 'III', 1800, '白金 III',  '#4ad6c0' ],
        [ 'platinum', 'II',  2000, '白金 II',   '#4ad6c0' ],
        [ 'platinum', 'I',   2200, '白金 I',    '#4ad6c0' ],
        [ 'emerald',  'IV',  2400, '翡翠 IV',   '#28b463' ],
        [ 'emerald',  'III', 2650, '翡翠 III',  '#28b463' ],
        [ 'emerald',  'II',  2900, '翡翠 II',   '#28b463' ],
        [ 'emerald',  'I',   3150, '翡翠 I',    '#28b463' ],
        [ 'diamond',  'IV',  3400, '鑽石 IV',   '#5dade2' ],
        [ 'diamond',  'III', 3800, '鑽石 III',  '#5dade2' ],
        [ 'diamond',  'II',  4200, '鑽石 II',   '#5dade2' ],
        [ 'diamond',  'I',   4600, '鑽石 I',    '#5dade2' ],
        [ 'master',   '',    5000, '大師',      '#bb8fce' ],
    ];

    const ICONS = [
        'iron'         => '🥉',
        'bronze'       => '🟫',
        'silver'       => '⚪',
        'gold'         => '🟡',
        'platinum'     => '🟦',
        'emerald'      => '🟢',
        'diamond'      => '💎',
        'master'       => '👑',
        'grandmaster'  => '🔥',
        'challenger'   => '⚡',
    ];

    /**
     * 由分數計算基礎段位（不考慮名次）
     */
    public static function tier_from_score( $score ) {
        $score = max( 0, (int) $score );
        $found = self::TIERS[0];
        foreach ( self::TIERS as $row ) {
            if ( $score >= $row[2] ) $found = $row;
            else break;
        }
        return [
            'key'      => $found[0],
            'division' => $found[1],
            'min'      => $found[2],
            'label'    => $found[3],
            'color'    => $found[4],
            'icon'     => self::ICONS[ $found[0] ] ?? '🎖️',
        ];
    }

    /**
     * 由分數 + 名次計算最終段位（含菁英 / 宗師 修正）
     *
     * @param int $score   賽季積分
     * @param int $rank    全站名次（1 為第 1 名；0 或負值 = 未上榜）
     * @return array
     */
    public static function tier_from_score_and_rank( $score, $rank = 0 ) {
        $base = self::tier_from_score( $score );

        // 必須達到大師門檻（5000）才有資格進菁英 / 宗師
        if ( $base['key'] !== 'master' || $rank <= 0 ) return $base;

        if ( $rank <= 50 ) {
            return [
                'key'      => 'challenger',
                'division' => '',
                'min'      => 5000,
                'label'    => '菁英',
                'color'    => '#ff6b6b',
                'icon'     => self::ICONS['challenger'],
            ];
        }
        if ( $rank <= 200 ) {
            return [
                'key'      => 'grandmaster',
                'division' => '',
                'min'      => 5000,
                'label'    => '宗師',
                'color'    => '#ff9f43',
                'icon'     => self::ICONS['grandmaster'],
            ];
        }
        return $base;
    }

    /**
     * 計算距離下一階所需分數
     */
    public static function progress_to_next( $score ) {
        $score = max( 0, (int) $score );
        $cur = self::TIERS[0];
        $next = null;
        foreach ( self::TIERS as $i => $row ) {
            if ( $score >= $row[2] ) {
                $cur = $row;
                $next = self::TIERS[ $i + 1 ] ?? null;
            } else {
                break;
            }
        }
        if ( ! $next ) {
            return [
                'is_max'    => true,
                'cur_min'   => $cur[2],
                'next_min'  => $cur[2],
                'to_next'   => 0,
                'percent'   => 100,
                'next_label'=> $cur[3],
            ];
        }
        $span    = max( 1, $next[2] - $cur[2] );
        $in_tier = $score - $cur[2];
        return [
            'is_max'     => false,
            'cur_min'    => $cur[2],
            'next_min'   => $next[2],
            'to_next'    => max( 0, $next[2] - $score ),
            'percent'    => min( 100, (int) round( $in_tier * 100 / $span ) ),
            'next_label' => $next[3],
        ];
    }

    /**
     * 取得當前賽季代碼（動漫慣例四季）
     *   1-3  月 = 冬（code 年 = 當年）
     *   4-6  月 = 春
     *   7-9  月 = 夏
     *   10-12 月 = 秋
     *
     * 回傳格式：'2026-spring'
     */
    public static function current_season_code( $ts = null ) {
        $ts    = $ts ?: current_time( 'timestamp' );
        $month = (int) date( 'n', $ts );
        $year  = (int) date( 'Y', $ts );

        if ( $month >= 1  && $month <= 3 )  return $year . '-winter';
        if ( $month >= 4  && $month <= 6 )  return $year . '-spring';
        if ( $month >= 7  && $month <= 9 )  return $year . '-summer';
        return $year . '-fall'; // 10-12
    }

    /**
     * 賽季代碼 → 顯示名稱（'2026-spring' → '2026 春季賽'）
     */
    public static function season_label( $code ) {
        if ( ! preg_match( '/^(\d{4})-(spring|summer|fall|winter)$/', $code, $m ) ) return $code;
        $map = [ 'spring' => '春', 'summer' => '夏', 'fall' => '秋', 'winter' => '冬' ];
        return sprintf( '%s %s季賽', $m[1], $map[ $m[2] ] );
    }

    /**
     * 賽季代碼 → [start_ts, end_ts]
     *
     * 動漫慣例四季：
     *   spring  : Y/4/1  00:00:00 ~ Y/6/30  23:59:59
     *   summer  : Y/7/1  00:00:00 ~ Y/9/30  23:59:59
     *   fall    : Y/10/1 00:00:00 ~ Y/12/31 23:59:59
     *   winter  : Y/1/1  00:00:00 ~ Y/3/31  23:59:59
     */
    public static function season_range( $code ) {
        if ( ! preg_match( '/^(\d{4})-(spring|summer|fall|winter)$/', $code, $m ) ) return [ 0, 0 ];
        $y = (int) $m[1];
        switch ( $m[2] ) {
            case 'spring':
                return [ mktime( 0, 0, 0, 4,  1, $y ), mktime( 23, 59, 59, 6,  30, $y ) ];
            case 'summer':
                return [ mktime( 0, 0, 0, 7,  1, $y ), mktime( 23, 59, 59, 9,  30, $y ) ];
            case 'fall':
                return [ mktime( 0, 0, 0, 10, 1, $y ), mktime( 23, 59, 59, 12, 31, $y ) ];
            case 'winter':
                return [ mktime( 0, 0, 0, 1,  1, $y ), mktime( 23, 59, 59, 3,  31, $y ) ];
        }
        return [ 0, 0 ];
    }
}
