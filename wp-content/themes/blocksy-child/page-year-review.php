<?php
/**
 * Template Name: 年度回顧
 *
 * 個人年度觀影回顧頁面（類 Spotify Wrapped）
 * 訪問 /year-review/ 或 /year-review/?year=2026
 *
 * @package Blocksy_Child
 * @version 1.0.3
 * @since   2026-05-13
 *
 * Changelog:
 * - v1.0.3 (2026-05-15)：
 *   1. 年份切換器最小年份改為 2026（網站正式上線年），不再顯示 2025/2024/2022。
 *   2. $year 參數下限同步改為 2026。
 * - v1.0.2 (2026-05-15)：
 *   移除 require_once '/inc/member-stats.php'（Phase 3 已遷移至 wxacg-members 外掛，
 *   由 plugins_loaded 自動載入）。加上 function_exists 防呆，避免外掛停用時 fatal error。
 * - v1.0.1 (2026-05-13)：
 *   兩處「回會員中心」按鈕改用 wxacg_get_member_center_url()，
 *   動態解析會員中心頁面 URL（例如 /mc/），不再寫死 /member/。
 * - v1.0.0 (2026-05-13)：初版
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/* ───── 強制登入 ───── */
if ( ! is_user_logged_in() ) {
    wp_safe_redirect( wp_login_url( get_permalink() ) );
    exit;
}

/* ───── v1.0.2：函式存在性檢查（外掛停用防呆）───── */
if ( ! function_exists( 'smacg_calc_year_review' ) ) {
    get_header();
    ?>
    <div class="yr-wrap">
        <section class="yr-section yr-empty">
            <div class="yr-empty-icon">⚠️</div>
            <h2>年度回顧暫時無法使用</h2>
            <p>請聯絡管理員啟用 <code>wxacg-members</code> 外掛。</p>
            <a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="yr-btn">回首頁</a>
        </section>
    </div>
    <?php
    get_footer();
    return;
}

/* ───── 參數處理 ───── */
$uid          = get_current_user_id();
$current_year = (int) date( 'Y' );

// v1.0.3：年份下限改為 2026（網站正式上線年）
$min_year = 2026;
$year     = isset( $_GET['year'] )
            ? max( $min_year, min( $current_year, (int) $_GET['year'] ) )
            : $current_year;

$user = wp_get_current_user();
$data = smacg_calc_year_review( $uid, $year );

/* v1.0.1：動態取得會員中心 URL（page-member.php 模板的頁面，例如 /mc/） */
$mc_url = function_exists( 'wxacg_get_member_center_url' )
          ? wxacg_get_member_center_url()
          : home_url( '/' );

