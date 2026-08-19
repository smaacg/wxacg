<?php
/**
 * Template Name: 會員中心
 *
 * @version 1.7.0 (2026-05-28)
 * @changelog
 *   v1.7.0 (2026-05-28)
 *     - hero meta 區改為徽章列：[📧 email] [✅ 已驗證] [🗓️ 加入 N 天]
 *     - 已驗證徽章改用漸層綠 + 微光（A2 樣式）
 *     - 「加入日期」改為相對時間「加入 N 天 / N 年 N 天」
 *     - 段位進度條「距下一階」獨立顯示於進度條下方，不再擠在右側
 *     - 5 格統計改為小卡片，加入背景色塊與懸停效果
 *     - 新增 $days_joined 變數
 *   v1.6.0 (2026-05-28)
 *     - 新增 hero email 區的「Email 驗證徽章」（已驗證 / 未驗證）
 *     - 未驗證時顯示「重寄驗證信」按鈕（呼叫 smacg_resend_verify_email AJAX）
 *     - 僅對使用者本人顯示，他人查看時不顯示
 *   v1.5.0 (2026-05-23)
 *     - 新增 hero「🔥 連續登入」顯示行（meta 區下方）
 *       顯示目前 streak 天數、是否斷簽、距離下個 7/30 里程碑還差幾天
 *       對齊 Exp_Events v2.7.2 + Daily_Login_Fallback v1.1.0 的循環設計
 *     - 新增對應 CSS（.mc-streak-row）
 *   v1.4.0 (2026-05-23)
 *     - hero 進度條文字精簡，移除「8 / 150」分數顯示
 *     - 賽季進度條補上「每季重置」title 提示
 *   v1.3.0 (2026-05-22)
 *     - 進度條改用 in_level_exp / level_total_exp
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! is_user_logged_in() ) {
    wp_safe_redirect( wp_login_url( get_permalink() ) );
    exit;
}

$wxacg_members_ready = defined( 'WXACG_MEMBERS_VERSION' )
    && function_exists( 'smacg_build_watchlist' )
    && function_exists( 'smacg_calc_member_stats' )
    && function_exists( 'smacg_render_dashboard' );

if ( ! $wxacg_members_ready ) {
    get_header(); ?>
    <div class="mc-wrap">
        <section class="mc-hero" style="padding:40px;text-align:center;">
            <h1>⚠️ 會員中心暫時無法使用</h1>
            <p style="margin-top:16px;color:#666;">
                <strong>SMACG Members</strong> 外掛尚未啟用或載入失敗，會員中心相關功能已停用。
            </p>
            <p style="color:#666;">請聯絡網站管理員至外掛頁啟用 <code>wxacg-members</code>。</p>
            <?php if ( current_user_can( 'activate_plugins' ) ) : ?>
                <p style="margin-top:24px;">
                    <a class="mc-btn mc-btn-primary" href="<?php echo esc_url( admin_url( 'plugins.php' ) ); ?>">前往外掛頁</a>
                </p>
            <?php endif; ?>
        </section>
    </div>
    <?php
    get_footer();
    return;
}

$user     = wp_get_current_user();
$uid      = $user->ID;
$display  = $user->display_name ?: $user->user_login;
$email    = $user->user_email;
$reg_date = mysql2date( 'Y-m-d', $user->user_registered );
$bio      = get_user_meta( $uid, 'description', true );

/* 頭像 */
$avatar_url = '';
$custom_aid = (int) get_user_meta( $uid, 'smacg_avatar_id', true );
if ( $custom_aid && wp_attachment_is_image( $custom_aid ) ) {
    $img = wp_get_attachment_image_src( $custom_aid, 'medium' );
    if ( $img ) $avatar_url = $img[0] . '?v=' . get_post_modified_time( 'U', false, $custom_aid );
}
if ( ! $avatar_url && function_exists( 'um_get_user_avatar_url' ) ) {
    $avatar_url = um_get_user_avatar_url( $uid, 'original' );
}
if ( ! $avatar_url ) $avatar_url = get_avatar_url( $uid, [ 'size' => 200 ] );
$account_url = get_permalink();

/* EXP / 等級 */
$lvl_info = function_exists( 'wxacg_get_user_level_info' )
    ? wxacg_get_user_level_info( $uid )
    : [
        'exp' => 0, 'level' => 1, 'title' => '新進會員', 'icon' => '🌱',
        'percent' => 0, 'in_level_exp' => 0, 'level_total_exp' => 1,
        'to_next' => 5, 'is_max' => false, 'next_floor' => 5,
    ];

