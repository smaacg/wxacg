<?php
/**
 * News list partial
 * 路徑：blocksy-child/template-parts/news-list.php
 *
 * 使用方式：
 *   set_query_var( 'news_main_query', $custom_query ); // 可選，預設用主迴圈
 *   get_template_part( 'template-parts/news-list' );
 *
 * @version 1.1.0 (2026-06-10)
 *
 * Changelog:
 *   1.1.0 (2026-06-10)
 *     - 卡片新增文章摘要（the_excerpt），增加列表頁內容量與相關性
 *     - 摘要限制長度，避免破版
 *   1.0.0 (2026-05-16) 初版
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// 取得查詢來源
$q = get_query_var( 'news_main_query' );
if ( ! ( $q instanceof WP_Query ) ) {
    global $wp_query;
    $q = $wp_query;
}
?>

<?php if ( $q->have_posts() ) : ?>

    <div class="news-card-list">
        <?php while ( $q->have_posts() ) : $q->the_post();
            $cats      = get_the_category();
            $cat_label = ! empty( $cats ) ? esc_html( $cats[0]->name ) : '文章';

            // 摘要：優先用手動摘要，否則從內文截取
            $excerpt = has_excerpt()
                ? get_the_excerpt()
                : wp_trim_words( wp_strip_all_tags( strip_shortcodes( get_the_content() ) ), 45, '…' );
        ?>
        <a href="<?php the_permalink(); ?>" class="news-card glass">
            <div class="news-card-img">
                <?php if ( has_post_thumbnail() ) : ?>
                    <?php the_post_thumbnail( 'medium', [ 'alt' => get_the_title(), 'loading' => 'lazy' ] ); ?>
                <?php else :
                    preg_match( '/<img[^>]+src=["\']([^"\']+)["\']/', get_the_content(), $cm );
                    if ( ! empty( $cm[1] ) ) : ?>
                        <img src="<?php echo esc_url( $cm[1] ); ?>"
                             alt="<?php echo esc_attr( get_the_title() ); ?>"
                             loading="lazy" />
                    <?php else : ?>
                        <span class="news-card-placeholder">📰</span>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
            <div class="news-card-body">
                <div class="news-card-tag"><?php echo $cat_label; ?></div>
                <h2 class="news-card-title"><?php the_title(); ?></h2>
                <?php if ( $excerpt ) : ?>
                <p class="news-card-excerpt"><?php echo esc_html( $excerpt ); ?></p>
                <?php endif; ?>
                <div class="news-card-meta">
                    <i class="fa-regular fa-clock"></i> <?php echo get_the_date( 'Y-m-d' ); ?>
                </div>
            </div>
        </a>
        <?php endwhile; ?>
    </div>

    <div class="news-pagination">
        <?php
        // 主查詢用 the_posts_pagination；自訂查詢用 paginate_links
        global $wp_query;
        if ( $q === $wp_query ) {
            the_posts_pagination( [
                'prev_text' => '<i class="fa-solid fa-chevron-left"></i>',
                'next_text' => '<i class="fa-solid fa-chevron-right"></i>',
                'end_size'  => 2,
                'mid_size'  => 1,
            ] );
        } else {
            $big = 999999999;
            echo paginate_links( [
                'base'      => str_replace( $big, '%#%', esc_url( get_pagenum_link( $big ) ) ),
                'format'    => '?paged=%#%',
                'current'   => max( 1, (int) $q->get( 'paged' ) ),
                'total'     => $q->max_num_pages,
                'prev_text' => '<i class="fa-solid fa-chevron-left"></i>',
                'next_text' => '<i class="fa-solid fa-chevron-right"></i>',
                'end_size'  => 2,
                'mid_size'  => 1,
            ] );
        }
        ?>
    </div>

<?php else : ?>

    <div class="news-empty glass">
        <i class="fa-regular fa-newspaper"></i>
        <p>目前沒有文章，請稍後再來查看。</p>
    </div>

<?php endif; wp_reset_postdata(); ?>
