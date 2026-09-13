<?php
/**
 * Archive Series Template
 * Plugin: Anime Sync Pro
 * Path: wp-content/plugins/anime-sync-pro/public/templates/archive-series.php
 * Version: 1.4.0 (noindex 修正版)
 *
 * v1.4.0 (2026-08-12):
 *   - [修正] get_header() 原本寫在檔案最頂端，早於 $all_posts 資料蒐集
 *     完成，導致想依內容量決定 noindex 時 wp_head() 早已跑完、來不及
 *     掛 filter。現在把 get_header() 移到 $all_posts（含 manga fallback）
 *     計算完成之後、輸出 HTML 之前。
 *   - [新增] 系列內作品數 <= 1 時（等同該系列頁內容與單一作品頁近乎
 *     重複），透過 rank_math/frontend/robots filter 動態輸出
 *     noindex,follow，避免被 Google 判定為近乎重複內容。之後若系列
 *     內作品數增加到 2 部以上，自動恢復可索引。
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

/* ── [v1.4.0] get_header() 不再放最前面 ──
 * 原本這裡就呼叫 get_header()，但 noindex 判斷需要先知道
 * $all_posts 的數量，所以往後移到資料蒐集完成之後。
 * 中間所有邏輯都不依賴 header 是否已輸出，搬移是安全的。
 */

/* ── 系列 Term 資訊 ──────────────────────────────────────── */
$series_term     = get_queried_object();
$series_name     = $series_term->name   ?? '系列';
$series_slug     = $series_term->slug   ?? '';
$series_desc     = term_description( $series_term->term_id ?? 0 );
$series_count    = (int) ( $series_term->count ?? 0 );
$series_url      = get_term_link( $series_term );
/*
 * [v1.6.0] 修正 term meta 鍵名。
 *
 * 這裡原本讀 'anime_series_root_id'，但寫入端
 * （class-import-manager.php:561 的 assign_series_taxonomy()）存的是
 * '_series_root_anilist_id'——兩邊從來沒對上。正式站實測：
 * anime_series_root_id 0 筆、_series_root_anilist_id 487 筆，
 * 也就是下方那個 AniList 外部連結區塊在 1,211 個系列頁上全部靜默不顯示。
 *
 * 舊鍵名保留為後備，避免有任何手動填過的資料被忽略。
 */
$root_anilist_id = get_term_meta( $series_term->term_id ?? 0, '_series_root_anilist_id', true );
if ( ! $root_anilist_id ) {
    $root_anilist_id = get_term_meta( $series_term->term_id ?? 0, 'anime_series_root_id', true );
}

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
            /*
             * 完整開播日（YYYYMMDD）。
             *
             * 觀看順序必須精確到「日」：《傷物語〈Ⅰ 鐵血篇〉》2016-01-08 與
             * 《歷物語》2016-01-09 只差一天，只比年份的話兩者先後不定，
             * 排出來的順序就是錯的。年份仍保留給篩選與分組用。
             */
            'sdate'      => preg_replace( '/\D/', '', (string) smacg_get_meta( $pid, 'start_date' ) ),
            'anilist_id' => (int) smacg_get_meta( $pid, 'anilist_id' ),
            'episodes'   => (int) smacg_get_meta( $pid, 'episodes' ),
            'volumes'    => (int) smacg_get_meta( $pid, 'volumes' ),
            'chapters'   => (int) smacg_get_meta( $pid, 'chapters' ),
        ];
    }
}

/* ── 一次 Query 取得所有「已打系列 tag」的作品（多 CPT，pre_get_posts 只設 post_type/-1）── */
$all_posts = [];
if ( have_posts() ) {
    while ( have_posts() ) {
        the_post();
        $all_posts[] = asa_build_post_row( get_the_ID() );
    }
    wp_reset_postdata();
}