/* TFT 段位賽季 */
if ( function_exists( 'smacg_get_user_rank_season_info' ) ) {
    $rank_info = smacg_get_user_rank_season_info( $uid );
} else {
    $rank_info = [
        'season_code' => '', 'season_label' => '', 'score' => 0, 'rank' => 0,
        'tier' => [ 'key'=>'iron','division'=>'IV','min'=>0,'label'=>'鐵 IV','color'=>'#6b6b6b','icon'=>'🥉' ],
        'progress' => [ 'is_max'=>false,'cur_min'=>0,'next_min'=>100,'to_next'=>100,'percent'=>0,'next_label'=>'鐵 III' ],
    ];
}
$career_peak   = function_exists( 'smacg_get_user_career_peak_tier' ) ? smacg_get_user_career_peak_tier( $uid ) : null;
$rank_page_url = home_url( '/ranking-users/?tab=rank_season' );
$guide_url     = home_url( '/level-guide/' );

$job_title  = function_exists( 'smacg_get_user_job_title' ) ? smacg_get_user_job_title( $uid ) : [];
$plan_label = function_exists( 'smacg_get_plan_label' )     ? smacg_get_plan_label( $user )   : '一般會員';

$privacy = function_exists( 'smacg_get_user_privacy' )
    ? smacg_get_user_privacy( $uid )
    : [ 'show_email' => 0, 'show_continue_watching' => 1 ];

$is_owner = ( get_current_user_id() === $uid );
if ( $is_owner || ! empty( $privacy['show_email'] ) ) {
    $display_email = $user->user_email;
} else {
    $display_email = function_exists( 'smacg_mask_email' ) ? smacg_mask_email( $user->user_email ) : '***@***';
}

$watchlist  = function_exists( 'smacg_build_watchlist' )     ? smacg_build_watchlist( $uid )    : [];
$ratings    = function_exists( 'smacg_get_user_ratings' )    ? smacg_get_user_ratings( $uid )   : [];
$points_log = function_exists( 'smacg_get_exp_log' )         ? smacg_get_exp_log( $uid, 50 )    : [];
$recent_cmt = function_exists( 'smacg_get_recent_comments' ) ? smacg_get_recent_comments( $uid, 5 ) : [];

$stats = function_exists( 'smacg_calc_member_stats' )
    ? smacg_calc_member_stats( $watchlist, $ratings )
    : [
        'counts' => [ 'watching'=>0,'completed'=>0,'favorited'=>0 ],
        'rating' => [ 'count' => 0 ], 'watch_time' => [ 'days' => 0 ],
    ];

$public_profile_url = function_exists( 'wxacg_get_public_profile_url' ) ? wxacg_get_public_profile_url( $uid ) : '';
$mc_followers   = function_exists( 'smacg_get_followers_count' )  ? smacg_get_followers_count( $uid )  : 0;
$mc_following   = function_exists( 'smacg_get_following_count' )  ? smacg_get_following_count( $uid )  : 0;
$mc_badge_count = function_exists( 'smacg_get_user_badge_count' ) ? smacg_get_user_badge_count( $uid ) : 0;

/* v1.5.0：streak 顯示資料 */
$streak_raw    = (int) get_user_meta( $uid, 'smacg_login_streak', true );
$streak_last   = (string) get_user_meta( $uid, 'smacg_last_login_date', true );
$streak_today  = current_time( 'Ymd' );
$streak_yest   = date( 'Ymd', strtotime( '-1 day', current_time( 'timestamp' ) ) );
$streak_alive  = in_array( $streak_last, [ $streak_today, $streak_yest ], true ); // 今天或昨天 = 還活著
$streak_show   = $streak_alive ? $streak_raw : 0;
// 下個 7 天里程碑 / 30 天里程碑還差幾天（活著時才計算，否則顯示「重新登入累積」）
$next_7        = $streak_show > 0 ? ( 7  - ( $streak_show % 7  ) ) % 7  : 7;
$next_30       = $streak_show > 0 ? ( 30 - ( $streak_show % 30 ) ) % 30 : 30;
if ( $next_7  === 0 ) $next_7  = 7;   // 剛好整除時，下一個是再 7 天後
if ( $next_30 === 0 ) $next_30 = 30;

/* v1.6.0：Email 驗證狀態（只對使用者本人計算 / 顯示） */
$verify_need  = false;
$resend_nonce = '';
if ( $is_owner ) {
    $verify_need = function_exists( 'smacg_user_needs_email_verify' )
        ? smacg_user_needs_email_verify( $uid )
        : false;
    $resend_nonce = wp_create_nonce( 'smacg_resend_verify' );
}

/* v1.7.0：加入天數計算 */
$reg_ts       = strtotime( $user->user_registered );
$days_joined  = $reg_ts ? max( 0, (int) floor( ( current_time( 'timestamp' ) - $reg_ts ) / DAY_IN_SECONDS ) ) : 0;

get_header(); ?>

