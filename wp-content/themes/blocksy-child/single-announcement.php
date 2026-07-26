<?php
/**
 * Single: Announcement 公告單篇
 *
 * @package blocksy-child
 * @version 1.0.0  2026-05-26
 *
 * 由 class-editorial-routing.php v3.2 的 template_include filter 載入。
 * 路徑：/announcement/{post-slug}/
 */

if ( ! defined( 'ABSPATH' ) ) exit;

get_header();

while ( have_posts() ) : the_post();

$post_id   = get_the_ID();
$is_sticky = is_sticky( $post_id );
$publish   = get_the_date( 'Y-m-d H:i' );
$modified  = get_the_modified_date( 'Y-m-d H:i' );
$author    = get_the_author();

/* 上一篇 / 下一篇（限定 announcement 分類） */
$prev_post = get_previous_post( true, '', 'category' );
$next_post = get_next_post( true, '', 'category' );

/* 側欄：其他近期公告（排除目前這篇） */
$related_query = new WP_Query([
    'post_type'      => 'post',
    'category_name'  => 'announcement',
    'posts_per_page' => 5,
    'post__not_in'   => [ $post_id ],
    'orderby'        => 'date',
    'order'          => 'DESC',
    'no_found_rows'  => true,
]);
?>

<div class="announcement-single">

    <!-- 麵包屑 -->
    <nav class="ann-breadcrumb" aria-label="麵包屑">
        <a href="<?php echo esc_url( home_url( '/' ) ); ?>">首頁</a>
        <span class="sep">/</span>
        <a href="<?php echo esc_url( home_url( '/announcement/' ) ); ?>">站務公告</a>
        <span class="sep">/</span>
        <span class="current"><?php the_title(); ?></span>
    </nav>

    <div class="ann-single-layout">

        <!-- 主內容 -->
        <main class="ann-single-main">
            <article id="post-<?php echo (int) $post_id; ?>" <?php post_class( 'ann-single-article' ); ?>>

                <header class="ann-single-header">
                    <div class="ann-single-meta-top">
                        <span class="ann-tag">📢 站務公告</span>
                        <?php if ( $is_sticky ) : ?>
                            <span class="ann-tag sticky">📌 置頂</span>
                        <?php endif; ?>
                    </div>

                    <h1 class="ann-single-title"><?php the_title(); ?></h1>

                    <div class="ann-single-meta">
                        <span class="meta-item">
                            <span class="meta-label">發布：</span>
                            <time datetime="<?php echo esc_attr( get_the_date( 'c' ) ); ?>">
                                <?php echo esc_html( $publish ); ?>
                            </time>
                        </span>
                        <?php if ( $modified !== $publish ) : ?>
                            <span class="meta-item">
                                <span class="meta-label">更新：</span>
                                <time datetime="<?php echo esc_attr( get_the_modified_date( 'c' ) ); ?>">
                                    <?php echo esc_html( $modified ); ?>
                                </time>
                            </span>
                        <?php endif; ?>
                        <span class="meta-item">
                            <span class="meta-label">作者：</span><?php echo esc_html( $author ); ?>
                        </span>
                    </div>
                </header>

                <?php if ( has_post_thumbnail() ) : ?>
                    <div class="ann-single-thumb">
                        <?php the_post_thumbnail( 'large', [ 'loading' => 'lazy' ] ); ?>
                    </div>
                <?php endif; ?>

                <div class="ann-single-content">
                    <?php the_content(); ?>
                </div>

                <?php
                $tags = get_the_tags();
                if ( $tags && ! is_wp_error( $tags ) ) : ?>
                    <footer class="ann-single-tags">
                        <span class="tags-label">標籤：</span>
                        <?php foreach ( $tags as $tag ) : ?>
                            <a href="<?php echo esc_url( get_tag_link( $tag ) ); ?>" class="ann-tag-link">
                                #<?php echo esc_html( $tag->name ); ?>
                            </a>
                        <?php endforeach; ?>
                    </footer>
                <?php endif; ?>

                <!-- 分享 -->
                <div class="ann-single-share">
                    <span class="share-label">分享：</span>
                    <a href="https://www.facebook.com/sharer/sharer.php?u=<?php echo urlencode( get_permalink() ); ?>"
                       target="_blank" rel="noopener noreferrer" class="share-btn fb">Facebook</a>
                    <a href="https://twitter.com/intent/tweet?url=<?php echo urlencode( get_permalink() ); ?>&text=<?php echo urlencode( get_the_title() ); ?>"
                       target="_blank" rel="noopener noreferrer" class="share-btn tw">X / Twitter</a>
                    <button type="button" class="share-btn copy-link"
                            data-url="<?php echo esc_attr( get_permalink() ); ?>">複製連結</button>
                </div>
            </article>

            <!-- 上一篇 / 下一篇 -->
            <nav class="ann-single-nav" aria-label="公告導航">
                <?php if ( $prev_post ) : ?>
                    <a class="nav-prev" href="<?php echo esc_url( get_permalink( $prev_post ) ); ?>">
                        <span class="nav-direction">← 上一則</span>
                        <span class="nav-title"><?php echo esc_html( get_the_title( $prev_post ) ); ?></span>
                    </a>
                <?php else : ?>
                    <span class="nav-prev disabled">
                        <span class="nav-direction">← 上一則</span>
                        <span class="nav-title">已是最舊公告</span>
                    </span>
                <?php endif; ?>

                <?php if ( $next_post ) : ?>
                    <a class="nav-next" href="<?php echo esc_url( get_permalink( $next_post ) ); ?>">
                        <span class="nav-direction">下一則 →</span>
                        <span class="nav-title"><?php echo esc_html( get_the_title( $next_post ) ); ?></span>
                    </a>
                <?php else : ?>
                    <span class="nav-next disabled">
                        <span class="nav-direction">下一則 →</span>
                        <span class="nav-title">已是最新公告</span>
                    </span>
                <?php endif; ?>
            </nav>

            <!-- 留言（沿用主題 comments） -->
            <?php if ( comments_open() || get_comments_number() ) : ?>
                <div class="ann-single-comments">
                    <?php comments_template(); ?>
                </div>
            <?php endif; ?>

        </main>

        <!-- 側欄 -->
        <aside class="ann-single-sidebar">

            <div class="ann-widget">
                <h3 class="widget-title">🗞 近期公告</h3>
                <?php if ( $related_query->have_posts() ) : ?>
                    <ul class="widget-list">
                        <?php while ( $related_query->have_posts() ) : $related_query->the_post(); ?>
                            <li>
                                <a href="<?php the_permalink(); ?>">
                                    <span class="widget-date"><?php echo esc_html( get_the_date( 'm/d' ) ); ?></span>
                                    <span class="widget-title-text"><?php the_title(); ?></span>
                                </a>
                            </li>
                        <?php endwhile; wp_reset_postdata(); ?>
                    </ul>
                <?php endif; ?>
                <a href="<?php echo esc_url( home_url( '/announcement/' ) ); ?>" class="widget-more-link">
                    查看全部公告 →
                </a>
            </div>

            <div class="ann-widget">
                <h3 class="widget-title">🔗 快速連結</h3>
                <ul class="widget-list">
                    <li><a href="<?php echo esc_url( home_url( '/level-guide/' ) ); ?>">等級制度說明</a></li>
                    <li><a href="<?php echo esc_url( home_url( '/disclaimer/' ) ); ?>">免責聲明</a></li>
                    <li><a href="<?php echo esc_url( home_url( '/privacy/' ) ); ?>">隱私政策</a></li>
                    <li><a href="<?php echo esc_url( home_url( '/contact/' ) ); ?>">聯絡我們</a></li>
                </ul>
            </div>

            <div class="ann-widget ann-subscribe-widget">
                <h3 class="widget-title">📡 訂閱通知</h3>
                <p>登入帳號可在會員中心開啟公告 Email 通知。</p>
                <?php if ( is_user_logged_in() ) : ?>
                    <a href="<?php echo esc_url( home_url( '/mc/?tab=notifications' ) ); ?>" class="widget-btn">
                        前往通知設定
                    </a>
                <?php else : ?>
                    <a href="<?php echo esc_url( wp_login_url( get_permalink() ) ); ?>" class="widget-btn">
                        登入
                    </a>
                <?php endif; ?>
            </div>

        </aside>

    </div>