/* ── [v1.5.7] 年份排序改在 PHP 層做 ──────────────────────────
 * 原本是 pre_get_posts 用 meta_key='anime_season_year' + orderby=meta_value_num
 * 在 SQL 排序，但那會讓 WordPress 加上 INNER JOIN postmeta，把「沒有該 meta 列」
 * 的作品整個排除（未播出的動畫、以及所有 manga/novel/game/music）。
 *
 * 改在這裡排序的好處：$p['year'] 已經套過完整 fallback
 * （season_year → start_date → post_date），比原始 meta 更能反映真實年份，
 * 而且不會漏掉任何資料。未播出的作品沒有確定檔期，一律排在最後。
 */
usort( $all_posts, static function ( array $a, array $b ): int {
    $a_pending = ( ( $a['status'] ?? '' ) === 'NOT_YET_RELEASED' );
    $b_pending = ( ( $b['status'] ?? '' ) === 'NOT_YET_RELEASED' );

    if ( $a_pending !== $b_pending ) {
        return $a_pending ? 1 : -1;
    }

    /*
     * [v1.6.0] 先比完整開播日，再退回年份。
     *
     * 原本只比年份，同一年的作品之間順序取決於 SQL 回傳次序，等於不定。
     * 這在一般列表看不太出來，但「播出順序」是一條有語意的清單，
     * 排錯就是給讀者錯誤資訊——《傷物語〈Ⅰ 鐵血篇〉》(20160108) 與
     * 《歷物語》(20160109) 差一天，年份相同時必須靠完整日期才分得出來。
     *
     * 漫畫／小說／遊戲多半沒有 start_date，這時 sdate 為空字串，
     * 自動退回年份比較，行為與改動前一致。
     */
    $a_sdate = (string) ( $a['sdate'] ?? '' );
    $b_sdate = (string) ( $b['sdate'] ?? '' );

    if ( strlen( $a_sdate ) === 8 && strlen( $b_sdate ) === 8 && $a_sdate !== $b_sdate ) {
        return $a_sdate <=> $b_sdate;
    }

    $a_year = (int) ( $a['year'] ?? 0 );
    $b_year = (int) ( $b['year'] ?? 0 );

    // 年份不明者排在同群組最後，避免 0 被當成最早年份衝到最前面
    return ( $a_year ?: PHP_INT_MAX ) <=> ( $b_year ?: PHP_INT_MAX );
} );

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

/* ── [v1.4.0] 單一作品系列 → noindex,follow ──
 * $all_posts 到這裡已經是最終數量（含 manga fallback）。
 * 系列內只有 0～1 部作品時，這個系列頁除了 Tab／統計框架外，
 * 呈現的內容跟該作品自己的文章頁幾乎完全重複，屬於近乎重複內容。
 * 用 RankMath 的 filter 動態掛 noindex，而不是自己印 <meta>，
 * 避免跟 RankMath 本身輸出的 robots meta 衝突。
 * 未來系列擴充到 2 部以上作品，$all_posts 數量增加，
 * 此區塊不再觸發，頁面自動恢復可索引。
 */
$asa_series_is_thin = ( count( $all_posts ) <= 1 );
if ( $asa_series_is_thin ) {
    add_filter( 'rank_math/frontend/robots', function ( $robots ) {
        $robots['index']  = 'noindex';
        $robots['follow'] = 'follow';
        unset( $robots['noarchive'], $robots['nosnippet'] );
        return $robots;
    } );
}

get_header();

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

/* ── [v1.6.0] 播出順序 ────────────────────────────────────────────────
 *
 * ★ 為什麼標題寫「播出順序」而不是「觀看順序」
 *
 *   程式能從資料算出來的只有「依日本首播日排列」。至於「該照什麼順序看」
 *   是編輯判斷——有些系列的故事時間軸與播出順序不同（物語系列就是最有名的
 *   例子）。把播出順序直接叫「觀看順序」會讓讀者以為那是我們的推薦，
 *   而那是我們沒有做過的判斷。所以自動產生的一律標「播出順序」，
 *   只有 term meta 填了人工排序時，才會顯示「本站建議觀看順序」。
 *
 * ★ 總集篇要標出來
 *
 *   《傷物語 -歷吸血鬼-》是前三部劇場版的總集篇，照日期排在第 15 位。
 *   不標註的話讀者會當成新故事去看，這是這種清單最容易造成的誤解。
 *
 *   偵測方式只採「反向 SUMMARY 邊」：AniList 是由**原作**指向總集篇
 *   （《進擊的巨人》→ Chronicle），總集篇本身不帶這個關聯，所以要反過來建索引。
 *
 *   刻意不用「MOVIE 且有多個 ANIME PARENT」這個看似合理的啟發法——
 *   正式站實測 25 部符合，裡面《紅蓮之絆篇》《五等分的新娘 新作OVA》
 *   《怪獸8號 番外篇》全是真新作。拿它判斷會把新作誤標成總集篇，
 *   比不標更糟。查不到就不標。
 */
