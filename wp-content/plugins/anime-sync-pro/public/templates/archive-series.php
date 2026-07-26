<?php
/**
 * Archive Series Template
 * Plugin: Anime Sync Pro
 * Path: wp-content/plugins/anime-sync-pro/public/templates/archive-series.php
 * Version: 1.3.1 (漫畫年份修正版)
 *
 * v1.3.1 變更:
 *   - [Fix] asa_build_post_row() 的 year 判斷新增 start_date fallback。
 *     根本原因：season_year 只有動畫（TV 季度）會有值，漫畫/小說/遊戲/
 *     音樂從未被寫入 anime_season_year，導致 year 一路退回
 *     get_the_date()（=文章匯入時間），使漫畫被錯誤分到匯入當年
 *     而非其真實出版年份。現在改為優先讀 season_year，其次讀
 *     anime_start_date（AniList startDate，年月日字串取前四碼年份），
 *     最後才退回文章發布日期。
 *
 * v1.3.0 變更:
 *   - 新增 fallback：漫畫若未被打上 anime_series_tax term，但透過
 *     manga_related_anime 指向系列內的動畫，仍會被撈入列表顯示。
 *     根本原因：class-manga-import-manager.php 匯入時只寫入
 *     manga_related_anime，從未同步複製動畫身上的系列 term。
 */
if ( ! defined( 'ABSPATH' ) ) exit;

get_header();

/* ── 系列 Term 資訊 ──────────────────────────────────────── */
$series_term     = get_queried_object();
$series_name     = $series_term->name   ?? '系列';
$series_slug     = $series_term->slug   ?? '';
$series_desc     = term_description( $series_term->term_id ?? 0 );
$series_count    = (int) ( $series_term->count ?? 0 );
$series_url      = get_term_link( $series_term );
$root_anilist_id = get_term_meta( $series_term->term_id ?? 0, 'anime_series_root_id', true );

/* ── meta 讀取 helper（向下相容）─────────────────────────── */
if ( ! function_exists( 'smacg_get_meta' ) ) {
    function smacg_get_meta( int $post_id, string $key, $default = '' ) {
        $new = get_post_meta( $post_id, '_smacg_' . $key, true );
        if ( $new !== '' && $new !== null && $new !== false ) return $new;
        $old = get_post_meta( $post_id, 'anime_' . $key, true );
        if ( $old !== '' && $old !== null && $old !== false ) return $old;
        return $default;
    }
}

/* ── helper：從 anime_start_date（YYYY-MM-DD 或 YYYY 開頭字串）取出年份 ──
 * [v1.3.1 新增] 漫畫/小說/遊戲/音樂沒有 season_year，只能靠這個欄位
 * 取得真實出版年份，避免被錯誤分到「文章匯入時間」那一年。
 */
if ( ! function_exists( 'asa_extract_year_from_date' ) ) {
    function asa_extract_year_from_date( $date_str ): int {
        $date_str = trim( (string) $date_str );
        if ( $date_str === '' ) return 0;
        if ( preg_match( '/^(\d{4})/', $date_str, $m ) ) {
            return (int) $m[1];
        }
        return 0;
    }
}

/* ── helper：把單篇文章轉成本模板要用的陣列格式 ── */
if ( ! function_exists( 'asa_build_post_row' ) ) {
    function asa_build_post_row( int $pid ): array {
        /* [v1.3.1 修正] 年份判斷順序：
         * 1. season_year（動畫季度年，最準確）
         * 2. anime_start_date 取年份（漫畫/小說/遊戲/音樂適用）
         * 3. 文章發布日期（最後手段，可能等於匯入時間，不準）
         */
        $season_year = (int) smacg_get_meta( $pid, 'season_year' );
        if ( $season_year > 0 ) {
            $year = $season_year;
        } else {
            $start_year = asa_extract_year_from_date( smacg_get_meta( $pid, 'start_date' ) );
            $year       = $start_year > 0 ? $start_year : (int) get_the_date( 'Y', $pid );
        }

        return [
            'id'         => $pid,
            'post_type'  => get_post_type( $pid ),
            'permalink'  => get_permalink( $pid ),
            'cover'      => smacg_get_meta( $pid, 'cover_image' )
                         ?: get_the_post_thumbnail_url( $pid, 'medium' ),
            'title_zh'   => smacg_get_meta( $pid, 'title_chinese' ) ?: get_the_title( $pid ),
            'title_ro'   => smacg_get_meta( $pid, 'title_romaji' ),
            'title_na'   => smacg_get_meta( $pid, 'title_native' ),
            'format'     => smacg_get_meta( $pid, 'format' ),
            'status'     => smacg_get_meta( $pid, 'status' ),
            'season'     => smacg_get_meta( $pid, 'season' ),
            'year'       => $year,
            'episodes'   => (int) smacg_get_meta( $pid, 'episodes' ),
            'volumes'    => (int) smacg_get_meta( $pid, 'volumes' ),
            'chapters'   => (int) smacg_get_meta( $pid, 'chapters' ),
            'score_raw'  => smacg_get_meta( $pid, 'score_anilist' ),
        ];
    }
}

