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
}