$recap_ids = [];   // 被其他作品列為 SUMMARY 目標的 AniList ID
foreach ( $all_posts as $p ) {
    $rel = json_decode( (string) get_post_meta( $p['id'], 'anime_relations_json', true ), true );
    foreach ( (array) $rel as $r ) {
        if ( ( $r['relation_type'] ?? '' ) === 'SUMMARY' && ! empty( $r['id'] ) ) {
            $recap_ids[ (int) $r['id'] ] = true;
        }
    }
}

/* 觀看順序只收動畫：漫畫、小說、遊戲、音樂不屬於「照順序看」這件事 */
$watch_aired   = [];
$watch_pending = [];
foreach ( $all_posts as $p ) {
    if ( ( $p['post_type'] ?? '' ) !== 'anime' ) { continue; }
    $p['is_recap'] = ! empty( $p['anilist_id'] ) && isset( $recap_ids[ $p['anilist_id'] ] );
    if ( ( $p['status'] ?? '' ) === 'NOT_YET_RELEASED' || strlen( (string) $p['sdate'] ) !== 8 ) {
        $watch_pending[] = $p;
    } else {
        $watch_aired[] = $p;
    }
}

/*
 * 人工排定的順序（沒有就不顯示）。
 * term meta 存 post ID 陣列；只有編輯真的排過，才敢稱「建議觀看順序」。
 */
$manual_order = get_term_meta( $series_term->term_id ?? 0, '_series_watch_order', true );
$manual_note  = (string) get_term_meta( $series_term->term_id ?? 0, '_series_watch_order_note', true );
$has_manual   = false;
if ( is_array( $manual_order ) && count( $manual_order ) >= 2 ) {
    $by_id = [];
    foreach ( $watch_aired as $p ) { $by_id[ $p['id'] ] = $p; }
    $ordered = [];
    foreach ( $manual_order as $mid ) {
        if ( isset( $by_id[ (int) $mid ] ) ) { $ordered[] = $by_id[ (int) $mid ]; unset( $by_id[ (int) $mid ] ); }
    }
    // 沒被排到的補在後面，不讓人工清單漏掉作品
    foreach ( $by_id as $p ) { $ordered[] = $p; }
    if ( count( $ordered ) >= 2 ) { $watch_aired = $ordered; $has_manual = true; }
}

/* ≥2 部才有「順序」可言，1 部的頁面顯示順序清單只是噪音 */
$show_watch_order = count( $watch_aired ) >= 2;

/* 日期格式化：20160108 → 2016/01/08 */
$asa_fmt_date = static function ( string $s ): string {
    return strlen( $s ) === 8
        ? substr( $s, 0, 4 ) . '/' . substr( $s, 4, 2 ) . '/' . substr( $s, 6, 2 )
        : '';
};

/*
 * ★ AEO：給 AI 一句可以整段引用的答案
 *
 * AI 概覽與聊天機器人引用的是「單獨拿出來也讀得懂」的句子。
 * 所以這句話自帶主詞、自帶站名、自帶排序依據，不依賴上下文；
 * 同時明講依據是首播日，避免被轉述成「本站推薦這樣看」。
 */