/* ── 一次 Query 取得所有「已打系列 tag」的作品（多 CPT，由 pre_get_posts 控制排序）── */
$all_posts = [];
if ( have_posts() ) {
    while ( have_posts() ) {
        the_post();
        $all_posts[] = asa_build_post_row( get_the_ID() );
    }
    wp_reset_postdata();
}

/* ── [v1.3.0 新增] Fallback：撈漏標籤但透過 manga_related_anime 連結的漫畫 ──
 * 原因：匯入漫畫時只寫入 manga_related_anime（指向動畫），
 * 從未同步複製動畫身上的 anime_series_tax term，
 * 導致漫畫永遠不會被主查詢的 tax_query 撈到。
 * 這裡用「本頁已出現的動畫 ID」反查有沒有漫畫連過來，沒被撈到就補進來。
 */
$existing_ids      = wp_list_pluck( $all_posts, 'id' );
$anime_ids_in_list = array_column(
    array_filter( $all_posts, fn( $p ) => $p['post_type'] === 'anime' ),
    'id'
);

if ( ! empty( $anime_ids_in_list ) ) {
    $extra_manga_ids = get_posts( [
        'post_type'      => 'manga',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'fields'         => 'ids',
        'no_found_rows'  => true,
        'post__not_in'   => $existing_ids ?: [ 0 ],
        'meta_query'     => [
            [
                'key'     => 'manga_related_anime',
                'value'   => $anime_ids_in_list,
                'compare' => 'IN',
            ],
        ],
    ] );

    foreach ( $extra_manga_ids as $mpid ) {
        $all_posts[] = asa_build_post_row( (int) $mpid );
    }
}

/* ── Tab 設定（順序、標籤、icon）── */
$tab_config = [
    'all'   => [ 'label' => '全部', 'icon' => '🔮', 'cpts' => [ 'anime', 'manga', 'novel', 'game', 'music' ] ],
    'anime' => [ 'label' => '動畫', 'icon' => '🎬', 'cpts' => [ 'anime' ] ],
    'manga' => [ 'label' => '漫畫', 'icon' => '📚', 'cpts' => [ 'manga' ] ],
    'novel' => [ 'label' => '小說', 'icon' => '📖', 'cpts' => [ 'novel' ] ],
    'game'  => [ 'label' => '遊戲', 'icon' => '🎮', 'cpts' => [ 'game' ] ],
    'music' => [ 'label' => '音樂', 'icon' => '🎵', 'cpts' => [ 'music' ] ],
];

/* ── 依 post_type 分組計數（決定 tab 是否顯示）── */
$count_by_type = [
    'anime' => 0, 'manga' => 0, 'novel' => 0, 'game' => 0, 'music' => 0,
];
foreach ( $all_posts as $p ) {
    if ( isset( $count_by_type[ $p['post_type'] ] ) ) {
        $count_by_type[ $p['post_type'] ]++;
    }
}

/* ── Sidebar 統計（彙總全部 CPT，不分類型）── */
$total_episodes = 0;
$years          = [];
foreach ( $all_posts as $p ) {
    $total_episodes += $p['episodes'];
    if ( $p['year'] ) $years[] = $p['year'];
}
$year_min  = $years ? min( $years ) : '';
$year_max  = $years ? max( $years ) : '';
$year_span = ( $year_min && $year_max && $year_min !== $year_max )
    ? $year_min . '–' . $year_max
    : ( $year_min ?: '—' );

