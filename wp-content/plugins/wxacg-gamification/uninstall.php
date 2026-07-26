<?php
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( ! defined( 'WXACG_GAMIFY_PURGE_ON_UNINSTALL' ) || ! WXACG_GAMIFY_PURGE_ON_UNINSTALL ) {
    return;
}

global $wpdb;

/* User meta */
$prefixes = [
    'smacg_exp_once_',
    'smacg_exp_daily_',
    'smacg_exp_comment_',
    'smacg_exp_badge_',
    'smacg_milestone_lv_',
    'smacg_notif_badge_enhanced_',
];
foreach ( $prefixes as $p ) {
    $wpdb->query( $wpdb->prepare(
        "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE %s",
        $wpdb->esc_like( $p ) . '%'
    ) );
}
$wpdb->query( "DELETE FROM {$wpdb->usermeta} WHERE meta_key = 'smacg_exp_fallback'" );

/* DB tables */
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}smacg_monthly_exp" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}smacg_rankings" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}smacg_event_progress" );

/* Post meta（活動 ended_flag / final_snapshot 等） */
$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '_smacg_event_%'" );

/* Options */
$options = [
    'smacg_gamify_version', 'smacg_gamify_activated_at',
    'smacg_ranking_db_version', 'smacg_ranking_last_rebuild',
    'smacg_event_db_version',   'smacg_event_cpt_flushed',
];
foreach ( $options as $o ) delete_option( $o );

/* Transient */
delete_transient( 'smacg_gamipress_setup_errors' );

/* Cron */
foreach ( [
    'smacg_exp_daily_reset',
    'smacg_ranking_recalc',
    'smacg_ranking_monthly_purge',
    'smacg_event_settle_sweep',
    'smacg_event_end_check',
] as $hook ) {
    wp_clear_scheduled_hook( $hook );
}
