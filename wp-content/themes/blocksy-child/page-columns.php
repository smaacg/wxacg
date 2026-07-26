<?php
/**
 * Template Name: 專欄頁面
 * @version 2.2.0  2026-07-02 SEO 強化（ItemList schema + 語意標籤）+ RWD 增強
 */

if ( ! defined( 'ABSPATH' ) ) exit;

get_header();

$review_query = new WP_Query([
    'post_type'      => 'post',
    'posts_per_page' => 12,
    'category_name'  => 'review',
    'post_status'    => 'publish',
    'no_found_rows'  => true,
]);

$feature_query = new WP_Query([
    'post_type'      => 'post',
    'posts_per_page' => 12,
    'category_name'  => 'feature',
    'post_status'    => 'publish',
    'no_found_rows'  => true,
]);

function asd_get_card_thumb_url( $post_id ) {
    if ( has_post_thumbnail( $post_id ) ) {
        $url = get_the_post_thumbnail_url( $post_id, 'medium_large' );
        if ( $url ) return $url;
    }
    $cover = get_post_meta( $post_id, 'anime_cover_image', true );
    if ( $cover ) return $cover;

    $content = get_post_field( 'post_content', $post_id );
    if ( $content && preg_match( '/<img[^>]+src=["\']([^"\']+)["\']/i', $content, $m ) ) {
        return $m[1];
    }
    return '';
}

/**
 * 收集文章資料給 JSON-LD 用（同時渲染卡片）
 */
function asd_render_column_card( &$schema_items = null, &$position = 0 ) {
    $post_id   = get_the_ID();
    $permalink = get_permalink();
    $title     = get_the_title();
    $date_iso  = get_the_date( 'c' );          // ISO 8601，給 <time> 與 schema
    $date_disp = get_the_date();
    $excerpt   = wp_trim_words( get_the_excerpt(), 30, '…' );
    $thumb_url = asd_get_card_thumb_url( $post_id );

    $channels = get_the_term_list( $post_id, 'channel', '', ' · ', '' );
    if ( is_wp_error( $channels ) ) $channels = '';

    // 累積 schema
    if ( is_array( $schema_items ) ) {
        $position++;
        $schema_items[] = [
            '@type'    => 'ListItem',
            'position' => $position,
            'url'      => $permalink,
            'name'     => $title,
        ];
    }
    ?>
    <article class="column-card">
        <a href="<?php echo esc_url( $permalink ); ?>" class="card-thumb-link" tabindex="-1" aria-hidden="true">
            <?php if ( $thumb_url ) : ?>
                <img src="<?php echo esc_url( $thumb_url ); ?>"
                     alt="<?php echo esc_attr( $title ); ?>"
                     class="card-thumb-img"
                     loading="lazy" decoding="async" />
            <?php else : ?>
                <div class="card-thumb-placeholder" aria-hidden="true">
                    <span class="placeholder-icon">🎬</span>
                </div>
            <?php endif; ?>
        </a>

        <div class="card-info">
            <?php if ( ! empty( $channels ) ) : ?>
                <div class="card-channels"><?php echo $channels; ?></div>
            <?php endif; ?>

            <h3 class="card-title">
                <a href="<?php echo esc_url( $permalink ); ?>"><?php echo esc_html( $title ); ?></a>
            </h3>

            <div class="card-meta">
                <time class="date" datetime="<?php echo esc_attr( $date_iso ); ?>">📅 <?php echo esc_html( $date_disp ); ?></time>
            </div>

            <?php if ( $excerpt ) : ?>
                <div class="card-excerpt"><?php echo esc_html( $excerpt ); ?></div>
            <?php endif; ?>
        </div>
    </article>
    <?php
}

// 準備 schema 容器
$schema_items = [];
$schema_pos   = 0;
?>

<main class="columns-page">
    <header class="columns-page-header">
        <h1 class="page-title"><span class="title-icon">🔍</span>專欄</h1>
        <p class="page-desc">深度評論與精選專題，帶你看見動漫世界的不同角度</p>
    </header>

    <section class="columns-section review-section" aria-labelledby="sec-review">
        <header class="section-header">
            <h2 class="section-title" id="sec-review"><span class="section-icon">📝</span>評論</h2>
            <a href="<?php echo esc_url( home_url( '/review/' ) ); ?>" class="more-link">查看全部 →</a>
        </header>

        <?php if ( $review_query->have_posts() ) : ?>
            <div class="columns-grid">
                <?php while ( $review_query->have_posts() ) : $review_query->the_post();
                    asd_render_column_card( $schema_items, $schema_pos );
                endwhile; wp_reset_postdata(); ?>
            </div>
        <?php else : ?>
            <p class="empty-msg">尚未有評論文章</p>
        <?php endif; ?>
    </section>

    <section class="columns-section feature-section" aria-labelledby="sec-feature">
        <header class="section-header">
            <h2 class="section-title" id="sec-feature"><span class="section-icon">📚</span>專題</h2>
            <a href="<?php echo esc_url( home_url( '/feature/' ) ); ?>" class="more-link">查看全部 →</a>
        </header>

        <?php if ( $feature_query->have_posts() ) : ?>
            <div class="columns-grid">
                <?php while ( $feature_query->have_posts() ) : $feature_query->the_post();
                    asd_render_column_card( $schema_items, $schema_pos );
                endwhile; wp_reset_postdata(); ?>
            </div>
        <?php else : ?>
            <p class="empty-msg">尚未有專題文章</p>
        <?php endif; ?>
    </section>
</main>

<?php
// 輸出 ItemList 結構化資料
if ( ! empty( $schema_items ) ) {
    $schema = [
        '@context'        => 'https://schema.org',
        '@type'           => 'ItemList',
        'name'            => '專欄 — 評論與專題',
        'itemListElement' => $schema_items,
    ];
    echo '<script type="application/ld+json">'
        . wp_json_encode( $schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES )
        . '</script>' . "\n";
}
?>

<?php get_footer(); ?>