$aeo_answer = '';
if ( $show_watch_order ) {
    $seq = [];
    foreach ( array_slice( $watch_aired, 0, 12 ) as $i => $p ) {
        $seq[] = ( $i + 1 ) . '.《' . $p['title_zh'] . '》'
               . ( $p['year'] ? '（' . $p['year'] . '）' : '' )
               . ( ! empty( $p['is_recap'] ) ? '〔總集篇〕' : '' );
    }
    $aeo_answer = sprintf(
        '%s：《%s》系列動畫共 %d 部，%s為 %s%s。',
        $has_manual ? '微笑ACG 建議觀看順序' : '依日本首播日排列',
        $series_name,
        count( $watch_aired ) + count( $watch_pending ),
        $has_manual ? '本站建議的觀看順序' : '播出順序',
        implode( '、', $seq ),
        count( $watch_aired ) > 12 ? '等' : ''
    );
    if ( $watch_pending ) {
        $aeo_answer .= sprintf( '另有 %d 部尚未公布播出日期。', count( $watch_pending ) );
    }
}

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
    /*
     * description 優先給 AEO 那句可整段引用的答案。
     * $geo_intro 只描述「有幾部、涵蓋哪些類型」，$aeo_answer 直接回答
     * 「順序是什麼」——後者才是搜尋這個頁面的人真正要的答案。
     */
    'description' => $aeo_answer ?: ( $geo_intro ?: ( $series_desc ? wp_strip_all_tags( $series_desc ) : '' ) ),
    'mainEntity'  => array_filter( [
        '@type'           => 'ItemList',
        'name'            => $series_name . ( $show_watch_order ? ( $has_manual ? ' 建議觀看順序' : ' 播出順序' ) : ' 系列作品' ),
        /*
         * itemListOrder 明講這份清單是有序的。
         * 少了它，ItemList 在語意上等同「一堆東西」，position 只是流水號；
         * 有了它，消費端才知道 1→N 的先後是刻意的。
         */
        'itemListOrder'   => $show_watch_order ? 'https://schema.org/ItemListOrderAscending' : null,
        'numberOfItems'   => count( $list_elements ),
        'itemListElement' => $list_elements,
    ], fn( $v ) => $v !== null ),
];
$schema_collection = array_filter( $schema_collection, fn( $v ) => $v !== null && $v !== '' );

/*
 * BreadcrumbList：頁面上早就有視覺麵包屑（見下方 .asa-breadcrumb），
 * 但一直沒有對應的結構化資料，搜尋結果裡就少一層階層資訊。
 * 兩者的項目必須一致，否則等於自相矛盾。
 */
$schema_breadcrumb = [
    '@context'        => 'https://schema.org',
    '@type'           => 'BreadcrumbList',
    'itemListElement' => [
        [ '@type' => 'ListItem', 'position' => 1, 'name' => '首頁',     'item' => home_url( '/' ) ],
        [ '@type' => 'ListItem', 'position' => 2, 'name' => '動漫列表', 'item' => home_url( '/anime/' ) ],
        [ '@type' => 'ListItem', 'position' => 3, 'name' => $series_name ],
    ],
];

