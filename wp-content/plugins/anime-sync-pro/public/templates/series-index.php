<?php
/**
 * Series Index Template (系列總覽頁 /series/)
 * Path: wp-content/plugins/anime-sync-pro/public/templates/series-index.php
 * Version: 1.0.1 (2026-07-20)
 *
 * 顯示所有「有作品」的系列（count > 0），每張卡片：
 *   - 封面：自動抓該系列內第一部作品的封面（term 本身無 meta）
 *   - 系列名、作品數
 *   - 連往 /series/{slug}/ 單一系列頁
 * 提供即時搜尋框（純前端過濾）。
 *
 * Changelog:
 *   1.0.1 (2026-07-20)
 *     - [優化] $series_data 加入 transient 快取（12 小時），
 *              避免每次載入對上百個系列各跑一次 get_posts。
 *     - [新增] 網址加 ?asi_refresh=1 可強制略過快取重建。
 *   1.0.0 (2026-07-20)
 *     - [新增] /series/ 系列總覽頁初版。
 */
if ( ! defined( 'ABSPATH' ) ) exit;

get_header();

$taxonomy = 'anime_series_tax';

/* ── helper：抓某系列內第一部作品的封面 ── */
if ( ! function_exists( 'asi_get_series_cover' ) ) {
    function asi_get_series_cover( int $term_id, string $taxonomy ): array {
        $posts = get_posts( [
            'post_type'      => [ 'anime', 'manga', 'novel', 'game', 'music' ],
            'post_status'    => 'publish',
            'posts_per_page' => 1,
            'orderby'        => 'date',
            'order'          => 'ASC',
            'no_found_rows'  => true,
            'tax_query'      => [
                [
                    'taxonomy' => $taxonomy,
                    'field'    => 'term_id',
                    'terms'    => $term_id,
                ],
            ],
        ] );

        if ( empty( $posts ) ) {
            return [ 'cover' => '', 'title' => '' ];
        }

        $pid   = $posts[0]->ID;
        $cover = get_post_meta( $pid, '_smacg_cover_image', true );
        if ( ! $cover ) {
            $cover = get_post_meta( $pid, 'anime_cover_image', true );
        }
        if ( ! $cover ) {
            $cover = get_the_post_thumbnail_url( $pid, 'medium' ) ?: '';
        }

        return [ 'cover' => $cover, 'title' => get_the_title( $pid ) ];
    }
}

/* ── helper：組出（含封面的）系列資料，帶 transient 快取 ── */
if ( ! function_exists( 'asi_get_series_data' ) ) {
    function asi_get_series_data( string $taxonomy ): array {
        $cache_key = 'asi_series_index_data_v1';

        // 網址帶 ?asi_refresh=1 時強制重建（方便手動刷新）
        $force = isset( $_GET['asi_refresh'] ) && $_GET['asi_refresh'] === '1';

        if ( ! $force ) {
            $cached = get_transient( $cache_key );
            if ( is_array( $cached ) ) {
                return $cached;
            }
        }

        $terms = get_terms( [
            'taxonomy'   => $taxonomy,
            'hide_empty' => true,   // 只要 count > 0
            'orderby'    => 'count',
            'order'      => 'DESC',
        ] );

        if ( is_wp_error( $terms ) ) {
            $terms = [];
        }

        $series_data = [];
        foreach ( $terms as $t ) {
            $link = get_term_link( $t );
            if ( is_wp_error( $link ) ) continue;

            $info = asi_get_series_cover( (int) $t->term_id, $taxonomy );

            $series_data[] = [
                'name'  => $t->name,
                'slug'  => $t->slug,
                'count' => (int) $t->count,
                'url'   => $link,
                'cover' => $info['cover'],
            ];
        }

        set_transient( $cache_key, $series_data, 12 * HOUR_IN_SECONDS );

        return $series_data;
    }
}

$series_data  = asi_get_series_data( $taxonomy );
$total_series = count( $series_data );

