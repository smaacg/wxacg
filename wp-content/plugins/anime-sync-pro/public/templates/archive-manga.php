<?php
/**
 * Archive Manga Template — Anime Sync Pro
 * Path: wp-content/plugins/anime-sync-pro/public/templates/archive-manga.php
 * Version: 1.2.0 (2026-08-12)
 *
 * v1.2.0 (2026-08-12) Thin Content 防護:
 *   - genre Taxonomy 頁文章數 < 3 且無描述時 noindex,follow。
 *   - 漫畫搜尋結果頁 (is_search) 強制 noindex。
 *   - get_header() 往後移到 filter 掛上之後。
 *
 * v1.1.0 (2026-07-17) — [Fix] 卡片補顯示日文原名
 *
 * ⚠️ 需要搜配 class-frontend.php 的路由修改才會生效，見檔案最後說明。
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$is_search = is_search() && get_query_var( 'post_type' ) === 'manga';
$is_genre  = is_tax( 'genre' ) && get_post_type() === 'manga';

$current_term = $is_genre ? get_queried_object() : null;

$archive_title = '漫畫列表';
$archive_desc  = '';
if ( $is_search ) {
    $archive_title = '搜尋結果：' . get_search_query();
} elseif ( $current_term ) {
    $archive_title = $current_term->name;
    $archive_desc  = term_description( $current_term->term_id );
}

$total_posts  = (int) $GLOBALS['wp_query']->found_posts;
$active_genre = $is_genre ? $current_term->slug : '';

$genre_terms = get_terms( [
    'taxonomy'   => 'genre',
    'orderby'    => 'count',
    'order'      => 'DESC',
    'hide_empty' => true,
    'number'     => 20,
] );

$canonical_url = ( $is_genre && $current_term )
    ? get_term_link( $current_term )
    : get_post_type_archive_link( 'manga' );

/* ── [v1.2.0] Thin Content 防護：noindex 判斷（在 get_header 之前）── */
add_filter( 'rank_math/frontend/robots', function ( $robots ) use (
    $is_search, $current_term, $total_posts, $archive_desc
) {
    if ( $is_search ) {
        $robots['index']  = 'noindex';
        $robots['follow'] = 'follow';
        unset( $robots['noarchive'], $robots['nosnippet'] );
        return $robots;
    }
    if ( $current_term instanceof WP_Term ) {
        $desc = trim( strip_tags( (string) $archive_desc ) );
        if ( $total_posts < 3 && $desc === '' ) {
            $robots['index']  = 'noindex';
            $robots['follow'] = 'follow';
            unset( $robots['noarchive'], $robots['nosnippet'] );
        }
    }
    return $robots;
} );

get_header();

/* ── GEO 導言句 ── */
$geo_intro = '';
if ( ! $is_search ) {
    if ( $current_term ) {
        $geo_intro = sprintf( '本頁彙整「%s」類型的漫畫作品，共 %d 部，提供評分、話數、卷數與連載狀態等資訊，可依需求瀏覽。', $current_term->name, $total_posts );
    } else {
        $geo_intro = sprintf( '本漫畫資料庫目前收錄 %d 部作品，涵蓋連載中與已完結作品，並依類型分類，提供評分、話數、卷數與台版出版資訊。', $total_posts );
    }
}

$format_labels  = [ 'MANGA' => '漫畫', 'ONE_SHOT' => '短篇', 'NOVEL' => '小說', 'LIGHT_NOVEL' => '輕小說', 'MANHWA' => '韓漫', 'MANHUA' => '國漫' ];
$status_labels  = [ 'FINISHED' => '已完結', 'RELEASING' => '連載中', 'NOT_YET_RELEASED' => '未發售', 'CANCELLED' => '已腰斬', 'HIATUS' => '休刊中' ];
$status_classes = [ 'FINISHED' => 's-fin', 'RELEASING' => 's-rel', 'NOT_YET_RELEASED' => 's-pre', 'CANCELLED' => 's-can', 'HIATUS' => 's-hia' ];