/* ── 格式 / 狀態 / 季節 對照表 ──────────────────────────── */
$format_labels = [
    'TV'       => 'TV',    'TV_SHORT' => 'TV短篇', 'MOVIE'   => '劇場版',
    'OVA'      => 'OVA',   'ONA'      => 'ONA',    'SPECIAL' => '特別篇',
    'MUSIC'    => 'MV',
    'MANGA'    => '漫畫',  'ONE_SHOT' => '短篇',   'NOVEL'   => '小說',
    'LIGHT_NOVEL' => '輕小說',
];
$status_labels = [
    'FINISHED'         => '已完結',
    'RELEASING'        => '連載中',
    'NOT_YET_RELEASED' => '尚未發布',
    'CANCELLED'        => '已取消',
    'HIATUS'           => '暫停中',
];
$status_classes = [
    'FINISHED'         => 's-fin',
    'RELEASING'        => 's-rel',
    'NOT_YET_RELEASED' => 's-pre',
    'CANCELLED'        => 's-can',
    'HIATUS'           => 's-hia',
];
$season_labels = [
    'WINTER' => '冬', 'SPRING' => '春',
    'SUMMER' => '夏', 'FALL'   => '秋',
];

/* ── GEO/AEO 導言句（自動生成，無條件顯示）── */
$geo_type_labels = [ 'anime' => '動畫', 'manga' => '漫畫', 'novel' => '小說', 'game' => '遊戲', 'music' => '音樂' ];
$geo_parts       = [];
foreach ( $geo_type_labels as $cpt => $label ) {
    if ( ( $count_by_type[ $cpt ] ?? 0 ) > 0 ) {
        $geo_parts[] = $label . ' ' . $count_by_type[ $cpt ] . ' 部';
    }
}
$geo_total = count( $all_posts );
$geo_intro = '';
if ( $geo_total > 0 ) {
    $geo_intro = sprintf( '《%s》系列共收錄 %d 部作品', $series_name, $geo_total );
    if ( $geo_parts ) {
        $geo_intro .= '，涵蓋' . implode( '、', $geo_parts );
    }
    if ( $year_min && $year_max && $year_min !== $year_max ) {
        $geo_intro .= sprintf( '，年份橫跨 %d–%d', $year_min, $year_max );
    } elseif ( $year_min ) {
        $geo_intro .= sprintf( '，首發於 %d 年', $year_min );
    }
    $geo_intro .= '。本頁彙整該系列的動畫、漫畫、小說、遊戲與音樂等各類作品，可依媒體類型切換瀏覽。';
}

/* ── Schema：CollectionPage + ItemList ── */
$item_type_map = [
    'anime' => 'TVSeries',
    'manga' => 'ComicSeries',
    'novel' => 'Book',
    'game'  => 'VideoGame',
    'music' => 'MusicAlbum',
];

$list_elements = [];
$position      = 0;
foreach ( $all_posts as $p ) {
    $position++;

    $alt_names = array_values( array_unique( array_filter( [
        $p['title_na'],
        $p['title_ro'],
    ], fn( $v ) => $v !== '' && $v !== null ) ) );

    $item = [
        '@type' => $item_type_map[ $p['post_type'] ] ?? 'CreativeWork',
        'name'  => $p['title_zh'],
        'url'   => $p['permalink'],
    ];
    if ( $alt_names ) {
        $item['alternateName'] = count( $alt_names ) === 1 ? $alt_names[0] : $alt_names;
    }
    if ( $p['cover'] ) {
        $item['image'] = $p['cover'];
    }
    if ( $p['year'] ) {
        $item['datePublished'] = (string) $p['year'];
    }

    $list_elements[] = [
        '@type'    => 'ListItem',
        'position' => $position,
        'item'     => array_filter( $item, fn( $v ) => $v !== null && $v !== '' && $v !== [] ),
    ];
}

$schema_collection = [
    '@context'    => 'https://schema.org',
    '@type'       => 'CollectionPage',
    'name'        => $series_name . ' 系列作品列表',
    'url'         => is_wp_error( $series_url ) ? '' : $series_url,
    'description' => $geo_intro ?: ( $series_desc ? wp_strip_all_tags( $series_desc ) : '' ),
    'mainEntity'  => [
        '@type'           => 'ItemList',
        'name'            => $series_name . ' 系列作品',
        'numberOfItems'   => count( $list_elements ),
        'itemListElement' => $list_elements,
    ],
];
$schema_collection = array_filter( $schema_collection, fn( $v ) => $v !== null && $v !== '' );