/* ── Schema：CollectionPage + ItemList ── */
$list_elements = [];
$pos = 0;
foreach ( $series_data as $s ) {
    $pos++;
    $item = [
        '@type' => 'CreativeWorkSeries',
        'name'  => $s['name'],
        'url'   => $s['url'],
    ];
    if ( $s['cover'] ) $item['image'] = $s['cover'];
    $list_elements[] = [
        '@type'    => 'ListItem',
        'position' => $pos,
        'item'     => $item,
    ];
}
$schema = [
    '@context'   => 'https://schema.org',
    '@type'      => 'CollectionPage',
    'name'       => '全部系列作品總覽',
    'url'        => home_url( '/series/' ),
    'description'=> sprintf( '本站共收錄 %d 個跨媒體作品系列，涵蓋動畫、漫畫、小說、遊戲與音樂。', $total_series ),
    'mainEntity' => [
        '@type'           => 'ItemList',
        'numberOfItems'   => count( $list_elements ),
        'itemListElement' => $list_elements,
    ],
];
?>

<script type="application/ld+json"><?php echo wp_json_encode( $schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); ?></script>

<div class="asi-wrap">

    <nav class="asi-breadcrumb" aria-label="麵包屑導航">
        <ol>
            <li><a href="<?php echo esc_url( home_url( '/' ) ); ?>">首頁</a></li>
            <li><a href="<?php echo esc_url( home_url( '/anime/' ) ); ?>">動漫列表</a></li>
            <li>系列總覽</li>
        </ol>
    </nav>

    <div class="asi-hero">
        <div class="asi-hero-label">🔮 跨媒體作品系列</div>
        <h1 class="asi-hero-title">全部系列總覽</h1>
        <p class="asi-hero-desc">
            本站共收錄 <strong><?php echo esc_html( $total_series ); ?></strong> 個作品系列，
            涵蓋動畫、漫畫、小說、遊戲與音樂。點擊任一系列可查看該系列的完整作品列表。
        </p>
    </div>

    <?php if ( empty( $series_data ) ) : ?>

        <div class="asi-empty">
            <div class="asi-empty-icon">📭</div>
            <p>目前還沒有任何有作品的系列。</p>
        </div>

    <?php else : ?>

        <div class="asi-toolbar">
            <input type="search" id="asi-search" class="asi-search"
                   placeholder="🔍 搜尋系列名稱…" autocomplete="off">
            <span class="asi-search-count" id="asi-search-count">
                共 <?php echo esc_html( $total_series ); ?> 個系列
            </span>
        </div>

        <div class="asi-grid" id="asi-grid">
            <?php foreach ( $series_data as $s ) : ?>
                <article class="asi-card"
                         data-name="<?php echo esc_attr( mb_strtolower( $s['name'] ) ); ?>">
                    <a href="<?php echo esc_url( $s['url'] ); ?>" class="asi-card-link">
                        <div class="asi-card-cover-wrap">
                            <?php if ( $s['cover'] ) : ?>
                                <img class="asi-card-cover"
                                     src="<?php echo esc_url( $s['cover'] ); ?>"
                                     alt="<?php echo esc_attr( $s['name'] ); ?> 系列封面"
                                     loading="lazy">
                            <?php else : ?>
                                <div class="asi-card-cover asi-no-cover">無封面</div>
                            <?php endif; ?>
                            <span class="asi-count-badge"><?php echo esc_html( $s['count'] ); ?> 部</span>
                        </div>
                        <div class="asi-card-body">
                            <h2 class="asi-card-title"><?php echo esc_html( $s['name'] ); ?></h2>
                        </div>
                    </a>
                </article>
            <?php endforeach; ?>
        </div>

        <div class="asi-noresult" id="asi-noresult" hidden>
            <p>找不到符合「<span id="asi-noresult-kw"></span>」的系列。</p>
        </div>

    <?php endif; ?>

</div><!-- .asi-wrap -->

