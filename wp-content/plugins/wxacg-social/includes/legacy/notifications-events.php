<?php
/**
 * Notifications System — 事件監聽（Event Producers）
 *
 * @package weixiaoacg
 * @version 1.2.1 (2026-05-26)
 *
 * v1.2.1 變更：
 *   - 【Bug C 修正】rating 通知查錯表：
 *       舊：{$wpdb->prefix}smacg_watchlist  (不存在的表)
 *       新：{$wpdb->prefix}anime_user_status (由 Anime_Sync_User_Status_Manager 管理)
 *     舊條件「status='favorited' OR favorited=1」亦錯（status 為 TINYINT 0~3）。
 *     新條件改為 `favorited = 1`，與 anime-sync-pro/class-installer.php
 *     的 schema 一致。
 *     副作用：此修正前，整個「動畫被評分 → 通知收藏者」功能完全沒有運作
 *     （SHOW TABLES 為 null 直接 return）。
 *
 * v1.2.0 變更：
 *   - 新增 #6：smacg_rank_tier_up → 'rank_up' 通知（段位大段升級）
 *
 * v1.1.0 變更：
 *   - Bug #6 修正：smacg_broadcast_system_notification 改用批次 INSERT
 *
 * 監聽各種站內事件，自動產生通知：
 *   - smacg_user_followed         → 'follow' 通知
 *   - wp_insert_comment           → 'comment_reply' 通知
 *   - smacg_anime_rated           → 'rating' 通知
 *   - smacg_level_up              → 'level_up' 通知
 *   - smacg_rank_tier_up          → 'rank_up' 通知 ★ v1.2.0
 *   - gamipress_award_achievement → 'badge' 通知（由 gamification 外掛掛）
 */
defined( 'ABSPATH' ) || exit;

/* ============================================================
   #1 追蹤事件 → follow 通知
   ============================================================ */
add_action( 'smacg_user_followed', function( $follower_id, $following_id ) {

    $follower = get_userdata( $follower_id );
    if ( ! $follower ) return;

    $follower_name = $follower->display_name ?: $follower->user_login;
    $profile_url   = function_exists( 'wxacg_get_public_profile_url' )
        ? wxacg_get_public_profile_url( $follower_id )
        : home_url( '/' );

    wxacg_create_notification( [
        'user_id'     => $following_id,
        'type'        => 'follow',
        'actor_id'    => $follower_id,
        'object_type' => 'user',
        'object_id'   => $follower_id,
        'data'        => [
            'title'   => sprintf( '%s 開始追蹤你', $follower_name ),
            'excerpt' => '',
            'url'     => $profile_url,
            'icon'    => 'fa-user-plus',
        ],
    ] );
}, 10, 2 );

/* ============================================================
   #2 留言被回覆 → comment_reply 通知
   ============================================================ */
add_action( 'wp_insert_comment', function( $comment_id, $comment ) {

    if ( (int) $comment->comment_approved !== 1 ) return;

    $parent_id = (int) $comment->comment_parent;
    if ( ! $parent_id ) return;

    $parent = get_comment( $parent_id );
    if ( ! $parent ) return;

    $parent_uid = (int) $parent->user_id;
    if ( ! $parent_uid ) return;

    $author_uid = (int) $comment->user_id;
    if ( ! $author_uid || $author_uid === $parent_uid ) return;

    $author = get_userdata( $author_uid );
    if ( ! $author ) return;
    $author_name = $author->display_name ?: $author->user_login;

    $post = get_post( $comment->comment_post_ID );
    if ( ! $post ) return;

    $excerpt = wp_strip_all_tags( $comment->comment_content );
    if ( mb_strlen( $excerpt ) > 80 ) {
        $excerpt = mb_substr( $excerpt, 0, 80 ) . '…';
    }

    wxacg_create_notification( [
        'user_id'     => $parent_uid,
        'type'        => 'comment_reply',
        'actor_id'    => $author_uid,
        'object_type' => 'comment',
        'object_id'   => (int) $comment_id,
        'data'        => [
            'title'      => sprintf( '%s 回覆了你的留言', $author_name ),
            'excerpt'    => $excerpt,
            'url'        => get_comment_link( $comment_id ),
            'icon'       => 'fa-reply',
            'post_title' => get_the_title( $post ),
        ],
    ] );
}, 10, 2 );