/* ── Schema：CollectionPage + ItemList（ComicSeries）── */
$list_elements = [];
if ( have_posts() ) {
    $position = 0;
    while ( have_posts() ) {
        the_post();
        $pid = get_the_ID();
        $position++;
        $m = get_post_meta( $pid );
        $g = fn( $k ) => isset( $m[ $k ][0] ) ? $m[ $k ][0] : '';

        $li_title = $g( 'anime_title_chinese' ) ?: get_the_title();
        $li_cover = $g( 'anime_cover_image' ) ?: get_the_post_thumbnail_url( $pid, 'medium' );

        $alt_names = array_values( array_unique( array_filter(
            [ $g( 'anime_title_native' ), $g( 'anime_title_romaji' ) ],
            fn( $v ) => $v !== '' && $v !== null
        ) ) );

        $item = [ '@type' => 'ComicSeries', 'name' => $li_title, 'url' => get_the_permalink( $pid ) ];
        if ( $alt_names ) $item['alternateName'] = count( $alt_names ) === 1 ? $alt_names[0] : $alt_names;
        if ( $li_cover )  $item['image'] = $li_cover;

        $list_elements[] = [
            '@type'    => 'ListItem',
            'position' => $position,
            'item'     => array_filter( $item, fn( $v ) => $v !== null && $v !== '' && $v !== [] ),
        ];
    }
    wp_reset_postdata();
}

$schema = [
    '@context'    => 'https://schema.org',
    '@type'       => 'CollectionPage',
    'name'        => $archive_title . ' | 漫畫資料庫',
    'description' => $geo_intro ?: ( $archive_desc ? wp_strip_all_tags( $archive_desc ) : '收錄所有漫畫資訊，包含評分、話數、卷數與連載狀態。' ),
    'url'         => $canonical_url,
];
if ( $list_elements ) {
    $schema['mainEntity'] = [
        '@type'           => 'ItemList',
        'name'            => $archive_title . ' 作品列表',
        'numberOfItems'   => count( $list_elements ),
        'itemListElement' => $list_elements,
    ];
}
?>
<script type="application/ld+json"><?php echo wp_json_encode( $schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); ?></script>

<div class="aaa-wrap">

<nav class="aaa-breadcrumb" aria-label="麵包屑">
    <ol>
        <li><a href="<?php echo esc_url( home_url( '/' ) ); ?>">首頁</a></li>
        <?php if ( $current_term || $is_search ) : ?>
        <li><a href="<?php echo esc_url( home_url( '/manga/' ) ); ?>">漫畫列表</a></li>
        <?php endif; ?>
        <li><?php echo esc_html( $archive_title ); ?></li>
    </ol>
</nav>

<header class="aaa-header">
    <h1 class="aaa-title"><?php echo esc_html( $archive_title ); ?></h1>
    <?php if ( $archive_desc ) : ?>
    <p class="aaa-desc"><?php echo wp_kses_post( $archive_desc ); ?></p>
    <?php endif; ?>
    <?php if ( $geo_intro ) : ?>
    <p class="aaa-desc"><?php echo esc_html( $geo_intro ); ?></p>
    <?php endif; ?>
    <p class="aaa-count">共 <strong><?php echo number_format( $total_posts ); ?></strong> 部作品</p>
</header>

<div class="aaa-search-wrap">
    <form role="search" method="get" action="<?php echo esc_url( home_url( '/' ) ); ?>">
        <div class="aaa-search-inner">
            <span class="aaa-search-icon">🔍</span>
            <input class="aaa-search-input" type="search" name="s"
                   placeholder="搜尋漫畫名稱…"
                   value="<?php echo esc_attr( get_search_query() ); ?>">
            <input type="hidden" name="post_type" value="manga">
            <button class="aaa-search-btn" type="submit">搜尋</button>
        </div>
    </form>
</div>

