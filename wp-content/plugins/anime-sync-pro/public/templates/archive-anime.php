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

/*
 * [v1.7.1] /upcoming-anime/ 限定：篩選選項改成只列「這批作品裡真的有的」。
 * 上面三行是全站範圍，套用在這裡會出現選了卻是空清單的選項（跟播映
 * 狀態篩選同一套謹慎邏輯——沒有作品的選項不顯示）。用 object_ids 精準
 * 查詢，只回傳這批作品實際掛過的詞。查詢已在 pre_get_posts 跑完
 * （posts_per_page 已改 -1），這裡直接讀 $wp_query->posts 不必重查。
 */
if ( $is_upcoming ) {
    $upcoming_ids = wp_list_pluck( $GLOBALS['wp_query']->posts, 'ID' );

    $format_terms = $upcoming_ids ? get_terms( [
        'taxonomy'   => 'anime_format_tax',
        'object_ids' => $upcoming_ids,
        'hide_empty' => false,
        'orderby'    => 'count',
        'order'      => 'DESC',
    ] ) : [];

    $genre_terms = $upcoming_ids ? get_terms( [
        'taxonomy'   => 'genre',
        'object_ids' => $upcoming_ids,
        'hide_empty' => false,
        'orderby'    => 'count',
        'order'      => 'DESC',
    ] ) : [];

    $source_terms = $upcoming_ids ? get_terms( [
        'taxonomy'   => 'anime_source_tax',
        'object_ids' => $upcoming_ids,
        'hide_empty' => false,
        'orderby'    => 'count',
        'order'      => 'DESC',
    ] ) : [];
}

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
// 對照表集中在 includes/class-format-registry.php，改名只需動那一處
$format_labels  = Anime_Sync_Format_Registry::get_labels();
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
    <?php if ( $is_upcoming ) : ?>
    <p class="aaa-count">共 <strong id="aaa-visible-count"><?php echo number_format( $total_posts ); ?></strong> / <?php echo number_format( $total_posts ); ?> 部作品</p>
    <?php else : ?>
    <p class="aaa-count">共 <strong><?php echo number_format( $total_posts ); ?></strong> 部作品</p>
    <?php endif; ?>
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

<?php
/*
 * [v1.7.1] /upcoming-anime/ 專用篩選面板：只留格式／原作類型／動漫類型。
 *
 * 不套用上面那組現成面板的原因：那組的「播出季度」「播映狀態」在這頁
 * 沒有篩選意義（狀態全部同值是 NOT_YET_RELEASED；季度這批作品多半還
 * 沒排定，見 anime-sync-pro v1.5.5 的撤回檔期修正），而且那組的每個
 * 按鈕都是用 get_term_link() 連到別的分類頁網址，點下去會離開這份
 * 「未定檔期」清單本身，篩選條件也一起丟失。
 *
 * 這裡改成 <button data-key data-value>，JS（upcoming-filters.js）端
 * 直接切卡片的 is-hidden-by-filter class，不重載頁面、三組可疊加。
 */
?>
<?php if ( $is_upcoming ) : ?>
<div class="aaa-filter-wrap" id="aaa-upcoming-filters">
    <?php if ( ! is_wp_error( $format_terms ) && $format_terms ) : ?>
    <div class="aaa-filter-group">
        <div class="aaa-filter-label">🎬 動漫格式</div>
        <div class="aaa-filter-row">
            <?php foreach ( $format_terms as $ft ) : ?>
            <button type="button" class="aaa-filter-btn" data-key="format" data-value="<?php echo esc_attr( $ft->name ); ?>">
                <?php echo esc_html( $format_labels[ $ft->name ] ?? $ft->name ); ?>
            </button>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if ( ! is_wp_error( $source_terms ) && $source_terms ) : ?>
    <div class="aaa-filter-group">
        <div class="aaa-filter-label">📖 原作類型</div>
        <div class="aaa-filter-row">
            <?php foreach ( $source_terms as $st ) : ?>
            <button type="button" class="aaa-filter-btn" data-key="source" data-value="<?php echo esc_attr( $st->name ); ?>">
                <?php echo esc_html( $st->name ); ?>
            </button>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if ( ! is_wp_error( $genre_terms ) && $genre_terms ) : ?>
    <div class="aaa-filter-group">
        <div class="aaa-filter-label">🏷️ 動漫類型</div>
        <div class="aaa-filter-row">
            <?php foreach ( $genre_terms as $gt ) : ?>
            <button type="button" class="aaa-filter-btn" data-key="genre" data-value="<?php echo esc_attr( $gt->name ); ?>">
                <?php echo esc_html( $gt->name ); ?>
            </button>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <button type="button" class="aaa-filter-btn" id="aaa-upcoming-filter-reset" hidden>清除篩選 ×</button>