<div class="mc-wrap" data-uid="<?php echo (int) $uid; ?>">

    <section class="mc-hero">
        <div class="mc-hero-avatar">
            <label class="mc-hero-avatar-link" title="點擊更換頭像">
                <img id="mc-avatar-img" src="<?php echo esc_url( $avatar_url ); ?>" alt="<?php echo esc_attr( $display ); ?>" loading="lazy">
                <div class="mc-hero-avatar-overlay">
                    <i class="fa-solid fa-camera"></i><span>更換頭像</span>
                </div>
                <input type="file" id="mc-avatar-input" accept="image/jpeg,image/png,image/webp" hidden>
            </label>

            <div class="mc-avatar-hint">
                <i class="fa-solid fa-circle-info"></i> 支援 JPG / PNG / WebP，最大 5 MB，建議方形圖片
            </div>

            <div id="mc-avatar-progress" class="mc-avatar-progress" hidden>
                <div class="mc-avatar-progress-bar"><div class="mc-avatar-progress-fill" style="width:0%"></div></div>
                <span class="mc-avatar-progress-text">0%</span>
            </div>
            <div id="mc-avatar-msg" class="mc-avatar-msg" hidden></div>
            <noscript>
                <a href="<?php echo esc_url( $account_url ); ?>" class="mc-avatar-fallback">請啟用 JavaScript 修改頭像</a>
            </noscript>
        </div>

        <div class="mc-hero-info">
            <h1 class="mc-hero-name">
                <?php echo esc_html( $display ); ?>
                <span class="mc-plan-badge"><?php echo esc_html( $plan_label ); ?></span>

                <?php if ( ! empty( $job_title ) ) : ?>
                    <span class="mc-job-badge" title="<?php echo esc_attr( $job_title['title_ref'] ?? '' ); ?>">
                        <?php echo esc_html( $job_title['job_icon'] ?? '' ); ?>
                        <?php echo esc_html( $job_title['title_name'] ?? '' ); ?>
                    </span>
                <?php endif; ?>

                <?php
                $tier      = $rank_info['tier'];
                $has_score = $rank_info['score'] > 0;
                $rank_pos  = (int) $rank_info['rank'];
                ?>
                <a href="<?php echo esc_url( $rank_page_url ); ?>"
                   class="mc-tier-mini<?php echo $has_score ? '' : ' mc-tier-mini--zero'; ?>"
                   style="--tier-color: <?php echo esc_attr( $tier['color'] ); ?>;"
                   title="<?php echo esc_attr( sprintf( '%s · %s 分 · %s',
                       $rank_info['season_label'] ?: '本賽季',
                       number_format( $rank_info['score'] ),
                       $rank_pos > 0 ? '#' . $rank_pos : '未上榜' ) ); ?>">
                    <span class="mc-tier-mini__icon"><?php echo esc_html( $tier['icon'] ); ?></span>
                    <span class="mc-tier-mini__label"><?php echo esc_html( $tier['label'] ); ?></span>
                    <?php if ( $rank_pos > 0 && $rank_pos <= 200 ) : ?>
                        <span class="mc-tier-mini__rank">#<?php echo $rank_pos; ?></span>
                    <?php endif; ?>
                </a>

                <?php if ( $career_peak ) : ?>
                    <span class="mc-tier-mini mc-tier-mini--peak" title="生涯最高段位">
                        <span class="mc-tier-mini__icon"><?php echo esc_html( $career_peak['icon'] ); ?></span>
                        <span class="mc-tier-mini__label">生涯 <?php echo esc_html( $career_peak['label'] ); ?></span>
                    </span>
                <?php endif; ?>

                <?php if ( $public_profile_url ) : ?>
                    <a href="<?php echo esc_url( $public_profile_url ); ?>" class="mc-public-link" title="查看公開個人頁">
                        <i class="fa-solid fa-eye"></i><span>公開頁</span>
                    </a>
                <?php endif; ?>
            </h1>

            <?php /* v1.7.0：Meta 徽章列（取代舊 .mc-hero-meta） */ ?>
            <div class="mc-meta-chips">
                <span class="mc-meta-chip mc-meta-chip--email">
                    <i class="fa-solid fa-envelope"></i>
                    <?php echo esc_html( $display_email ); ?>
                </span>

                <?php if ( $is_owner ) : ?>
                    <?php if ( $verify_need ) : ?>
                        <span class="mc-meta-chip mc-meta-chip--unverified">
                            <i class="fa-solid fa-triangle-exclamation"></i> 未驗證
                        </span>
                        <button type="button"
                                class="mc-meta-chip mc-meta-chip--resend"
                                id="mc-verify-resend"
                                data-nonce="<?php echo esc_attr( $resend_nonce ); ?>">
                            <i class="fa-solid fa-paper-plane"></i> 重寄驗證信
                        </button>
                        <span class="mc-verify-msg" id="mc-verify-msg" hidden></span>
                    <?php else : ?>
                        <span class="mc-meta-chip mc-meta-chip--verified">
                            <i class="fa-solid fa-circle-check"></i> 已驗證
                        </span>
                    <?php endif; ?>
                <?php endif; ?>

                <span class="mc-meta-chip mc-meta-chip--joined">
                    <i class="fa-solid fa-calendar-check"></i>
                    <?php if ( $days_joined < 1 ) : ?>
                        今天加入
                    <?php elseif ( $days_joined < 365 ) : ?>
                        加入 <?php echo (int) $days_joined; ?> 天
                    <?php else : ?>
                        加入 <?php echo (int) floor( $days_joined / 365 ); ?> 年 <?php echo (int) ( $days_joined % 365 ); ?> 天
                    <?php endif; ?>
                </span>
            </div>

            <?php /* v1.5.0：連續登入顯示行 */ ?>
            <p class="mc-streak-row">
                <span class="mc-streak-main">
                    🔥 連續登入
                    <strong class="<?php echo $streak_show > 0 ? 'is-alive' : 'is-dead'; ?>"><?php echo (int) $streak_show; ?></strong>
                    天
                </span>

                <?php if ( $streak_show > 0 ) : ?>
                    <span class="mc-streak-goal mc-streak-goal--7">
                        距 <?php echo (int) ( $streak_show + $next_7 ); ?> 天里程碑還差
                        <b><?php echo (int) $next_7; ?></b> 天 <small>(+100 EXP / +100 分)</small>
                    </span>
                    <span class="mc-streak-goal mc-streak-goal--30">
                        距 <?php echo (int) ( $streak_show + $next_30 ); ?> 天里程碑還差
                        <b><?php echo (int) $next_30; ?></b> 天 <small>(+500 EXP / +500 分)</small>
                    </span>
                <?php else : ?>
                    <?php if ( $streak_raw > 0 && ! $streak_alive ) : ?>
                        <span class="mc-streak-broken">⚠️ 已斷簽（前次紀錄 <?php echo (int) $streak_raw; ?> 天）</span>
                    <?php endif; ?>
                    <span class="mc-streak-goal">今天登入即開始新的連續紀錄！</span>
                <?php endif; ?>
            </p>

            <p class="mc-follow-meta">
                <span class="mc-follow-stat"><b><?php echo number_format( $mc_followers ); ?></b><em>粉絲</em></span>
                <span class="mc-follow-stat"><b><?php echo number_format( $mc_following ); ?></b><em>追蹤中</em></span>
                <span class="mc-follow-stat"><b><?php echo number_format( $mc_badge_count ); ?></b><em>成就</em></span>
            </p>

            <?php if ( $bio ) : ?>
                <p class="mc-hero-bio"><?php echo esc_html( $bio ); ?></p>
            <?php endif; ?>

            <div class="mc-progress-head">
                <span class="mc-progress-head__label">📊 我的進度</span>
                <a href="<?php echo esc_url( $guide_url ); ?>" class="mc-progress-head__guide" title="查看完整指南">
                    <i class="fa-solid fa-circle-question"></i>
                    <span>規則說明</span>
                </a>
            </div>

            <?php /* EXP 進度條（永久累積；v1.4.0 拿掉「8 / 150」） */ ?>
            <div class="mc-level-bar" title="Lv.<?php echo (int) $lvl_info['level']; ?>　目前累計 <?php echo (int) $lvl_info['exp']; ?> EXP">
                <div class="mc-level-fill" style="width:<?php echo (int) $lvl_info['percent']; ?>%"></div>
                <span class="mc-level-text">
                    <?php echo esc_html( $lvl_info['icon'] ); ?>
                    Lv.<?php echo (int) $lvl_info['level']; ?> · <?php echo esc_html( $lvl_info['title'] ); ?>
                    · <?php echo number_format( (int) $lvl_info['exp'] ); ?> EXP
                    <?php if ( empty( $lvl_info['is_max'] ) ) : ?>
                        <span class="mc-level-next">距 Lv.<?php echo (int) $lvl_info['level'] + 1; ?> 還差 <?php echo number_format( (int) $lvl_info['to_next'] ); ?> EXP</span>
                    <?php else : ?>
                        <span class="mc-level-max">MAX</span>
                    <?php endif; ?>
                </span>
            </div>

            <?php /* 段位進度條（每季重置；v1.4.0 強調「距下一階還差 N 分」） */ ?>
            <?php $prog = $rank_info['progress']; ?>
            <a href="<?php echo esc_url( $rank_page_url ); ?>"
               class="mc-rank-bar<?php echo $rank_info['score'] > 0 ? '' : ' mc-rank-bar--zero'; ?>"
               style="--tier-color: <?php echo esc_attr( $rank_info['tier']['color'] ); ?>;"
               title="<?php echo esc_attr( ( $rank_info['season_label'] ?: '本賽季' ) . '（每季重置）' ); ?>">
                <div class="mc-rank-fill" style="width:<?php echo (int) $prog['percent']; ?>%"></div>
                <span class="mc-rank-text">
                    <?php echo esc_html( $rank_info['tier']['icon'] ); ?>
                    <?php echo esc_html( $rank_info['tier']['label'] ); ?>
                    · <?php echo number_format( $rank_info['score'] ); ?> 分
                    <?php if ( $prog['is_max'] ) : ?>
                        <span class="mc-rank-max">最高段位 MAX</span>
                    <?php else : ?>
                        <span class="mc-rank-next">距 <?php echo esc_html( $prog['next_label'] ); ?> 還差 <?php echo number_format( $prog['to_next'] ); ?> 分</span>
                    <?php endif; ?>
                </span>
            </a>

            <div class="mc-hero-stats">
                <div><b><?php echo (int) ( $stats['counts']['watching']  ?? 0 ); ?></b><span>追番中</span></div>
                <div><b><?php echo (int) ( $stats['counts']['completed'] ?? 0 ); ?></b><span>已看完</span></div>
                <div><b><?php echo (int) ( $stats['counts']['favorited'] ?? 0 ); ?></b><span>收藏</span></div>
                <div><b><?php echo (int) ( $stats['rating']['count']     ?? 0 ); ?></b><span>評分</span></div>
                <div><b><?php echo (int) ( $stats['watch_time']['days']  ?? 0 ); ?>天</b><span>觀看時數</span></div>
            </div>
        </div>
    </section>

    <?php if ( ! empty( $privacy['show_continue_watching'] ) && function_exists( 'smacg_render_continue_watching' ) ) : ?>
        <?php smacg_render_continue_watching( $watchlist ); ?>
    <?php endif; ?>

    <nav class="mc-tabs" role="tablist">
        <?php
        $tabs = [
            'dashboard'     => '📊 總覽',
            'watchlist'     => '🎬 我的清單',
            'favorites'     => '❤️ 我的收藏',
            'stats'         => '📈 統計',
            'ratings'       => '⭐ 我的評分',
            'achievements'  => '🏆 成就',
            'career'        => '🎯 職業',
            'notifications' => '🔔 通知',
            'comments'      => '💬 留言',
            'points'        => '🎮 EXP',
            'settings'      => '⚙️ 設定',
        ];
        foreach ( $tabs as $k => $label ) :
            $active = $k === 'dashboard' ? ' active' : '';
            ?>
            <button class="mc-tab<?php echo $active; ?>" data-tab="<?php echo esc_attr( $k ); ?>" role="tab"><?php echo $label; ?></button>
        <?php endforeach; ?>

        <?php /* === 新增：訊息入口（外連 Better Messages 全寬頁，不走 panel 切換） === */ ?>
        <?php if ( $is_owner && shortcode_exists( 'better_messages_my_messages_url' ) ) : ?>
            <a class="mc-tab mc-tab--link"
               href="<?php echo esc_url( do_shortcode( '[better_messages_my_messages_url]' ) ); ?>"
               title="開啟我的訊息（全寬頁面）">
                💬 訊息
                <?php echo do_shortcode( '[better_messages_unread_counter hide_when_no_messages="1" preserve_space="1"]' ); ?>
            </a>
        <?php endif; ?>
    </nav>

       <div class="mc-panels">
        <section class="mc-panel active" data-panel="dashboard">
            <?php /* 手機版統計卡（Hero 極簡化後移至此） */ ?>
            <div class="mc-mobile-stats">
                <div><b><?php echo (int)($stats['counts']['watching']  ?? 0); ?></b><span>追番中</span></div>
                <div><b><?php echo (int)($stats['counts']['completed'] ?? 0); ?></b><span>已看完</span></div>
                <div><b><?php echo (int)($stats['counts']['favorited'] ?? 0); ?></b><span>收藏</span></div>
                <div><b><?php echo (int)($stats['rating']['count']     ?? 0); ?></b><span>評分</span></div>
                <div><b><?php echo (int)($stats['watch_time']['days']  ?? 0); ?>天</b><span>觀看</span></div>
            </div>
            <?php if ( function_exists( 'smacg_render_dashboard' ) ) {
                smacg_render_dashboard( $watchlist, $stats, $recent_cmt, $points_log, $plan_label, $uid );
            } else { echo '<p class="mc-empty">總覽模組尚未載入</p>'; } ?>
        </section>
        <section class="mc-panel" data-panel="watchlist">
            <?php if ( function_exists( 'smacg_render_watchlist' ) ) {
                smacg_render_watchlist( $watchlist, $stats['counts'] );
            } else { echo '<p class="mc-empty">清單模組尚未載入</p>'; } ?>
        </section>

        <section class="mc-panel" data-panel="favorites">
            <?php
            /*
             * 角色／聲優收藏。與「我的清單」分開：那是作品的追番狀態，
             * 這是實體收藏，兩者的資料來源與語意都不同
             * （wxacg_entity_favorites vs anime_user_status）。
             */
            if ( function_exists( 'wxacg_render_entity_favorites' ) ) {
                wxacg_render_entity_favorites( $uid );
            } else { echo '<p class="mc-empty">收藏模組尚未載入</p>'; } ?>
        </section>
        <section class="mc-panel" data-panel="stats">
            <?php if ( function_exists( 'smacg_render_stats' ) ) {
                smacg_render_stats( $stats );
            } else { echo '<p class="mc-empty">統計模組尚未載入</p>'; } ?>
        </section>
        <section class="mc-panel" data-panel="ratings">
            <?php if ( function_exists( 'smacg_render_ratings' ) ) {
                smacg_render_ratings( $ratings, $stats['rating'] );
            } else { echo '<p class="mc-empty">評分模組尚未載入</p>'; } ?>
        </section>
        <section class="mc-panel" data-panel="achievements">
            <?php if ( function_exists( 'smacg_render_badges' ) ) {
                smacg_render_badges( $uid );
            } else { echo '<p class="mc-empty">成就模組尚未載入</p>'; } ?>
        </section>
        <section class="mc-panel" data-panel="career">
            <?php if ( function_exists( 'smacg_render_career' ) ) {
                smacg_render_career( $uid, $lvl_info, $job_title );
            } else { echo '<p class="mc-empty">職業模組尚未載入</p>'; } ?>
        </section>
        <section class="mc-panel" data-panel="notifications">
            <?php if ( function_exists( 'smacg_render_notifications_tab' ) ) {
                smacg_render_notifications_tab();
            } else { echo '<p class="mc-empty">通知模組尚未載入</p>'; } ?>
        </section>
        <section class="mc-panel" data-panel="comments">
            <?php if ( function_exists( 'smacg_render_comments' ) ) {
                smacg_render_comments( $uid );
            } else { echo '<p class="mc-empty">留言模組尚未載入</p>'; } ?>
        </section>
        <section class="mc-panel" data-panel="points">
            <?php if ( function_exists( 'smacg_render_points' ) ) {
                smacg_render_points( $uid, $lvl_info, $points_log );
            } else { echo '<p class="mc-empty">EXP 模組尚未載入</p>'; } ?>
        </section>
        <section class="mc-panel" data-panel="settings">
            <?php if ( function_exists( 'smacg_render_settings' ) ) {
                smacg_render_settings( $user, $privacy, $is_owner );
            } else { echo '<p class="mc-empty">設定模組尚未載入</p>'; } ?>
        </section>
    </div>