/* ── 卡片渲染 helper ── */
if ( ! function_exists( 'asa_render_card' ) ) {
    function asa_render_card(
        array $p,
        array $format_labels,
        array $status_labels,
        array $status_classes,
        array $season_labels
    ): void {
        /*
         * 卡片不再顯示評分（原本是封面右下角的 ⭐ 藥丸），與 /anime/ 列表一致。
         * score_raw 也一併從 asa_build_post_row() 移除——留著只是每張卡
         * 多讀一次 meta。評分仍在作品頁與排行榜顯示，資料本身沒有動。
         */

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
                <?php
                /*
                 * 狀態掛牌放在 .asa-card-link 底下、不放進 .asa-card-cover-wrap：
                 * cover-wrap 有 overflow:hidden，放裡面會被裁掉、伸不出卡片外。
                 * 定位基準是 .asa-card（該元素已補 position:relative）。
                 */
                ?>
                <?php if ( $status_label ) : ?>
                    <span class="asa-status-tag <?php echo esc_attr( $status_class ); ?>"><?php echo esc_html( $status_label ); ?></span>
                <?php endif; ?>


                <div class="asa-card-cover-wrap">
                    <?php if ( $p['cover'] ) : ?>
                        <img class="asa-card-cover"
                             src="<?php echo esc_url( $p['cover'] ); ?>"
                             alt="<?php echo esc_attr( $p['title_zh'] ); ?> 封面圖"
                             loading="lazy">
                    <?php else : ?>
                        <div class="asa-card-cover asa-no-cover">無封面</div>
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
<script type="application/ld+json"><?php echo wp_json_encode( $schema_breadcrumb, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); ?></script>

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

    <?php if ( $show_watch_order ) : ?>
    <?php
    /*
     * 播出順序 / 建議觀看順序。
     *
     * 三個防誤解的設計，每一個都對應一種實際會發生的誤讀：
     *   1. 標題與說明句直說排序依據是「首播日」，不含糊稱「觀看順序」；
     *   2. 總集篇掛牌並寫明「劇情與前作重複」，避免被當成新故事；
     *   3. 未公布檔期的獨立成一區，不塞進編號裡假裝有先後。
     */
    ?>
    <section class="asa-order" id="watch-order" aria-labelledby="asa-order-h2">
        <h2 class="asa-order__h2" id="asa-order-h2">
            <?php echo esc_html( $series_name ); ?>
            <?php echo $has_manual ? '建議觀看順序' : '播出順序'; ?>
        </h2>

        <p class="asa-order__lead">
            <?php if ( $has_manual ) : ?>
                以下是本站編輯排定的建議觀看順序，共 <strong><?php echo count( $watch_aired ); ?></strong> 部。
                <?php echo $manual_note ? esc_html( $manual_note ) : ''; ?>
            <?php else : ?>
                以下 <strong><?php echo count( $watch_aired ); ?></strong> 部依<strong>日本首播日期</strong>由早到晚排列。
                部分系列的故事時間軸與播出順序不同，若你想照劇情時序觀看，請以官方或原作說明為準。
            <?php endif; ?>
        </p>

        <ol class="asa-order__list">
            <?php foreach ( $watch_aired as $wi => $p ) : ?>
                <li class="asa-order__item<?php echo ! empty( $p['is_recap'] ) ? ' is-recap' : ''; ?>">
                    <span class="asa-order__num"><?php echo (int) ( $wi + 1 ); ?></span>
                    <span class="asa-order__body">
                        <a class="asa-order__title" href="<?php echo esc_url( $p['permalink'] ); ?>"><?php echo esc_html( $p['title_zh'] ); ?></a>
                        <?php if ( ! empty( $p['is_recap'] ) ) : ?>
                            <span class="asa-order__badge">總集篇</span>
                        <?php endif; ?>
                        <span class="asa-order__meta">
                            <?php
                            $bits = array_filter( [
                                $format_labels[ $p['format'] ] ?? $p['format'],
                                $p['episodes'] > 0 ? $p['episodes'] . ' 集' : '',
                                $asa_fmt_date( (string) $p['sdate'] ),
                            ] );
                            echo esc_html( implode( '｜', $bits ) );
                            ?>
                        </span>
                        <?php if ( ! empty( $p['is_recap'] ) ) : ?>
                            <span class="asa-order__hint">劇情與系列前作重複，初次觀看可略過。</span>
                        <?php endif; ?>
                    </span>
                </li>
            <?php endforeach; ?>
        </ol>

        <?php if ( $watch_pending ) : ?>
            <p class="asa-order__pending">
                <strong>尚未公布播出日期（<?php echo count( $watch_pending ); ?> 部）：</strong>
                <?php
                $pend = [];
                foreach ( $watch_pending as $p ) { $pend[] = $p['title_zh']; }
                echo esc_html( implode( '、', $pend ) );
                ?>
                　這幾部因為官方尚未公布檔期，無法排入上方順序；檔期公布後本頁會自動更新。
            </p>
        <?php endif; ?>
    </section>
    <?php endif; ?>

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

    <?php if ( $show_watch_order ) : ?>
    <?php
    /*
     * 常見問題。
     *
     * ★ 為什麼答案寫得這麼「完整句」
     *   AI 概覽與聊天機器人引用的是「單獨抽出來也讀得懂」的段落。
     *   「共 18 部」這種答案被抽走就沒有主詞，AI 不會用；
     *   「《物語系列》系列動畫在本站共收錄 18 部」才引用得動。
     *
     * ★ 不掛 FAQPage 結構化資料
     *   Google 從 2023 年 8 月起只對政府與醫療類網站顯示 FAQ 複合式結果，
     *   一般網站掛了不會有 rich result。但可見的問答本身仍有價值——
     *   那正是 AI 概覽會抓的段落，所以留純 HTML。
     *
     * ★ 第二題刻意講「我們不知道」
     *   沒有人工排序時，硬答「照播出順序看就對了」是我們沒把握的建議。
     *   照實說明差別、把判斷權還給讀者，比裝作有答案更不會誤導。
     */
    $faq_total   = count( $watch_aired ) + count( $watch_pending );
    $faq_first   = $watch_aired[0] ?? null;
    $faq_recaps  = array_values( array_filter( $watch_aired, fn( $x ) => ! empty( $x['is_recap'] ) ) );
    ?>
    <section class="asa-faq" aria-labelledby="asa-faq-h2">
        <h2 class="asa-faq__h2" id="asa-faq-h2"><?php echo esc_html( $series_name ); ?> 常見問題</h2>

        <?php if ( $faq_first ) : ?>
        <h3 class="asa-faq__q"><?php echo esc_html( $series_name ); ?>要從哪一部開始看？</h3>
        <p class="asa-faq__a">
            依日本首播日期，《<?php echo esc_html( $series_name ); ?>》系列動畫最早播出的是
            《<?php echo esc_html( $faq_first['title_zh'] ); ?>》<?php
            echo $faq_first['year'] ? '（' . esc_html( $faq_first['year'] ) . ' 年）' : ''; ?>。
            <?php if ( $has_manual ) : ?>
                本站建議的觀看順序已列在本頁「建議觀看順序」段落。
            <?php else : ?>
                本頁的順序是依首播日排列，並非依故事時間軸；
                若該系列的劇情時序與播出順序不同，建議以官方或原作說明為準。
            <?php endif; ?>
        </p>
        <?php endif; ?>

        <h3 class="asa-faq__q"><?php echo esc_html( $series_name ); ?>總共有幾部？</h3>
        <p class="asa-faq__a">
            《<?php echo esc_html( $series_name ); ?>》系列動畫在本站共收錄
            <strong><?php echo (int) $faq_total; ?></strong> 部，
            其中 <?php echo count( $watch_aired ); ?> 部已公布播出日期<?php
            if ( $watch_pending ) { echo '、' . count( $watch_pending ) . ' 部檔期未定'; } ?>。
            <?php if ( count( $all_posts ) > $faq_total ) : ?>
                另外本站也收錄了同系列的漫畫、小說、遊戲或音樂作品，合計
                <?php echo count( $all_posts ); ?> 筆，可在本頁依類型切換瀏覽。
            <?php endif; ?>
        </p>

        <?php if ( $faq_recaps ) : ?>
        <h3 class="asa-faq__q"><?php echo esc_html( $series_name ); ?>有哪幾部是總集篇、可以略過？</h3>
        <p class="asa-faq__a">
            《<?php echo esc_html( $series_name ); ?>》系列中，
            <?php
            $rn = [];
            foreach ( $faq_recaps as $r ) { $rn[] = '《' . $r['title_zh'] . '》'; }
            echo esc_html( implode( '、', $rn ) );
            ?>
            屬於總集篇，內容重新剪輯自系列前作，劇情沒有新進展，初次觀看可以略過；
            想快速回顧的人則可以拿它當複習。
        </p>
        <?php endif; ?>
    </section>
    <?php endif; ?>

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