</div>
<?php endif; ?>

<?php if ( have_posts() ) : ?>
    <div class="aaa-grid" id="aaa-grid">
    <?php while ( have_posts() ) : the_post();
        $pid = get_the_ID();
        $m   = get_post_meta( $pid );
        $g   = fn( $k ) => isset( $m[ $k ][0] ) ? $m[ $k ][0] : '';

        $cover      = $g( 'anime_cover_image' ) ?: get_the_post_thumbnail_url( $pid, 'medium' );
        /*
         * 主標：繁體 → 簡體 → 文章標題。
         * 副標：日文原文（原本是羅馬字）。
         *
         * ★ 為什麼副標不用羅馬字
         *   讀者是繁中使用者，「Karakai Jouzu no Takagi-san 3」對他們沒有
         *   辨識作用；日文原名反而是查得到、認得出的那個名字。
         *
         * ★ 為什麼主標要有簡體這一層
         *   繁體標題來自 Bangumi name_cn 逐字簡轉繁，少數作品沒有 name_cn
         *   就會空白。與其掉回文章標題（常常是日文或羅馬字），不如先用簡體。
         */
        $title_zh   = $g( 'anime_title_chinese' ) ?: ( $g( 'anime_title_simplified' ) ?: get_the_title() );
        $title_sub  = $g( 'anime_title_native' );

        // 原文與主標一字不差時就不重複印（例如主標本來就退回了日文）
        if ( $title_sub !== '' && $title_sub === $title_zh ) {
            $title_sub = '';
        }
        /*
         * 卡片不再顯示評分（原本是封面右下角的 ⭐ 藥丸），
         * 連帶把計算一併移除——留著只是每張卡多讀一次 meta。
         * 評分仍在作品頁與排行榜顯示，資料本身沒有動。
         */
        $season     = $g( 'anime_season' );
        $year       = (int) $g( 'anime_season_year' );
        $format     = $g( 'anime_format' );
        $status     = $g( 'anime_status' );
        $episodes   = (int) $g( 'anime_episodes' );

        $season_label = $season_labels[ strtoupper( $season ) ] ?? '';
        $format_label = $format_labels[ $format ] ?? $format;
        $status_label = $status_labels[ $status ] ?? '';
        $status_class = $status_classes[ $status ] ?? '';

        /*
         * 卡片上的季度去掉「季」字：「2022 春季」→「2022 春」。
         *
         * 三個標籤（格式／季度／集數）在六欄版面的卡片寬度下會擠到換行，
         * 換行的卡片就跟旁邊對不齊。少一個字加上縮小內距剛好排得下。
         * $season_labels 本身不動——那份對照表的完整寫法別處還要用。
         */
        $season_short = $season_label !== '' ? rtrim( $season_label, '季' ) : '';
        $season_str   = ( $year && $season_short ) ? $year . ' ' . $season_short : ( $year ?: '' );

        /*
         * [v1.7.1] 篩選用的 data-* 屬性，只在 /upcoming-anime/ 才算——
         * 一般列表頁沒有篩選面板，不需要多這兩個 taxonomy 查詢。
         * WP_Query 預設已把這兩個 taxonomy 的關聯批次撈進 cache
         * （update_post_term_cache，預設開啟），這裡不會變成 N+1。
         */
        $card_data_attrs = '';
        if ( $is_upcoming ) {
            $card_genre_names  = wp_get_post_terms( $pid, 'genre', [ 'fields' => 'names' ] );
            $card_source_names = wp_get_post_terms( $pid, 'anime_source_tax', [ 'fields' => 'names' ] );
            $card_data_attrs   = sprintf(
                ' data-format="%s" data-source="%s" data-genres="%s"',
                esc_attr( $format ),
                esc_attr( is_wp_error( $card_source_names ) ? '' : implode( '|', $card_source_names ) ),
                esc_attr( is_wp_error( $card_genre_names ) ? '' : implode( '|', $card_genre_names ) )
            );
        }
    ?>
        <article class="aaa-card"<?php echo $card_data_attrs; ?>>
            <a href="<?php the_permalink(); ?>" class="aaa-card-link">
                <?php if ( $status_label ) : ?>
                    <span class="aaa-status-tag <?php echo esc_attr( $status_class ); ?>"><?php echo esc_html( $status_label ); ?></span>
                <?php endif; ?>
                <div class="aaa-card-cover-wrap">
                    <?php if ( $cover ) : ?>
                        <img class="aaa-card-cover" src="<?php echo esc_url( $cover ); ?>"
                             alt="<?php echo esc_attr( $title_zh ); ?> 封面圖" loading="lazy">
                    <?php else : ?>
                        <div class="aaa-card-cover aaa-no-cover">無封面</div>
                    <?php endif; ?>
                </div>
                <div class="aaa-card-body">
                    <?php
                    /*
                     * 播映狀態改成掛牌，掛在封面下緣、文字區上方。
                     *
                     * 原本是壓在封面左上角的半透明藥丸。那個位置常常蓋到角色的臉，
                     * 而且背景亮的封面（白底、雪景）會讓文字幾乎看不見——
                     * 半透明底色救不了對比度。移到封面外之後底色是固定的卡片底，
                     * 對比度不再受封面影響。
                     *
                     * 標籤本身在 .aaa-card-body 裡用絕對定位，靠上方的
                     * padding 讓出位置；掛繩與繩孔是 ::before / ::after，
                     * 不多包一層 DOM。
                     */
                    ?>
                    <h3 class="aaa-card-title"><?php echo esc_html( $title_zh ); ?></h3>
                    <p class="aaa-card-romaji"><?php echo esc_html( $title_sub ); ?></p>
                    <div class="aaa-card-meta">
                        <?php if ( $format_label ) : ?><span class="aaa-meta-tag aaa-meta-format"><?php echo esc_html( $format_label ); ?></span><?php endif; ?>
                        <?php if ( $season_str )   : ?><span class="aaa-meta-tag aaa-meta-season"><?php echo esc_html( $season_str ); ?></span><?php endif; ?>
                        <?php if ( $episodes )     : ?><span class="aaa-meta-tag aaa-meta-ep"><?php echo esc_html( $episodes ); ?>集</span><?php endif; ?>
                    </div>
                </div>
            </a>
        </article>
    <?php endwhile; ?>
    </div>

    <?php if ( ! $is_upcoming ) : ?>
    <nav class="aaa-pagination" aria-label="分頁導航">
        <?php echo paginate_links( [ 'prev_text' => '← 上一頁', 'next_text' => '下一頁 →', 'mid_size' => 2, 'type' => 'list' ] ); ?>
    </nav>
    <?php endif; ?>

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
/* [v1.7.1] /upcoming-anime/ 的篩選鈕是 <button> 不是 <a>，補上瀏覽器預設按鈕樣式的重置 */
button.aaa-filter-btn{font:inherit;-webkit-appearance:none;appearance:none;cursor:pointer;}
#aaa-upcoming-filter-reset{align-self:flex-start;color:#fecaca;border-color:rgba(248,113,113,.4);}
#aaa-upcoming-filter-reset:hover{color:#fff;background:rgba(248,113,113,.18);border-color:rgba(248,113,113,.5);}
.aaa-card.is-hidden-by-filter{display:none;}
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
/* gap 要容得下框外的直式狀態牌（約 19px），否則會壓到隔壁卡片 */
.aaa-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:24px;margin-bottom:40px;}
@media(min-width:600px){.aaa-grid{grid-template-columns:repeat(auto-fill,minmax(180px,1fr));}}
@media(min-width:1024px){.aaa-grid{grid-template-columns:repeat(6,1fr);gap:26px;}}
/*
 * overflow 從 hidden 改成 visible，讓狀態掛牌能伸出卡片右緣。
 *
 * 原本 hidden 有兩個用途：切封面的 hover 縮放、切圓角。
 * 前者 .aaa-card-cover-wrap 自己就有 overflow:hidden，不受影響；
 * 後者改成直接給 cover-wrap 上緣圓角，效果一樣。
 *
 * ★ 必須同時補 min-width:0，否則整個格線會爆開。
 *   CSS Grid 規範裡，grid item 的「自動最小尺寸」只有在 overflow:visible
 *   時才會用 min-content 撐開；overflow 一旦不是 visible 就自動變 0。
 *   也就是說原本的 overflow:hidden 一直兼職在做 min-width:0。改成 visible
 *   之後這個隱性保護就沒了，長標題會把 1fr 欄位頂寬，卡片寬高全部跑掉。
 */
