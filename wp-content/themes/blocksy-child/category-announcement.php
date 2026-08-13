<?php
/**
 * Category: Announcement 公告列表頁
 *
 * @package blocksy-child
 * @version 1.1.0  2026-05-26
 *
 * 由 class-editorial-routing.php v3.2 的 template_include filter 載入。
 * 路徑：/announcement/ 與 /announcement/page/{n}/
 *
 * 變更紀錄：
 *  - v1.1.0 (2026-05-26)：
 *    修正深色背景下文字被父主題 `#192A3D` 等規則覆蓋的問題。
 *    - .widget-empty / .ann-empty / .ann-excerpt / .ann-subscribe-widget p
 *      皆加上 !important 強制白色系
 *    - 新增「通用兜底」區塊，讓 .announcement-page 內所有 <p>/<li>/<span>
 *      繼承父層白色，不被外部 p { color:#192A3D } 蓋掉
 *  - v1.0.0 (2026-05-26)：初版
 *
 * 結構：
 *   1. Hero（標題 + 訂閱 RSS）
 *   2. 置頂區（sticky posts in announcement category）
 *   3. 時間排序列表（公告流，每則顯示日期/標題/摘要）
 *   4. 分頁
 *   5. 側欄（最新動態 + 常見問題置頂）
 */

if ( ! defined( 'ABSPATH' ) ) exit;

get_header();

/* ====== Query 1：置頂公告（sticky）====== */
$sticky_ids = get_option( 'sticky_posts', [] );
$sticky_query = null;
if ( ! empty( $sticky_ids ) ) {
    $sticky_query = new WP_Query([
        'post_type'           => 'post',
        'post__in'            => $sticky_ids,
        'category_name'       => 'announcement',
        'posts_per_page'      => -1,
        'ignore_sticky_posts' => true,
        'orderby'             => 'date',
        'order'               => 'DESC',
        'no_found_rows'       => true,
    ]);
}

/* ====== Query 2：一般公告（排除置頂）====== */
$paged = max( 1, get_query_var( 'paged' ) ?: get_query_var( 'page' ) ?: 1 );
$main_query = new WP_Query([
    'post_type'      => 'post',
    'category_name'  => 'announcement',
    'posts_per_page' => 15,
    'paged'          => $paged,
    'post__not_in'   => $sticky_ids,
    'orderby'        => 'date',
    'order'          => 'DESC',
]);

/* ====== Query 3：側欄 — 近期常見問題（tag: faq）====== */
$faq_query = new WP_Query([
    'post_type'      => 'post',
    'category_name'  => 'announcement',
    'posts_per_page' => 5,
    'no_found_rows'  => true,
    'tag'            => 'faq',
]);

/**
 * 渲染單則公告（列表項目）
 */
function smacg_render_announcement_item( $is_sticky = false ) {
    $post_id   = get_the_ID();
    $permalink = get_permalink();
    $title     = get_the_title();
    $date      = get_the_date( 'Y-m-d' );
    $time      = get_the_date( 'H:i' );
    $excerpt   = wp_trim_words( get_the_excerpt(), 60, '…' );
    ?>
    <article class="ann-item <?php echo $is_sticky ? 'is-sticky' : ''; ?>">
        <div class="ann-meta">
            <span class="ann-date"><?php echo esc_html( $date ); ?></span>
            <span class="ann-time"><?php echo esc_html( $time ); ?></span>
            <?php if ( $is_sticky ) : ?>
                <span class="ann-badge sticky-badge">📌 置頂</span>
            <?php endif; ?>
        </div>
        <h2 class="ann-title">
            <a href="<?php echo esc_url( $permalink ); ?>"><?php echo esc_html( $title ); ?></a>
        </h2>
        <?php if ( $excerpt ) : ?>
            <p class="ann-excerpt"><?php echo esc_html( $excerpt ); ?></p>
        <?php endif; ?>
        <a href="<?php echo esc_url( $permalink ); ?>" class="ann-more">閱讀全文 →</a>
    </article>
    <?php
}
?>