/* ── 卡片渲染 helper ── */
if ( ! function_exists( 'asa_render_card' ) ) {
    function asa_render_card(
        array $p,
        array $format_labels,
        array $status_labels,
        array $status_classes,
        array $season_labels
    ): void {
        $score = ( is_numeric( $p['score_raw'] ) && (float) $p['score_raw'] > 0 )
            ? number_format( (float) $p['score_raw'] / 10, 1 ) : '';

        $format_label = $format_labels[ $p['format'] ] ?? $p['format'];
        $status_label = $status_labels[ $p['status'] ] ?? '';
        $status_class = $status_classes[ $p['status'] ] ?? '';
        $season_label = $season_labels[ strtoupper( (string) $p['season'] ) ] ?? '';
        $season_str   = ( $p['year'] && $season_label ) ? $p['year'] . ' ' . $season_label : '';

        $count_str = '';
        if ( $p['post_type'] === 'anime' && $p['episodes'] ) {
            $count_str = $p['episodes'] . ' 集';
        } elseif ( $p['post_type'] === 'manga' ) {
            if ( $p['volumes'] )       $count_str = $p['volumes'] . ' 卷';
            elseif ( $p['chapters'] )  $count_str = $p['chapters'] . ' 話';
        } elseif ( $p['post_type'] === 'novel' && $p['volumes'] ) {
            $count_str = $p['volumes'] . ' 卷';
        } elseif ( ( $p['post_type'] === 'game' || $p['post_type'] === 'music' ) && $p['episodes'] ) {
            $count_str = $p['episodes'] . ' 項';
        }
        ?>
        <article class="asa-card" data-type="<?php echo esc_attr( $p['post_type'] ); ?>">
            <a href="<?php echo esc_url( $p['permalink'] ); ?>" class="asa-card-link">

                <div class="asa-card-cover-wrap">
                    <?php if ( $p['cover'] ) : ?>
                        <img class="asa-card-cover"
                             src="<?php echo esc_url( $p['cover'] ); ?>"
                             alt="<?php echo esc_attr( $p['title_zh'] ); ?> 封面圖"
                             loading="lazy">
                    <?php else : ?>
                        <div class="asa-card-cover asa-no-cover">無封面</div>
                    <?php endif; ?>

                    <?php if ( $status_label ) : ?>
                        <span class="asa-status-badge <?php echo esc_attr( $status_class ); ?>">
                            <?php echo esc_html( $status_label ); ?>
                        </span>
                    <?php endif; ?>

                    <?php if ( $score ) : ?>
                        <span class="asa-score-badge">⭐ <?php echo esc_html( $score ); ?></span>
                    <?php endif; ?>
                </div>

                <div class="asa-card-body">
                    <h3 class="asa-card-title"><?php echo esc_html( $p['title_zh'] ); ?></h3>

                    <?php if ( $p['title_na'] ) : ?>
                        <p class="asa-card-native"><?php echo esc_html( $p['title_na'] ); ?></p>
                    <?php endif; ?>

                    <div class="asa-card-meta">
                        <?php if ( $format_label ) : ?>
                            <span class="asa-meta-tag asa-meta-format">
                                <?php echo esc_html( $format_label ); ?>
                            </span>
                        <?php endif; ?>
                        <?php if ( $season_str ) : ?>
                            <span class="asa-meta-tag asa-meta-season">
                                <?php echo esc_html( $season_str ); ?>
                            </span>
                        <?php endif; ?>
                        <?php if ( $count_str ) : ?>
                            <span class="asa-meta-tag asa-meta-ep">
                                <?php echo esc_html( $count_str ); ?>
                            </span>
                        <?php endif; ?>
                    </div>
                </div>

            </a>
        </article>
        <?php
    }
}

