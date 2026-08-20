<?php
/**
 * Archive Anime Template — Anime Sync Pro
 * v2.6 (2026-08-12) Thin Content 防護
 *   - is_genre / is_season / is_format 的 Taxonomy 頁文章數 < 3 且無描述時，
 *     透過 rank_math/frontend/robots filter 動態加上 noindex,follow。
 *   - 動畫搜尋結果頁 (is_search) 強制 noindex，避免無限搜尋網址被索引。
 *   - get_header() 往後移到 noindex filter 掛上之後，確保能在 wp_head() 執行前生效。
 * v2.5 (2026-06-02) 精簡 + 查詢優化 + 版面壓縮
 */
if ( ! defined( 'ABSPATH' ) ) exit;

/* ── /upcoming-anime/ 專用 ── */
$is_upcoming = (bool) get_query_var('anime_upcoming');

/* ★ 頁首橫幅圖片（留空則不顯示） */
$banner_image_url = '';

/* ── 頁面資訊 ── */
$is_archive   = is_post_type_archive( 'anime' );
$is_genre     = is_tax( 'genre' );
$is_season    = is_tax( 'anime_season_tax' );
$is_format    = is_tax( 'anime_format_tax' );
$is_source    = is_tax( 'anime_source_tax' );
$is_search    = is_search() && get_query_var( 'post_type' ) === 'anime';
$current_term = ( $is_genre || $is_season || $is_format || $is_source ) ? get_queried_object() : null;

$archive_title = '動漫列表';
$archive_desc  = '';
if ( $is_upcoming ) {
    $archive_title = '動畫化確定・製作進行中作品';

    /*
     * 頁面說明改寫重點：
     * - 先講「這裡有什麼、能幫你解決什麼」，而非只描述資料來源。
     * - 原文末句「持續同步 AniList 資料」把資料來源放在最顯眼的位置，
     *   等於告訴讀者「這裡的東西 AniList 也有」，反而弱化本站價值；
     *   改為說明更新頻率，來源標示留給頁面下方的資料來源區塊。
     */
    $archive_desc  = '這裡收錄已宣布動畫化決定、企劃進行中與製作中的作品——'
        . '包含漫畫與輕小說改編、以及確定製作續季的系列。'
        . '每部作品附上原作出處、製作公司與目前確定的播出季度，'
        . '尚未定檔期者也會先行建檔，官方一有新情報就更新。';
}
/* ── 播映狀態篩選（/anime/?anime_status=releasing 等）── */
$status_filter_slug  = sanitize_key( (string) get_query_var( 'anime_status' ) );
$status_filter_map   = function_exists( 'anime_sync_get_status_filter_map' )
    ? anime_sync_get_status_filter_map()
    : [];
$status_filter_label = $status_filter_map[ $status_filter_slug ]['label'] ?? '';

if ( $is_search ) {
    $archive_title = '搜尋結果：' . get_search_query();
} elseif ( $current_term ) {
    $archive_title = $current_term->name;
    $archive_desc  = term_description( $current_term->term_id );
} elseif ( $status_filter_label !== '' ) {
    $archive_title = $status_filter_label . '的動畫';
}

$total_posts  = (int) $GLOBALS['wp_query']->found_posts;
$current_page = max( 1, get_query_var( 'paged' ) );
$show_banner  = $is_archive && ! $current_term && ! $is_search && $current_page === 1;

$active_genre  = $is_genre  ? $current_term->slug : '';
$active_season = $is_season ? $current_term->slug : '';
$active_format = $is_format ? $current_term->slug : '';
$active_source = $is_source ? $current_term->slug : '';

