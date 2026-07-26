<?php
namespace WXACG\Gamification;

defined( 'ABSPATH' ) || exit;

/**
 * Rank Season — 賽季積分累積 + 自動換季結算
 *
 * 資料表：wp_smacg_rank_season（當季）/ wp_smacg_rank_season_archive（歷季）
 *
 * v1.3.0 (2026-05-23)
 *   - Feat：add_score() 加入「大段升級」偵測，fire `smacg_rank_tier_up`
 *           action 供 wxacg-social 寫入通知。
 *   - Feat：settle_season() 結算時清空 user_meta `smacg_rank_last_notified_tier_key`
 *           讓新賽季從鐵 IV 起跳時可重新觸發通知。
 *
 * v1.2.0 (2026-05-20)
 *   - Fix #6 ：get_leaderboard() 套用 ranking 隱私過濾
 *   - Fix #14：settle_season() 包 transaction + 冪等保護
 */
class Rank_Season {

    /** 段位大階順序（用於判定是否為「大段切換」） */
    const TIER_ORDER = [
        'iron'        => 1,
        'bronze'      => 2,
        'silver'      => 3,
        'gold'        => 4,
        'platinum'    => 5,
        'emerald'     => 6,
        'diamond'     => 7,
        'master'      => 8,
        'grandmaster' => 9,
        'challenger'  => 10,
    ];

    private static $instance = null;

    public static function instance() {
        if ( self::$instance === null ) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        add_action( 'smacg_rank_season_check', [ __CLASS__, 'check_rollover' ] );

        if ( ! wp_next_scheduled( 'smacg_rank_season_check' ) ) {
            wp_schedule_event( time() + 300, 'hourly', 'smacg_rank_season_check' );
        }
    }

    /* ==========================================================
     * Table accessors
     * ========================================================== */
    public static function table_current() {
        global $wpdb;
        return $wpdb->prefix . 'smacg_rank_season';
    }
    public static function table_archive() {
        global $wpdb;
        return $wpdb->prefix . 'smacg_rank_season_archive';
    }

    /* ==========================================================
     * 隱私過濾：取得 NOT IN ($list) 字串
     * ========================================================== */
    private static function excluded_in_clause() {
        if ( class_exists( __NAMESPACE__ . '\\Ranking_System' ) ) {
            $excluded = Ranking_System::excluded_user_ids();
        } else {
            global $wpdb;
            $excluded = $wpdb->get_col( $wpdb->prepare(
                "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value = '0'",
                WXACG_RANKING_META_KEY
            ) );
        }
        if ( empty( $excluded ) ) return '0';
        return implode( ',', array_map( 'intval', $excluded ) );
    }

    /* ==========================================================
     * 寫入分數（由 Exp_Events 呼叫）
     *
     * v1.3.0：分數累積後比對「大段是否升級」
     *   - 取得當前名次（含菁英 / 宗師 修正）
     *   - 與 user_meta `smacg_rank_last_notified_tier_key` 比對
     *   - 若新階 order > 舊階 order → fire `smacg_rank_tier_up`
     * ========================================================== */
    public static function add_score( $uid, $amount ) {
        global $wpdb;
        $uid    = (int) $uid;
        $amount = (int) $amount;
        if ( $uid <= 0 || $amount <= 0 ) return false;

        $code = Rank_Tier::current_season_code();
        $tbl  = self::table_current();

        $wpdb->query( $wpdb->prepare(
            "INSERT INTO {$tbl} (user_id, season_code, season_score, updated_at)
             VALUES (%d, %s, %d, %s)
             ON DUPLICATE KEY UPDATE
                season_score = season_score + VALUES(season_score),
                updated_at   = VALUES(updated_at)",
            $uid, $code, $amount, current_time( 'mysql' )
        ) );

        // ========== v1.3.0：大段升級偵測 ==========
        self::maybe_fire_tier_up( $uid, $code );

        return true;
    }

