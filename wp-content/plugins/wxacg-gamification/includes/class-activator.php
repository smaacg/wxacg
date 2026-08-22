<?php
namespace WXACG\Gamification;

defined( 'ABSPATH' ) || exit;

class Activator {

    public static function run() {
        self::install_ranking_tables();
        self::install_event_tables();
        self::install_rank_season_tables();
        self::schedule_crons();

        update_option( 'smacg_gamify_version',      WXACG_GAMIFY_VERSION );
        update_option( 'smacg_gamify_activated_at', current_time( 'mysql' ) );

        // 觸發 init 99 階段的 flush（CPT 此時尚未註冊，不能在此直接 flush）
        update_option( 'smacg_event_cpt_flushed', '0' );

        // 紀錄當前賽季
        if ( ! get_option( 'smacg_rank_current_season' ) ) {
            update_option( 'smacg_rank_current_season', Rank_Tier::current_season_code() );
        }
    }

    public static function install_ranking_tables() {
        global $wpdb;
        $charset      = $wpdb->get_charset_collate();
        $tbl_monthly  = $wpdb->prefix . 'smacg_monthly_exp';
        $tbl_rankings = $wpdb->prefix . 'smacg_rankings';

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $sql1 = "CREATE TABLE {$tbl_monthly} (
            user_id BIGINT(20) UNSIGNED NOT NULL,
            ym CHAR(6) NOT NULL,
            exp_amount BIGINT(20) NOT NULL DEFAULT 0,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (user_id, ym),
            KEY ym_exp (ym, exp_amount)
        ) {$charset};";

