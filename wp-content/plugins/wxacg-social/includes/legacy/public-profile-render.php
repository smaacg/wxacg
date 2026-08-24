<?php
/**
 * Public Profile - Render Functions
 *
 * @package    weixiaoacg
 * @subpackage wxacg-social
 * @version    1.2.3
 *
 * Changelog:
 * - 1.2.3 (2026-05-26) — Activity timeline 完整顯示
 *   * smacg_pp_render_activity() 改寫：
 *     - 補上作品名稱（用 $ev['post_id'] 撈 get_the_title）
 *     - 根據 type 組合不同句型（看完《xxx》/ 評分《xxx》/ 於《xxx》留言 / 點數變動）
 *     - li 加上 type/subtype/color class 方便日後個別樣式
 *     - 加上 inline CSS 美化 timeline 視覺
 * - 1.2.2 (2026-05-17) — Hero 牌位顯示
 *   * 新增職業稱號徽章 (.pp-job-badge)
 *   * 新增 TFT 段位徽章 (.pp-tier-mini)
 *   * 新增生涯最高段位徽章 (.pp-tier-mini--peak)
 * - 1.2.1 (2026-05-16) — Bug fix release
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/* ========================================================================
 * Hero 區塊
 * ====================================================================== */

if ( ! function_exists( 'smacg_pp_render_hero' ) ) :
function smacg_pp_render_hero( $user, $args ) {
    $uid          = (int) $user->ID;
    $display_name = ! empty( $args['display_name'] ) ? $args['display_name'] : $user->display_name;
    $bio          = ! empty( $args['bio'] )          ? $args['bio']          : '';
    $reg_date     = ! empty( $args['reg_date'] )     ? $args['reg_date']     : '';
    $email        = isset( $args['email'] )          ? $args['email']        : '';
    $points       = isset( $args['points'] )         ? (int) $args['points'] : 0;
    $plan_label   = ! empty( $args['plan_label'] )   ? $args['plan_label']   : '';
    $avatar_url   = ! empty( $args['avatar_url'] )   ? $args['avatar_url']   : get_avatar_url( $uid, [ 'size' => 200 ] );
    $lvl          = ! empty( $args['lvl_info'] )     ? $args['lvl_info']     : null;
    $is_owner     = ! empty( $args['is_owner'] );

    /* v1.2.2 — 牌位資料（職業稱號 + TFT 段位 + 生涯最高） */
    $job_title   = function_exists( 'smacg_get_user_job_title' ) ? smacg_get_user_job_title( $uid ) : [];
    $rank_info   = function_exists( 'smacg_get_user_rank_season_info' ) ? smacg_get_user_rank_season_info( $uid ) : null;
    $career_peak = function_exists( 'smacg_get_user_career_peak_tier' ) ? smacg_get_user_career_peak_tier( $uid ) : null;
    $rank_page_url = home_url( '/ranking-users/?tab=rank_season' );

    /* 粉絲 / 追蹤中 */
    $followers_count = function_exists( 'smacg_get_followers_count' ) ? (int) smacg_get_followers_count( $uid ) : 0;
    $following_count = function_exists( 'smacg_get_following_count' ) ? (int) smacg_get_following_count( $uid ) : 0;

    $profile_url   = function_exists( 'wxacg_get_public_profile_url' ) ? wxacg_get_public_profile_url( $user ) : home_url( '/u/' . $user->user_login . '/' );
    $profile_url   = trailingslashit( $profile_url );
    $followers_url = $profile_url . 'followers/';
    $following_url = $profile_url . 'following/';

    /* 互追判定 */
    $is_mutual = false;
    if ( ! $is_owner && is_user_logged_in() ) {
        $viewer_id = get_current_user_id();
        if ( function_exists( 'smacg_is_mutual_follow' ) ) {
            $is_mutual = (bool) smacg_is_mutual_follow( $viewer_id, $uid );
        } elseif ( function_exists( 'wxacg_is_following' ) ) {
            $is_mutual = wxacg_is_following( $viewer_id, $uid ) && wxacg_is_following( $uid, $viewer_id );
        }
    }
    ?>
    <section class="pp-hero">
        <div class="pp-hero-inner">

            <div class="pp-hero-avatar">
                <img src="<?php echo esc_url( $avatar_url ); ?>" alt="<?php echo esc_attr( $display_name ); ?>" loading="eager" decoding="async">
                <?php if ( $lvl && ! empty( $lvl['icon'] ) ) : ?>
                    <span class="pp-hero-level-icon" title="<?php echo esc_attr( 'Lv.' . $lvl['level'] . ' ' . ( $lvl['title'] ?? '' ) ); ?>"><?php echo esc_html( $lvl['icon'] ); ?></span>
                <?php endif; ?>
            </div>

            <div class="pp-hero-main">
                <div class="pp-hero-title">
                    <h1 class="pp-hero-name"><?php echo esc_html( $display_name ); ?></h1>

                    <?php if ( $plan_label ) : ?>
                        <span class="pp-hero-plan"><?php echo esc_html( $plan_label ); ?></span>
                    <?php endif; ?>

                    <?php /* v1.2.2 — 職業稱號徽章 */ ?>
                    <?php if ( ! empty( $job_title ) && ! empty( $job_title['title_name'] ) ) : ?>
                        <span class="pp-job-badge" title="<?php echo esc_attr( $job_title['title_ref'] ?? '' ); ?>">
                            <?php echo esc_html( $job_title['job_icon'] ?? '' ); ?>
                            <?php echo esc_html( $job_title['title_name'] ); ?>
                        </span>
                    <?php endif; ?>

                    <?php /* v1.2.2 — TFT 段位徽章 */ ?>
                    <?php
                    if ( $rank_info && ! empty( $rank_info['tier'] ) ) :
                        $tier      = $rank_info['tier'];
                        $has_score = ! empty( $rank_info['score'] ) && $rank_info['score'] > 0;
                        $rank_pos  = (int) ( $rank_info['rank'] ?? 0 );
                    ?>
                        <a href="<?php echo esc_url( $rank_page_url ); ?>"
                           class="pp-tier-mini<?php echo $has_score ? '' : ' pp-tier-mini--zero'; ?>"
                           style="--tier-color: <?php echo esc_attr( $tier['color'] ?? '#6b6b6b' ); ?>;"
                           title="<?php echo esc_attr( sprintf( '%s · %s 分 · %s',
                               $rank_info['season_label'] ?: '本賽季',
                               number_format( (int) ( $rank_info['score'] ?? 0 ) ),
                               $rank_pos > 0 ? '#' . $rank_pos : '未上榜' ) ); ?>">
                            <span class="pp-tier-mini__icon"><?php echo esc_html( $tier['icon'] ?? '🥉' ); ?></span>
                            <span class="pp-tier-mini__label"><?php echo esc_html( $tier['label'] ?? '' ); ?></span>
                            <?php if ( $rank_pos > 0 && $rank_pos <= 200 ) : ?>
                                <span class="pp-tier-mini__rank">#<?php echo $rank_pos; ?></span>
                            <?php endif; ?>
                        </a>
                    <?php endif; ?>

                    <?php /* v1.2.2 — 生涯最高段位 */ ?>
                    <?php if ( $career_peak && ! empty( $career_peak['label'] ) ) : ?>
                        <span class="pp-tier-mini pp-tier-mini--peak" title="生涯最高段位">
                            <span class="pp-tier-mini__icon"><?php echo esc_html( $career_peak['icon'] ?? '🏆' ); ?></span>
                            <span class="pp-tier-mini__label">生涯 <?php echo esc_html( $career_peak['label'] ); ?></span>
                        </span>
                    <?php endif; ?>
                </div>

                <div class="pp-hero-meta">
                    <?php if ( $reg_date ) : ?>
                        <span class="pp-meta-item">📅 <?php echo esc_html( '加入於 ' . $reg_date ); ?></span>
                    <?php endif; ?>
                    <?php if ( $email ) : ?>
                        <span class="pp-meta-item">✉️ <?php echo esc_html( $email ); ?></span>
                    <?php endif; ?>
                    <?php if ( $points ) : ?>
                        <span class="pp-meta-item">💎 <?php echo esc_html( number_format_i18n( $points ) ); ?> 點</span>
                    <?php endif; ?>
                    <a class="pp-meta-item pp-count-link" href="<?php echo esc_url( $followers_url ); ?>">
                        👥 粉絲 <strong class="pp-followers-count"><?php echo esc_html( number_format_i18n( $followers_count ) ); ?></strong>
                    </a>
                    <a class="pp-meta-item pp-count-link" href="<?php echo esc_url( $following_url ); ?>">
                        ➡️ 追蹤中 <strong class="pp-following-count"><?php echo esc_html( number_format_i18n( $following_count ) ); ?></strong>
                    </a>
                </div>

                <?php if ( $bio ) : ?>
                    <p class="pp-hero-bio"><?php echo esc_html( $bio ); ?></p>
                <?php endif; ?>

                <?php if ( $lvl ) : ?>
                    <div class="pp-hero-level">
                        <div class="pp-level-info">
                            <span class="pp-level-title">Lv.<?php echo (int) $lvl['level']; ?> <?php echo esc_html( $lvl['title'] ?? '' ); ?></span>
                            <?php if ( empty( $lvl['is_max'] ) ) : ?>
                                <span class="pp-level-exp"><?php echo esc_html( number_format_i18n( (int) ( $lvl['exp'] ?? 0 ) ) ); ?> EXP</span>
                            <?php else : ?>
                                <span class="pp-level-exp">MAX</span>
                            <?php endif; ?>
                        </div>
                        <div class="pp-level-bar">
                            <div class="pp-level-bar-fill" style="width: <?php echo esc_attr( (float) ( $lvl['percent'] ?? 0 ) ); ?>%;"></div>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="pp-hero-actions">
                    <?php if ( $is_owner ) : ?>
                        <a class="pp-btn pp-btn-primary" href="<?php echo esc_url( home_url( '/mc/#settings' ) ); ?>">⚙️ 編輯個人資料</a>
                    <?php elseif ( is_user_logged_in() ) : ?>
                        <?php
                        $is_following = function_exists( 'wxacg_is_following' ) ? wxacg_is_following( get_current_user_id(), $uid ) : false;
                        ?>
                        <button class="smacg-follow-btn <?php echo $is_following ? 'is-following' : ''; ?>"
                                data-user-id="<?php echo esc_attr( $uid ); ?>"
                                data-following="<?php echo $is_following ? '1' : '0'; ?>">
                            <?php echo $is_following ? '✓ 追蹤中' : '+ 追蹤'; ?>
                        </button>
                        <?php if ( $is_mutual ) : ?>
                            <span class="pp-mutual-badge" title="你們互相追蹤對方">🤝 互相追蹤</span>
                        <?php endif; ?>
                    <?php else : ?>
                        <a class="pp-btn pp-btn-primary" href="<?php echo esc_url( wp_login_url( get_permalink() ) ); ?>">登入後追蹤</a>
                    <?php endif; ?>
                    <button class="pp-btn pp-btn-ghost pp-share-btn" data-url="<?php echo esc_attr( get_permalink() ); ?>">🔗 分享</button>
                </div>

            </div>
        </div>
    </section>
    <?php
    smacg_pp_render_inline_css();
}
endif;