/* ============================================================
   #3 動畫被評分 → rating 通知（給該動畫的收藏者）
   ------------------------------------------------------------
   v1.2.1 Bug C 修正：
     - 表名改為 anime_user_status（由 Anime_Sync_User_Status_Manager 管理）
     - 條件改為 favorited = 1（schema: TINYINT(1) NOT NULL DEFAULT 0）
   ============================================================ */
add_action( 'smacg_rating_added', function( $rater_id, $anime_id, $overall_score = null ) {
    global $wpdb;

    $anime_id = absint( $anime_id );
    $rater_id = absint( $rater_id );
    if ( ! $anime_id || ! $rater_id ) return;

    $rater = get_userdata( $rater_id );
    if ( ! $rater ) return;
    $rater_name = $rater->display_name ?: $rater->user_login;

    $status_table = $wpdb->prefix . 'anime_user_status';

    // 防呆：表不存在直接返回（升級中或 anime-sync-pro 未啟用時）
    if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $status_table ) ) !== $status_table ) {
        return;
    }

    // 取收藏此動畫的使用者（favorited = 1）
    $collector_ids = $wpdb->get_col( $wpdb->prepare(
        "SELECT DISTINCT user_id FROM {$status_table}
         WHERE anime_id = %d AND favorited = 1
         LIMIT 200",
        $anime_id
    ) );

    if ( empty( $collector_ids ) ) return;

    $title     = get_the_title( $anime_id );
    $url       = get_permalink( $anime_id );
    $score_str = is_numeric( $overall_score ) ? sprintf( ' %.1f 分', (float) $overall_score ) : '';

    foreach ( $collector_ids as $uid ) {
        $uid = (int) $uid;
        if ( $uid === $rater_id ) continue;

        wxacg_create_notification( [
            'user_id'     => $uid,
            'type'        => 'rating',
            'actor_id'    => $rater_id,
            'object_type' => 'anime',
            'object_id'   => $anime_id,
            'data'        => [
                'title'   => sprintf( '%s 評分了你收藏的《%s》%s', $rater_name, $title, $score_str ),
                'excerpt' => '',
                'url'     => $url,
                'icon'    => 'fa-star',
            ],
        ] );
    }
}, 10, 3 );

/* ============================================================
   #4 等級提升 → level_up 通知
   ============================================================ */
add_action( 'smacg_level_up', function( $user_id, $new_level, $level_title = '' ) {
    $user_id   = absint( $user_id );
    $new_level = absint( $new_level );
    if ( ! $user_id || ! $new_level ) return;

    wxacg_create_notification( [
        'user_id'     => $user_id,
        'type'        => 'level_up',
        'actor_id'    => null,
        'object_type' => 'level',
        'object_id'   => $new_level,
        'data'        => [
            'title'   => sprintf( '恭喜升上 Lv.%d %s', $new_level, $level_title ),
            'excerpt' => '繼續觀看、評分、留言可以累積更多點數！',
            'url'     => home_url( '/mc/?tab=points' ),
            'icon'    => 'fa-arrow-up',
        ],
    ] );
}, 10, 3 );

/* ============================================================
   #5 系統公告 helper
   ============================================================ */