/* ── [v2.6] Thin Content 防護：noindex 判斷（在 get_header 之前）── */
add_filter( 'rank_math/frontend/robots', function ( $robots ) use (
    $is_search, $current_term, $total_posts, $archive_desc, $status_filter_label
) {
    // 動畫搜尋結果頁強制 noindex（同 search.php 同樣邏輯）
    if ( $is_search ) {
        $robots['index']  = 'noindex';
        $robots['follow'] = 'follow';
        unset( $robots['noarchive'], $robots['nosnippet'] );
        return $robots;
    }
    /*
     * 狀態篩選是帶參數的檢視，內容與主歸檔高度重疊（已完結就佔 1000 部以上），
     * 給它索引只會製造重複內容。follow 保留，讓權重仍能流向作品頁。
     */
    if ( $status_filter_label !== '' ) {
        $robots['index']  = 'noindex';
        $robots['follow'] = 'follow';
        unset( $robots['noarchive'], $robots['nosnippet'] );
        return $robots;
    }
    // Taxonomy 頁（genre/season/format/source）文章數過少且無描述 → noindex
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

/* ── 季度 ── */
$all_seasons = get_terms( [
    'taxonomy'   => 'anime_season_tax',
    'orderby'    => 'slug',
    'order'      => 'DESC',
    'hide_empty' => true,
] );

$season_children    = [];
$active_season_year = '';
if ( ! is_wp_error( $all_seasons ) && $all_seasons ) {
    $years = [];
    $kids  = [];
    foreach ( $all_seasons as $t ) {
        if ( (int) $t->parent === 0 ) {
            $years[ $t->term_id ] = $t;
        } else {
            $kids[ $t->parent ][] = $t;
        }
    }
    /*
     * 季節必須照時序排（冬→春→夏→秋），不能沿用 get_terms() 的排序。
     *
     * 上面的 get_terms() 是 orderby=slug、order=DESC——年份要新的在前，
     * 這對父層是對的；但子層跟著套用時，slug 倒序排出來會變成
     * winter → summer → spring → fall，也就是畫面上看到的「冬 夏 春 秋」。
     *
     * 年份仍維持 DESC，只有季節這一層改用固定時序。
     */
    $season_seq = [ 'winter' => 0, 'spring' => 1, 'summer' => 2, 'fall' => 3 ];

    foreach ( $years as $year_term ) {
        if ( empty( $kids[ $year_term->term_id ] ) ) continue;
        $children = $kids[ $year_term->term_id ];

        usort( $children, static function ( $a, $b ) use ( $season_seq ) {
            // slug 格式為 {year}-{season}，例如 2026-spring
            $ka = strtolower( explode( '-', $a->slug )[1] ?? '' );
            $kb = strtolower( explode( '-', $b->slug )[1] ?? '' );

            return ( $season_seq[ $ka ] ?? 99 ) <=> ( $season_seq[ $kb ] ?? 99 );
        } );

        $season_children[ $year_term->name ] = [
            'year_slug' => $year_term->slug,
            'children'  => $children,
        ];
        if ( $is_season ) {
            foreach ( $children as $c ) {
                if ( $c->slug === $active_season ) $active_season_year = $year_term->name;
            }
        }
    }
}
if ( ! $active_season_year && ! empty( $season_children ) ) {
    // 預設展開當前年份，若不存在則取第一個
    $current_year = date("Y");
    if ( isset( $season_children[ $current_year ] ) ) {
        $active_season_year = $current_year;
    } else {
        $active_season_year = array_key_first( $season_children );
    }
}

$format_terms = get_terms( [ 'taxonomy' => 'anime_format_tax', 'orderby' => 'count', 'order' => 'DESC', 'hide_empty' => true ] );
$genre_terms  = get_terms( [ 'taxonomy' => 'genre', 'orderby' => 'count', 'order' => 'DESC', 'hide_empty' => true, 'number' => 20 ] );
$source_terms = get_terms( [ 'taxonomy' => 'anime_source_tax', 'orderby' => 'count', 'order' => 'DESC', 'hide_empty' => true ] );

/* ── 播映狀態：直接統計 meta ──
   狀態會隨時間變動（連載中 → 已完結），沒有對應分類法可用 get_terms 取數量，
   因此掃 postmeta 統計。快取一小時，避免每次載入列表頁都跑一次群組查詢。
   只有真的有作品的狀態才會顯示，避免出現點進去是空的篩選鈕。 */
$status_counts = get_transient( 'wxacg_anime_status_counts' );

if ( false === $status_counts ) {
    $status_rows = $GLOBALS['wpdb']->get_results(
        "SELECT pm.meta_value AS code, COUNT(*) AS n
         FROM {$GLOBALS['wpdb']->postmeta} pm
         JOIN {$GLOBALS['wpdb']->posts} p ON p.ID = pm.post_id
         WHERE pm.meta_key = 'anime_status'
           AND p.post_type = 'anime'
           AND p.post_status = 'publish'
         GROUP BY pm.meta_value",
        ARRAY_A
    );

    $status_counts = [];

    foreach ( (array) $status_rows as $status_row ) {
        $status_counts[ (string) $status_row['code'] ] = (int) $status_row['n'];
    }

    set_transient( 'wxacg_anime_status_counts', $status_counts, HOUR_IN_SECONDS );
}

/* ── Schema canonical ── */
$canonical_url = ( $is_genre || $is_season || $is_format || $is_source ) && $current_term
    ? get_term_link( $current_term )
    : get_post_type_archive_link( 'anime' );

/* ── GEO 導言句 ── */
$geo_intro = '';
if ( ! $is_search && ! $is_upcoming ) {
    if ( $current_term ) {
        $kind_label = $is_genre ? '類型' : ( $is_season ? '季度' : '格式' );
        $geo_intro  = sprintf( '本頁彙整「%s」%s的動漫作品，共 %d 部，提供評分、播出季度、集數與狀態等資訊，可依需求瀏覽。', $current_term->name, $kind_label, $total_posts );
    } else {
        $geo_intro = sprintf( '本動漫資料庫目前收錄 %d 部作品，涵蓋 TV、劇場版、OVA、ONA 等多種格式，並依年份季度與類型分類，提供評分、集數與播出狀態等完整資訊。', $total_posts );
    }
}

/* ── Schema ── */
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
        $li_year  = (int) $g( 'anime_season_year' );

        $alt_names = array_values( array_unique( array_filter(
            [ $g( 'anime_title_native' ), $g( 'anime_title_romaji' ) ],
            fn( $v ) => $v !== '' && $v !== null
        ) ) );

        $item = [ '@type' => 'TVSeries', 'name' => $li_title, 'url' => get_the_permalink( $pid ) ];
        if ( $alt_names ) $item['alternateName'] = count( $alt_names ) === 1 ? $alt_names[0] : $alt_names;
        if ( $li_cover )  $item['image'] = $li_cover;
        if ( $li_year )   $item['datePublished'] = (string) $li_year;

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
    'name'        => $archive_title . ' | 動漫資料庫',
    'description' => $geo_intro ?: ( $archive_desc ? wp_strip_all_tags( $archive_desc ) : '收錄所有動漫資訊，包含評分、季度、類型、聲優等完整資料。' ),
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
<script type="application/ld+json"><?php echo wp_json_encode( $schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT ); ?></script>

<?php
$season_labels  = [ 'WINTER' => '冬季', 'SPRING' => '春季', 'SUMMER' => '夏季', 'FALL' => '秋季' ];

/*
 * 季度篩選按鈕專用：季名後面標出對應月份。
 *
 * ★ 為什麼要標月份
 *   「冬 春 夏 秋」的排列本身是照時序的（1／4／7／10 月），但年份那一層是
 *   遞減排序（2027 → 2026 → …）。畫面上只看到四個季名時，兩層方向看起來
 *   相反，會被誤讀成排序壞掉。標上月份之後，順序就自己解釋了自己。
 *
 * ★ 為什麼不直接改 $season_labels
 *   那份對照表同時給下方的作品卡片用（第 456 行，輸出「2026 冬季」）。
 *   共用會讓卡片變成「2026 冬季 1月」——同一張卡上年月重複、字串也更長。
 *   篩選按鈕與卡片的需求不同，各用各的。
 */
$season_filter_labels = [
    'WINTER' => '冬季 1月',
    'SPRING' => '春季 4月',
    'SUMMER' => '夏季 7月',
    'FALL'   => '秋季 10月',
];
$format_labels  = [ 'TV' => 'TV', 'TV_SHORT' => 'TV短篇', 'MOVIE' => '劇場版', 'OVA' => 'OVA', 'ONA' => 'ONA', 'SPECIAL' => '特別篇', 'MUSIC' => 'MV' ];
$status_labels  = [ 'FINISHED' => '已完結', 'RELEASING' => '連載中', 'NOT_YET_RELEASED' => '尚未播出', 'CANCELLED' => '已取消', 'HIATUS' => '暫停中' ];
$status_classes = [ 'FINISHED' => 's-fin', 'RELEASING' => 's-rel', 'NOT_YET_RELEASED' => 's-pre', 'CANCELLED' => 's-can', 'HIATUS' => 's-hia' ];
?>

<div class="aaa-wrap">

<?php if ( $banner_image_url ) : ?>
<div class="aaa-hero">
    <img class="aaa-hero-img" src="<?php echo esc_url( $banner_image_url ); ?>" alt="<?php echo esc_attr( $archive_title ); ?>" loading="eager">
    <div class="aaa-hero-fade"></div>
</div>
<?php endif; ?>

<nav class="aaa-breadcrumb" aria-label="麵包屑">
    <ol>
        <li><a href="<?php echo esc_url( home_url( '/' ) ); ?>">首頁</a></li>
        <?php if ( $current_term || $is_search || $is_upcoming ) : ?>
        <li><a href="<?php echo esc_url( home_url( '/anime/' ) ); ?>">動漫列表</a></li>
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
    <?php if ( ! $is_upcoming && ! $current_term && ! $is_search ) : ?>
    <div class="aaa-shortcut-row">
        <a href="<?php echo esc_url( home_url( "/upcoming-anime/" ) ); ?>" class="aaa-shortcut-btn aaa-shortcut-upcoming">
            🎬 製作決定・動畫化確定作品
            <span class="aaa-shortcut-arrow">→</span>
        </a>
    </div>
    <?php endif; ?>
</header>

<?php if ( ! $is_upcoming ) : ?>
<div class="aaa-filter-wrap">
    <?php
    /*
     * 搜尋框放在篩選卡片內的第一列。
     * 它做的事是「把這份清單縮小」，與下方季度／狀態／格式／類型同一類操作；
     * 原本擺在卡片外、外觀又與頁首的全站搜尋幾乎相同，兩個框相隔不到一屏，
     * 使用者無從分辨範圍差異（頁首是全站即時搜尋，這裡只搜動漫）。
     */
    ?>
    <div class="aaa-filter-group">
        <div class="aaa-filter-label">🔍 搜尋名稱</div>
        <form role="search" method="get" action="<?php echo esc_url( home_url( '/' ) ); ?>">
            <div class="aaa-search-inner">
                <span class="aaa-search-icon">🔍</span>
                <input class="aaa-search-input" type="search" name="s"
                       placeholder="在動漫列表中搜尋…"
                       value="<?php echo esc_attr( get_search_query() ); ?>">
                <input type="hidden" name="post_type" value="anime">
                <button class="aaa-search-btn" type="submit">搜尋</button>
            </div>
        </form>
    </div>

    <div class="aaa-filter-group">
        <div class="aaa-filter-label">📅 播出季度</div>
        <div class="aaa-year-accordion">
        <?php foreach ( $season_children as $year_name => $data ) :
            $is_open = ( $year_name === $active_season_year );
            $uid = 'yr-' . esc_attr( $data['year_slug'] );
        ?>
            <div class="aaa-year-item <?php echo $is_open ? 'has-active' : ''; ?>">
                <input class="aaa-year-toggle" type="checkbox" id="<?php echo $uid; ?>"
                       <?php checked( $is_open ); ?>>
                <label class="aaa-year-head" for="<?php echo $uid; ?>">
                    <span class="aaa-year-name"><?php echo esc_html( $year_name ); ?></span>
                    <span class="aaa-year-count"><?php echo count( $data['children'] ); ?></span>
                    <span class="aaa-year-arrow">▾</span>
                </label>
                <div class="aaa-year-body">
                <?php foreach ( $data['children'] as $child ) :
                    $is_act = ( $child->slug === $active_season );
                    $lbl    = $season_filter_labels[ strtoupper( explode( '-', $child->slug )[1] ?? '' ) ] ?? $child->name;
                ?>
                    <a href="<?php echo esc_url( get_term_link( $child ) ); ?>"
                       class="aaa-filter-btn <?php echo $is_act ? 'active' : ''; ?>">
                        <?php echo esc_html( $lbl ); ?>
                    </a>
                <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>
        </div>
    </div>

    <?php if ( ! empty( $status_filter_map ) && ! empty( $status_counts ) ) : ?>
    <div class="aaa-filter-group">
        <div class="aaa-filter-label">📺 播映狀態</div>
        <div class="aaa-filter-row">
            <a href="<?php echo esc_url( home_url( '/anime/' ) ); ?>"
               class="aaa-filter-btn <?php echo ! $status_filter_slug ? 'active' : ''; ?>">全部</a>
            <?php foreach ( $status_filter_map as $st_slug => $st_info ) :
                $st_count = $status_counts[ $st_info['code'] ] ?? 0;

                // 沒有作品的狀態不顯示，避免點進去是空清單
                if ( $st_count < 1 ) {
                    continue;
                }
                ?>
            <a href="<?php echo esc_url( add_query_arg( 'anime_status', $st_slug, home_url( '/anime/' ) ) ); ?>"
               class="aaa-filter-btn <?php echo ( $st_slug === $status_filter_slug ) ? 'active' : ''; ?>">
                <?php echo esc_html( $st_info['label'] ); ?>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if ( ! is_wp_error( $format_terms ) && $format_terms ) : ?>
    <div class="aaa-filter-group">
        <div class="aaa-filter-label">🎬 動漫格式</div>
        <div class="aaa-filter-row">
            <a href="<?php echo esc_url( home_url( '/anime/' ) ); ?>"
               class="aaa-filter-btn <?php echo ! $active_format ? 'active' : ''; ?>">全部</a>
            <?php foreach ( $format_terms as $ft ) : ?>
            <a href="<?php echo esc_url( get_term_link( $ft ) ); ?>"
               class="aaa-filter-btn <?php echo ( $ft->slug === $active_format ) ? 'active' : ''; ?>">
                <?php echo esc_html( $format_labels[ $ft->name ] ?? $ft->name ); ?>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if ( ! is_wp_error( $source_terms ) && $source_terms ) : ?>
    <div class="aaa-filter-group">
        <div class="aaa-filter-label">📖 原作類型</div>
        <div class="aaa-filter-row">
            <?php foreach ( $source_terms as $st ) : ?>
            <a href="<?php echo esc_url( get_term_link( $st ) ); ?>"
               class="aaa-filter-btn <?php echo ( $st->slug === $active_source ) ? 'active' : ''; ?>">
                <?php echo esc_html( $st->name ); ?>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if ( ! is_wp_error( $genre_terms ) && $genre_terms ) : ?>
    <div class="aaa-filter-group">
        <div class="aaa-filter-label">🏷️ 動漫類型</div>
        <div class="aaa-filter-row">
            <?php foreach ( $genre_terms as $gt ) : ?>
            <a href="<?php echo esc_url( get_term_link( $gt ) ); ?>"
               class="aaa-filter-btn <?php echo ( $gt->slug === $active_genre ) ? 'active' : ''; ?>">
                <?php echo esc_html( $gt->name ); ?>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
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
        $title_ro   = $g( 'anime_title_romaji' );
        $score_raw  = $g( 'anime_score_anilist' );
        $score      = ( is_numeric( $score_raw ) && (float) $score_raw > 0 ) ? number_format( (float) $score_raw / 10, 1 ) : '';
        $season     = $g( 'anime_season' );
        $year       = (int) $g( 'anime_season_year' );
        $format     = $g( 'anime_format' );
        $status     = $g( 'anime_status' );
        $episodes   = (int) $g( 'anime_episodes' );
        $popularity = (int) $g( 'anime_popularity' );

        $season_label = $season_labels[ strtoupper( $season ) ] ?? '';
        $format_label = $format_labels[ $format ] ?? $format;
        $status_label = $status_labels[ $status ] ?? '';
        $status_class = $status_classes[ $status ] ?? '';
        $season_str   = ( $year && $season_label ) ? $year . ' ' . $season_label : ( $year ?: '' );
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
                    <?php if ( $title_ro ) : ?><p class="aaa-card-romaji"><?php echo esc_html( $title_ro ); ?></p><?php endif; ?>
                    <div class="aaa-card-meta">
                        <?php if ( $format_label ) : ?><span class="aaa-meta-tag aaa-meta-format"><?php echo esc_html( $format_label ); ?></span><?php endif; ?>
                        <?php if ( $season_str )   : ?><span class="aaa-meta-tag aaa-meta-season"><?php echo esc_html( $season_str ); ?></span><?php endif; ?>
                        <?php if ( $episodes )     : ?><span class="aaa-meta-tag aaa-meta-ep"><?php echo esc_html( $episodes ); ?> 集</span><?php endif; ?>
                    </div>
                    <?php if ( $popularity ) : ?><div class="aaa-card-pop">👥 <?php echo esc_html( number_format( $popularity ) ); ?></div><?php endif; ?>
                </div>
            </a>
        </article>
    <?php endwhile; ?>
    </div>

    <nav class="aaa-pagination" aria-label="分頁導航">
        <?php echo paginate_links( [ 'prev_text' => '← 上一頁', 'next_text' => '下一頁 →', 'mid_size' => 2, 'type' => 'list' ] ); ?>
    </nav>

    <?php if ( ! $is_search ) : ?>
    <div class="aaa-seo-footer">
        <?php if ( ! is_wp_error( $genre_terms ) && $genre_terms ) : ?>
        <div class="aaa-seo-row">
            <span class="aaa-seo-label">動漫類型：</span>
            <?php foreach ( $genre_terms as $g_t ) : ?>
            <a href="<?php echo esc_url( get_term_link( $g_t ) ); ?>" class="aaa-seo-tag"><?php echo esc_html( $g_t->name ); ?></a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <?php if ( ! is_wp_error( $format_terms ) && $format_terms ) : ?>
        <div class="aaa-seo-row">
            <span class="aaa-seo-label">動漫格式：</span>
            <?php foreach ( $format_terms as $f_t ) : ?>
            <a href="<?php echo esc_url( get_term_link( $f_t ) ); ?>" class="aaa-seo-tag"><?php echo esc_html( $f_t->name ); ?></a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

<?php else : ?>
    <div class="aaa-empty">
        <?php if ( $is_search ) : ?>
            <p>找不到「<?php echo esc_html( get_search_query() ); ?>」的相關動漫</p>
            <a href="<?php echo esc_url( home_url( '/anime/' ) ); ?>" class="aaa-import-btn">回到動漫列表</a>
        <?php else : ?>
            <p>目前沒有動漫資料</p>
            <?php if ( current_user_can( 'manage_options' ) ) : ?>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=anime-sync-import' ) ); ?>" class="aaa-import-btn">前往匯入動漫</a>
            <?php endif; ?>
        <?php endif; ?>
    </div>
<?php endif; ?>

</div><!-- .aaa-wrap -->

<style>
.aaa-wrap{--p:#7c5cff;--p2:#4cc9f0;--txt:#f7f9ff;--muted:rgba(247,249,255,.62);--faint:rgba(247,249,255,.42);--bd:rgba(255,255,255,.10);--surf:rgba(10,20,40,.55);--surf2:rgba(10,20,40,.70);--blur:blur(16px);--rmd:18px;--pill:999px;--tr:.25s ease;--sh:0 8px 24px rgba(0,0,0,.28);--sh2:0 16px 40px rgba(0,0,0,.38);max-width:1280px;margin:0 auto;padding:0 20px 80px;color:var(--txt);position:relative;box-sizing:border-box;}
.aaa-wrap *,.aaa-wrap *::before,.aaa-wrap *::after{box-sizing:border-box;}
.aaa-wrap::before{content:'';position:fixed;inset:0;z-index:-1;pointer-events:none;background:radial-gradient(circle at 15% 10%,rgba(124,92,255,.22),transparent 32%),radial-gradient(circle at 85% 8%,rgba(76,201,240,.16),transparent 28%),linear-gradient(180deg,#07111f 0%,#091426 45%,#0a1730 100%);}
.aaa-hero{position:relative;margin:0 -20px;aspect-ratio:1280/320;overflow:hidden;line-height:0;pointer-events:none;}
.aaa-hero-img{width:100%;height:100%;object-fit:cover;display:block;}
.aaa-hero-fade{position:absolute;inset:0;pointer-events:none;background:linear-gradient(180deg,rgba(7,17,31,0) 35%,rgba(7,17,31,.55) 75%,#091426 100%);}
.aaa-breadcrumb{margin:20px 0 0;padding:12px 20px;border-radius:var(--pill);background:var(--surf);border:1px solid var(--bd);backdrop-filter:var(--blur);-webkit-backdrop-filter:var(--blur);display:inline-block;position:relative;z-index:2;}
.aaa-breadcrumb ol{display:flex;flex-wrap:wrap;gap:6px;list-style:none;margin:0;padding:0;font-size:13px;color:var(--muted);align-items:center;}
.aaa-breadcrumb li+li::before{content:'/';margin-right:6px;color:var(--faint);}
.aaa-breadcrumb a{color:rgba(247,249,255,.82);text-decoration:none;transition:color var(--tr);}
.aaa-breadcrumb a:hover{color:#fff;}
.aaa-header{text-align:center;padding:16px 20px 12px;position:relative;z-index:2;}
.aaa-title{font-size:clamp(1.4rem,3vw,2rem);font-weight:800;margin:0 0 6px;color:#fff;line-height:1.2;}
.aaa-desc{color:var(--muted);margin:0 0 6px;font-size:.9rem;line-height:1.6;}
.aaa-count{color:var(--faint);font-size:13px;margin:0 0 4px;}
.aaa-count strong{color:var(--p2);font-weight:700;}
/* 搜尋框已移入 .aaa-filter-wrap 內，寬度與間距交由 filter-group 控制，
   原本的 .aaa-search-wrap 置中容器不再需要。 */
.aaa-search-inner{display:flex;align-items:center;gap:10px;padding:10px 16px;border-radius:var(--pill);background:var(--surf);border:1px solid var(--bd);backdrop-filter:var(--blur);-webkit-backdrop-filter:var(--blur);transition:border-color var(--tr),box-shadow var(--tr);}
.aaa-search-inner:focus-within{border-color:rgba(124,92,255,.55);box-shadow:0 0 0 3px rgba(124,92,255,.14);}
.aaa-search-icon{font-size:16px;flex-shrink:0;opacity:.7;}
.aaa-search-input{flex:1;background:transparent;border:none;outline:none;color:var(--txt);font-size:15px;min-width:0;}
.aaa-search-input::placeholder{color:var(--faint);}
.aaa-search-btn{flex-shrink:0;padding:6px 18px;border-radius:var(--pill);border:none;cursor:pointer;background:linear-gradient(135deg,var(--p),#9d6bff);color:#fff;font-size:13px;font-weight:700;box-shadow:0 6px 18px rgba(124,92,255,.28);transition:transform var(--tr),opacity var(--tr);}
.aaa-search-btn:hover{transform:translateY(-1px);opacity:.92;}
.aaa-filter-wrap{background:var(--surf);border:1px solid var(--bd);border-radius:24px;backdrop-filter:var(--blur);-webkit-backdrop-filter:var(--blur);box-shadow:var(--sh);padding:22px;margin-bottom:36px;display:flex;flex-direction:column;gap:18px;}
.aaa-filter-group{display:flex;flex-direction:column;gap:10px;}
.aaa-filter-label{font-size:12px;color:var(--muted);font-weight:700;letter-spacing:.06em;text-transform:uppercase;}
.aaa-filter-row{display:flex;flex-wrap:wrap;gap:7px;align-items:center;}
.aaa-filter-btn{display:inline-flex;align-items:center;min-height:32px;padding:0 14px;border-radius:var(--pill);font-size:13px;text-decoration:none;color:var(--muted);background:rgba(255,255,255,.05);border:1px solid var(--bd);transition:all var(--tr);white-space:nowrap;}
.aaa-filter-btn:hover{color:#fff;background:rgba(124,92,255,.18);border-color:rgba(124,92,255,.42);}
.aaa-filter-btn.active{color:#fff;background:rgba(124,92,255,.28);border-color:rgba(124,92,255,.6);box-shadow:0 0 16px rgba(124,92,255,.28);}
.aaa-year-accordion{display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:8px;margin-top:4px;}
.aaa-year-item{border-radius:var(--rmd);border:1px solid var(--bd);background:rgba(255,255,255,.03);overflow:hidden;transition:border-color var(--tr),background var(--tr);}
.aaa-year-item.has-active{border-color:rgba(124,92,255,.55);background:rgba(124,92,255,.08);box-shadow:0 0 16px rgba(124,92,255,.18);}
.aaa-year-toggle{display:none;}
.aaa-year-head{display:flex;align-items:center;gap:8px;padding:8px 12px;cursor:pointer;user-select:none;transition:background var(--tr);}
.aaa-year-head:hover{background:rgba(124,92,255,.1);}
.aaa-year-name{font-size:13px;font-weight:700;color:#fff;flex:1;}
.aaa-year-count{font-size:11px;color:var(--faint);padding:2px 8px;border-radius:var(--pill);background:rgba(255,255,255,.06);}
.aaa-year-arrow{font-size:14px;color:var(--muted);transition:transform var(--tr);}
.aaa-year-toggle:checked~.aaa-year-head .aaa-year-arrow{transform:rotate(180deg);color:var(--p2);}
.aaa-year-body{display:none;flex-wrap:wrap;gap:6px;padding:0 12px 12px;}
.aaa-year-toggle:checked~.aaa-year-body{display:flex;}
.aaa-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:20px;margin-bottom:40px;}
@media(min-width:600px){.aaa-grid{grid-template-columns:repeat(auto-fill,minmax(180px,1fr));}}
@media(min-width:1024px){.aaa-grid{grid-template-columns:repeat(6,1fr);gap:16px;}}
.aaa-card{border-radius:var(--rmd);overflow:hidden;background:var(--surf);border:1px solid var(--bd);backdrop-filter:var(--blur);-webkit-backdrop-filter:var(--blur);box-shadow:var(--sh);transition:transform var(--tr),box-shadow var(--tr),border-color var(--tr);}
.aaa-card:hover{transform:translateY(-6px);box-shadow:var(--sh2);border-color:rgba(124,92,255,.35);}
.aaa-card-link{display:block;text-decoration:none;color:inherit;}
.aaa-card-cover-wrap{position:relative;aspect-ratio:2/3;overflow:hidden;background:#10213f;}
.aaa-card-cover{width:100%;height:100%;object-fit:cover;display:block;transition:transform .38s ease;}
.aaa-card:hover .aaa-card-cover{transform:scale(1.06);}
.aaa-no-cover{display:flex;align-items:center;justify-content:center;color:var(--faint);font-size:13px;height:100%;background:linear-gradient(135deg,#0d1d38,#101d35);}
.aaa-status-badge{position:absolute;top:10px;left:10px;padding:3px 10px;border-radius:var(--pill);font-size:11px;font-weight:700;backdrop-filter:blur(8px);-webkit-backdrop-filter:blur(8px);}
.s-fin{background:rgba(52,211,153,.2);color:#34d399;border:1px solid rgba(52,211,153,.36);}
.s-rel{background:rgba(76,201,240,.2);color:#4cc9f0;border:1px solid rgba(76,201,240,.36);}
.s-pre{background:rgba(251,191,36,.2);color:#fbbf24;border:1px solid rgba(251,191,36,.36);}
.s-can,.s-hia{background:rgba(251,113,133,.2);color:#fb7185;border:1px solid rgba(251,113,133,.36);}
.aaa-score-badge{position:absolute;bottom:10px;right:10px;background:rgba(0,0,0,.72);backdrop-filter:blur(8px);-webkit-backdrop-filter:blur(8px);color:#fbbf24;padding:3px 9px;border-radius:var(--pill);font-size:12px;font-weight:800;border:1px solid rgba(251,191,36,.28);}
.aaa-card-body{padding:12px 14px 14px;background:var(--surf2);}
.aaa-card-title{font-size:13px;font-weight:700;margin:0 0 5px;line-height:1.45;color:#fff;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;}
.aaa-card-romaji{font-size:11px;color:var(--faint);margin:0 0 8px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.aaa-card-meta{display:flex;flex-wrap:wrap;gap:4px;margin-bottom:7px;}
.aaa-meta-tag{font-size:11px;padding:2px 8px;border-radius:var(--pill);font-weight:600;}
.aaa-meta-format{background:rgba(124,92,255,.2);color:#b8a0ff;border:1px solid rgba(124,92,255,.34);}
.aaa-meta-season{background:rgba(52,211,153,.16);color:#34d399;border:1px solid rgba(52,211,153,.3);}
.aaa-meta-ep{background:rgba(76,201,240,.16);color:#4cc9f0;border:1px solid rgba(76,201,240,.3);}
.aaa-card-pop{font-size:11px;color:var(--faint);}
.aaa-pagination{display:flex;justify-content:center;margin:36px 0;}
.aaa-pagination ul{display:flex;flex-wrap:wrap;gap:6px;list-style:none;margin:0;padding:0;justify-content:center;}
.aaa-pagination .page-numbers{display:inline-flex;align-items:center;justify-content:center;min-width:38px;height:38px;padding:0 12px;border-radius:var(--pill);background:var(--surf);color:var(--muted);text-decoration:none;font-size:14px;font-weight:600;border:1px solid var(--bd);backdrop-filter:var(--blur);-webkit-backdrop-filter:var(--blur);transition:all var(--tr);}
.aaa-pagination .page-numbers:hover{color:#fff;background:rgba(124,92,255,.22);border-color:rgba(124,92,255,.48);}
.aaa-pagination .page-numbers.current{background:linear-gradient(135deg,var(--p),#9d6bff);color:#fff;border-color:transparent;box-shadow:0 6px 20px rgba(124,92,255,.38);}
.aaa-pagination .page-numbers.dots{background:none;border:none;backdrop-filter:none;cursor:default;color:var(--faint);}
.aaa-seo-footer{border-top:1px solid var(--bd);padding-top:28px;margin-top:20px;display:flex;flex-direction:column;gap:12px;}
.aaa-seo-row{display:flex;flex-wrap:wrap;gap:7px;align-items:center;}
.aaa-seo-label{font-size:12px;color:var(--faint);min-width:68px;font-weight:600;}
.aaa-seo-tag{font-size:12px;color:var(--muted);text-decoration:none;padding:3px 10px;border-radius:var(--pill);border:1px solid var(--bd);transition:all var(--tr);}
.aaa-seo-tag:hover{color:#fff;background:rgba(124,92,255,.14);border-color:rgba(124,92,255,.36);}
.aaa-empty{text-align:center;padding:100px 20px;color:var(--muted);}
.aaa-empty p{font-size:1.1rem;margin:0 0 20px;}
.aaa-import-btn{display:inline-flex;align-items:center;min-height:44px;padding:0 24px;background:linear-gradient(135deg,var(--p),#9d6bff);color:#fff;border-radius:var(--pill);text-decoration:none;font-weight:700;box-shadow:0 10px 26px rgba(124,92,255,.32);transition:transform var(--tr),opacity var(--tr);}
.aaa-import-btn:hover{transform:translateY(-2px);opacity:.92;color:#fff;}
@media(max-width:720px){
  .aaa-wrap{padding:0 14px 60px;}
  .aaa-hero{margin:0 -14px;aspect-ratio:16/7;}
  .aaa-header{padding:22px 14px 20px;}
  .aaa-filter-wrap{padding:16px;gap:14px;}
  .aaa-filter-btn{font-size:12px;min-height:28px;padding:0 11px;}
  .aaa-search-inner{padding:8px 12px;}
  .aaa-search-btn{padding:5px 14px;font-size:12px;}
  .aaa-year-accordion{display:flex;flex-wrap:nowrap;overflow-x:auto;gap:8px;padding-bottom:6px;-webkit-overflow-scrolling:touch;scrollbar-width:thin;}
  .aaa-year-item{flex:0 0 auto;min-width:120px;}
  .aaa-filter-row{flex-wrap:nowrap;overflow-x:auto;padding-bottom:6px;-webkit-overflow-scrolling:touch;}
  .aaa-filter-btn{flex:0 0 auto;}
}
@media(max-width:480px){
  .aaa-grid{grid-template-columns:repeat(2,1fr);gap:10px;}
  .aaa-card-cover-wrap{aspect-ratio:3/4;}
  .aaa-card-body{padding:8px 9px 9px;}
  .aaa-card-romaji{display:none;}
  .aaa-breadcrumb{padding:9px 14px;}
  .aaa-hero{aspect-ratio:16/8;}
}
.aaa-shortcut-row{display:flex;justify-content:center;margin:16px 0 0;}
.aaa-shortcut-btn{display:inline-flex;align-items:center;gap:10px;padding:12px 28px;border-radius:var(--pill);font-size:14px;font-weight:700;text-decoration:none;transition:all var(--tr);border:1.5px solid transparent;}
.aaa-shortcut-upcoming{background:linear-gradient(135deg,rgba(124,92,255,.18),rgba(76,201,240,.12));border-color:rgba(124,92,255,.45);color:#c4b0ff;}
.aaa-shortcut-upcoming:hover{background:linear-gradient(135deg,rgba(124,92,255,.32),rgba(76,201,240,.22));border-color:rgba(124,92,255,.8);color:#fff;transform:translateY(-2px);box-shadow:0 8px 24px rgba(124,92,255,.28);}
.aaa-shortcut-arrow{font-size:16px;transition:transform var(--tr);}
.aaa-shortcut-upcoming:hover .aaa-shortcut-arrow{transform:translateX(4px);}
</style>

<?php get_footer(); ?>