        $sql2 = "CREATE TABLE {$tbl_rankings} (
            rank_type VARCHAR(32) NOT NULL,
            rank_pos INT UNSIGNED NOT NULL,
            user_id BIGINT(20) UNSIGNED NOT NULL,
            score BIGINT(20) NOT NULL DEFAULT 0,
            extra LONGTEXT NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (rank_type, rank_pos),
            KEY type_user (rank_type, user_id)
        ) {$charset};";

        dbDelta( $sql1 );
        dbDelta( $sql2 );

        update_option( 'smacg_ranking_db_version', WXACG_RANKING_DB_VERSION );
    }

    public static function install_event_tables() {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        $tbl     = $wpdb->prefix . 'smacg_event_progress';

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $sql = "CREATE TABLE {$tbl} (
            event_id BIGINT(20) UNSIGNED NOT NULL,
            user_id BIGINT(20) UNSIGNED NOT NULL,
            progress BIGINT(20) NOT NULL DEFAULT 0,
            reached_at DATETIME NULL DEFAULT NULL,
            awarded_at DATETIME NULL DEFAULT NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (event_id, user_id),
            KEY event_progress (event_id, progress),
            KEY event_reached (event_id, reached_at)
        ) {$charset};";

        dbDelta( $sql );
        update_option( 'smacg_event_db_version', WXACG_EVENT_DB_VERSION );
    }

    public static function install_rank_season_tables() {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        $cur     = $wpdb->prefix . 'smacg_rank_season';
        $arc     = $wpdb->prefix . 'smacg_rank_season_archive';

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $sql1 = "CREATE TABLE {$cur} (
            user_id BIGINT(20) UNSIGNED NOT NULL,
            season_code VARCHAR(20) NOT NULL,
            season_score BIGINT(20) NOT NULL DEFAULT 0,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (user_id, season_code),
            KEY season_score (season_code, season_score)
        ) {$charset};";

        $sql2 = "CREATE TABLE {$arc} (
            user_id BIGINT(20) UNSIGNED NOT NULL,
            season_code VARCHAR(20) NOT NULL,
            final_rank INT UNSIGNED NOT NULL DEFAULT 0,
            final_score BIGINT(20) NOT NULL DEFAULT 0,
            tier_key VARCHAR(20) NOT NULL DEFAULT '',
            tier_label VARCHAR(40) NOT NULL DEFAULT '',
            settled_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (user_id, season_code),
            KEY season_rank (season_code, final_rank)
        ) {$charset};";

        dbDelta( $sql1 );
        dbDelta( $sql2 );

        update_option( 'smacg_rank_season_db_version', WXACG_RANK_SEASON_DB_VERSION );
    }

    public static function schedule_crons() {
        if ( ! wp_next_scheduled( 'smacg_exp_daily_reset' ) ) {
            $ts = strtotime( 'tomorrow 00:05:00', current_time( 'timestamp' ) );
            wp_schedule_event( $ts, 'daily', 'smacg_exp_daily_reset' );
        }
        if ( ! wp_next_scheduled( 'smacg_ranking_recalc' ) ) {
            wp_schedule_event( time() + 60, 'hourly', 'smacg_ranking_recalc' );
        }
        if ( ! wp_next_scheduled( 'smacg_ranking_monthly_purge' ) ) {
            wp_schedule_event( strtotime( 'tomorrow 04:00' ), 'daily', 'smacg_ranking_monthly_purge' );
        }
        if ( ! wp_next_scheduled( 'smacg_event_settle_sweep' ) ) {
            wp_schedule_event( time() + 120, 'wxacg_10min', 'smacg_event_settle_sweep' );
        }
        if ( ! wp_next_scheduled( 'smacg_event_end_check' ) ) {
            wp_schedule_event( time() + 60, 'hourly', 'smacg_event_end_check' );
        }
        if ( ! wp_next_scheduled( 'smacg_rank_season_check' ) ) {
            wp_schedule_event( time() + 300, 'hourly', 'smacg_rank_season_check' );
        }
    }

    /**
     * 建立累積型里程碑徽章貼文（achievements CPT）。
     *
     * 既有的 13 個「初次」徽章當初是用一次性 wp eval 腳本建的（見
     * class-first-badge.php 註解），沒有落地成程式碼。這裡改為由
     * maybe_upgrade_db() 依版本自動執行，不需要人工跑腳本，
     * 站台還原或換主機時也會自己補齊。
     *
     * 以 slug 判斷是否已存在，重複執行不會產生重複貼文。
     * 已存在的貼文完全不動——管理員可能已經上傳徽章圖或改過文案。
     */
    public static function install_milestone_badges() {
        if ( ! post_type_exists( WXACG_BADGE_SLUG ) ) {
            // CPT 由 GamiPress 註冊；尚未就緒時不建立，留待下次載入再試
            return;
        }

        require_once WXACG_GAMIFY_DIR . 'includes/milestone/class-milestone-badge.php';

        $created = 0;
        foreach ( Milestone_Badge::get_types() as $type => $conf ) {
            foreach ( Milestone_Badge::tiers_for( $type ) as $i => $target ) {
                $slug = Milestone_Badge::badge_slug( $type, $target );

                if ( get_page_by_path( $slug, OBJECT, WXACG_BADGE_SLUG ) ) {
                    continue;
                }

                $post_id = wp_insert_post( [
                    'post_type'    => WXACG_BADGE_SLUG,
                    'post_status'  => 'publish',
                    'post_name'    => $slug,
                    'post_title'   => sprintf( $conf['label_tpl'], $target ),
                    'post_content' => sprintf( $conf['desc_tpl'], $target ),
                    /*
                     * menu_order 讓成就頁的排序穩定：同類別相鄰、階層由低到高。
                     * 前面留 100 給既有的「初次」徽章，累積型排在其後。
                     */
                    'menu_order'   => 100 + ( array_search( $type, array_keys( Milestone_Badge::get_types() ), true ) * 10 ) + $i,
                ], true );

                if ( is_wp_error( $post_id ) || ! $post_id ) {
                    continue;
                }

                /*
                 * 供成就頁顯示進度條用（member-render.php 會讀這兩個 meta
                 * 去算「目前 4 / 目標 10」），也讓徽章與程式規則的對應
                 * 不必靠 slug 字串解析。
                 */
                update_post_meta( $post_id, '_wxacg_milestone_type',   $type );
                update_post_meta( $post_id, '_wxacg_milestone_target', $target );

                /*
                 * 刻意不設 _smacg_badge_exp。
                 *
                 * Exp_Events::on_badge_unlock() 讀到該 meta 時會用它
                 * exp_override 掉 badge_unlock 的預設 20，而不是「額外加碼」。
                 * 若在這裡填入與里程碑規則相同的 TIER_EXP，同一個成就會被
                 * 給兩次同樣的金額（實測「看完 3 部」拿到 30+30=60，
                 * 而非預期的 30+20=50）。
                 *
                 * 留空 → 徽章解鎖走預設 20，實際總額為：
                 *   3 階 30+20=50、10 階 80+20=100、
                 *   30 階 200+20=220、100 階 500+20=520
                 */

                $created++;
            }
        }

        update_option( 'smacg_milestone_badge_version', WXACG_MILESTONE_BADGE_VERSION );

        return $created;
    }
}