/* ── 渲染一個 Tab Panel（依年份分組 + 卡片網格）── */
if ( ! function_exists( 'asa_render_tab_panel' ) ) {
    function asa_render_tab_panel(
        string $tab_key,
        array $posts,
        bool $is_active,
        array $format_labels,
        array $status_labels,
        array $status_classes,
        array $season_labels
    ): array {
        $grouped = [];
        foreach ( $posts as $p ) {
            $key = $p['year'] ?: '未知年份';
            $grouped[ $key ][] = $p;
        }
        ksort( $grouped );

        $panel_id = 'asa-panel-' . $tab_key;
        ?>
        <section class="asa-tab-panel <?php echo $is_active ? 'is-active' : ''; ?>"
                 id="<?php echo esc_attr( $panel_id ); ?>"
                 role="tabpanel"
                 aria-labelledby="asa-tab-<?php echo esc_attr( $tab_key ); ?>"
                 <?php echo $is_active ? '' : 'hidden'; ?>>

            <?php if ( empty( $posts ) ) : ?>
                <div class="asa-empty">
                    <p>這個分類目前還沒有作品。</p>
                </div>
            <?php else : ?>
                <?php foreach ( $grouped as $group_year => $group_posts ) : ?>
                    <section class="asa-year-section"
                             id="year-<?php echo esc_attr( $tab_key . '-' . $group_year ); ?>">
                        <h2 class="asa-year-heading">
                            <?php echo esc_html( $group_year ); ?>
                            <span class="asa-year-count">（<?php echo count( $group_posts ); ?>）</span>
                        </h2>
                        <div class="asa-cards">
                            <?php foreach ( $group_posts as $p ) {
                                asa_render_card( $p, $format_labels, $status_labels, $status_classes, $season_labels );
                            } ?>
                        </div>
                    </section>
                <?php endforeach; ?>
            <?php endif; ?>

        </section>
        <?php

        return array_keys( $grouped );
    }
}
?>

<script type="application/ld+json"><?php echo wp_json_encode( $schema_collection, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); ?></script>