</div>

<?php endwhile; ?>

<style>
/* ===== Announcement Single ===== */
.announcement-single {
    max-width: 1280px;
    margin: 0 auto;
    padding: 20px 16px 60px;
    color: var(--theme-text-color, #e0e0e0);
}

/* 麵包屑 */
.ann-breadcrumb {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
    font-size: 0.88rem;
    color: rgba(255,255,255,0.55);
    margin-bottom: 20px;
    padding: 12px 16px;
    background: rgba(255,255,255,0.03);
    border-radius: 8px;
}
.ann-breadcrumb a {
    color: rgba(255,255,255,0.75);
    text-decoration: none;
    transition: color .15s;
}
.ann-breadcrumb a:hover { color: var(--theme-link-hover-color, #ffa500); }
.ann-breadcrumb .sep { opacity: 0.4; }
.ann-breadcrumb .current { color: rgba(255,255,255,0.95); font-weight: 500; }

/* Layout */
.ann-single-layout {
    display: grid;
    grid-template-columns: 1fr 300px;
    gap: 32px;
}
@media (max-width: 900px) {
    .ann-single-layout { grid-template-columns: 1fr; }
}

/* Article */
.ann-single-article {
    background: rgba(255,255,255,0.03);
    border: 1px solid rgba(255,255,255,0.06);
    border-radius: 16px;
    padding: 40px;
}
@media (max-width: 600px) {
    .ann-single-article { padding: 24px 20px; }
}

/* Header */
.ann-single-header {
    margin-bottom: 28px;
    padding-bottom: 24px;
    border-bottom: 1px solid rgba(255,255,255,0.08);
}
.ann-single-meta-top {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    margin-bottom: 16px;
}
.ann-tag {
    display: inline-block;
    padding: 4px 12px;
    background: rgba(108,92,231,0.15);
    border: 1px solid rgba(108,92,231,0.35);
    color: #a29bfe;
    border-radius: 6px;
    font-size: 0.8rem;
    font-weight: 600;
}
.ann-tag.sticky {
    background: rgba(255,165,0,0.15);
    border-color: rgba(255,165,0,0.35);
    color: #ffa500;
}
.ann-single-title {
    font-size: 2rem;
    line-height: 1.4;
    margin: 0 0 16px;
    color: #fff;
}
@media (max-width: 600px) {
    .ann-single-title { font-size: 1.5rem; }
}
.ann-single-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 16px;
    font-size: 0.88rem;
    color: rgba(255,255,255,0.55);
}
.meta-item .meta-label { opacity: 0.7; margin-right: 2px; }

/* Thumb */
.ann-single-thumb {
    margin: 0 0 28px;
    border-radius: 12px;
    overflow: hidden;
}
.ann-single-thumb img {
    width: 100%;
    height: auto;
    display: block;
}

/* Content */
.ann-single-content {
    font-size: 1rem;
    line-height: 1.85;
    color: rgba(255,255,255,0.88);
}
.ann-single-content > * { margin: 0 0 1.2em; }
.ann-single-content > *:last-child { margin-bottom: 0; }
.ann-single-content h2,
.ann-single-content h3,
.ann-single-content h4 {
    margin-top: 1.8em;
    margin-bottom: 0.8em;
    color: #fff;
    line-height: 1.4;
}
.ann-single-content h2 { font-size: 1.4rem; }
.ann-single-content h3 { font-size: 1.2rem; }
.ann-single-content h4 { font-size: 1.05rem; }
.ann-single-content a {
    color: var(--theme-link-initial-color, #6ec1e4);
    text-decoration: underline;
    text-underline-offset: 3px;
}
.ann-single-content a:hover { color: var(--theme-link-hover-color, #ffa500); }
.ann-single-content blockquote {
    border-left: 3px solid var(--theme-palette-color-1, #ffa500);
    padding: 12px 20px;
    background: rgba(255,165,0,0.06);
    margin: 1em 0;
    border-radius: 0 8px 8px 0;
    color: rgba(255,255,255,0.78);
    font-style: italic;
}
.ann-single-content code {
    background: rgba(255,255,255,0.08);
    padding: 2px 6px;
    border-radius: 4px;
    font-size: 0.9em;
    color: #ffa500;
}
.ann-single-content pre {
    background: rgba(0,0,0,0.4);
    padding: 16px;
    border-radius: 8px;
    overflow-x: auto;
    border: 1px solid rgba(255,255,255,0.06);
}
.ann-single-content pre code {
    background: none;
    padding: 0;
    color: #e0e0e0;
}
.ann-single-content ul,
.ann-single-content ol {
    padding-left: 1.6em;
}
.ann-single-content li {
    margin-bottom: 0.5em;
}
.ann-single-content img {
    max-width: 100%;
    height: auto;
    border-radius: 8px;
}
.ann-single-content table {
    width: 100%;
    border-collapse: collapse;
    margin: 1em 0;
}
.ann-single-content th,
.ann-single-content td {
    border: 1px solid rgba(255,255,255,0.1);
    padding: 8px 12px;
    text-align: left;
}
.ann-single-content th {
    background: rgba(255,255,255,0.05);
}

/* Tags */
.ann-single-tags {
    margin-top: 32px;
    padding-top: 20px;
    border-top: 1px solid rgba(255,255,255,0.08);
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    align-items: center;
}
.tags-label { color: rgba(255,255,255,0.55); font-size: 0.9rem; }
.ann-tag-link {
    padding: 4px 10px;
    background: rgba(255,255,255,0.05);
    border-radius: 4px;
    color: rgba(255,255,255,0.75);
    text-decoration: none;
    font-size: 0.85rem;
    transition: all .15s;
}
.ann-tag-link:hover {
    background: rgba(255,255,255,0.1);
    color: #fff;
}

/* Share */
.ann-single-share {
    margin-top: 24px;
    padding-top: 20px;
    border-top: 1px solid rgba(255,255,255,0.08);
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    align-items: center;
}
.share-label { color: rgba(255,255,255,0.55); font-size: 0.9rem; margin-right: 4px; }
.share-btn {
    padding: 6px 14px;
    border: 1px solid rgba(255,255,255,0.12);
    background: rgba(255,255,255,0.04);
    color: rgba(255,255,255,0.85);
    border-radius: 6px;
    text-decoration: none;
    font-size: 0.85rem;
    cursor: pointer;
    transition: all .15s;
}
.share-btn:hover {
    background: rgba(255,255,255,0.09);
    border-color: rgba(255,255,255,0.2);
}
.share-btn.fb:hover { background: rgba(24,119,242,0.2); border-color: #1877f2; color: #fff; }
.share-btn.tw:hover { background: rgba(29,161,242,0.2); border-color: #1da1f2; color: #fff; }

/* Prev / Next */
.ann-single-nav {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
    margin-top: 32px;
}
@media (max-width: 600px) {
    .ann-single-nav { grid-template-columns: 1fr; }
}
.ann-single-nav .nav-prev,
.ann-single-nav .nav-next {
    display: flex;
    flex-direction: column;
    gap: 6px;
    padding: 16px 20px;
    background: rgba(255,255,255,0.03);
    border: 1px solid rgba(255,255,255,0.06);
    border-radius: 10px;
    color: rgba(255,255,255,0.85);
    text-decoration: none;
    transition: all .15s;
}
.ann-single-nav .nav-next { text-align: right; }
.ann-single-nav a.nav-prev:hover,
.ann-single-nav a.nav-next:hover {
    background: rgba(255,255,255,0.06);
    border-color: rgba(255,255,255,0.15);
    transform: translateY(-2px);
}
.ann-single-nav .nav-direction {
    font-size: 0.8rem;
    color: rgba(255,255,255,0.5);
}
.ann-single-nav .nav-title {
    font-size: 0.95rem;
    font-weight: 500;
}
.ann-single-nav .disabled {
    opacity: 0.4;
    cursor: not-allowed;
}

/* Comments wrapper */
.ann-single-comments {
    margin-top: 32px;
    padding: 24px;
    background: rgba(255,255,255,0.03);
    border: 1px solid rgba(255,255,255,0.06);
    border-radius: 12px;
}

/* Sidebar widget (sticky on desktop) */
.ann-single-sidebar { position: relative; }
@media (min-width: 901px) {
    .ann-single-sidebar { position: sticky; top: 80px; align-self: start; }
}
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
}
.widget-list { list-style: none; padding: 0; margin: 0; }
.widget-list li {
    padding: 8px 0;
    border-bottom: 1px dashed rgba(255,255,255,0.06);
}
.widget-list li:last-child { border-bottom: none; }
.widget-list a {
    color: rgba(255,255,255,0.75);
    text-decoration: none;
    font-size: 0.92rem;
    display: flex;
    gap: 8px;
    align-items: baseline;
    transition: color .15s;
}
.widget-list a:hover { color: var(--theme-link-hover-color, #ffa500); }
.widget-date {
    font-size: 0.78rem;
    color: rgba(255,255,255,0.45);
    font-variant-numeric: tabular-nums;
    flex-shrink: 0;
}
.widget-title-text { flex: 1; }
.widget-more-link {
    display: block;
    margin-top: 12px;
    padding-top: 10px;
    border-top: 1px solid rgba(255,255,255,0.08);
    color: var(--theme-link-initial-color, #6ec1e4);
    text-decoration: none;
    font-size: 0.88rem;
    text-align: right;
}
.widget-more-link:hover { text-decoration: underline; }
.ann-subscribe-widget p {
    color: rgba(255,255,255,0.65);
    font-size: 0.88rem;
    line-height: 1.6;
    margin: 0 0 12px;
}
.widget-btn {
    display: block;
    text-align: center;
    padding: 10px 16px;
    background: var(--theme-palette-color-1, #ffa500);
    color: #000;
    border-radius: 8px;
    text-decoration: none;
    font-weight: 600;
    transition: transform .15s;
}
.widget-btn:hover { transform: translateY(-1px); }
</style>

<script>
(function () {
    document.querySelectorAll('.share-btn.copy-link').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var url = this.getAttribute('data-url');
            if (!url) return;
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(url).then(function () {
                    var original = btn.textContent;
                    btn.textContent = '✓ 已複製';
                    setTimeout(function () { btn.textContent = original; }, 1500);
                });
            } else {
                var ta = document.createElement('textarea');
                ta.value = url;
                document.body.appendChild(ta);
                ta.select();
                try { document.execCommand('copy'); } catch (e) {}
                document.body.removeChild(ta);
                var original = btn.textContent;
                btn.textContent = '✓ 已複製';
                setTimeout(function () { btn.textContent = original; }, 1500);
            }
        });
    });
})();
</script>

<?php get_footer(); ?>
