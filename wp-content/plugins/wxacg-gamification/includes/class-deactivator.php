<?php
namespace WXACG\Gamification;

defined( 'ABSPATH' ) || exit;

/**
 * v1.1.0 (2026-05-20)
 *   - Fix #4：補上 'smacg_rank_season_check' cron 清除（先前漏列導致停用後遺留排程）
 */
class Deactivator {
    public static function run() {
        $hooks = [
            'smacg_exp_daily_reset',
            'smacg_ranking_recalc',
            'smacg_ranking_monthly_purge',
            'smacg_event_settle_sweep',
            'smacg_event_end_check',
            'smacg_rank_season_check', // ★ Fix #4
        ];
        foreach ( $hooks as $h ) {
            $ts = wp_next_scheduled( $h );
            if ( $ts ) wp_unschedule_event( $ts, $h );
            wp_clear_scheduled_hook( $h );
        }
        flush_rewrite_rules( false );
    }
}