get_header();
?>
<div class="yr-wrap" data-year="<?php echo esc_attr( $year ); ?>">

    <?php /* ───── Hero 封面 ───── */ ?>
    <section class="yr-section yr-hero" data-anim="fade">
        <div class="yr-hero-bg"></div>
        <div class="yr-hero-content">
            <div class="yr-hero-year"><?php echo esc_html( $year ); ?></div>
            <h1 class="yr-hero-title">
                <?php echo esc_html( $user->display_name ); ?> 的<br>
                年度動畫回顧
            </h1>
            <p class="yr-hero-sub">這一年，你和動畫的故事 ✨</p>

            <?php /* 年份切換（v1.0.3：只列 2026 ~ 當年） */ ?>
            <?php if ( $current_year >= $min_year ) : ?>
                <div class="yr-year-switch">
                    <?php
                    for ( $y = $current_year; $y >= $min_year; $y-- ) :
                        $active = ( $y === $year ) ? ' is-active' : '';
                        ?>
                        <a href="<?php echo esc_url( add_query_arg( 'year', $y, get_permalink() ) ); ?>"
                           class="yr-year-pill<?php echo $active; ?>"><?php echo $y; ?></a>
                    <?php endfor; ?>
                </div>
            <?php endif; ?>

            <div class="yr-hero-scroll">↓ 往下捲動開始 ↓</div>
        </div>
    </section>

    <?php if ( $data['total_completed'] === 0 && $data['total_watching'] === 0 ) : ?>

        <?php /* 無資料 */ ?>
        <section class="yr-section yr-empty" data-anim="fade">
            <div class="yr-empty-icon">🌱</div>
            <h2><?php echo esc_html( $year ); ?> 年還沒有觀影紀錄</h2>
            <p>開始追番後，這裡會自動產生你的年度回顧。</p>
            <a href="<?php echo esc_url( $mc_url ); ?>" class="yr-btn">回到會員中心</a>
        </section>

    <?php else : ?>

        <?php /* ───── 總覽數字 ───── */ ?>
        <section class="yr-section yr-overview" data-anim="up">
            <h2 class="yr-section-title">今年的你 📊</h2>
            <div class="yr-stat-grid">
                <div class="yr-stat-card">
                    <div class="yr-stat-num" data-count="<?php echo (int) $data['total_completed']; ?>">0</div>
                    <div class="yr-stat-label">部看完</div>
                </div>
                <div class="yr-stat-card">
                    <div class="yr-stat-num" data-count="<?php echo (int) $data['total_episodes']; ?>">0</div>
                    <div class="yr-stat-label">集觀看</div>
                </div>
                <div class="yr-stat-card">
                    <div class="yr-stat-num" data-count="<?php echo (int) $data['total_hours']; ?>">0</div>
                    <div class="yr-stat-label">小時投入</div>
                </div>
                <div class="yr-stat-card">
                    <div class="yr-stat-num" data-count="<?php echo (int) $data['total_rated']; ?>">0</div>
                    <div class="yr-stat-label">部評分</div>
                </div>
            </div>

            <?php if ( $data['total_days'] >= 1 ) : ?>
                <p class="yr-overview-fun">
                    換算下來，你花了
                    <strong><?php echo number_format( $data['total_days'], 1 ); ?> 天</strong>
                    在動畫的世界 🌌
                </p>
            <?php endif; ?>
        </section>

        <?php /* ───── 月份分布 ───── */ ?>
        <?php if ( ! empty( $data['monthly'] ) ) : ?>
            <section class="yr-section yr-monthly" data-anim="up">
                <h2 class="yr-section-title">每月觀影熱度 🔥</h2>
                <div class="yr-month-chart">
                    <?php
                    $max_month = max( $data['monthly'] );
                    $max_month = $max_month > 0 ? $max_month : 1;
                    $month_names = array( '1', '2', '3', '4', '5', '6', '7', '8', '9', '10', '11', '12' );
                    foreach ( $month_names as $i => $name ) :
                        $count = isset( $data['monthly'][ $i + 1 ] ) ? $data['monthly'][ $i + 1 ] : 0;
                        $pct   = ( $count / $max_month ) * 100;
                        ?>
                        <div class="yr-month-col" title="<?php echo (int) $count; ?> 部">
                            <div class="yr-month-bar-wrap">
                                <div class="yr-month-bar" style="--h:<?php echo $pct; ?>%">
                                    <span class="yr-month-num"><?php echo (int) $count; ?></span>
                                </div>
                            </div>
                            <div class="yr-month-label"><?php echo $name; ?>月</div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php if ( ! empty( $data['peak_month'] ) ) : ?>
                    <p class="yr-monthly-peak">
                        <strong><?php echo (int) $data['peak_month']; ?> 月</strong>
                        是你最瘋狂的月份，看了
                        <strong><?php echo (int) $data['peak_month_count']; ?> 部</strong> 🎉
                    </p>
                <?php endif; ?>
            </section>
        <?php endif; ?>

        <?php /* ───── Top 類型 ───── */ ?>
        <?php if ( ! empty( $data['top_genres'] ) ) : ?>
            <section class="yr-section yr-top yr-top-genres" data-anim="up">
                <h2 class="yr-section-title">你最愛的類型 ❤️</h2>
                <ol class="yr-top-list">
                    <?php foreach ( $data['top_genres'] as $i => $g ) : ?>
                        <li class="yr-top-item">
                            <span class="yr-top-rank"><?php echo $i + 1; ?></span>
                            <span class="yr-top-name"><?php echo esc_html( $g['name'] ); ?></span>
                            <span class="yr-top-count"><?php echo (int) $g['count']; ?> 部</span>
                        </li>
                    <?php endforeach; ?>
                </ol>
            </section>
        <?php endif; ?>

        <?php /* ───── Top 工作室 ───── */ ?>
        <?php if ( ! empty( $data['top_studios'] ) ) : ?>
            <section class="yr-section yr-top yr-top-studios" data-anim="up">
                <h2 class="yr-section-title">最常看的工作室 🎬</h2>
                <ol class="yr-top-list">
                    <?php foreach ( $data['top_studios'] as $i => $s ) : ?>
                        <li class="yr-top-item">
                            <span class="yr-top-rank"><?php echo $i + 1; ?></span>
                            <span class="yr-top-name"><?php echo esc_html( $s['name'] ); ?></span>
                            <span class="yr-top-count"><?php echo (int) $s['count']; ?> 部</span>
                        </li>
                    <?php endforeach; ?>
                </ol>
            </section>
        <?php endif; ?>

        <?php /* ───── 最高評分 ───── */ ?>
        <?php if ( ! empty( $data['top_rated'] ) ) : ?>
            <section class="yr-section yr-toprated" data-anim="up">
                <h2 class="yr-section-title">你的年度心頭好 ⭐</h2>
                <div class="yr-toprated-grid">
                    <?php foreach ( $data['top_rated'] as $r ) : ?>
                        <a href="<?php echo esc_url( get_permalink( $r['post_id'] ) ); ?>" class="yr-toprated-card">
                            <div class="yr-toprated-thumb">
                                <?php if ( has_post_thumbnail( $r['post_id'] ) ) : ?>
                                    <?php echo get_the_post_thumbnail( $r['post_id'], 'medium', array( 'loading' => 'lazy' ) ); ?>
                                <?php else : ?>
                                    <div class="yr-toprated-noimg">🎞️</div>
                                <?php endif; ?>
                                <span class="yr-toprated-score"><?php echo number_format( $r['score'], 1 ); ?></span>
                            </div>
                            <div class="yr-toprated-title"><?php echo esc_html( get_the_title( $r['post_id'] ) ); ?></div>
                        </a>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>

        <?php /* ───── 達成徽章 ───── */ ?>
        <?php if ( ! empty( $data['badges'] ) ) : ?>
            <section class="yr-section yr-badges" data-anim="up">
                <h2 class="yr-section-title">本年成就 🏆</h2>
                <div class="yr-badge-grid">
                    <?php foreach ( $data['badges'] as $b ) : ?>
                        <div class="yr-badge">
                            <div class="yr-badge-icon"><?php echo esc_html( $b['icon'] ); ?></div>
                            <div class="yr-badge-name"><?php echo esc_html( $b['name'] ); ?></div>
                            <div class="yr-badge-desc"><?php echo esc_html( $b['desc'] ); ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>

        <?php /* ───── 結尾 / 分享 ───── */ ?>
        <section class="yr-section yr-outro" data-anim="fade">
            <h2 class="yr-section-title">謝謝你的 <?php echo esc_html( $year ); ?> 🎉</h2>
            <p class="yr-outro-text">期待明年繼續一起看番！</p>

            <div class="yr-share">
                <button type="button" class="yr-btn yr-btn-share" data-action="copy">
                    📋 複製分享連結
                </button>
                <a href="<?php echo esc_url( $mc_url ); ?>" class="yr-btn yr-btn-secondary">
                    回會員中心
                </a>
            </div>

            <div class="yr-share-msg" aria-live="polite"></div>
        </section>

    <?php endif; ?>

</div>
<?php
get_footer();