</div>

<style>
/* 「我的進度」標題列 + 規則說明連結 */
.mc-progress-head {
    display: flex; align-items: center; justify-content: space-between;
    margin: 16px 0 6px;
}
.mc-progress-head__label {
    font-size: 14px; font-weight: 700;
    color: var(--text-color, #fff);
    opacity: 0.85;
}
.mc-progress-head__guide {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 4px 10px; border-radius: 999px;
    background: rgba(40, 200, 214, 0.08);
    border: 1px solid rgba(40, 200, 214, 0.25);
    color: var(--accent-cyan, #28c8d6);
    font-size: 12px; font-weight: 600;
    text-decoration: none;
    transition: all .2s ease;
}
.mc-progress-head__guide:hover {
    background: rgba(40, 200, 214, 0.18);
    transform: translateY(-1px);
}

/* v1.4.0：等級條 / 段位條的「還差 N」次要文字 */
.mc-level-text .mc-level-next,
.mc-rank-text  .mc-rank-next {
    margin-left: 8px;
    font-size: 0.85em;
    opacity: 0.78;
    font-weight: 500;
}
.mc-level-text .mc-level-max,
.mc-rank-text  .mc-rank-max {
    margin-left: 8px;
    padding: 2px 8px;
    border-radius: 999px;
    background: linear-gradient(135deg, #ffd700, #ff8a00);
    color: #1a1a1a;
    font-size: 0.78em;
    font-weight: 700;
    letter-spacing: 0.05em;
}

/* v1.5.0：連續登入顯示行 */
.mc-streak-row {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 6px 14px;
    margin: 10px 0 6px;
    font-size: 14px;
    color: var(--text-color, #fff);
    opacity: 0.92;
}
.mc-streak-row .mc-streak-main {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    font-weight: 600;
}
.mc-streak-row strong {
    font-size: 1.25em;
    font-weight: 800;
    padding: 0 4px;
}
.mc-streak-row strong.is-alive { color: #ff6b35; }
.mc-streak-row strong.is-dead  { color: #999; }
.mc-streak-row .mc-streak-goal {
    font-size: 0.88em;
    opacity: 0.78;
    display: inline-flex;
    align-items: baseline;
    gap: 3px;
}
.mc-streak-row .mc-streak-goal b {
    color: #ffd166;
    font-weight: 700;
}
.mc-streak-row .mc-streak-goal small {
    opacity: 0.65;
    font-size: 0.9em;
}
.mc-streak-row .mc-streak-broken {
    color: #ff5577;
    font-size: 0.88em;
}
@media (max-width: 640px) {
    .mc-streak-row { flex-direction: column; align-items: flex-start; gap: 4px; }
}

/* ============================================================
 * v1.7.0：Meta 膠囊群 + 段位條鬆綁 + 統計卡片化
 * ============================================================ */

/* === Meta 膠囊群（取代舊 .mc-hero-meta） === */
.mc-meta-chips {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 8px;
    margin: 10px 0 12px;
}
.mc-meta-chip {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 5px 12px;
    border-radius: 999px;
    font-size: 13px;
    font-weight: 500;
    line-height: 1.4;
    background: rgba(255, 255, 255, 0.06);
    border: 1px solid rgba(255, 255, 255, 0.1);
    color: var(--text-color, #fff);
    white-space: nowrap;
    transition: all .2s ease;
    font-family: inherit;
}
.mc-meta-chip i {
    font-size: 0.9em;
    opacity: 0.75;
}

/* Email 膠囊 */
.mc-meta-chip--email i {
    color: #64b5f6;
    opacity: 1;
}

/* 已驗證膠囊（A2：漸層綠 + 微光） */
.mc-meta-chip--verified {
    background: linear-gradient(135deg, rgba(46, 204, 113, 0.28), rgba(39, 174, 96, 0.18));
    border-color: rgba(46, 204, 113, 0.55);
    color: #7fd99f;
    font-weight: 600;
    box-shadow: 0 0 0 1px rgba(46, 204, 113, 0.12), 0 2px 8px rgba(46, 204, 113, 0.15);
}
.mc-meta-chip--verified i {
    color: #2ecc71;
    opacity: 1;
    filter: drop-shadow(0 0 4px rgba(46, 204, 113, 0.6));
}

/* 未驗證膠囊 */
.mc-meta-chip--unverified {
    background: rgba(255, 152, 0, 0.18);
    border-color: rgba(255, 152, 0, 0.5);
    color: #ffb74d;
    font-weight: 600;
}
.mc-meta-chip--unverified i {
    color: #ff9800;
    opacity: 1;
}

/* 重寄驗證信按鈕 */
.mc-meta-chip--resend {
    background: linear-gradient(135deg, #ff9800, #ff6b35);
    border-color: transparent;
    color: #fff;
    font-weight: 600;
    cursor: pointer;
}
.mc-meta-chip--resend i {
    color: #fff;
    opacity: 0.95;
}
.mc-meta-chip--resend:hover:not(:disabled) {
    transform: translateY(-1px);
    box-shadow: 0 3px 10px rgba(255, 107, 53, 0.45);
}
.mc-meta-chip--resend:disabled {
    opacity: 0.5;
    cursor: not-allowed;
    transform: none;
    box-shadow: none;
}

/* 加入天數膠囊 */
.mc-meta-chip--joined {
    opacity: 0.85;
}
.mc-meta-chip--joined i {
    color: #ce93d8;
    opacity: 1;
}

/* 重寄驗證結果訊息 */
.mc-verify-msg {
    font-size: 12px;
    padding: 4px 10px;
    border-radius: 999px;
    line-height: 1.4;
    border: 1px solid transparent;
}
.mc-verify-msg.is-success {
    background: rgba(46, 204, 113, 0.15);
    color: #7fd99f;
    border-color: rgba(46, 204, 113, 0.4);
}
.mc-verify-msg.is-error {
    background: rgba(255, 85, 119, 0.15);
    color: #ff8aa3;
    border-color: rgba(255, 85, 119, 0.4);
}

/* === 新增：訊息 tab（外連型，沿用 .mc-tab 樣式 + 未讀徽章） === */
.mc-tab--link {
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 4px;
}
.mc-tab--link .better-messages-unread-counter {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 18px;
    height: 18px;
    padding: 0 5px;
    margin-left: 2px;
    font-size: 11px;
    font-weight: 700;
    line-height: 1;
    background: #ff6bae;
    color: #fff;
    border-radius: 999px;
}

/* === v1.7.1 (2026-05-28) 段位進度條：單行縮排修正 === */
.mc-rank-bar{
  position: relative;
  height: 28px;              /* 從 24px 微調至 28px，給文字呼吸空間 */
  border-radius: 14px;
  overflow: hidden;
  background: rgba(0,0,0,0.08);
  margin-top: 6px;
}
.mc-rank-bar-fill{
  position: absolute;
  inset: 0 auto 0 0;         /* 由 width 控制長度 */
  background: linear-gradient(90deg,#b08968,#d4a373);
  border-radius: 14px;
  transition: width .4s ease;
}
.mc-rank-text{
  position: relative;
  z-index: 2;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  height: 100%;
  padding: 0 12px;
  font-size: 0.82rem;        /* 主文字略縮 */
  font-weight: 600;
  color: #fff;
  text-shadow: 0 1px 2px rgba(0,0,0,0.35);
  white-space: nowrap;       /* 強制單行，不換行 */
  line-height: 1;
}
.mc-rank-text .mc-rank-main{
  display: inline-flex;
  align-items: center;
  gap: 4px;
}
.mc-rank-next{
  font-size: 0.7rem;         /* 副文字縮小 → 同行可容納 */
  font-weight: 500;
  opacity: 0.88;
  margin-left: 4px;
  padding-left: 8px;
  border-left: 1px solid rgba(255,255,255,0.45);  /* 用直線分隔，視覺更清楚 */
}

/* 手機窄螢幕：副文字過長時自動縮更小 */
@media (max-width: 480px){
  .mc-rank-text{ font-size: 0.78rem; }
  .mc-rank-next{ font-size: 0.65rem; }
}

/* === 5 格統計卡片化（2B） === */
.mc-hero-stats {
    gap: 10px !important;
    margin-top: 18px !important;
}
.mc-hero-stats > div {
    background: rgba(255, 255, 255, 0.04);
    border: 1px solid rgba(255, 255, 255, 0.08);
    border-radius: 12px;
    padding: 14px 8px !important;
    transition: all .2s ease;
    text-align: center;
    cursor: default;
}
.mc-hero-stats > div:hover {
    background: rgba(40, 200, 214, 0.08);
    border-color: rgba(40, 200, 214, 0.3);
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(40, 200, 214, 0.15);
}
.mc-hero-stats > div b {
    display: block;
    font-size: 1.45em;
    font-weight: 800;
    line-height: 1.1;
    margin-bottom: 4px;
    background: linear-gradient(135deg, #28c8d6, #6fc3df);
    -webkit-background-clip: text;
    background-clip: text;
    -webkit-text-fill-color: transparent;
}
.mc-hero-stats > div span {
    display: block;
    font-size: 11px;
    opacity: 0.7;
    font-weight: 500;
    letter-spacing: 0.05em;
}

@media (max-width: 640px) {
    .mc-hero-stats {
        grid-template-columns: repeat(3, 1fr) !important;
    }
    .mc-hero-stats > div b { font-size: 1.25em; }
    .mc-meta-chips { gap: 6px; }
    .mc-meta-chip { font-size: 12px; padding: 4px 10px; }
}
</style>

<?php /* v1.6.0：重寄驗證信 AJAX */ ?>
<script>
(function(){
    var btn = document.getElementById('mc-verify-resend');
    if (!btn) return; // 已驗證或非本人 → 無此按鈕

    btn.addEventListener('click', function(){
        var msgEl = document.getElementById('mc-verify-msg');
        var nonce = btn.dataset.nonce;
        var ajaxUrl = (window.weixiaoacg_ajax && window.weixiaoacg_ajax.ajax_url)
            ? window.weixiaoacg_ajax.ajax_url
            : '/wp-admin/admin-ajax.php';

        btn.disabled = true;
        var originalHTML = btn.innerHTML;
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> 寄送中...';
        if (msgEl) { msgEl.hidden = true; msgEl.className = 'mc-verify-msg'; }

        var fd = new FormData();
        fd.append('action', 'smacg_resend_verify_email');
        fd.append('nonce', nonce);

        fetch(ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function(r){ return r.json(); })
            .then(function(res){
                var ok = res && res.success;
                if (msgEl) {
                    msgEl.hidden = false;
                    msgEl.className = 'mc-verify-msg ' + (ok ? 'is-success' : 'is-error');
                    msgEl.textContent = (res && res.data && res.data.msg) ? res.data.msg : (ok ? '已寄出' : '寄送失敗');
                }
                btn.innerHTML = ok
                    ? '<i class="fa-solid fa-check"></i> 已寄出'
                    : originalHTML;
                // 成功 → 保持 disabled 避免連點；失敗 → 30 秒後解鎖
                if (!ok) setTimeout(function(){ btn.disabled = false; }, 30000);
            })
            .catch(function(){
                if (msgEl) {
                    msgEl.hidden = false;
                    msgEl.className = 'mc-verify-msg is-error';
                    msgEl.textContent = '網路錯誤，請稍後再試';
                }
                btn.innerHTML = originalHTML;
                setTimeout(function(){ btn.disabled = false; }, 10000);
            });
    });
})();
</script>

<?php /* Cropper.js 裁切 Modal */ ?>
<div id="mc-cropper-modal" class="mc-cropper-modal" hidden aria-hidden="true" role="dialog">
    <div class="mc-cropper-backdrop"></div>
    <div class="mc-cropper-dialog" role="document">
        <div class="mc-cropper-header">
            <h3><i class="fa-solid fa-crop-simple"></i> 裁切頭像</h3>
            <button type="button" class="mc-cropper-close" id="mc-cropper-close" aria-label="關閉">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>
        <div class="mc-cropper-body">
            <div class="mc-cropper-canvas"><img id="mc-cropper-image" src="" alt="裁切預覽"></div>
            <div class="mc-cropper-tips">
                <i class="fa-solid fa-lightbulb"></i> 拖曳調整裁切框，滾輪縮放。輸出為 400×400 方形頭像。
            </div>
        </div>
        <div class="mc-cropper-footer">
            <button type="button" class="mc-btn mc-btn-secondary" id="mc-cropper-cancel">
                <i class="fa-solid fa-rotate-left"></i> 取消
            </button>
            <button type="button" class="mc-btn mc-btn-primary" id="mc-cropper-confirm">
                <i class="fa-solid fa-check"></i> 確認上傳
            </button>
        </div>
    </div>
</div>

<?php get_footer(); ?>