<?php if ( ! is_wp_error( $genre_terms ) && $genre_terms ) : ?>
<div class="aaa-filter-wrap">
    <div class="aaa-filter-group">
        <div class="aaa-filter-label">🏷️ 漫畫類型</div>
        <div class="aaa-filter-row">
            <a href="<?php echo esc_url( home_url( '/manga/' ) ); ?>"
               class="aaa-filter-btn <?php echo ! $active_genre ? 'active' : ''; ?>">全部</a>
            <?php foreach ( $genre_terms as $gt ) : ?>
            <a href="<?php echo esc_url( get_term_link( $gt ) ); ?>"
               class="aaa-filter-btn <?php echo ( $gt->slug === $active_genre ) ? 'active' : ''; ?>">
                <?php echo esc_html( $gt->name ); ?>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ( have_posts() ) : ?>
    <div class="aaa-grid" id="aaa-grid">
    <?php while ( have_posts() ) : the_post();
        $pid = get_the_ID();
        $m   = get_post_meta( $pid );
        $g   = fn( $k ) => isset( $m[ $k ][0] ) ? $m[ $k ][0] : '';

        $cover      = $g( 'anime_cover_image' ) ?: get_the_post_thumbnail_url( $pid, 'medium' );
        $title_zh   = $g( 'anime_title_chinese' ) ?: get_the_title();
        $title_na   = $g( 'anime_title_native' ); // ★ [v1.1.0] 新增：日文原名
        $title_ro   = $g( 'anime_title_romaji' );
        $score_raw  = $g( 'anime_score_anilist' );
        $score      = ( is_numeric( $score_raw ) && (float) $score_raw > 0 ) ? number_format( (float) $score_raw / 10, 1 ) : '';
        $format     = $g( 'anime_format' );
        $status     = $g( 'anime_status' );
        $chapters   = (int) $g( 'manga_chapters' );
        $volumes    = (int) $g( 'manga_volumes' );

        $format_label = $format_labels[ $format ] ?? $format;
        $status_label = $status_labels[ $status ] ?? '';
        $status_class = $status_classes[ $status ] ?? '';

        $count_str = '';
        if ( $volumes > 0 )       $count_str = $volumes . ' 卷';
        elseif ( $chapters > 0 ) $count_str = $chapters . ' 話';
    ?>
        <article class="aaa-card">
            <a href="<?php the_permalink(); ?>" class="aaa-card-link">
                <div class="aaa-card-cover-wrap">
                    <?php if ( $cover ) : ?>
                        <img class="aaa-card-cover" src="<?php echo esc_url( $cover ); ?>"
                             alt="<?php echo esc_attr( $title_zh ); ?> 封面圖" loading="lazy">
                    <?php else : ?>
                        <div class="aaa-card-cover aaa-no-cover">無封面</div>
                    <?php endif; ?>
                    <?php if ( $status_label ) : ?>
                        <span class="aaa-status-badge <?php echo esc_attr( $status_class ); ?>"><?php echo esc_html( $status_label ); ?></span>
                    <?php endif; ?>
                    <?php if ( $score ) : ?>
                        <span class="aaa-score-badge">⭐ <?php echo esc_html( $score ); ?></span>
                    <?php endif; ?>
                </div>
                <div class="aaa-card-body">
                    <h3 class="aaa-card-title"><?php echo esc_html( $title_zh ); ?></h3>
                    <?php if ( $title_na ) : ?><p class="aaa-card-native"><?php echo esc_html( $title_na ); ?></p><?php endif; ?>
                    <?php if ( $title_ro ) : ?><p class="aaa-card-romaji"><?php echo esc_html( $title_ro ); ?></p><?php endif; ?>
                    <div class="aaa-card-meta">
                        <?php if ( $format_label ) : ?><span class="aaa-meta-tag aaa-meta-format"><?php echo esc_html( $format_label ); ?></span><?php endif; ?>
                        <?php if ( $count_str )    : ?><span class="aaa-meta-tag aaa-meta-ep"><?php echo esc_html( $count_str ); ?></span><?php endif; ?>
                    </div>
                </div>
            </a>
        </article>
    <?php endwhile; ?>
    </div>

    <nav class="aaa-pagination" aria-label="分頁導航">
        <?php echo paginate_links( [ 'prev_text' => '← 上一頁', 'next_text' => '下一頁 →', 'mid_size' => 2, 'type' => 'list' ] ); ?>
    </nav>

<?php else : ?>
    <div class="aaa-empty">
        <?php if ( $is_search ) : ?>
            <p>找不到「<?php echo esc_html( get_search_query() ); ?>」的相關漫畫</p>
            <a href="<?php echo esc_url( home_url( '/manga/' ) ); ?>" class="aaa-import-btn">回到漫畫列表</a>
        <?php else : ?>
            <p>目前沒有漫畫資料</p>
        <?php endif; ?>
    </div>
<?php endif; ?>

</div><!-- .aaa-wrap -->

<?php get_footer(); ?>