<div class="announcement-page">

    <!-- Hero -->
    <header class="ann-hero">
        <div class="ann-hero-inner">
            <h1 class="ann-hero-title">
                <span class="hero-icon">📢</span>站務公告
            </h1>
            <p class="ann-hero-desc">網站維護通知、規則異動、新功能上線等重要訊息</p>
            <a href="<?php echo esc_url( get_post_type_archive_feed_link( 'post' ) ); ?>"
               class="ann-rss-btn"
               title="訂閱 RSS">
                <span aria-hidden="true">📡</span> 訂閱 RSS
            </a>
        </div>
    </header>

    <div class="ann-layout">

        <!-- 主內容 -->
        <main class="ann-main">

            <!-- 置頂區 -->
            <?php if ( $sticky_query && $sticky_query->have_posts() ) : ?>
                <section class="ann-section ann-sticky-section">
                    <h2 class="ann-section-title">
                        <span class="section-icon">📌</span>置頂公告
                    </h2>
                    <div class="ann-list">
                        <?php while ( $sticky_query->have_posts() ) : $sticky_query->the_post();
                            smacg_render_announcement_item( true );
                        endwhile; wp_reset_postdata(); ?>
                    </div>
                </section>
            <?php endif; ?>

            <!-- 一般公告 -->
            <section class="ann-section ann-list-section">
                <h2 class="ann-section-title">
                    <span class="section-icon">🗞</span>最新公告
                </h2>

                <?php if ( $main_query->have_posts() ) : ?>
                    <div class="ann-list">
                        <?php while ( $main_query->have_posts() ) : $main_query->the_post();
                            smacg_render_announcement_item( false );
                        endwhile; ?>
                    </div>

                    <!-- 分頁 -->
                    <nav class="ann-pagination" aria-label="公告分頁">
                        <?php
                        echo paginate_links([
                            'total'     => $main_query->max_num_pages,
                            'current'   => $paged,
                            'mid_size'  => 2,
                            'prev_text' => '← 上一頁',
                            'next_text' => '下一頁 →',
                            'type'      => 'list',
                            'base'      => home_url( '/announcement/page/%#%/' ),
                            'format'    => '',
                        ]);
                        ?>
                    </nav>

                    <?php wp_reset_postdata(); ?>
                <?php else : ?>
                    <p class="ann-empty">目前沒有公告</p>
                <?php endif; ?>
            </section>

        </main>

        <!-- 側欄 -->
        <aside class="ann-sidebar">

            <div class="ann-widget">
                <h3 class="widget-title">📚 常見問題</h3>
                <?php if ( $faq_query->have_posts() ) : ?>
                    <ul class="widget-list">
                        <?php while ( $faq_query->have_posts() ) : $faq_query->the_post(); ?>
                            <li>
                                <a href="<?php the_permalink(); ?>"><?php the_title(); ?></a>
                            </li>
                        <?php endwhile; wp_reset_postdata(); ?>
                    </ul>
                <?php else : ?>
                    <p class="widget-empty">尚無常見問題</p>
                <?php endif; ?>
            </div>

            <div class="ann-widget">
                <h3 class="widget-title">🔗 快速連結</h3>
                <ul class="widget-list">
                    <li><a href="<?php echo esc_url( home_url( '/level-guide/' ) ); ?>">等級制度說明</a></li>
                    <li><a href="<?php echo esc_url( home_url( '/disclaimer/' ) ); ?>">免責聲明</a></li>
                    <li><a href="<?php echo esc_url( home_url( '/privacy-policy/' ) ); ?>">隱私政策</a></li>
                    <li><a href="<?php echo esc_url( home_url( '/contact/' ) ); ?>">聯絡我們</a></li>
                </ul>
            </div>

            <div class="ann-widget ann-subscribe-widget">
                <h3 class="widget-title">📡 訂閱通知</h3>
                <p>登入帳號可在會員中心開啟公告 Email 通知，重要訊息不錯過。</p>
                <?php if ( is_user_logged_in() ) : ?>
                    <a href="<?php echo esc_url( home_url( '/mc/?tab=notifications' ) ); ?>"
                       class="widget-btn">前往通知設定</a>
                <?php else : ?>
                    <a href="<?php echo esc_url( wp_login_url( get_permalink() ) ); ?>"
                       class="widget-btn">登入</a>
                <?php endif; ?>
            </div>

        </aside>

    </div>

</div>

<style>
/* ===== Announcement Page (v1.1.0) ===== */

/* ─────────────────────────────────────────
   v1.1.0 通用兜底：強制覆蓋父主題寫死的 #192A3D 等深色
   ───────────────────────────────────────── */
.announcement-page {
    max-width: 1280px;
    margin: 0 auto;
    padding: 20px 16px 60px;
    color: rgba(255,255,255,0.85) !important;
}
.announcement-page p,
.announcement-page li,
.announcement-page span:not(.ann-badge):not(.hero-icon):not(.section-icon):not(.ann-date):not(.ann-time) {
    color: inherit;
}

/* Hero */
.ann-hero {
    background: linear-gradient(135deg, #1a1a2e 0%, #16213e 50%, #0f3460 100%);
    border-radius: 16px;
    padding: 40px 32px;
    margin-bottom: 32px;
    border: 1px solid rgba(255,255,255,0.06);
    position: relative;
    overflow: hidden;
}
.ann-hero-title {
    font-size: 2.2rem;
    margin: 0 0 8px;
    color: #fff !important;
    display: flex;
    align-items: center;
    gap: 12px;
}
.hero-icon { font-size: 2rem; }
.ann-hero-desc {
    color: rgba(255,255,255,0.7) !important;
    margin: 0 0 20px;
    font-size: 1rem;
}
.ann-rss-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 16px;
    background: rgba(255,165,0,0.15);
    border: 1px solid rgba(255,165,0,0.4);
    color: #ffa500 !important;
    border-radius: 8px;
    text-decoration: none;
    font-size: 0.9rem;
    transition: all .2s;
}
.ann-rss-btn:hover {
    background: rgba(255,165,0,0.25);
    transform: translateY(-1px);
}