<script>
(function () {
    'use strict';
    var input   = document.getElementById('asi-search');
    var grid    = document.getElementById('asi-grid');
    var countEl = document.getElementById('asi-search-count');
    var noRes   = document.getElementById('asi-noresult');
    var noResKw = document.getElementById('asi-noresult-kw');
    if (!input || !grid) return;

    var cards = Array.prototype.slice.call(grid.querySelectorAll('.asi-card'));
    var total = cards.length;

    function filter() {
        var kw = input.value.trim().toLowerCase();
        var shown = 0;
        cards.forEach(function (c) {
            var name = c.getAttribute('data-name') || '';
            var match = kw === '' || name.indexOf(kw) !== -1;
            c.style.display = match ? '' : 'none';
            if (match) shown++;
        });
        if (countEl) {
            countEl.textContent = kw === ''
                ? '共 ' + total + ' 個系列'
                : '符合 ' + shown + ' / ' + total + ' 個';
        }
        if (noRes) {
            if (shown === 0 && kw !== '') {
                noRes.removeAttribute('hidden');
                if (noResKw) noResKw.textContent = input.value.trim();
            } else {
                noRes.setAttribute('hidden', '');
            }
        }
    }

    var timer = null;
    input.addEventListener('input', function () {
        clearTimeout(timer);
        timer = setTimeout(filter, 120);
    });
})();
</script>

<style>
.asi-wrap { max-width: 1200px; margin: 0 auto; padding: 20px 16px 60px; }

/* 麵包屑 */
.asi-breadcrumb ol { list-style: none; display: flex; flex-wrap: wrap; gap: 6px; padding: 0; margin: 0 0 20px; font-size: 13px; color: #888; }
.asi-breadcrumb li:not(:last-child)::after { content: '›'; margin-left: 6px; color: #ccc; }
.asi-breadcrumb a { color: #0073aa; text-decoration: none; }
.asi-breadcrumb a:hover { text-decoration: underline; }

/* Hero */
.asi-hero { margin-bottom: 24px; }
.asi-hero-label { font-size: 13px; color: #8b5cf6; font-weight: 600; margin-bottom: 6px; }
.asi-hero-title { font-size: 28px; font-weight: 800; margin: 0 0 10px; }
.asi-hero-desc { font-size: 15px; line-height: 1.7; color: #555; max-width: 720px; }

/* Toolbar */
.asi-toolbar { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; margin-bottom: 20px; }
.asi-search {
    flex: 1; min-width: 220px; max-width: 420px;
    padding: 10px 14px; font-size: 14px;
    border: 1px solid #ddd; border-radius: 8px;
}
.asi-search:focus { outline: none; border-color: #8b5cf6; box-shadow: 0 0 0 3px rgba(139,92,246,.15); }
.asi-search-count { font-size: 13px; color: #888; }

/* Grid */
.asi-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
    gap: 16px;
}
.asi-card { border-radius: 10px; overflow: hidden; background: #fff; border: 1px solid #eee; transition: transform .15s, box-shadow .15s; }
.asi-card:hover { transform: translateY(-3px); box-shadow: 0 6px 18px rgba(0,0,0,.12); }
.asi-card-link { display: block; text-decoration: none; color: inherit; }

.asi-card-cover-wrap { position: relative; aspect-ratio: 3 / 4; background: #f2f2f2; }
.asi-card-cover { width: 100%; height: 100%; object-fit: cover; display: block; }
.asi-no-cover { display: flex; align-items: center; justify-content: center; color: #bbb; font-size: 13px; width: 100%; height: 100%; }
.asi-count-badge {
    position: absolute; bottom: 6px; right: 6px;
    background: rgba(0,0,0,.72); color: #fff;
    font-size: 11px; padding: 2px 8px; border-radius: 10px;
}
.asi-card-body { padding: 10px; }
.asi-card-title { font-size: 13px; font-weight: 600; line-height: 1.4; margin: 0; color: #23282d; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
.asi-card:hover .asi-card-title { color: #8b5cf6; }

/* Empty / no result */
.asi-empty, .asi-noresult { text-align: center; padding: 60px 20px; color: #888; }
.asi-empty-icon { font-size: 48px; margin-bottom: 12px; }

@media screen and (max-width: 600px) {
    .asi-hero-title { font-size: 22px; }
    .asi-grid { grid-template-columns: repeat(auto-fill, minmax(120px, 1fr)); gap: 12px; }
}
</style>

<?php get_footer(); ?>