/* ========================================================================
 * 一次性 inline CSS（v1.2.3 — 加上 timeline 樣式）
 * ====================================================================== */

if ( ! function_exists( 'smacg_pp_render_inline_css' ) ) :
function smacg_pp_render_inline_css() {
    static $printed = false;
    if ( $printed ) return;
    $printed = true;
    ?>
    <style id="smacg-pp-inline-v123">
        /* 粉絲/追蹤連結 */
        .pp-count-link{text-decoration:none;color:inherit;transition:color .15s ease, transform .15s ease;display:inline-flex;align-items:center;gap:4px}
        .pp-count-link:hover{color:#ff6bae;transform:translateY(-1px)}
        .pp-count-link strong{font-weight:700}

        /* 互追徽章 */
        .pp-mutual-badge{display:inline-flex;align-items:center;gap:4px;padding:6px 12px;border-radius:999px;background:linear-gradient(135deg,#10b981,#059669);color:#fff;font-size:13px;font-weight:600;line-height:1;white-space:nowrap;box-shadow:0 2px 6px rgba(16,185,129,.25);cursor:default;user-select:none}

        /* Hero name 標題列：可換行排列徽章 */
        .pp-hero-title{display:flex;align-items:center;flex-wrap:wrap;gap:8px;margin-bottom:6px}
        .pp-hero-title .pp-hero-name{margin:0}

        /* v1.2.2 — 職業稱號徽章 */
        .pp-job-badge{
            display:inline-flex;align-items:center;gap:5px;
            padding:4px 11px;
            font-size:12px;font-weight:600;line-height:1.4;
            background:linear-gradient(135deg,rgba(167,139,250,.18),rgba(244,114,182,.18));
            color:#e2c8ff;
            border:1px solid rgba(167,139,250,.4);
            border-radius:999px;
            white-space:nowrap;
            backdrop-filter:blur(4px);
        }

        /* v1.2.2 — TFT 段位徽章 */
        .pp-tier-mini{
            display:inline-flex;align-items:center;gap:5px;
            padding:4px 11px;
            font-size:12px;font-weight:600;line-height:1.4;
            background:rgba(255,255,255,.04);
            color:var(--tier-color,#cbd5e1);
            border:1px solid var(--tier-color,rgba(255,255,255,.18));
            border-radius:999px;
            text-decoration:none;
            white-space:nowrap;
            transition:transform .15s, box-shadow .15s, background .15s;
        }
        .pp-tier-mini:hover{
            transform:translateY(-1px);
            background:rgba(255,255,255,.08);
            box-shadow:0 4px 14px rgba(0,0,0,.25);
        }
        .pp-tier-mini__icon{font-size:14px;line-height:1}
        .pp-tier-mini__label{color:#e6e8eb}
        .pp-tier-mini__rank{
            margin-left:3px;
            padding:1px 6px;
            font-size:10px;font-weight:700;
            background:linear-gradient(135deg,#fbbf24,#f59e0b);
            color:#1a1a1a;
            border-radius:999px;
        }
        .pp-tier-mini--zero{opacity:.55}
        .pp-tier-mini--zero:hover{opacity:.9}
        .pp-tier-mini--peak{
            background:linear-gradient(135deg,rgba(251,191,36,.12),rgba(245,158,11,.12));
            border-color:rgba(251,191,36,.4);
        }
        .pp-tier-mini--peak .pp-tier-mini__label{color:#fbbf24}

        /* Hero level 進度條 */
        .pp-hero-level{margin-top:10px}
        .pp-level-info{display:flex;justify-content:space-between;font-size:12px;color:#cbd5e1;margin-bottom:4px}
        .pp-level-title{font-weight:600;color:#e6e8eb}
        .pp-level-exp{color:#94a3b8}
        .pp-level-bar{height:8px;background:rgba(0,0,0,.35);border-radius:999px;overflow:hidden}
        .pp-level-bar-fill{height:100%;background:linear-gradient(90deg,#ff6bae,#6bb6ff);border-radius:999px;transition:width .6s ease}

        /* Hero actions */
        .pp-hero-actions{display:flex;flex-wrap:wrap;align-items:center;gap:10px}

        /* Hero level icon */
        .pp-hero-avatar{position:relative}
        .pp-hero-level-icon{
            position:absolute;bottom:-10px;right:-10px;
            width:32px;height:32px;
            display:grid;place-items:center;
            background:#1a1f26;
            border:2px solid #ff6bae;
            border-radius:50%;
            font-size:16px;
            box-shadow:0 2px 8px rgba(0,0,0,.4);
        }

        /* Meta item */
        .pp-meta-item{display:inline-flex;align-items:center;gap:5px}

        /* v1.2.3 — Activity Timeline */
        .pp-timeline{list-style:none;margin:0;padding:0;display:flex;flex-direction:column;gap:10px}
        .pp-timeline-item{
            display:flex;gap:14px;align-items:flex-start;
            padding:14px 16px;
            background:rgba(255,255,255,.04);
            border:1px solid rgba(255,255,255,.08);
            border-radius:12px;
            transition:background .15s, border-color .15s, transform .15s;
        }
        .pp-timeline-item:hover{
            background:rgba(255,255,255,.07);
            border-color:rgba(255,255,255,.16);
            transform:translateX(2px);
        }
        .pp-timeline-icon{
            flex:0 0 auto;
            width:40px;height:40px;
            display:grid;place-items:center;
            font-size:20px;line-height:1;
            background:rgba(255,255,255,.06);
            border-radius:50%;
        }
        .pp-timeline-item--completed .pp-timeline-icon{background:rgba(16,185,129,.18);color:#10b981}
        .pp-timeline-item--watching  .pp-timeline-icon{background:rgba(59,130,246,.18);color:#3b82f6}
        .pp-timeline-item--want      .pp-timeline-icon{background:rgba(250,204,21,.18);color:#facc15}
        .pp-timeline-item--dropped   .pp-timeline-icon{background:rgba(148,163,184,.18);color:#94a3b8}
        .pp-timeline-item--rating    .pp-timeline-icon{background:rgba(250,204,21,.18);color:#facc15}
        .pp-timeline-item--comment   .pp-timeline-icon{background:rgba(244,114,182,.18);color:#f472b6}
        .pp-timeline-item--points    .pp-timeline-icon{background:rgba(167,139,250,.18);color:#a78bfa}
        .pp-timeline-body{flex:1;min-width:0;display:flex;flex-direction:column;gap:4px}
        .pp-timeline-title{font-size:15px;font-weight:600;color:#e6e8eb;line-height:1.4;word-break:break-word}
        .pp-timeline-title a{color:inherit;text-decoration:none;transition:color .15s}
        .pp-timeline-title a:hover{color:#ff6bae}
        .pp-timeline-work{color:#6bb6ff;font-weight:600}
        .pp-timeline-meta{font-size:13px;color:#cbd5e1;line-height:1.5;word-break:break-word}
        .pp-timeline-meta--rating{color:#facc15;font-weight:600}
        .pp-timeline-meta--points-gain{color:#10b981;font-weight:600}
        .pp-timeline-meta--points-loss{color:#f87171;font-weight:600}
        .pp-timeline-time{font-size:12px;color:#94a3b8}
        .pp-timeline-empty{text-align:center;padding:60px 16px;color:#94a3b8}

        @media (max-width:480px){
            .pp-mutual-badge{font-size:12px;padding:5px 10px}
            .pp-job-badge,.pp-tier-mini{font-size:11px;padding:3px 9px}
            .pp-tier-mini__icon{font-size:13px}
            .pp-timeline-item{padding:12px 14px;gap:10px}
            .pp-timeline-icon{width:36px;height:36px;font-size:18px}
            .pp-timeline-title{font-size:14px}
        }
    </style>
    <?php
}
endif;

/* ========================================================================
 * Overview Tab — 完全保留原本 v1.2.1 邏輯
 * ====================================================================== */

if ( ! function_exists( 'smacg_pp_render_overview' ) ) :
function smacg_pp_render_overview( $user, $watchlist, $stats, $can_w, $can_r ) {
    $uid = (int) $user->ID;
    ?>
    <section class="pp-section pp-overview">
        <div class="pp-grid pp-grid-2">

            <div class="pp-card">
                <h2 class="pp-card-title">📺 最近更新</h2>
                <?php
                $recent = [];
                if ( $can_w && ! empty( $watchlist ) ) {
                    $recent = array_slice( $watchlist, 0, 8 );
                }
                if ( $recent ) :
                ?>
                    <div class="pp-anime-grid">
                        <?php foreach ( $recent as $item ) {
                            $pid = is_array( $item ) ? ( $item['post_id'] ?? 0 ) : (int) $item;
                            if ( $pid ) smacg_pp_render_anime_card( $pid, $item );
                        } ?>
                    </div>
                <?php else : ?>
                    <p class="pp-empty">尚無資料</p>
                <?php endif; ?>
            </div>

            <div class="pp-card">
                <h2 class="pp-card-title">🏷️ 喜愛類型</h2>
                <?php
                $genres_src = [];
                if ( ! empty( $stats['genres'] ) ) {
                    $genres_src = (array) $stats['genres'];
                } elseif ( ! empty( $stats['top_genres'] ) ) {
                    $genres_src = (array) $stats['top_genres'];
                }
                $top_genres = $genres_src ? array_slice( $genres_src, 0, 10 ) : [];
                if ( $top_genres ) :
                ?>
                    <div class="pp-tag-cloud">
                        <?php foreach ( $top_genres as $g ) :
                            $name = is_array( $g ) ? ( $g['name'] ?? '' ) : (string) $g;
                            $cnt  = is_array( $g ) ? (int) ( $g['count'] ?? 0 ) : 0;
                            if ( ! $name ) continue;
                        ?>
                            <span class="pp-tag"><?php echo esc_html( $name ); ?><?php if ( $cnt ) : ?> <em>×<?php echo (int) $cnt; ?></em><?php endif; ?></span>
                        <?php endforeach; ?>
                    </div>
                <?php else : ?>
                    <p class="pp-empty">尚無偏好統計</p>
                <?php endif; ?>
            </div>

        </div>
    </section>
    <?php
}
endif;

/* ========================================================================
 * Watchlist Tab — 完全保留原本 v1.2.1 邏輯
 * ====================================================================== */

if ( ! function_exists( 'smacg_pp_render_watchlist' ) ) :
function smacg_pp_render_watchlist( $watchlist ) {
    if ( empty( $watchlist ) ) {
        echo '<section class="pp-section"><p class="pp-empty">尚未加入任何作品</p></section>';
        return;
    }

    $counts = [ 'all' => 0, 'watching' => 0, 'completed' => 0, 'favorited' => 0, 'want' => 0, 'dropped' => 0, 'paused' => 0 ];
    foreach ( $watchlist as $w ) {
        $counts['all']++;
        $s = is_array( $w ) ? ( $w['status'] ?? '' ) : '';
        if ( isset( $counts[ $s ] ) ) $counts[ $s ]++;
        if ( ! empty( $w['favorited'] ) && $s !== 'favorited' ) {
            $counts['favorited']++;
        }
    }
    ?>
    <section class="pp-section pp-watchlist">
        <div class="pp-filter-bar">
            <button class="pp-filter pp-filter-active" data-filter="all">全部 <em><?php echo (int) $counts['all']; ?></em></button>
            <button class="pp-filter" data-filter="watching">👀 觀看中 <em><?php echo (int) $counts['watching']; ?></em></button>
            <button class="pp-filter" data-filter="completed">✅ 已完結 <em><?php echo (int) $counts['completed']; ?></em></button>
            <button class="pp-filter" data-filter="favorited">❤️ 收藏 <em><?php echo (int) $counts['favorited']; ?></em></button>
            <button class="pp-filter" data-filter="want">📌 想看 <em><?php echo (int) $counts['want']; ?></em></button>
        </div>
        <div class="pp-anime-grid">
            <?php foreach ( $watchlist as $item ) {
                $pid = is_array( $item ) ? ( $item['post_id'] ?? 0 ) : (int) $item;
                if ( $pid ) smacg_pp_render_anime_card( $pid, $item );
            } ?>
        </div>
    </section>
    <?php
}
endif;

/* ========================================================================
 * Ratings Tab — 完全保留原本 v1.2.1 邏輯
 * ====================================================================== */

if ( ! function_exists( 'smacg_pp_render_ratings' ) ) :
function smacg_pp_render_ratings( $ratings ) {
    if ( empty( $ratings ) ) {
        echo '<section class="pp-section"><p class="pp-empty">尚未評分任何作品</p></section>';
        return;
    }
    usort( $ratings, function( $a, $b ) {
        $sa = (float) ( $a['overall_score'] ?? 0 );
        $sb = (float) ( $b['overall_score'] ?? 0 );
        return $sb <=> $sa;
    } );
    ?>
    <section class="pp-section pp-ratings">
        <p class="pp-section-info">共評分 <strong><?php echo count( $ratings ); ?></strong> 部作品</p>
        <div class="pp-anime-grid">
            <?php foreach ( $ratings as $r ) {
                $pid = (int) ( $r['post_id'] ?? $r['anime_id'] ?? 0 );
                if ( $pid ) smacg_pp_render_anime_card( $pid, $r );
            } ?>
        </div>
    </section>
    <?php
}
endif;

/* ========================================================================
 * Badges Tab — 完全保留原本 v1.2.1 邏輯
 * ====================================================================== */

if ( ! function_exists( 'smacg_pp_render_badges' ) ) :
function smacg_pp_render_badges( $uid, $stats ) {
    $badges = [];
    $completed   = (int) ( $stats['completed'] ?? 0 );
    $rated_count = (int) ( $stats['rated_count'] ?? 0 );
    $watch_days  = (int) ( $stats['watch_days'] ?? 0 );
    $favorites   = (int) ( $stats['favorites'] ?? 0 );

    if ( $completed >= 1 )   $badges[] = [ '🎬', '初心者', '完結第一部作品' ];
    if ( $completed >= 10 )  $badges[] = [ '📺', '觀劇達人', '完結 10 部作品' ];
    if ( $completed >= 50 )  $badges[] = [ '🏆', '資深觀眾', '完結 50 部作品' ];
    if ( $completed >= 100 ) $badges[] = [ '👑', '動畫王者', '完結 100 部作品' ];
    if ( $rated_count >= 10 )  $badges[] = [ '⭐', '評論家', '評分 10 部作品' ];
    if ( $rated_count >= 50 )  $badges[] = [ '🌟', '專業評論', '評分 50 部作品' ];
    if ( $watch_days >= 30 )   $badges[] = [ '📅', '常駐觀眾', '觀看 30 天' ];
    if ( $favorites >= 10 )    $badges[] = [ '❤️', '收藏家', '收藏 10 部作品' ];

    if ( function_exists( 'gamipress_get_user_achievements' ) ) {
        $achievements = gamipress_get_user_achievements( [ 'user_id' => $uid ] );
        if ( $achievements ) {
            foreach ( $achievements as $a ) {
                $badges[] = [ '🏅', get_the_title( $a->ID ), wp_strip_all_tags( get_post_field( 'post_excerpt', $a->ID ) ) ];
            }
        }
    }
    ?>
    <section class="pp-section pp-badges">
        <?php if ( $badges ) : ?>
            <div class="pp-badge-grid">
                <?php foreach ( $badges as $b ) : ?>
                    <div class="pp-badge">
                        <div class="pp-badge-icon"><?php echo esc_html( $b[0] ); ?></div>
                        <div class="pp-badge-name"><?php echo esc_html( $b[1] ); ?></div>
                        <div class="pp-badge-desc"><?php echo esc_html( $b[2] ); ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else : ?>
            <p class="pp-empty">尚未獲得任何徽章</p>
        <?php endif; ?>
    </section>
    <?php
}
endif;

/* ========================================================================
 * Activity Tab — v1.2.3 重寫：補上作品名稱、根據 type 組句、分顏色
 * ====================================================================== */

if ( ! function_exists( 'smacg_pp_render_activity' ) ) :
function smacg_pp_render_activity( $activity ) {
    if ( empty( $activity ) ) {
        echo '<section class="pp-section"><p class="pp-timeline-empty">📡 尚無近期活動</p></section>';
        return;
    }
    ?>
    <section class="pp-section pp-activity">
        <ul class="pp-timeline">
            <?php foreach ( $activity as $ev ) :
                $type    = $ev['type']    ?? '';
                $subtype = $ev['subtype'] ?? '';
                $icon    = $ev['icon']    ?? '📝';
                $action  = $ev['title']   ?? '';     // 動作：看完了 / 評分 / 留言 ...
                $meta    = $ev['meta']    ?? '';
                $color   = $ev['color']   ?? '';
                $url     = $ev['url']     ?? ( $ev['link'] ?? '' );
                $pid     = (int) ( $ev['post_id'] ?? 0 );

                /* 取得作品名稱 */
                $post_title = '';
                if ( $pid && get_post_status( $pid ) === 'publish' ) {
                    $post_title = get_the_title( $pid );
                }

                /* 組合句型 */
                $headline_html = '';
                if ( $post_title ) {
                    $work_html = '<span class="pp-timeline-work">《' . esc_html( $post_title ) . '》</span>';
                    switch ( $type ) {
                        case 'comment':
                            $headline_html = '於 ' . $work_html . ' 留言';
                            break;
                        case 'rating':
                            $headline_html = '評分 ' . $work_html;
                            break;
                        case 'watchlist':
                            $headline_html = esc_html( $action ) . ' ' . $work_html;
                            break;
                        default:
                            $headline_html = esc_html( $action ) . ' ' . $work_html;
                    }
                } else {
                    /* 沒有作品（例如 points 變動） */
                    $headline_html = esc_html( $action );
                }

                /* meta 樣式分流 */
                $meta_class = 'pp-timeline-meta';
                if ( $type === 'rating' ) {
                    $meta_class .= ' pp-timeline-meta--rating';
                } elseif ( $type === 'points' ) {
                    $meta_class .= $subtype === 'gain' ? ' pp-timeline-meta--points-gain' : ' pp-timeline-meta--points-loss';
                }

                /* 時間 */
                $diff = '';
                if ( ! empty( $ev['time_human'] ) ) {
                    $diff = (string) $ev['time_human'];
                } else {
                    $ts = (int) ( $ev['time'] ?? $ev['timestamp'] ?? 0 );
                    if ( $ts ) {
                        $diff = human_time_diff( $ts, current_time( 'timestamp' ) ) . '前';
                    }
                }

                /* li class */
                $li_class = 'pp-timeline-item';
                if ( $type )    $li_class .= ' pp-timeline-item--' . sanitize_html_class( $type );
                if ( $subtype ) $li_class .= ' pp-timeline-item--' . sanitize_html_class( $subtype );
            ?>
                <li class="<?php echo esc_attr( $li_class ); ?>">
                    <span class="pp-timeline-icon"><?php echo esc_html( $icon ); ?></span>
                    <div class="pp-timeline-body">
                        <div class="pp-timeline-title">
                            <?php if ( $url ) : ?>
                                <a href="<?php echo esc_url( $url ); ?>"><?php echo $headline_html; // 已逐段 esc ?></a>
                            <?php else : ?>
                                <?php echo $headline_html; // 已逐段 esc ?>
                            <?php endif; ?>
                        </div>
                        <?php if ( $meta ) : ?>
                            <div class="<?php echo esc_attr( $meta_class ); ?>"><?php echo esc_html( $meta ); ?></div>
                        <?php endif; ?>
                        <?php if ( $diff ) : ?>
                            <div class="pp-timeline-time"><?php echo esc_html( $diff ); ?></div>
                        <?php endif; ?>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
    </section>
    <?php
}
endif;

/* ========================================================================
 * 共用 Anime 卡片 — 完全保留原本 v1.2.1 邏輯
 * ====================================================================== */

if ( ! function_exists( 'smacg_pp_render_anime_card' ) ) :
function smacg_pp_render_anime_card( $pid, $extra = [] ) {
    $pid = (int) $pid;
    if ( ! $pid ) return;
    $post = get_post( $pid );
    if ( ! $post || $post->post_status !== 'publish' ) return;

    $title = get_the_title( $pid );
    $url   = get_permalink( $pid );
    $thumb = '';
    if ( has_post_thumbnail( $pid ) ) {
        $thumb = get_the_post_thumbnail_url( $pid, 'weixiaoacg-cover' );
    }
    if ( ! $thumb && function_exists( 'weixiaoacg_acf' ) ) {
        $thumb = weixiaoacg_acf( 'cover_image', $pid );
    }
    if ( ! $thumb ) {
        $thumb = get_stylesheet_directory_uri() . '/assets/images/placeholder.svg';
    }

    $status = is_array( $extra ) ? ( $extra['status'] ?? '' ) : '';

    $is_favorite = false;
    if ( is_array( $extra ) ) {
        $is_favorite = ! empty( $extra['favorited'] ) || ! empty( $extra['favorite'] );
    }

    $score      = is_array( $extra ) ? (float) ( $extra['overall_score'] ?? 0 ) : 0;
    $watched_ep = is_array( $extra ) ? (int) ( $extra['watched_ep'] ?? 0 ) : 0;
    $total_ep   = is_array( $extra ) ? (int) ( $extra['total_ep'] ?? 0 ) : 0;

    $status_labels = [
        'watching'  => '👀 觀看中',
        'completed' => '✅ 已完結',
        'favorited' => '❤️ 收藏',
        'want'      => '📌 想看',
        'paused'    => '⏸ 暫停',
        'dropped'   => '⛔ 棄追',
    ];
    $status_label = $status_labels[ $status ] ?? '';
    ?>
    <article class="pp-anime-card"
             data-status="<?php echo esc_attr( $status ); ?>"
             data-favorited="<?php echo $is_favorite ? '1' : '0'; ?>">
        <a class="pp-anime-thumb" href="<?php echo esc_url( $url ); ?>">
            <img src="<?php echo esc_url( $thumb ); ?>" alt="<?php echo esc_attr( $title ); ?>" loading="lazy" decoding="async">
            <?php if ( $is_favorite ) : ?>
                <span class="pp-anime-fav">❤️</span>
            <?php endif; ?>
            <?php if ( $status_label ) : ?>
                <span class="pp-anime-status"><?php echo esc_html( $status_label ); ?></span>
            <?php endif; ?>
        </a>
        <div class="pp-anime-info">
            <h3 class="pp-anime-title"><a href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $title ); ?></a></h3>
            <?php if ( $score > 0 ) : ?>
                <div class="pp-anime-score">⭐ <?php echo esc_html( number_format( $score, 1 ) ); ?></div>
            <?php endif; ?>
            <?php if ( $status === 'watching' && $total_ep > 0 ) :
                $percent = min( 100, round( $watched_ep / $total_ep * 100 ) );
            ?>
                <div class="pp-anime-progress">
                    <div class="pp-anime-progress-bar"><div class="pp-anime-progress-fill" style="width:<?php echo (float) $percent; ?>%"></div></div>
                    <div class="pp-anime-progress-text"><?php echo (int) $watched_ep; ?> / <?php echo (int) $total_ep; ?></div>
                </div>
            <?php endif; ?>
        </div>
    </article>
    <?php
}
endif;

/* ========================================================================
 * Reviews Tab — 這位會員發表過的評論
 *
 * 資料來自 anime-sync-pro 的 wxacg_review：post_parent 指向被評論的
 * 目標（動漫／漫畫／新聞／角色／人物載體），因此這裡只依作者撈取，
 * 再各自連回目標頁。回覆（有 _wxacg_review_reply_to）不列入，
 * 個人頁要呈現的是「這個人發表的評論」，不是他在別人串下的回話。
 * ====================================================================== */

if ( ! function_exists( 'smacg_pp_render_reviews' ) ) :
function smacg_pp_render_reviews( $uid ) {
    $uid = (int) $uid;

    if ( ! post_type_exists( 'wxacg_review' ) ) {
        echo '<section class="pp-section"><p class="pp-empty">評論功能尚未啟用</p></section>';
        return;
    }

    $reviews = get_posts( [
        'post_type'      => 'wxacg_review',
        'post_status'    => 'publish',
        'author'         => $uid,
        'posts_per_page' => 30,
        'orderby'        => 'date',
        'order'          => 'DESC',
        'no_found_rows'  => true,
        'meta_query'     => [
            [ 'key' => '_wxacg_review_reply_to', 'compare' => 'NOT EXISTS' ],
        ],
    ] );

    if ( empty( $reviews ) ) {
        echo '<section class="pp-section"><p class="pp-empty">尚未發表任何評論</p></section>';
        return;
    }
    ?>
    <section class="pp-section pp-reviews">
        <p class="pp-section-info">共 <strong><?php echo count( $reviews ); ?></strong> 則評論</p>

        <div class="pp-review-list">
            <?php foreach ( $reviews as $rv ) :
                $target_id = (int) $rv->post_parent;
                $target    = $target_id ? get_post( $target_id ) : null;

                // 目標被刪除時仍顯示評論內容，只是沒有連結可去
                $title = '';
                $url   = '';
                if ( $target ) {
                    $title = get_post_meta( $target_id, 'anime_title_chinese', true ) ?: get_the_title( $target_id );
                    $url   = get_permalink( $target_id );
                }

                $track   = get_post_meta( $rv->ID, '_wxacg_review_track', true ) ?: 'short';
                $episode = (int) get_post_meta( $rv->ID, '_wxacg_review_episode', true );
                $spoiler = (bool) get_post_meta( $rv->ID, '_wxacg_review_spoiler', true );

                $excerpt = wp_strip_all_tags( $rv->post_content );
                if ( mb_strlen( $excerpt ) > 140 ) {
                    $excerpt = mb_substr( $excerpt, 0, 140 ) . '…';
                }
                ?>
                <article class="pp-review-item">
                    <div class="pp-review-head">
                        <?php if ( $url ) : ?>
                            <a class="pp-review-target" href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $title ); ?></a>
                        <?php else : ?>
                            <span class="pp-review-target pp-review-target--gone">（作品已移除）</span>
                        <?php endif; ?>

                        <?php if ( $track === 'long' ) : ?>
                            <span class="pp-review-tag">長評</span>
                        <?php endif; ?>
                        <?php if ( $episode > 0 ) : ?>
                            <span class="pp-review-tag">第 <?php echo $episode; ?> 集</span>
                        <?php endif; ?>
                        <?php if ( $spoiler ) : ?>
                            <span class="pp-review-tag pp-review-tag--spoiler">含雷</span>
                        <?php endif; ?>

                        <time class="pp-review-date" datetime="<?php echo esc_attr( get_the_date( 'c', $rv ) ); ?>">
                            <?php echo esc_html( get_the_date( 'Y-m-d', $rv ) ); ?>
                        </time>
                    </div>

                    <?php if ( $track === 'long' && $rv->post_title ) : ?>
                        <h4 class="pp-review-title"><?php echo esc_html( $rv->post_title ); ?></h4>
                    <?php endif; ?>

                    <p class="pp-review-text<?php echo $spoiler ? ' is-spoiler' : ''; ?>">
                        <?php echo esc_html( $excerpt ); ?>
                    </p>
                </article>
            <?php endforeach; ?>
        </div>
    </section>
    <?php
}
endif;

/* 評論分頁樣式 */
if ( ! function_exists( 'smacg_pp_reviews_css' ) ) :
function smacg_pp_reviews_css() {
    if ( ! is_page_template( 'page-public-profile.php' ) && ! get_query_var( 'smacg_pp_user' ) ) {
        return;
    }
    ?>
    <style>
    .pp-review-list { display: flex; flex-direction: column; gap: 10px; }
    .pp-review-item {
        background: rgba(255,255,255,.03);
        border: 1px solid rgba(255,255,255,.08);
        border-radius: 10px;
        padding: 12px 14px;
    }
    .pp-review-head {
        display: flex; align-items: center; gap: 8px;
        flex-wrap: wrap; margin-bottom: 6px;
    }
    .pp-review-target { color: #63a8ff; font-weight: 600; text-decoration: none; font-size: 14px; }
    .pp-review-target:hover { text-decoration: underline; }
    .pp-review-target--gone { color: #888; font-style: italic; }
    .pp-review-tag {
        background: rgba(255,255,255,.08);
        border-radius: 4px; color: #bbb;
        font-size: 11px; padding: 2px 6px;
    }
    .pp-review-tag--spoiler { background: rgba(214,54,56,.18); color: #ff8a8c; }
    .pp-review-date { color: #888; font-size: 11px; margin-left: auto; }
    .pp-review-title { font-size: 14px; margin: 0 0 4px; }
    .pp-review-text { color: #ccc; font-size: 13px; line-height: 1.7; margin: 0; }
    .pp-review-text.is-spoiler { filter: blur(4px); transition: filter .15s; cursor: pointer; }
    .pp-review-text.is-spoiler:hover { filter: none; }
    </style>
    <?php
}
add_action( 'wp_head', 'smacg_pp_reviews_css', 20 );
endif;