    /**
     * 偵測使用者是否剛升上一個「大段」，若是則 fire action
     *
     * 大段定義：tier_key 改變（iron→bronze、master→grandmaster ... ）
     * 小段（IV→III→II→I）不視為大段，不通知。
     */
    private static function maybe_fire_tier_up( $uid, $season_code ) {
        global $wpdb;

        // 1. 取最新分數
        $new_score = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT season_score FROM " . self::table_current() . "
             WHERE user_id=%d AND season_code=%s",
            $uid, $season_code
        ) );
        if ( $new_score <= 0 ) return;

        // 2. 取最新名次（含隱私過濾 — 與 get_user_info() 一致）
        $excluded_in = self::excluded_in_clause();
        $rank = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*)+1 FROM " . self::table_current() . "
             WHERE season_code=%s
               AND season_score > %d
               AND user_id NOT IN ($excluded_in)",
            $season_code, $new_score
        ) );

        // 3. 計算新段位（含菁英 / 宗師 修正）
        $new_tier     = Rank_Tier::tier_from_score_and_rank( $new_score, $rank );
        $new_key      = $new_tier['key'];
        $new_order    = self::TIER_ORDER[ $new_key ] ?? 0;
        if ( $new_order <= 0 ) return;

        // 4. 與 user_meta 紀錄的「上次發過通知的大段」比對
        //    meta 預設為空 → 第一次升上「銅 IV」即視為大段升級（從 iron→bronze）
        //    用 'iron' 當預設值（最低階），避免新註冊用戶剛上鐵 IV 就被通知
        $last_key   = (string) get_user_meta( $uid, 'smacg_rank_last_notified_tier_key', true );
        if ( $last_key === '' ) $last_key = 'iron';
        $last_order = self::TIER_ORDER[ $last_key ] ?? 1;

        if ( $new_order <= $last_order ) return; // 沒升大段，跳過

        // 5. 升大段！更新 meta 並 fire action
        update_user_meta( $uid, 'smacg_rank_last_notified_tier_key', $new_key );

        $old_tier = [
            'key'   => $last_key,
            'label' => self::tier_label_from_key( $last_key ),
            'icon'  => Rank_Tier::ICONS[ $last_key ] ?? '🎖️',
        ];

        /**
         * Action: 段位「大段」升級
         *
         * @param int    $uid          使用者 ID
         * @param array  $new_tier     新段位（含 key / label / icon / color）
         * @param array  $old_tier     舊段位（含 key / label / icon）
         * @param int    $new_score    當前賽季積分
         * @param string $season_code  當前賽季代碼（如 2026-spring）
         */
        do_action( 'smacg_rank_tier_up', $uid, $new_tier, $old_tier, $new_score, $season_code );

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( sprintf(
                '[SMACG] rank tier up uid=%d %s → %s (score=%d, rank=%d, season=%s)',
                $uid, $last_key, $new_key, $new_score, $rank, $season_code
            ) );
        }
    }

    /**
     * 由 tier_key 反查段位中文標籤（用於老段位的 label，因為我們只記了 key）
     * 取該大階「最低 division」的 label，例如 bronze → '銅 IV'
     */
    private static function tier_label_from_key( $key ) {
        if ( $key === 'grandmaster' ) return '宗師';
        if ( $key === 'challenger'  ) return '菁英';
        foreach ( Rank_Tier::TIERS as $row ) {
            if ( $row[0] === $key ) return $row[3]; // 第一個 match（即 IV）
        }
        return $key;
    }

    /* ==========================================================
     * 讀取：個人賽季積分 + 名次 + 段位（當季）
     * ========================================================== */
    public static function get_user_info( $uid, $season_code = null ) {
        global $wpdb;
        $uid  = (int) $uid;
        $code = $season_code ?: Rank_Tier::current_season_code();
        $tbl  = self::table_current();

        $score = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT season_score FROM {$tbl} WHERE user_id=%d AND season_code=%s",
            $uid, $code
        ) );

        $excluded_in = self::excluded_in_clause();
        $rank = $score > 0 ? (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*)+1 FROM {$tbl}
             WHERE season_code=%s
               AND season_score > %d
               AND user_id NOT IN ($excluded_in)",
            $code, $score
        ) ) : 0;

        $tier     = Rank_Tier::tier_from_score_and_rank( $score, $rank );
        $progress = Rank_Tier::progress_to_next( $score );

        return [
            'season_code'  => $code,
            'season_label' => Rank_Tier::season_label( $code ),
            'score'        => $score,
            'rank'         => $rank,
            'tier'         => $tier,
            'progress'     => $progress,
        ];
    }

    /* ==========================================================
     * Top N 排行（當季）
     * ========================================================== */
    public static function get_leaderboard( $limit = 100, $offset = 0, $season_code = null ) {
        global $wpdb;
        $code   = $season_code ?: Rank_Tier::current_season_code();
        $limit  = max( 1, min( 500, (int) $limit ) );
        $offset = max( 0, (int) $offset );
        $tbl    = self::table_current();

        $excluded_in = self::excluded_in_clause();

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT user_id, season_score FROM {$tbl}
             WHERE season_code=%s
               AND season_score > 0
               AND user_id NOT IN ($excluded_in)
             ORDER BY season_score DESC, user_id ASC
             LIMIT %d OFFSET %d",
            $code, $limit, $offset
        ), ARRAY_A );

        $out = [];
        foreach ( $rows as $i => $r ) {
            $rank = $offset + $i + 1;
            $tier = Rank_Tier::tier_from_score_and_rank( (int) $r['season_score'], $rank );
            $out[] = [
                'rank'    => $rank,
                'user_id' => (int) $r['user_id'],
                'score'   => (int) $r['season_score'],
                'tier'    => $tier,
            ];
        }
        return $out;
    }

    /* ==========================================================
     * Archive 相關
     * ========================================================== */

    public static function get_last_settled_season_code() {
        global $wpdb;
        $arc = self::table_archive();

        if ( $wpdb->get_var( "SHOW TABLES LIKE '$arc'" ) !== $arc ) return '';

        $code = $wpdb->get_var(
            "SELECT season_code FROM {$arc}
             ORDER BY settled_at DESC, season_code DESC
             LIMIT 1"
        );
        return $code ?: '';
    }

    public static function get_archive_leaderboard( $season_code = null, $limit = 100, $offset = 0 ) {
        global $wpdb;

        $code = $season_code ?: self::get_last_settled_season_code();
        if ( ! $code ) {
            return [
                'season_code'  => '',
                'season_label' => '',
                'items'        => [],
                'total'        => 0,
            ];
        }

        $arc    = self::table_archive();
        $limit  = max( 1, min( 500, (int) $limit ) );
        $offset = max( 0, (int) $offset );

        $excluded_in = self::excluded_in_clause();

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT user_id, final_rank, final_score, tier_key, tier_label
             FROM {$arc}
             WHERE season_code = %s
               AND user_id NOT IN ($excluded_in)
             ORDER BY final_rank ASC
             LIMIT %d OFFSET %d",
            $code, $limit, $offset
        ), ARRAY_A );

        $items = [];
        foreach ( $rows as $r ) {
            $tier_icon = Rank_Tier::ICONS[ $r['tier_key'] ] ?? '🎖️';
            $items[] = [
                'rank'    => (int) $r['final_rank'],
                'user_id' => (int) $r['user_id'],
                'score'   => (int) $r['final_score'],
                'tier'    => [
                    'key'   => (string) $r['tier_key'],
                    'label' => (string) $r['tier_label'],
                    'icon'  => $tier_icon,
                    'color' => self::tier_color_from_key( $r['tier_key'] ),
                ],
            ];
        }

        return [
            'season_code'  => $code,
            'season_label' => Rank_Tier::season_label( $code ),
            'items'        => $items,
            'total'        => self::archive_total( $code ),
        ];
    }

    public static function archive_total( $season_code ) {
        global $wpdb;
        if ( ! $season_code ) return 0;
        $arc = self::table_archive();
        if ( $wpdb->get_var( "SHOW TABLES LIKE '$arc'" ) !== $arc ) return 0;

        $excluded_in = self::excluded_in_clause();
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$arc}
             WHERE season_code = %s
               AND user_id NOT IN ($excluded_in)",
            $season_code
        ) );
    }

    public static function get_archive_user_position( $uid, $season_code = null ) {
        global $wpdb;
        $uid = (int) $uid;
        if ( $uid <= 0 ) return null;

        $code = $season_code ?: self::get_last_settled_season_code();
        if ( ! $code ) return null;

        $arc = self::table_archive();
        $row = $wpdb->get_var( $wpdb->prepare(
            "SELECT final_rank FROM {$arc}
             WHERE user_id = %d AND season_code = %s",
            $uid, $code
        ) );
        return $row ? (int) $row : null;
    }

    private static function tier_color_from_key( $key ) {
        if ( $key === 'challenger' )  return '#ff6b6b';
        if ( $key === 'grandmaster' ) return '#ff9f43';

        foreach ( Rank_Tier::TIERS as $row ) {
            if ( $row[0] === $key ) return $row[4];
        }
        return '#888888';
    }

    /* ==========================================================
     * 換季檢查
     * ========================================================== */
    public static function check_rollover() {
        $current  = Rank_Tier::current_season_code();
        $recorded = get_option( 'smacg_rank_current_season', '' );

        if ( $recorded === '' ) {
            update_option( 'smacg_rank_current_season', $current );
            return;
        }
        if ( $recorded === $current ) return;

        $ok = self::settle_season( $recorded );
        if ( $ok ) {
            update_option( 'smacg_rank_current_season', $current );
            do_action( 'smacg_rank_season_rolled', $recorded, $current );

            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( sprintf( '[SMACG] Rank season rolled: %s -> %s', $recorded, $current ) );
            }
        } else {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( sprintf( '[SMACG] Rank season settle FAILED for %s, will retry', $recorded ) );
            }
        }
    }

    /* ==========================================================
     * 結算
     *
     * v1.3.0：成功結算後，清空所有用戶的 `smacg_rank_last_notified_tier_key`
     *         讓下個賽季從鐵 IV 起跳時可重新觸發大段升級通知。
     * ========================================================== */
    public static function settle_season( $season_code ) {
        global $wpdb;
        if ( ! $season_code ) return false;

        $cur = self::table_current();
        $arc = self::table_archive();

        $wpdb->query( 'START TRANSACTION' );

        try {
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT user_id, season_score FROM {$cur}
                 WHERE season_code=%s AND season_score > 0
                 ORDER BY season_score DESC, user_id ASC",
                $season_code
            ), ARRAY_A );

            $now = current_time( 'mysql' );
            foreach ( $rows as $i => $r ) {
                $rank  = $i + 1;
                $score = (int) $r['season_score'];
                $uid   = (int) $r['user_id'];
                $tier  = Rank_Tier::tier_from_score_and_rank( $score, $rank );

                $already = (int) $wpdb->get_var( $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$arc} WHERE user_id=%d AND season_code=%s",
                    $uid, $season_code
                ) );

                $wpdb->replace( $arc, [
                    'user_id'     => $uid,
                    'season_code' => $season_code,
                    'final_rank'  => $rank,
                    'final_score' => $score,
                    'tier_key'    => $tier['key'],
                    'tier_label'  => $tier['label'],
                    'settled_at'  => $now,
                ] );

                if ( $already === 0 ) {
                    do_action( 'smacg_rank_season_settle_user', $uid, $tier, $rank, $score, $season_code );
                    self::maybe_update_career_peak( $uid, $tier );
                }
            }

            $wpdb->query( $wpdb->prepare(
                "DELETE FROM {$cur} WHERE season_code=%s",
                $season_code
            ) );

            // ========== v1.3.0：清空通知記憶，讓新賽季可重新觸發大段升級 ==========
            $wpdb->query(
                "DELETE FROM {$wpdb->usermeta}
                 WHERE meta_key = 'smacg_rank_last_notified_tier_key'"
            );

            $wpdb->query( 'COMMIT' );

            do_action( 'smacg_rank_season_settled', $season_code, count( $rows ) );

            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( sprintf(
                    '[SMACG] Season %s settled: %d users archived, tier_up memory cleared',
                    $season_code, count( $rows )
                ) );
            }

            return true;

        } catch ( \Throwable $e ) {
            $wpdb->query( 'ROLLBACK' );
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( '[SMACG] settle_season exception: ' . $e->getMessage() );
            }
            return false;
        }
    }

    private static function maybe_update_career_peak( $uid, $tier ) {
        $order = self::TIER_ORDER;
        $cur_peak = get_user_meta( $uid, 'smacg_rank_career_peak', true );
        $cur_lvl  = $order[ $cur_peak['key'] ?? '' ] ?? 0;
        $new_lvl  = $order[ $tier['key'] ] ?? 0;

        if ( $new_lvl > $cur_lvl ) {
            update_user_meta( $uid, 'smacg_rank_career_peak', [
                'key'   => $tier['key'],
                'label' => $tier['label'],
                'icon'  => $tier['icon'],
            ] );
        }
    }
}