<div class="asa-wrap">

    <nav class="asa-breadcrumb" aria-label="麵包屑導航">
        <ol>
            <li><a href="<?php echo esc_url( home_url( '/' ) ); ?>">首頁</a></li>
            <li><a href="<?php echo esc_url( home_url( '/anime/' ) ); ?>">動漫列表</a></li>
            <li><?php echo esc_html( $series_name ); ?></li>
        </ol>
    </nav>

    <div class="asa-hero">
        <div class="asa-hero-inner">
            <div class="asa-hero-label">🔮 跨媒體作品系列</div>
            <h1 class="asa-hero-title"><?php echo esc_html( $series_name ); ?></h1>
            <?php if ( $series_slug && $series_slug !== sanitize_title( $series_name ) ) : ?>
                <p class="asa-hero-romaji"><?php echo esc_html( $series_slug ); ?></p>
            <?php endif; ?>
            <div class="asa-hero-meta">
                <span class="asa-hero-meta-item">
                    🎬 <strong><?php echo esc_html( count( $all_posts ) ); ?></strong> 部作品
                </span>
                <?php if ( $total_episodes ) : ?>
                <span class="asa-hero-meta-sep">·</span>
                <span class="asa-hero-meta-item">
                    📋 <strong><?php echo esc_html( $total_episodes ); ?></strong> 集
                </span>
                <?php endif; ?>
                <?php if ( $year_span ) : ?>
                <span class="asa-hero-meta-sep">·</span>
                <span class="asa-hero-meta-item">
                    📅 <strong><?php echo esc_html( $year_span ); ?></strong>
                </span>
                <?php endif; ?>
            </div>

            <?php if ( $geo_intro ) : ?>
                <p class="asa-hero-desc"><?php echo esc_html( $geo_intro ); ?></p>
            <?php endif; ?>

            <?php if ( $series_desc ) : ?>
                <p class="asa-hero-desc"><?php echo wp_kses_post( $series_desc ); ?></p>
            <?php endif; ?>
        </div>
    </div>

    <div class="asa-layout">

        <main class="asa-main">

            <?php if ( empty( $all_posts ) ) : ?>

                <div class="asa-empty">
                    <div class="asa-empty-icon">📭</div>
                    <p class="asa-empty-title">這個系列目前沒有已匯入的作品。</p>
                    <?php if ( current_user_can( 'manage_options' ) ) : ?>
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=anime-sync-import' ) ); ?>"
                           class="asa-btn">前往匯入動漫</a>
                    <?php endif; ?>
                </div>

            <?php else : ?>

                <?php
                $visible_tabs = [];
                foreach ( $tab_config as $key => $cfg ) {
                    if ( $key === 'all' ) {
                        $visible_tabs[ $key ] = $cfg;
                        continue;
                    }
                    $cnt = 0;
                    foreach ( $cfg['cpts'] as $cpt ) {
                        $cnt += $count_by_type[ $cpt ] ?? 0;
                    }
                    if ( $cnt > 0 ) {
                        $cfg['count']        = $cnt;
                        $visible_tabs[ $key ] = $cfg;
                    }
                }
                $visible_tabs['all']['count'] = count( $all_posts );

                $non_all_keys = array_diff( array_keys( $visible_tabs ), [ 'all' ] );
                $default_tab  = ( count( $non_all_keys ) === 1 ) ? reset( $non_all_keys ) : 'all';
                ?>

                <div class="asa-tabs" role="tablist" aria-label="作品類型切換">
                    <?php foreach ( $visible_tabs as $key => $cfg ) :
                        $is_active = ( $key === $default_tab );
                    ?>
                        <button type="button"
                                class="asa-tab"
                                role="tab"
                                id="asa-tab-<?php echo esc_attr( $key ); ?>"
                                data-type="<?php echo esc_attr( $key ); ?>"
                                data-target="asa-panel-<?php echo esc_attr( $key ); ?>"
                                aria-controls="asa-panel-<?php echo esc_attr( $key ); ?>"
                                aria-selected="<?php echo $is_active ? 'true' : 'false'; ?>">
                            <span class="asa-tab-icon"><?php echo esc_html( $cfg['icon'] ); ?></span>
                            <span class="asa-tab-label"><?php echo esc_html( $cfg['label'] ); ?></span>
                            <span class="asa-tab-count"><?php echo esc_html( $cfg['count'] ); ?></span>
                        </button>
                    <?php endforeach; ?>
                </div>

                <?php
                $tab_years = [];

                foreach ( $visible_tabs as $key => $cfg ) {
                    $tab_posts = array_filter(
                        $all_posts,
                        fn( $p ) => in_array( $p['post_type'], $cfg['cpts'], true )
                    );
                    $tab_posts = array_values( $tab_posts );

                    $tab_years[ $key ] = asa_render_tab_panel(
                        $key,
                        $tab_posts,
                        $key === $default_tab,
                        $format_labels,
                        $status_labels,
                        $status_classes,
                        $season_labels
                    );
                }
                ?>

            <?php endif; ?>

        </main><!-- .asa-main -->

        <aside class="asa-sidebar">

            <div class="asa-sidebar-card">
                <h3 class="asa-sidebar-title">📊 系列統計</h3>
                <ul class="asa-stat-list">
                    <li>
                        <span class="asa-stat-label">總作品數</span>
                        <span class="asa-stat-value"><?php echo esc_html( count( $all_posts ) ); ?> 部</span>
                    </li>
                    <?php foreach ( [ 'anime' => '動畫', 'manga' => '漫畫', 'novel' => '小說', 'game' => '遊戲', 'music' => '音樂' ] as $cpt => $label ) :
                        if ( ( $count_by_type[ $cpt ] ?? 0 ) === 0 ) continue;
                    ?>
                    <li>
                        <span class="asa-stat-label"><?php echo esc_html( $label ); ?></span>
                        <span class="asa-stat-value"><?php echo esc_html( $count_by_type[ $cpt ] ); ?> 部</span>
                    </li>
                    <?php endforeach; ?>
                    <?php if ( $total_episodes ) : ?>
                    <li>
                        <span class="asa-stat-label">總集數</span>
                        <span class="asa-stat-value"><?php echo esc_html( $total_episodes ); ?> 集</span>
                    </li>
                    <?php endif; ?>
                    <?php if ( $year_span ) : ?>
                    <li>
                        <span class="asa-stat-label">年份</span>
                        <span class="asa-stat-value"><?php echo esc_html( $year_span ); ?></span>
                    </li>
                    <?php endif; ?>
                    <?php if ( $root_anilist_id ) : ?>
                    <li>
                        <span class="asa-stat-label">AniList</span>
                        <span class="asa-stat-value">
                            <a href="https://anilist.co/anime/<?php echo esc_attr( $root_anilist_id ); ?>/"
                               target="_blank" rel="noopener noreferrer" class="asa-ext-link">
                                查看根作品 ↗
                            </a>
                        </span>
                    </li>
                    <?php endif; ?>
                </ul>
            </div>

            <?php if ( ! empty( $tab_years ) && ! empty( $tab_years[ $default_tab ?? 'all' ] ?? [] ) ) : ?>
            <div class="asa-sidebar-card" id="asa-jump-card">
                <h3 class="asa-sidebar-title">🗂️ 快速跳轉</h3>
                <nav class="asa-jump-nav" aria-label="依年份跳轉" id="asa-jump-nav">
                    <?php foreach ( $tab_years[ $default_tab ] as $jump_year ) : ?>
                        <a href="#year-<?php echo esc_attr( $default_tab . '-' . $jump_year ); ?>"
                           class="asa-jump-btn">
                            <?php echo esc_html( $jump_year ); ?>
                        </a>
                    <?php endforeach; ?>
                </nav>
            </div>
            <?php endif; ?>

            <?php
            $related_formats = [];
            foreach ( $all_posts as $p ) {
                if ( $p['format'] && isset( $format_labels[ $p['format'] ] ) ) {
                    $related_formats[ $p['format'] ] = $format_labels[ $p['format'] ];
                }
            }
            ?>
            <?php if ( $related_formats ) : ?>
            <div class="asa-sidebar-card">
                <h3 class="asa-sidebar-title">🏷️ 作品類型</h3>
                <div class="asa-tag-row">
                    <?php foreach ( $related_formats as $fmt_key => $fmt_label ) :
                        $fmt_term = get_term_by( 'slug', strtolower( $fmt_key ), 'anime_format_tax' );
                        $fmt_url  = $fmt_term ? get_term_link( $fmt_term ) : '#';
                    ?>
                        <a href="<?php echo esc_url( is_wp_error( $fmt_url ) ? '#' : $fmt_url ); ?>"
                           class="asa-tag">
                            <?php echo esc_html( $fmt_label ); ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

        </aside><!-- .asa-sidebar -->

    </div><!-- .asa-layout -->