function smacg_broadcast_system_notification( $title, $excerpt = '', $url = '', $user_ids = null, $opts = [] ) {
    global $wpdb;

    $title = wp_strip_all_tags( $title );
    if ( ! $title ) return 0;

    $opts = wp_parse_args( $opts, [
        'force'      => false,
        'batch_size' => 500,
        'sleep_ms'   => 50,
        'icon'       => 'fa-bullhorn',
    ] );

    if ( $user_ids === null ) {
        $user_ids = $wpdb->get_col( "SELECT ID FROM {$wpdb->users}" );
    }
    $user_ids = array_filter( array_map( 'intval', (array) $user_ids ) );
    if ( empty( $user_ids ) ) return 0;

    $data_json = wp_json_encode( [
        'title'   => $title,
        'excerpt' => wp_strip_all_tags( $excerpt ),
        'url'     => esc_url_raw( $url ) ?: home_url( '/' ),
        'icon'    => sanitize_key( $opts['icon'] ),
    ] );
    $now = current_time( 'mysql' );

    if ( ! $opts['force'] ) {
        $user_ids = array_values( array_filter( $user_ids, function( $uid ) {
            return smacg_should_notify( $uid, 'system', 'site' );
        } ) );
        if ( empty( $user_ids ) ) return 0;
    }

    $table = wxacg_notifications_table();
    $sent  = 0;

    $batches = array_chunk( $user_ids, max( 1, (int) $opts['batch_size'] ) );

    foreach ( $batches as $batch ) {
        $values = [];
        $params = [];
        foreach ( $batch as $uid ) {
            $values[] = "(%d, %s, NULL, NULL, NULL, %s, 0, %s)";
            $params[] = $uid;
            $params[] = 'system';
            $params[] = $data_json;
            $params[] = $now;
        }

        $sql = "INSERT INTO {$table}
                (user_id, type, actor_id, object_type, object_id, data, is_read, created_at)
                VALUES " . implode( ', ', $values );

        $result = $wpdb->query( $wpdb->prepare( $sql, $params ) );
        if ( false !== $result ) {
            $sent += (int) $result;
        }

        foreach ( $batch as $uid ) {
            smacg_clear_notification_cache( $uid );
        }

        do_action( 'smacg_system_notification_broadcast_batch', $batch, $title, $data_json );

        if ( $opts['sleep_ms'] > 0 && count( $batches ) > 1 ) {
            usleep( (int) $opts['sleep_ms'] * 1000 );
        }
    }

    if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
        error_log( sprintf(
            '[SMACG Broadcast] sent %d / %d users in %d batches',
            $sent, count( $user_ids ), count( $batches )
        ) );
    }

    return $sent;
}

/* ============================================================
   #6 段位升級 → rank_up 通知  ★ v1.2.0
   ============================================================ */
add_action( 'smacg_rank_tier_up', function( $uid, $new_tier, $old_tier, $new_score, $season_code ) {
    $uid = absint( $uid );
    if ( ! $uid || ! is_array( $new_tier ) || empty( $new_tier['label'] ) ) return;

    $new_label = (string) $new_tier['label'];
    $new_icon  = (string) ( $new_tier['icon']  ?? '🎖️' );
    $old_label = is_array( $old_tier ) ? (string) ( $old_tier['label'] ?? '' ) : '';

    $season_label = $season_code;
    if ( class_exists( '\\WXACG\\Gamification\\Rank_Tier' ) ) {
        $season_label = \WXACG\Gamification\Rank_Tier::season_label( $season_code );
    }

    if ( $old_label ) {
        $title = sprintf( '%s 段位提升：%s → %s', $new_icon, $old_label, $new_label );
    } else {
        $title = sprintf( '%s 段位提升至 %s', $new_icon, $new_label );
    }

    wxacg_create_notification( [
        'user_id'     => $uid,
        'type'        => 'rank_up',
        'actor_id'    => null,
        'object_type' => 'rank_tier',
        'object_id'   => null,
        'data'        => [
            'title'   => $title,
            'excerpt' => sprintf(
                '%s · 當前賽季分 %d 分。繼續累積分數挑戰更高段位！',
                $season_label, (int) $new_score
            ),
            'url'     => home_url( '/ranking-users/?tab=rank_season' ),
            'icon'    => 'fa-trophy',
        ],
    ] );
}, 10, 5 );
