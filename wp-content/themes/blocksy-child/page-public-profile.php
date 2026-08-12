<?php
/**
 * Public Profile Template - /u/{username}/
 *
 * 由 wxacg-social 外掛的 public-profile.php template_redirect 載入。
 *
 * 全域變數：$GLOBALS['smacg_pp_user_obj']  WP_User 物件（由 dispatch 設定）
 *
 * 流程：取得 user → 隱私檢查 → 準備資料 → render
 *
 * @package weixiaoacg
 *
 * Changelog:
 * - 2026-05-16 Bug #10 修正：
 *   smacg_pp_render_hero() args key 與 render 端對齊
 *     avatar         → avatar_url
 *     display        → display_name
 *     plan           → plan_label
 *     email_display  → email
 *   （can_view_email / stats / is_logged_in 為 render 端不讀取的多餘 key，已移除）
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// 必要 helper（檔案已搬至外掛，於 plugins_loaded 自動載入；此處僅做存在性檢查）
if ( ! function_exists( 'smacg_build_watchlist' )
     || ! function_exists( 'smacg_calc_member_stats' )
     || ! function_exists( 'smacg_pp_render_hero' ) ) {
    status_header( 503 );
    get_header();
    echo '<div class="pp-error" style="padding:60px 20px;text-align:center;">';
    echo '<h1>服務暫時無法使用</h1>';
    echo '<p>會員系統尚未完整載入，請聯絡管理員。</p>';
    echo '</div>';
    get_footer();
    return;
}

// ---------- 取得使用者 ----------
$user = wxacg_get_public_profile_user();
if ( ! $user ) {
    status_header( 404 );
    get_header();
    echo '<div class="pp-error"><h1>404 - 找不到使用者</h1></div>';
    get_footer();
    return;
}

$uid          = (int) $user->ID;
$current_uid  = get_current_user_id();
$is_owner     = ( $current_uid === $uid );
$is_logged_in = is_user_logged_in();

// ---------- 隱私 ----------
$privacy = function_exists( 'smacg_get_user_privacy' ) ? smacg_get_user_privacy( $uid ) : [];

$can_view_profile   = smacg_can_view_profile_section( $uid, 'profile' );
$can_view_watchlist = smacg_can_view_profile_section( $uid, 'watchlist' );
$can_view_ratings   = smacg_can_view_profile_section( $uid, 'ratings' );
$can_view_activity  = smacg_can_view_profile_section( $uid, 'activity' );
$can_view_email     = smacg_can_view_profile_section( $uid, 'email' );

// ---------- 基本資料 ----------
$display  = $user->display_name ?: $user->user_login;
$bio      = get_user_meta( $uid, 'description', true );
$reg_date = mysql2date( 'Y-m-d', $user->user_registered );

// 等級資訊（GamiPress）
$lvl_info = function_exists( 'wxacg_get_user_level_info' )
    ? wxacg_get_user_level_info( $uid )
    : [ 'level' => 1, 'exp' => 0, 'title' => '見習', 'icon' => '🌱', 'percent' => 0, 'is_max' => false ];
$points   = (int) ( $lvl_info['exp'] ?? 0 );

$plan = function_exists( 'smacg_get_plan_label' ) ? smacg_get_plan_label( $user ) : '';

// 頭像
$avatar_url = '';
$aid = (int) get_user_meta( $uid, 'smacg_avatar_id', true );
if ( $aid && wp_attachment_is_image( $aid ) ) {
    $img = wp_get_attachment_image_src( $aid, 'medium' );
    if ( $img ) {
        $avatar_url = $img[0] . '?v=' . get_post_modified_time( 'U', false, $aid );
    }
}
if ( ! $avatar_url ) {
    $avatar_url = get_avatar_url( $uid, [ 'size' => 200 ] );
}

// Email（依隱私）
$email_display = $can_view_email
    ? $user->user_email
    : ( function_exists( 'smacg_mask_email' ) ? smacg_mask_email( $user->user_email ) : '' );

// ---------- 統計資料（無論是否能看清單都先算，避免 cache 失效） ----------
$watchlist = $can_view_watchlist ? smacg_build_watchlist( $uid ) : [];
$ratings   = $can_view_ratings   ? smacg_get_user_ratings( $uid ) : [];
$stats     = smacg_calc_member_stats( $watchlist, $ratings, $uid );

// 最近活動
$activity = ( $can_view_activity && function_exists( 'smacg_get_recent_activity' ) )
    ? smacg_get_recent_activity( $uid, 20 )
    : [];

// 預設 tab
$active_tab = get_query_var( 'smacg_pp_tab' ) ?: 'overview';
$valid_tabs = [ 'overview', 'watchlist', 'ratings', 'badges', 'activity' ];
if ( ! in_array( $active_tab, $valid_tabs, true ) ) {
    $active_tab = 'overview';
}

// ── [v1.1] 會員公開頁一律 noindex，避免空白新會員頁面被搜尋引擎建立索引 ──
add_filter( 'rank_math/frontend/robots', function ( $robots ) {
    $robots['index']  = 'noindex';
    $robots['follow'] = 'follow';
    unset( $robots['noarchive'], $robots['nosnippet'] );
    return $robots;
} );

get_header(); ?>

<div class="pp-wrap" data-uid="<?php echo $uid; ?>" data-owner="<?php echo $is_owner ? '1' : '0'; ?>">

    <?php if ( $is_owner ) : ?>
        <div class="pp-preview-banner">
            <i class="fa-solid fa-eye"></i>
            <span>這是你的公開個人頁預覽，其他人會看到下方內容</span>
            <a href="<?php echo esc_url( home_url( '/mc/#settings' ) ); ?>" class="pp-preview-btn">
                <i class="fa-solid fa-gear"></i> 隱私設定
            </a>
            <a href="<?php echo esc_url( home_url( '/mc/' ) ); ?>" class="pp-preview-btn pp-preview-btn--ghost">
                <i class="fa-solid fa-arrow-right"></i> 回會員中心
            </a>
        </div>
    <?php endif; ?>

    <?php if ( ! $can_view_profile && ! $is_owner ) : ?>
        <section class="pp-private">
            <div class="pp-private-icon"><i class="fa-solid fa-lock"></i></div>
            <h1>此使用者已將個人頁設為私人</h1>
            <p>只有 <b><?php echo esc_html( $display ); ?></b> 本人能查看。</p>
            <a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="pp-btn">回首頁</a>
        </section>

    <?php else : ?>

        <?php
        /**
         * Bug #10 修正：args key 對齊 smacg_pp_render_hero() 期待的鍵名
         *   avatar_url / display_name / plan_label / email
         */
        smacg_pp_render_hero( $user, [
            'avatar_url'   => $avatar_url,
            'display_name' => $display,
            'bio'          => $bio,
            'plan_label'   => $plan,
            'lvl_info'     => $lvl_info,
            'points'       => $points,
            'reg_date'     => $reg_date,
            'email'        => $email_display,
            'is_owner'     => $is_owner,
        ] ); ?>

        <nav class="pp-tabs" role="tablist">
            <?php
            $tabs = [
                'overview'  => [ '📊', '總覽', true ],
                'watchlist' => [ '🎬', '清單', $can_view_watchlist ],
                'ratings'   => [ '⭐', '評分', $can_view_ratings ],
                'badges'    => [ '🏆', '徽章', $can_view_profile ],
                'activity'  => [ '📡', '動態', $can_view_activity ],
            ];
            foreach ( $tabs as $key => $info ) {
                [ $icon, $label, $visible ] = $info;
                if ( ! $visible ) continue;
                $active = ( $key === $active_tab ) ? ' active' : '';
                printf(
                    '<button class="pp-tab%s" data-tab="%s" role="tab">%s %s</button>',
                    esc_attr( $active ),
                    esc_attr( $key ),
                    esc_html( $icon ),
                    esc_html( $label )
                );
            }
            ?>
        </nav>

        <div class="pp-panels">
            <section class="pp-panel<?php echo $active_tab === 'overview' ? ' active' : ''; ?>" data-panel="overview">
                <?php smacg_pp_render_overview( $user, $watchlist, $stats, $can_view_watchlist, $can_view_ratings ); ?>
            </section>

            <?php if ( $can_view_watchlist ) : ?>
            <section class="pp-panel<?php echo $active_tab === 'watchlist' ? ' active' : ''; ?>" data-panel="watchlist">
                <?php smacg_pp_render_watchlist( $watchlist ); ?>
            </section>
            <?php endif; ?>

            <?php if ( $can_view_ratings ) : ?>
            <section class="pp-panel<?php echo $active_tab === 'ratings' ? ' active' : ''; ?>" data-panel="ratings">
                <?php smacg_pp_render_ratings( $ratings ); ?>
            </section>
            <?php endif; ?>

            <?php if ( $can_view_profile ) : ?>
            <section class="pp-panel<?php echo $active_tab === 'badges' ? ' active' : ''; ?>" data-panel="badges">
                <?php smacg_pp_render_badges( $uid, $stats ); ?>
            </section>
            <?php endif; ?>

            <?php if ( $can_view_activity ) : ?>
            <section class="pp-panel<?php echo $active_tab === 'activity' ? ' active' : ''; ?>" data-panel="activity">
                <?php smacg_pp_render_activity( $activity ); ?>
            </section>
            <?php endif; ?>
        </div>

    <?php endif; ?>

</div>

<?php get_footer(); ?>