/* Layout */
.ann-layout {
    display: grid;
    grid-template-columns: 1fr 300px;
    gap: 32px;
}
@media (max-width: 900px) {
    .ann-layout { grid-template-columns: 1fr; }
}

/* Section */
.ann-section { margin-bottom: 40px; }
.ann-section-title {
    font-size: 1.4rem;
    margin: 0 0 16px;
    padding-bottom: 10px;
    border-bottom: 2px solid rgba(255,255,255,0.08);
    color: #fff !important;
    display: flex;
    align-items: center;
    gap: 8px;
}
.section-icon { font-size: 1.2rem; }

/* List */
.ann-list {
    display: flex;
    flex-direction: column;
    gap: 16px;
}
.ann-item {
    background: rgba(255,255,255,0.03);
    border: 1px solid rgba(255,255,255,0.06);
    border-radius: 12px;
    padding: 20px 24px;
    transition: all .2s;
}
.ann-item:hover {
    background: rgba(255,255,255,0.05);
    border-color: rgba(255,255,255,0.12);
    transform: translateY(-2px);
}
.ann-item.is-sticky {
    background: linear-gradient(135deg, rgba(255,165,0,0.08) 0%, rgba(255,255,255,0.03) 100%);
    border-color: rgba(255,165,0,0.25);
}
.ann-meta {
    display: flex;
    align-items: center;
    gap: 10px;
    font-size: 0.85rem;
    color: rgba(255,255,255,0.55) !important;
    margin-bottom: 8px;
}
.ann-date { font-weight: 600; }
.ann-badge {
    padding: 2px 8px;
    border-radius: 4px;
    font-size: 0.75rem;
    background: rgba(255,165,0,0.2);
    color: #ffa500 !important;
    border: 1px solid rgba(255,165,0,0.35);
}
.ann-title {
    font-size: 1.2rem;
    margin: 0 0 10px;
    line-height: 1.45;
}
.ann-title a {
    color: #fff !important;
    text-decoration: none;
    transition: color .15s;
}
.ann-title a:hover { color: #ffa500 !important; }
.ann-excerpt {
    color: rgba(255,255,255,0.75) !important;
    line-height: 1.7;
    margin: 0 0 10px;
    font-size: 0.95rem;
}
.ann-more {
    display: inline-block;
    color: #6ec1e4 !important;
    text-decoration: none;
    font-size: 0.9rem;
    font-weight: 500;
}
.ann-more:hover {
    text-decoration: underline;
    color: #ffa500 !important;
}

/* Pagination */
.ann-pagination {
    margin-top: 32px;
    display: flex;
    justify-content: center;
}
.ann-pagination .page-numbers {
    display: flex;
    gap: 8px;
    list-style: none;
    padding: 0;
    margin: 0;
}
.ann-pagination .page-numbers li > * {
    padding: 8px 14px;
    border-radius: 8px;
    background: rgba(255,255,255,0.05);
    color: rgba(255,255,255,0.75) !important;
    text-decoration: none;
    font-size: 0.9rem;
}
.ann-pagination .page-numbers li > .current {
    background: #ffa500;
    color: #000 !important;
    font-weight: 700;
}
.ann-pagination .page-numbers li > a:hover {
    background: rgba(255,255,255,0.12);
    color: #fff !important;
}

.ann-empty {
    text-align: center;
    color: rgba(255,255,255,0.55) !important;
    padding: 40px;
    background: rgba(255,255,255,0.03);
    border-radius: 12px;
}

/* Sidebar Widget */
.ann-widget {
    background: rgba(255,255,255,0.03);
    border: 1px solid rgba(255,255,255,0.06);
    border-radius: 12px;
    padding: 20px;
    margin-bottom: 20px;
}
.widget-title {
    font-size: 1rem;
    margin: 0 0 14px;
    padding-bottom: 10px;
    border-bottom: 1px solid rgba(255,255,255,0.08);
    color: #fff !important;
}
.widget-list {
    list-style: none;
    padding: 0;
    margin: 0;
}
.widget-list li {
    padding: 8px 0;
    border-bottom: 1px dashed rgba(255,255,255,0.06);
}
.widget-list li:last-child { border-bottom: none; }
.widget-list a {
    color: rgba(255,255,255,0.75) !important;
    text-decoration: none;
    font-size: 0.92rem;
    transition: color .15s;
}
.widget-list a:hover { color: #ffa500 !important; }
.widget-empty {
    color: rgba(255,255,255,0.55) !important;
    font-size: 0.9rem;
    margin: 0;
    text-align: center;
    padding: 8px 0;
}
.ann-subscribe-widget p {
    color: rgba(255,255,255,0.7) !important;
    font-size: 0.88rem;
    line-height: 1.6;
    margin: 0 0 12px;
}
.widget-btn {
    display: block;
    text-align: center;
    padding: 10px 16px;
    background: #ffa500;
    color: #000 !important;
    border-radius: 8px;
    text-decoration: none;
    font-weight: 600;
    transition: transform .15s;
}
.widget-btn:hover {
    transform: translateY(-1px);
    color: #000 !important;
}
</style>

<?php get_footer(); ?>