.aaa-card{position:relative;border-radius:var(--rmd);overflow:visible;min-width:0;background:var(--surf);border:1px solid var(--bd);backdrop-filter:var(--blur);-webkit-backdrop-filter:var(--blur);box-shadow:var(--sh);transition:transform var(--tr),box-shadow var(--tr),border-color var(--tr);}
.aaa-card:hover{transform:translateY(-6px);box-shadow:var(--sh2);border-color:rgba(124,92,255,.35);}
.aaa-card-link{display:block;text-decoration:none;color:inherit;}
/* 卡片改成 overflow:visible 之後，上緣圓角改由封面自己負責 */
.aaa-card-cover-wrap{position:relative;aspect-ratio:2/3;overflow:hidden;background:#10213f;border-radius:calc(var(--rmd) - 1px) calc(var(--rmd) - 1px) 0 0;}
.aaa-card-cover{width:100%;height:100%;object-fit:cover;display:block;transition:transform .38s ease;}
.aaa-card:hover .aaa-card-cover{transform:scale(1.06);}
.aaa-no-cover{display:flex;align-items:center;justify-content:center;color:var(--faint);font-size:13px;height:100%;background:linear-gradient(135deg,#0d1d38,#101d35);}
/* ── 播映狀態直式掛牌 ──────────────────────────────────────────
 * 直立在卡片右上角，整塊站在框格外。
 *
 * ★ 前三版都失敗，記錄一下踩過的坑：
 *   v1 橫式＋畫 1px 掛繩與 5px 繩孔 —— 繩子在深色底上等於沒畫，
 *      繩孔在 11px 字級旁邊被讀成項目符號。
 *   v2 橫式貼在封面下緣 —— 和標題搶同一塊位置，兩者互相干擾。
 *   v3 橫式伸出右緣 —— 突出量小到只像沒對齊，且仍壓在封面上。
 *
 *   共同的錯誤是想在 11px 的尺度上做寫實細節。改成直式之後，
 *   形狀本身（窄長、貼在邊上、左緣切平右緣圓角）就足以讀成掛牌，
 *   一個裝飾元素都不必加；中文直排也比橫排更像實體木牌。
 *
 * ★ 定位在 .aaa-card 而不是 .aaa-card-body
 *   要掛在卡片右上角就必須以整張卡為基準，所以 .aaa-card 補了
 *   position:relative，標記也從 card-body 搬到 card-link 底下。
 *
 * ★ right:-19px 是「完全在框格外」而不是壓在封面上
 *   牌寬約 17px，所以 -19 會讓它整塊落在卡片外緣、還留 2px 空隙。
 *   相對地格線 gap 必須加大（桌機 26px、手機 20px），否則會壓到
 *   隔壁卡片；最後一欄則靠 .aaa-wrap 的 20px padding 容納。
 */
.aaa-status-tag{position:absolute;top:14px;right:-19px;z-index:2;
  writing-mode:vertical-rl;text-orientation:upright;
  padding:9px 3px;border-radius:0 5px 5px 0;
  font-size:10px;font-weight:700;line-height:1;letter-spacing:.14em;
  box-shadow:3px 3px 10px rgba(0,0,0,.45);}
.aaa-card-body{position:relative;padding:12px 14px 14px;background:var(--surf2);border-radius:0 0 calc(var(--rmd) - 1px) calc(var(--rmd) - 1px);}
.s-fin{background:rgba(52,211,153,.2);color:#34d399;border:1px solid rgba(52,211,153,.36);}
.s-rel{background:rgba(76,201,240,.2);color:#4cc9f0;border:1px solid rgba(76,201,240,.36);}
.s-pre{background:rgba(251,191,36,.2);color:#fbbf24;border:1px solid rgba(251,191,36,.36);}
.s-can,.s-hia{background:rgba(251,113,133,.2);color:#fb7185;border:1px solid rgba(251,113,133,.36);}
/*
 * 卡片文字區塊一律固定高度，整排才會對齊。
 *
 * 原本標題 1～2 行、標籤 1～2 行都可能，於是每張卡的標籤落在不同高度，
 * 整個列表看起來參差不齊。三個地方各鎖一個高度就解決：
 *   標題   一律佔兩行（line-clamp 只管上限，min-height 補下限）
 *   副標   即使沒有原文也保留一行的位置
 *   標籤   禁止換行（配合縮短的季度字串與較小的內距）
 */
.aaa-card-title{font-size:13px;font-weight:700;margin:0 0 5px;line-height:1.45;color:#fff;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;min-height:calc(1.45em * 2);}
.aaa-card-romaji{font-size:11px;color:var(--faint);margin:0 0 8px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;min-height:1.3em;}
.aaa-card-meta{display:flex;flex-wrap:nowrap;gap:3px;margin-bottom:7px;overflow:hidden;}
.aaa-meta-tag{font-size:11px;padding:2px 6px;border-radius:var(--pill);font-weight:600;white-space:nowrap;}
/* 六欄版面在 1024～1199px 時卡片最窄，標籤再縮一點才塞得下三個 */
@media(min-width:1024px) and (max-width:1199px){
  .aaa-meta-tag{font-size:10px;padding:2px 5px;}
}
.aaa-meta-format{background:rgba(124,92,255,.2);color:#b8a0ff;border:1px solid rgba(124,92,255,.34);}
.aaa-meta-season{background:rgba(52,211,153,.16);color:#34d399;border:1px solid rgba(52,211,153,.3);}
.aaa-meta-ep{background:rgba(76,201,240,.16);color:#4cc9f0;border:1px solid rgba(76,201,240,.3);}
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
  .aaa-grid{grid-template-columns:repeat(2,1fr);gap:20px;}
  .aaa-card-cover-wrap{aspect-ratio:3/4;}
  /* 手機：容器 padding 只有 14px，牌子突出量要縮，最後一欄才不會頂出畫面 */
  .aaa-card-body{padding:10px 9px 9px;}
  .aaa-status-tag{top:10px;right:-14px;font-size:9px;padding:7px 2px;letter-spacing:.1em;}
  .aaa-card-romaji{display:none;}
  /* 兩欄版面在 320px 級距的手機上最窄，標籤同樣縮一級才不會被裁掉 */
  .aaa-meta-tag{font-size:10px;padding:2px 5px;}
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