</div><!-- .asa-wrap -->

<script>
window.asaTabYears = <?php echo wp_json_encode( $tab_years ?? new stdClass(), JSON_UNESCAPED_UNICODE ); ?>;
window.asaDefaultTab = <?php echo wp_json_encode( $default_tab ?? 'all' ); ?>;
</script>

<script>
(function () {
    'use strict';

    var tabs   = document.querySelectorAll('.asa-tab');
    var panels = document.querySelectorAll('.asa-tab-panel');

    function activateTab(tabKey) {
        tabs.forEach(function (t) {
            var isActive = t.dataset.type === tabKey;
            t.setAttribute('aria-selected', isActive ? 'true' : 'false');
        });

        panels.forEach(function (p) {
            var isActive = p.id === 'asa-panel-' + tabKey;
            p.classList.toggle('is-active', isActive);
            if (isActive) {
                p.removeAttribute('hidden');
            } else {
                p.setAttribute('hidden', '');
            }
        });

        updateJumpNav(tabKey);

        if (history.replaceState) {
            history.replaceState(null, '', '#tab-' + tabKey);
        }
    }

    tabs.forEach(function (tab) {
        tab.addEventListener('click', function () {
            activateTab(this.dataset.type);
        });
    });

    function updateJumpNav(tabKey) {
        var nav = document.getElementById('asa-jump-nav');
        if (!nav || !window.asaTabYears) return;

        var years = window.asaTabYears[tabKey] || [];
        if (years.length === 0) {
            var card = document.getElementById('asa-jump-card');
            if (card) card.style.display = 'none';
            return;
        }

        var card = document.getElementById('asa-jump-card');
        if (card) card.style.display = '';

        nav.innerHTML = years.map(function (y) {
            return '<a href="#year-' + tabKey + '-' + y + '" class="asa-jump-btn">' + y + '</a>';
        }).join('');

        bindSmoothScroll();
    }

    function bindSmoothScroll() {
        document.querySelectorAll('.asa-jump-btn').forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                var target = document.querySelector(this.getAttribute('href'));
                if (target) {
                    target.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }
            });
        });
    }

    bindSmoothScroll();

    if (window.location.hash.indexOf('#tab-') === 0) {
        var hashTab = window.location.hash.replace('#tab-', '');
        var validTab = document.querySelector('.asa-tab[data-type="' + hashTab + '"]');
        if (validTab) {
            activateTab(hashTab);
        }
    }
})();
</script>

<?php get_footer(); ?>