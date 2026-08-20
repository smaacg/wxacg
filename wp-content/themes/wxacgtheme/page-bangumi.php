<?php
/**
 * Template Name: 番組表 - 季度詳細列表
 * File: blocksy-child/page-bangumi.php
 * Version: 1.8.0
 * Date: 2026-08-17
 *
 * Changelog
 *  v1.8.0 (2026-08-17) 分頁改為 全部／新作／續作
 *    - [Feature] 改讀 anime_has_prequel 欄位判斷新作／續作。該欄位由
 *                anime-sync-pro 的每小時 cron 搭既有 AniList 查詢順風車
 *                寫入（不額外發送 API 請求），前台只讀欄位、零外部請求。
 *    - [Change] 移除「跨季續播」分頁；跨季作品仍會出現在列表中，只是改
 *               依有無前作歸入新作或續作，不再單獨分頁。
 *  v1.7.3 (2026-08-17) 修正跨季續播漏抓大量作品
 *    - [Fix] 原本用「anime_end_date >= 本季開始」判斷跨季續播，但多數
 *            正在播出的作品結束日是 0（未定），導致 17 部跨季作品裡有
 *            15 部被濾掉（例如黃泉使者）。改以「開播日早於本季 + 狀態
 *            仍為 RELEASING」判斷，結束日改在 PHP 端做例外過濾。
 *  v1.7.2 (2026-08-17) 緊急修正：OR meta_query 造成查詢逾時
 *    - [Fix] v1.7.0 用單一 OR meta_query 同時撈「本季新番」與「跨季續播」，
 *            WordPress 會為 OR 的每個子條件各自 LEFT JOIN postmeta，
 *            在本站上千筆作品下查詢慢到頁面逾時。改為兩個各自單純的
 *            AND 查詢（結構同 v1.6.0 原查詢）再以 PHP 去重合併。
 *  v1.7.1 (2026-08-17) 緊急修正：移除拖垮頁面的即時 AniList 查詢
 *    - [Fix] v1.7.0 的「新作／續作」細分會在每次頁面載入時，對本季所有
 *            作品（75 部）同步呼叫 AniList API 判斷有無 PREQUEL，導致
 *            頁面組不出來直接逾時。移除該細分與 wxacg_bgm_has_prequel()，
 *            只保留純資料庫判斷的「本季新番／跨季續播」。
 *  v1.7.0 (2026-08-17) 跨季續播分頁
 *    - [Feature] WP_Query 加入「跨季續播」分支：RELEASING 且結束日落在
 *                本季（含）之後的作品，即使 anime_season 標籤是前一季也撈得到。
 *    - [UI] 新增「全部／本季新番／跨季續播」分頁列，篩選邏輯整合進既有
 *           bgm-card data-* 篩選架構，不影響原有篩選/排序/搜尋。
 *  v1.6.0 (2026-07-04) 日期格式相容 + 時間表優化
 *    - [Fix] anime_start_date 純數字 YYYYMMDD 格式無法被 strtotime 解析，
 *            導致 weekday 落回「待定」；新增 smacg_bgm_norm_date() 正規化。
 *    - [Feature] 新增 start_date_human（人類可讀開播日），詳情頁「開播」不再顯示裸數字。
 *    - [Feature] 時間表 item：無精確時刻時 fallback 顯示「M/D 開播」而非光禿禿「未定」。
 *    - [UI] 內嵌一段桌面版時間表緊湊化 CSS（可移除，若外部 css 已處理）。
 *  v1.5.0 (2026-06-21) SEO 關鍵字強化
 *  v1.4.0 (2026-05-19) P0 + P1 完整升級（三視圖 / 多維篩選 / 今日更新 / 21 平台徽章）
 *  v1.3.3 (2026-05-18) 基底版本
 *
 * @package weixiaoacg
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

/* ============================================================
 * [1.6.0] Helper：日期正規化
 * 支援 YYYYMMDD（純數字）與 YYYY-MM-DD，回傳 unix timestamp 或 0
 * ============================================================ */
if ( ! function_exists( 'smacg_bgm_norm_date_ts' ) ) {
    function smacg_bgm_norm_date_ts( $raw ) {
        $raw = trim( (string) $raw );
        if ( $raw === '' ) return 0;
        // 純數字 8 碼 YYYYMMDD → 補成 YYYY-MM-DD
        if ( ctype_digit( $raw ) && strlen( $raw ) === 8 ) {
            $raw = substr( $raw, 0, 4 ) . '-' . substr( $raw, 4, 2 ) . '-' . substr( $raw, 6, 2 );
        }
        $ts = strtotime( $raw );
        return $ts ? (int) $ts : 0;
    }
}

/* ============================================================
 * 區段 1：URL 解析、季節判定
 * ============================================================ */
$ym_raw = get_query_var( 'bangumi_ym' );
if ( ! $ym_raw && isset( $_SERVER['REQUEST_URI'] ) ) {
    if ( preg_match( '#/bangumi/(\d{6})/?#', $_SERVER['REQUEST_URI'], $mo ) ) {
        $ym_raw = $mo[1];
    }
}
if ( function_exists( 'smacg_bangumi_normalize_ym' ) ) {
    $ym = smacg_bangumi_normalize_ym( $ym_raw );
} else {
    $ym = $ym_raw ?: date_i18n( 'Ym' );
}

if ( function_exists( 'smacg_bangumi_parse_ym' ) ) {
    $ctx = smacg_bangumi_parse_ym( $ym );
} else {
    $y  = (int) substr( $ym, 0, 4 );
    $mn = (int) substr( $ym, 4, 2 );
    $season_key = $mn <= 3 ? 'WINTER' : ( $mn <= 6 ? 'SPRING' : ( $mn <= 9 ? 'SUMMER' : 'FALL' ) );
    $season_zh  = [ 'WINTER' => '冬', 'SPRING' => '春', 'SUMMER' => '夏', 'FALL' => '秋' ][ $season_key ];
    $ctx = [
        'year'         => $y,
        'month'        => $mn,
        'season'       => $season_key,
        'season_key'   => $season_key,
        'season_zh'    => $season_zh,
        'label'        => sprintf( '%d年%d月新番表', $y, $mn ),
        'season_label' => sprintf( '%d年%s季新番', $y, $season_zh ),
    ];
}

$prev_ym = function_exists( 'smacg_bangumi_shift_ym' ) ? smacg_bangumi_shift_ym( $ym, -1 ) : '';
$next_ym = function_exists( 'smacg_bangumi_shift_ym' ) ? smacg_bangumi_shift_ym( $ym, +1 ) : '';

$current_ym        = function_exists( 'smacg_bangumi_current_ym' ) ? smacg_bangumi_current_ym() : date_i18n( 'Ym' );
$is_current_season = ( $ym === $current_ym );

$season_themes = [
    'SPRING' => [ 'main' => '#f9a8d4', 'soft' => '#fce7f3', 'icon' => '🌸' ],
    'SUMMER' => [ 'main' => '#60a5fa', 'soft' => '#dbeafe', 'icon' => '🌊' ],
    'FALL'   => [ 'main' => '#fb923c', 'soft' => '#fed7aa', 'icon' => '🍁' ],
    'WINTER' => [ 'main' => '#94a3b8', 'soft' => '#e2e8f0', 'icon' => '❄️' ],
];
$theme_key = $ctx['season_key'] ?? ( $ctx['season'] ?? 'SPRING' );
$theme     = $season_themes[ $theme_key ] ?? $season_themes['SPRING'];

/* ============================================================
 * 區段 2：WP_Query 撈本季作品
 * ============================================================ */
global $wpdb;

$season_key = $ctx['season_key'] ?? $ctx['season'] ?? 'SPRING';
$year       = (int) $ctx['year'];

/* [1.7.0] 本季首月，用來判斷「跨季續播」：RELEASING 且結束日落在本季（含）之後的作品 */
$season_first_month = [ 'WINTER' => 1, 'SPRING' => 4, 'SUMMER' => 7, 'FALL' => 10 ][ $season_key ] ?? 1;
$season_start_ymd    = (int) sprintf( '%d%02d01', $year, $season_first_month );

/**
 * [1.7.2] 拆成兩個單純的 AND 查詢再於 PHP 合併。
 *
 * v1.7.0 用單一 OR meta_query 同時撈「本季新番」與「跨季續播」，
 * WordPress 會為 OR 的每個子條件各自 LEFT JOIN postmeta，在本站
 * 上千筆作品的資料量下查詢直接慢到頁面逾時。改用兩個各自只有兩個
 * 條件的 AND 查詢（與 v1.6.0 原本那句同樣結構，效能已驗證），
 * 再以 PHP 去重合併，避免 OR 造成的 JOIN 膨脹。
 */
$bgm_query_base = [
    'post_type'      => 'anime',
    'post_status'    => 'publish',
    'posts_per_page' => -1,
    'no_found_rows'  => true,
    'orderby'        => 'meta_value_num',
    'meta_key'       => 'anime_popularity',
    'order'          => 'DESC',
];

// 本季新番（結構與 v1.6.0 原本的查詢完全相同）
$q = new WP_Query( array_merge( $bgm_query_base, [
    'meta_query' => [
        'relation' => 'AND',
        [ 'key' => 'anime_season',      'value' => $season_key, 'compare' => '=' ],
        [ 'key' => 'anime_season_year', 'value' => $year,       'compare' => '=', 'type' => 'NUMERIC' ],
    ],
] ) );

/**
 * 跨季續播：在本季之前開播、且目前仍在播出的作品。
 *
 * ★ 不能用 anime_end_date 當條件——多數正在播出的作品結束日是 0（未定），
 *   用「結束日 >= 本季開始」會把它們全部濾掉。改以「開播日早於本季」判斷，
 *   是否已完結交給 anime_status。已收掉的作品（狀態仍為 RELEASING 但結束日
 *   已過）於下方合併時再以 PHP 過濾，避免多一個 meta JOIN。
 */
$q_continuing = new WP_Query( array_merge( $bgm_query_base, [
    'meta_query' => [
        'relation' => 'AND',
        [ 'key' => 'anime_status',     'value' => 'RELEASING',       'compare' => '=' ],
        [ 'key' => 'anime_start_date', 'value' => $season_start_ymd, 'compare' => '<', 'type' => 'NUMERIC' ],
    ],
] ) );

// 合併去重（本季新番優先，跨季續播補在後面）
$bgm_post_objects = $q->posts;
$bgm_seen_ids     = [];
foreach ( $bgm_post_objects as $bgm_obj ) {
    $bgm_seen_ids[ (int) $bgm_obj->ID ] = true;
}
foreach ( $q_continuing->posts as $bgm_obj ) {
    $bgm_id = (int) $bgm_obj->ID;
    if ( isset( $bgm_seen_ids[ $bgm_id ] ) ) {
        continue;
    }

    // 開播日不明的資料不納入（避免殘缺資料混進來）
    $bgm_start = (int) get_post_meta( $bgm_id, 'anime_start_date', true );
    if ( $bgm_start <= 0 ) {
        continue;
    }

    // 結束日有填、且早於本季開始 → 本季之前就播完了，不算跨季續播
    $bgm_end = (int) get_post_meta( $bgm_id, 'anime_end_date', true );
    if ( $bgm_end > 0 && $bgm_end < $season_start_ymd ) {
        continue;
    }

    $bgm_seen_ids[ $bgm_id ] = true;
    $bgm_post_objects[]      = $bgm_obj;
}

$rows            = [];
$tw_urls_by_post = [];
$genres_by_post  = [];

if ( ! empty( $bgm_post_objects ) ) {
    foreach ( $bgm_post_objects as $post_obj ) {
        $pid = (int) $post_obj->ID;
        $m   = get_post_meta( $pid );

        $tw_urls = [];
        foreach ( $m as $mk => $mv ) {
            if ( strpos( $mk, 'anime_tw_streaming_url_' ) === 0 && ! empty( $mv[0] ) ) {
                $platform              = substr( $mk, strlen( 'anime_tw_streaming_url_' ) );
                $tw_urls[ $platform ]  = $mv[0];
            }
        }
        $tw_urls_by_post[ $pid ] = $tw_urls;

        /* 類型 taxonomy */
        $g_terms = wp_get_post_terms( $pid, 'genre', [ 'fields' => 'names' ] );
        $genres_by_post[ $pid ] = is_wp_error( $g_terms ) ? [] : $g_terms;

        $rows[] = [
            'ID'             => $pid,
            'post_title'     => $post_obj->post_title,
            'post_name'      => $post_obj->post_name,
            'post_content'   => $post_obj->post_content,
            'post_excerpt'   => $post_obj->post_excerpt,
            'cover'          => $m['anime_cover_image'][0]        ?? '',
            'title_cn'       => $m['anime_title_chinese'][0]      ?? '',
            'title_jp'       => $m['anime_title_native'][0]       ?? '',
            'title_en'       => $m['anime_title_english'][0]      ?? '',
            'title_romaji'   => $m['anime_title_romaji'][0]       ?? '',
            'synopsis'       => $m['anime_synopsis_chinese'][0]   ?? '',
            'studios'        => $m['anime_studios'][0]            ?? '',
            'staff'          => $m['anime_staff_json'][0]         ?? '',
            'cast'           => $m['anime_cast_json'][0]          ?? '',
            'episodes_json'  => $m['anime_episodes_json'][0]      ?? '',
            'themes'         => $m['anime_themes'][0]             ?? '',
            'streaming'      => $m['anime_streaming'][0]          ?? '',
            'tw_platforms'   => $m['anime_tw_streaming'][0]       ?? '',
            'tw_other'       => $m['anime_tw_streaming_other'][0] ?? '',
            'tw_broadcast'   => $m['anime_tw_broadcast'][0]       ?? '',
            'trailer'        => $m['anime_trailer_url'][0]        ?? '',
            'score'          => isset( $m['anime_score_anilist'][0] ) ? (float) $m['anime_score_anilist'][0] : null,
            'popularity'     => (int) ( $m['anime_popularity'][0] ?? 0 ),
            'ep_total'       => (int) ( $m['anime_episodes'][0]   ?? 0 ),
            'ep_aired'       => (int) ( $m['anime_episodes_aired'][0] ?? 0 ),
            'next_airing'    => $m['anime_next_airing'][0]        ?? '',
            'start_date'     => $m['anime_start_date'][0]         ?? '',
            'source'         => $m['anime_source'][0]             ?? '',
            'format'         => $m['anime_format'][0]             ?? '',
            'status'         => $m['anime_status'][0]             ?? '',
            'official'       => $m['anime_official_site'][0]      ?? '',
            'is_new'         => ( ( $m['anime_season'][0] ?? '' ) === $season_key )
                                 && ( (int) ( $m['anime_season_year'][0] ?? 0 ) === $year ),
            /* [1.8.0] 由 cron 依 AniList PREQUEL 關聯寫入；尚未取得資料的先當新作 */
            'has_prequel'    => (int) ( $m['anime_has_prequel'][0] ?? 0 ) === 1,
            'user_status'    => '',
            'user_progress'  => 0,
        ];
    }
}
wp_reset_postdata();

/* user_status 表 */
$current_uid      = get_current_user_id();
$status_table     = $wpdb->prefix . 'anime_user_status';
$has_status_table = (bool) $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $status_table ) );

if ( $has_status_table && $current_uid > 0 && $rows ) {
    $ids = array_map( 'intval', wp_list_pluck( $rows, 'ID' ) );
    $ids = array_filter( $ids );
    if ( $ids ) {
        $in      = implode( ',', $ids );
        $us_rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT anime_id, status, progress FROM {$status_table}
             WHERE user_id=%d AND anime_id IN ({$in})",
            $current_uid
        ), ARRAY_A );
        $us_map = [];
        $status_map_int = [ 0 => 'want', 1 => 'watching', 2 => 'completed', 3 => 'dropped', 4 => 'paused' ];
        foreach ( (array) $us_rows as $u ) {
            $aid = (int) $u['anime_id'];
            $st  = $u['status'];
            if ( is_numeric( $st ) ) {
                $st = $status_map_int[ (int) $st ] ?? '';
            }
            $us_map[ $aid ] = [ 'status' => $st, 'progress' => (int) $u['progress'] ];
        }
        foreach ( $rows as $i => $r ) {
            if ( isset( $us_map[ $r['ID'] ] ) ) {
                $rows[ $i ]['user_status']   = $us_map[ $r['ID'] ]['status'];
                $rows[ $i ]['user_progress'] = $us_map[ $r['ID'] ]['progress'];
            }
        }
    }
}

/* ============================================================
 * 區段 3：解析 JSON / 陣列欄位
 * ============================================================ */
$tw_platform_labels = [
    'bahamut'      => '巴哈動畫瘋',  'hami'      => 'Hami Video',  'myvideo'   => 'MyVideo',
    'linetv'       => 'LINE TV',     'friday'    => 'friDay影音',  'ofiii'     => 'Ofiii',
    'catchplay'    => 'CatchPlay+',  'bilibili'  => 'B站台灣',     'ani_one'   => 'Ani-One',
    'muse'         => 'Muse 木棉花', 'mighty'    => '曼迪 YT',     'ani_mi'    => 'Ani-Mi',
    'netflix'      => 'Netflix',     'disney'    => 'Disney+',     'litv'      => 'LiTV',
    'tropicsanime' => '回歸線娛樂',  'iqiyi'     => '愛奇藝',      'renta'     => 'renta!',
    'anipass'      => 'AniPASS',     'amazon'    => 'Prime Video', 'crunchyroll' => 'Crunchyroll',
];

$source_labels = [
    'ORIGINAL' => '原創', 'MANGA' => '漫畫改編', 'LIGHT_NOVEL' => '輕小說改編',
    'NOVEL' => '小說改編', 'VISUAL_NOVEL' => '視覺小說改編', 'VIDEO_GAME' => '遊戲改編',
    'GAME' => '遊戲改編', 'COMIC' => '漫畫改編', 'WEB_NOVEL' => '網路小說改編',
    'DOUJINSHI' => '同人誌改編', 'LIVE_ACTION' => '真人改編', 'ANIME' => '動畫改編',
    'MULTIMEDIA_PROJECT' => '多媒體企劃', 'PICTURE_BOOK' => '繪本改編', 'OTHER' => '其他',
];

$format_labels = [
    'TV' => 'TV', 'TV_SHORT' => 'TV 短篇', 'MOVIE' => '劇場版',
    'SPECIAL' => '特別篇', 'OVA' => 'OVA', 'ONA' => 'ONA', 'MUSIC' => '音樂',
];

$weekday_zh = [ 1 => '週一', 2 => '週二', 3 => '週三', 4 => '週四', 5 => '週五', 6 => '週六', 7 => '週日' ];

$parse_trailers = function( $raw ) {
    if ( ! $raw ) return [];
    $lines = preg_split( '/[\r\n,;]+/u', $raw );
    $out   = [];
    $idx   = 0;
    foreach ( $lines as $line ) {
        $line = trim( $line );
        if ( $line === '' ) continue;
        $title = '';
        if ( strpos( $line, '|' ) !== false ) {
            list( $url, $title ) = array_map( 'trim', explode( '|', $line, 2 ) );
        } else {
            $parts = preg_split( '/\s+/', $line );
            $url   = trim( $parts[0] );
            if ( count( $parts ) > 1 ) {
                $title = trim( implode( ' ', array_slice( $parts, 1 ) ) );
            }
        }
        if ( ! preg_match( '#^https?://#i', $url ) ) continue;
        $vid = '';
        if ( preg_match( '#(?:youtube\.com/watch\?v=|youtu\.be/|youtube\.com/embed/)([A-Za-z0-9_-]{6,})#i', $url, $mm ) ) {
            $vid = $mm[1];
        }
        $idx++;
        $out[] = [
            'url'   => $url,
            'vid'   => $vid,
            'thumb' => $vid ? "https://i.ytimg.com/vi/{$vid}/hqdefault.jpg" : '',
            'title' => $title !== '' ? $title : ( 'PV ' . $idx ),
        ];
    }
    return $out;
};

$parse_staff = function( $json ) {
    if ( ! $json ) return [];
    $arr = json_decode( $json, true );
    if ( ! is_array( $arr ) ) return [];
    $out = [];
    foreach ( $arr as $row ) {
        if ( ! is_array( $row ) ) continue;
        $name = $row['name_cn'] ?? ( $row['name'] ?? '' );
        $role = $row['relation'] ?? ( $row['role'] ?? '' );
        if ( $name === '' ) continue;
        $out[] = [ 'role' => $role, 'name' => $name ];
    }
    return $out;
};

$parse_cast = function( $json ) {
    if ( ! $json ) return [];
    $arr = json_decode( $json, true );
    if ( ! is_array( $arr ) ) return [];
    $out = [];
    foreach ( $arr as $row ) {
        if ( ! is_array( $row ) ) continue;
        $char_name = '';
        if ( isset( $row['character']['name_cn'] ) && $row['character']['name_cn'] !== '' ) {
            $char_name = $row['character']['name_cn'];
        } elseif ( isset( $row['character']['name'] ) ) {
            $char_name = $row['character']['name'];
        } elseif ( isset( $row['character_name'] ) ) {
            $char_name = $row['character_name'];
        }
        $actor_name = '';
        if ( isset( $row['actors'][0]['name'] ) ) {
            $actor_name = $row['actors'][0]['name'];
        } elseif ( isset( $row['actor_name'] ) ) {
            $actor_name = $row['actor_name'];
        }
        if ( $char_name === '' || $actor_name === '' ) continue;
        $out[] = [ 'char' => $char_name, 'actor' => $actor_name ];
    }
    return $out;
};

$parse_themes = function( $json ) {
    if ( ! $json ) return [ 'op' => [], 'ed' => [] ];
    $arr = json_decode( $json, true );
    if ( ! is_array( $arr ) ) return [ 'op' => [], 'ed' => [] ];
    $out = [ 'op' => [], 'ed' => [] ];
    foreach ( $arr as $row ) {
        if ( ! is_array( $row ) ) continue;

        $type = strtoupper( $row['type'] ?? '' );
        $slug = strtoupper( $row['slug'] ?? '' );
        $key  = '';
        if ( $type === 'OP' || strpos( $slug, 'OP' ) === 0 ) $key = 'op';
        elseif ( $type === 'ED' || strpos( $slug, 'ED' ) === 0 ) $key = 'ed';
        if ( $key === '' ) continue;

        $title = $row['title']
            ?? ( $row['song']['title'] ?? '' );
        if ( $title === '' ) continue;

        $artist = '';
        $artist_pool = $row['artists']
            ?? ( $row['song']['artists'] ?? [] );
        if ( is_array( $artist_pool ) && $artist_pool ) {
            $names = [];
            foreach ( $artist_pool as $a ) {
                if ( ! is_array( $a ) ) continue;
                $nm = $a['name_native'] ?: ( $a['name'] ?? '' );
                if ( $nm !== '' ) $names[] = $nm;
            }
            $artist = implode( ' × ', $names );
        } elseif ( isset( $row['artist'] ) && is_string( $row['artist'] ) ) {
            $artist = $row['artist'];
        }

        $out[ $key ][] = [
            'title'      => $title,
            'artist'     => $artist,
            'slug'       => $slug ?: $type,
            'audio_url'  => $row['audio_url']  ?? '',
            'video_url'  => $row['video_url']  ?? '',
            'resolution' => isset( $row['resolution'] ) ? (int) $row['resolution'] : 0,
        ];
    }
    return $out;
};


$parse_streaming = function( $json ) {
    if ( ! $json ) return [];
    $arr = json_decode( $json, true );
    if ( ! is_array( $arr ) ) return [];
    $out = [];
    foreach ( $arr as $row ) {
        if ( ! is_array( $row ) ) continue;
        $site = $row['site'] ?? ( $row['name'] ?? '' );
        $url  = $row['url']  ?? '';
        if ( $site === '' || $url === '' ) continue;
        $out[] = [ 'site' => $site, 'url' => $url ];
    }
    return $out;
};

$parse_tw_platforms = function( $raw ) {
    if ( ! $raw ) return [];
    if ( is_array( $raw ) ) return $raw;
    if ( function_exists( 'is_serialized' ) && is_serialized( $raw ) ) {
        $arr = @unserialize( $raw );
        return is_array( $arr ) ? $arr : [];
    }
    $decoded = json_decode( $raw, true );
    if ( is_array( $decoded ) ) return $decoded;
    return array_filter( array_map( 'trim', explode( ',', $raw ) ) );
};

/*
 * next_airing 解析。
 *
 * ★ 改為呼叫外掛的共用函式 wxacg_parse_next_airing()。
 *   這個欄位有兩種歷史格式：匯入端寫 JSON {"airingAt":…,"episode":…}，
 *   cron 曾經寫純 Unix 時間戳（全站 137 筆中兩種各半）。原本這裡的
 *   fallback 是 strtotime( $raw )，而 strtotime() 對純數字字串一律回
 *   false，所以那 86 筆時間戳格式在本頁一樣解析失敗。
 *
 *   三個讀取端各寫一份解析、各漏一種格式，是這個 bug 的根源。
 */
$parse_next_airing = function( $raw ) {
    if ( function_exists( 'wxacg_parse_next_airing' ) ) {
        $p = wxacg_parse_next_airing( $raw );
        return [ 'ts' => $p['airingAt'], 'episode' => $p['episode'] ];
    }

    // 外掛未載入時的最小後備，行為與共用函式一致
    if ( ! $raw ) return [ 'ts' => 0, 'episode' => 0 ];
    if ( ctype_digit( (string) $raw ) ) return [ 'ts' => (int) $raw, 'episode' => 0 ];
    $arr = json_decode( (string) $raw, true );
    if ( is_array( $arr ) && isset( $arr['airingAt'] ) ) {
        return [ 'ts' => (int) $arr['airingAt'], 'episode' => (int) ( $arr['episode'] ?? 0 ) ];
    }
    return [ 'ts' => 0, 'episode' => 0 ];
};

/* ============================================================
 * 區段 4：處理每筆資料 + 統計
 * ============================================================ */
$posts          = [];
$by_weekday     = [ 0 => [], 1 => [], 2 => [], 3 => [], 4 => [], 5 => [], 6 => [], 7 => [] ];
$stat_total       = 0;
$stat_owned       = 0;
$stat_watching    = 0;
$stat_completed   = 0;
$stat_brand_new   = 0;
$stat_sequel      = 0;
$score_sum      = 0;
$score_cnt      = 0;
$first_cover    = '';
$has_real_weekday = false;
$today_ymd      = date_i18n( 'Y-m-d' );

$all_platforms_used = [];
$all_formats_used   = [];
$all_sources_used   = [];
$all_genres_used    = [];

foreach ( $rows as $r ) {
    $pid = (int) $r['ID'];

    $na      = $parse_next_airing( $r['next_airing'] );
    $na_ts   = $na['ts'];
    $na_ep   = $na['episode'];

    /* [1.6.0] start_date 正規化（相容純數字 YYYYMMDD） */
    $sd_ts    = smacg_bgm_norm_date_ts( $r['start_date'] );
    $sd_human = $sd_ts ? date_i18n( 'Y-m-d', $sd_ts ) : '';

    $weekday = 0;
    if ( $na_ts ) {
        $weekday = (int) date_i18n( 'N', $na_ts );
    } elseif ( $sd_ts ) {
        $weekday = (int) date_i18n( 'N', $sd_ts );
    }

    $is_today = false;
    if ( $na_ts ) {
        $is_today = ( date_i18n( 'Y-m-d', $na_ts ) === $today_ymd );
    }

    /* [1.8.0] 新作／續作分類（純讀資料庫欄位，不呼叫外部 API） */
    $newness = $r['has_prequel'] ? 'sequel' : 'brand_new';

    $title_cn   = $r['title_cn'] ?: ( $r['title_en'] ?: ( $r['title_romaji'] ?: $r['post_title'] ) );
    $score      = $r['score'] !== null ? (float) $r['score'] : null;
    $score_disp = $score !== null ? round( $score / 10, 1 ) : null;
    $ep_total   = (int) $r['ep_total'];
    $ep_aired   = (int) $r['ep_aired'];

    $tw_platforms = $parse_tw_platforms( $r['tw_platforms'] );
    $tw_urls      = $tw_urls_by_post[ $pid ] ?? [];

    $genres = $genres_by_post[ $pid ] ?? [];

    foreach ( (array) $tw_platforms as $tp ) { if ( $tp ) $all_platforms_used[ $tp ] = true; }
    if ( $r['format'] ) $all_formats_used[ $r['format'] ] = true;
    if ( $r['source'] ) $all_sources_used[ $r['source'] ] = true;
    foreach ( $genres as $gn ) { if ( $gn ) $all_genres_used[ $gn ] = true; }

    $na_human = '';
    if ( $na_ts ) {
        $na_human = date_i18n( 'Y-m-d H:i', $na_ts );
    }

    $item = [
        'id'           => $pid,
        'url'          => get_permalink( $pid ),
        'title_cn'     => $title_cn,
        'title_jp'     => $r['title_jp'] ?: '',
        'title_en'     => $r['title_en'] ?: '',
        'title_romaji' => $r['title_romaji'] ?: '',
        'cover'        => $r['cover'] ?: '',
        'synopsis'     => $r['synopsis'] ?: ( $r['post_excerpt'] ?: '' ),
        'studios'      => $r['studios'] ?: '',
        'source'       => $r['source'] ?: '',
        'source_zh'    => $source_labels[ $r['source'] ?? '' ] ?? ( $r['source'] ?: '' ),
        'format'       => $r['format'] ?: '',
        'format_zh'    => $format_labels[ $r['format'] ?? '' ] ?? ( $r['format'] ?: '' ),
        'status'       => $r['status'] ?: '',
        'score'        => $score,
        'score_disp'   => $score_disp,
        'popularity'   => (int) $r['popularity'],
        'ep_total'     => $ep_total,
        'ep_aired'     => $ep_aired,
        'next_airing'  => $r['next_airing'] ?: '',
        'na_ts'        => $na_ts,
        'na_ep'        => $na_ep,
        'na_human'     => $na_human,
        'start_date'   => $r['start_date'] ?: '',
        'start_date_human' => $sd_human,   // [1.6.0]
        'start_ts'     => $sd_ts,          // [1.6.0]
        'weekday'      => $weekday,
        'is_today'     => $is_today,
        'tw_broadcast' => $r['tw_broadcast'] ?: '',
        'tw_other'     => $r['tw_other'] ?: '',
        'official'     => $r['official'] ?: '',
        'trailers'     => $parse_trailers( $r['trailer'] ),
        'staff'        => $parse_staff( $r['staff'] ),
        'cast'         => $parse_cast( $r['cast'] ),
        'themes'       => $parse_themes( $r['themes'] ),
        'streaming'    => $parse_streaming( $r['streaming'] ),
        'tw_platforms' => array_values( (array) $tw_platforms ),
        'tw_urls'      => $tw_urls,
        'genres'       => array_values( $genres ),
        'user_status'  => $r['user_status'] ?? '',
        'user_progress'=> (int) ( $r['user_progress'] ?? 0 ),
        'newness'      => $newness,
    ];

    $posts[] = $item;
    $by_weekday[ $weekday ][] = $item;
    if ( $weekday >= 1 && $weekday <= 7 ) $has_real_weekday = true;

    $stat_total++;
    if ( $item['user_status'] !== '' ) $stat_owned++;
    if ( $item['user_status'] === 'watching' ) $stat_watching++;
    if ( $item['user_status'] === 'completed' ) $stat_completed++;
    if ( $score !== null && $score > 0 ) { $score_sum += $score; $score_cnt++; }
    if ( ! $first_cover && $item['cover'] ) $first_cover = $item['cover'];

    if ( $newness === 'sequel' ) { $stat_sequel++; }
    else { $stat_brand_new++; }
}

$avg_score        = $score_cnt > 0 ? round( $score_sum / $score_cnt / 10, 1 ) : null;
$show_weekday_tabs = ( $is_current_season && $has_real_weekday );

ksort( $all_platforms_used );
ksort( $all_formats_used );
ksort( $all_sources_used );
ksort( $all_genres_used );

/* ============================================================
 * 區段 5：SEO（v1.5.0：年+月+新番表 主關鍵字）
 * ============================================================ */
/* 關閉 Rank Math 在本頁的前端輸出，避免與本模板自訂 SEO 重複 */
add_filter( 'rank_math/frontend/disable_integration', '__return_true' );
add_action( 'template_redirect', function () {
    if ( class_exists( '\RankMath\Frontend\Frontend' ) ) {
        remove_all_actions( 'rank_math/head' );
    }
}, 11 );

$kw_label     = $ctx['label'];                                    // 2026年7月新番表
$kw_season    = $ctx['season_label'] ?? $ctx['label'];            // 2026年夏季新番

$canonical = home_url( "/bangumi/{$ym}/" );
/* [1.7.0] title／description 補上「線上看」關鍵字，比照 pt_anime_title 的做法 */
$seo_title = sprintf(
    '%s線上看｜%s 共 %d 部%s - 微笑動漫',
    $kw_label,
    $kw_season,
    $stat_total,
    $avg_score !== null ? '・平均 ' . $avg_score . ' 分' : ''
);
$seo_desc = sprintf(
    '%s線上看資訊整理（%s），共 %d 部新番動畫，提供中文大綱、配音聲優、製作人員、OP/ED、PV 預告、台灣合法線上看／串流平台（巴哈動畫瘋、Netflix、Muse 木棉花、Ani-One 等）與海外播放時間，支援即時搜尋與多維篩選。',
    $kw_label,
    $kw_season,
    $stat_total
);

$seo_ctx = [
    'label'       => $kw_label,
    'canonical'   => $canonical,
    'title'       => $seo_title,
    'desc'        => $seo_desc,
    'description' => $seo_desc,
    'og_image'    => $first_cover,
    'total'       => $stat_total,
    'avg_score'   => $avg_score,
];

if ( function_exists( 'smacg_bangumi_render_meta' ) ) {
    add_action( 'wp_head', function () use ( $seo_ctx ) { smacg_bangumi_render_meta( $seo_ctx ); }, 1 );
}
if ( function_exists( 'smacg_bangumi_render_og' ) ) {
    add_action( 'wp_head', function () use ( $seo_ctx ) { smacg_bangumi_render_og( $seo_ctx ); }, 2 );
}
if ( function_exists( 'smacg_bangumi_render_schema' ) ) {
    add_action( 'wp_head', function () use ( $seo_ctx, $posts ) { smacg_bangumi_render_schema( $seo_ctx, $posts ); }, 3 );
}
add_filter( 'pre_get_document_title', function () use ( $seo_title ) { return $seo_title; }, 99 );
add_filter( 'body_class', function ( $c ) use ( $theme_key ) {
    $c[] = 'is-bangumi-season';
    $c[] = 'bgm-season-' . strtolower( $theme_key );
    return $c;
} );

get_header();
?>

<style id="bgm-vars">
:root,
.is-bangumi-season {
    --bgm-main: <?php echo esc_attr( $theme['main'] ); ?>;
    --bgm-soft: <?php echo esc_attr( $theme['soft'] ); ?>;
}
</style>

<?php /* ============================================================
 * [1.6.0] 桌面版時間表緊湊化（可選，若外部 css 已處理可刪整段）
 * ============================================================ */ ?>
<style id="bgm-sched-compact">
@media (min-width: 900px) {
    .bgm-sched-grid { gap: 8px; }
    .bgm-sched-col { padding: 6px; }
    .bgm-sched-list { gap: 4px; }
    .bgm-sched-item {
        gap: 6px;
        padding: 4px 6px;
        align-items: center;
    }
    .bgm-sched-item .bgm-sched-thumb {
        width: 34px;
        height: 34px;
        flex: 0 0 34px;
        border-radius: 4px;
        overflow: hidden;
    }
    .bgm-sched-item .bgm-sched-thumb img {
        width: 100%; height: 100%; object-fit: cover;
    }
    .bgm-sched-item .bgm-sched-title {
        display: -webkit-box;
        -webkit-line-clamp: 1;
        -webkit-box-orient: vertical;
        overflow: hidden;
        font-size: 12px;
        line-height: 1.3;
    }
    .bgm-sched-item .bgm-sched-time { font-size: 11px; }
    .bgm-sched-item .bgm-sched-time.is-tba { opacity: .7; }
    .bgm-sched-item .bgm-sched-ep { font-size: 10px; opacity: .7; }
}
</style>

<main class="bgm-main">

    <nav class="bgm-breadcrumb" aria-label="breadcrumb">
        <a href="<?php echo esc_url( home_url( '/' ) ); ?>">首頁</a>
        <span>›</span>
        <a href="<?php echo esc_url( home_url( '/bangumi/' ) ); ?>">新番表</a>
        <span>›</span>
        <span aria-current="page"><?php echo esc_html( $ctx['label'] ); ?></span>
    </nav>

    <section class="bgm-hero bgm-season-<?php echo esc_attr( strtolower( $theme_key ) ); ?>">
        <div class="bgm-hero-inner">
            <div class="bgm-hero-badge"><?php echo esc_html( $theme['icon'] ); ?> <?php echo esc_html( $ctx['season_zh'] ); ?>季</div>
            <h1 class="bgm-hero-title"><?php echo esc_html( $ctx['label'] ); ?></h1>
            <p class="bgm-hero-sub"><?php echo esc_html( $kw_season ); ?>，<?php echo esc_html( $seo_desc ); ?></p>

            <div class="bgm-nav">
                <?php if ( $prev_ym ) :
                    $prev_ctx = function_exists( 'smacg_bangumi_parse_ym' ) ? smacg_bangumi_parse_ym( $prev_ym ) : [ 'label' => '上一季' ]; ?>
                <a class="bgm-nav-btn" href="<?php echo esc_url( home_url( "/bangumi/{$prev_ym}/" ) ); ?>" rel="prev">← <?php echo esc_html( $prev_ctx['label'] ); ?></a>
                <?php endif; ?>
                <a class="bgm-nav-btn is-current" href="<?php echo esc_url( $canonical ); ?>" aria-current="page"><?php echo esc_html( $ctx['label'] ); ?></a>
                <?php if ( $next_ym ) :
                    $next_ctx = function_exists( 'smacg_bangumi_parse_ym' ) ? smacg_bangumi_parse_ym( $next_ym ) : [ 'label' => '下一季' ]; ?>
                <a class="bgm-nav-btn" href="<?php echo esc_url( home_url( "/bangumi/{$next_ym}/" ) ); ?>" rel="next"><?php echo esc_html( $next_ctx['label'] ); ?> →</a>
                <?php endif; ?>
                <a class="bgm-nav-btn is-archive" href="<?php echo esc_url( home_url( '/bangumi/archive/' ) ); ?>">📚 歷年存檔</a>
            </div>

            <div class="bgm-stats">
                <div class="bgm-stat">
                    <span class="bgm-stat-n"><?php echo (int) $stat_total; ?></span>
                    <span class="bgm-stat-l">總作品</span>
                </div>
                <div class="bgm-stat">
                    <span class="bgm-stat-n"><?php echo $avg_score !== null ? esc_html( $avg_score ) : '–'; ?></span>
                    <span class="bgm-stat-l">平均分</span>
                </div>
                <?php if ( $current_uid > 0 ) : ?>
                <div class="bgm-stat">
                    <span class="bgm-stat-n"><?php echo (int) $stat_owned; ?></span>
                    <span class="bgm-stat-l">我的收藏</span>
                </div>
                <div class="bgm-stat">
                    <span class="bgm-stat-n"><?php echo (int) $stat_watching; ?></span>
                    <span class="bgm-stat-l">追番中</span>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <!-- ===== 篩選工具列 ===== -->
    <section class="bgm-filters" id="bgm-filters">
        <div class="bgm-filters-row bgm-filters-row-top">
            <div class="bgm-search-wrap">
                <span class="bgm-search-icon" aria-hidden="true">🔍</span>
                <input type="search" id="bgm-search" class="bgm-search" placeholder="輸入作品名稱、配音、製作公司…" autocomplete="off">
                <button type="button" class="bgm-search-clear" id="bgm-search-clear" aria-label="清除搜尋" hidden>×</button>
            </div>

            <div class="bgm-views" role="tablist" aria-label="切換顯示模式">
                <button type="button" class="bgm-view-btn is-active" data-view="grid" role="tab" aria-selected="true" title="網格">
                    <span aria-hidden="true">▦</span><span class="bgm-view-l">網格</span>
                </button>
                <button type="button" class="bgm-view-btn" data-view="list" role="tab" aria-selected="false" title="列表">
                    <span aria-hidden="true">☰</span><span class="bgm-view-l">列表</span>
                </button>
                <button type="button" class="bgm-view-btn" data-view="schedule" role="tab" aria-selected="false" title="時間表">
                    <span aria-hidden="true">📅</span><span class="bgm-view-l">時間表</span>
                </button>
            </div>
        </div>

        <div class="bgm-filters-row bgm-filters-row-selects">
            <?php if ( $all_platforms_used ) : ?>
            <select class="bgm-fil" id="bgm-fil-platform" data-filter="platform" aria-label="平台篩選">
                <option value="">全部平台</option>
                <?php foreach ( array_keys( $all_platforms_used ) as $pk ) : ?>
                    <option value="<?php echo esc_attr( $pk ); ?>"><?php echo esc_html( $tw_platform_labels[ $pk ] ?? $pk ); ?></option>
                <?php endforeach; ?>
            </select>
            <?php endif; ?>

            <?php if ( $all_formats_used ) : ?>
            <select class="bgm-fil" id="bgm-fil-format" data-filter="format" aria-label="格式篩選">
                <option value="">全部格式</option>
                <?php foreach ( array_keys( $all_formats_used ) as $fk ) : ?>
                    <option value="<?php echo esc_attr( $fk ); ?>"><?php echo esc_html( $format_labels[ $fk ] ?? $fk ); ?></option>
                <?php endforeach; ?>
            </select>
            <?php endif; ?>

            <?php if ( $all_sources_used ) : ?>
            <select class="bgm-fil" id="bgm-fil-source" data-filter="source" aria-label="原作篩選">
                <option value="">全部原作</option>
                <?php foreach ( array_keys( $all_sources_used ) as $sk ) : ?>
                    <option value="<?php echo esc_attr( $sk ); ?>"><?php echo esc_html( $source_labels[ $sk ] ?? $sk ); ?></option>
                <?php endforeach; ?>
            </select>
            <?php endif; ?>

            <?php if ( $all_genres_used ) : ?>
            <select class="bgm-fil" id="bgm-fil-genre" data-filter="genre" aria-label="類型篩選">
                <option value="">全部類型</option>
                <?php foreach ( array_keys( $all_genres_used ) as $gn ) : ?>
                    <option value="<?php echo esc_attr( $gn ); ?>"><?php echo esc_html( $gn ); ?></option>
                <?php endforeach; ?>
            </select>
            <?php endif; ?>

            <?php if ( $current_uid > 0 ) : ?>
            <select class="bgm-fil" id="bgm-fil-status" data-filter="status" aria-label="追番狀態">
                <option value="">全部追蹤狀態</option>
                <option value="want">想看</option>
                <option value="watching">追番中</option>
                <option value="completed">已完結</option>
                <option value="paused">暫停</option>
                <option value="dropped">已棄</option>
                <option value="__none__">尚未追蹤</option>
            </select>
            <?php endif; ?>

            <div class="bgm-sort-wrap">
                <label for="bgm-sort" class="bgm-sort-label">排序</label>
                <select id="bgm-sort" class="bgm-sort">
                    <option value="default">人氣高 → 低</option>
                    <option value="score">評分高 → 低</option>
                    <option value="ep">集數多 → 少</option>
                </select>
            </div>

            <button type="button" class="bgm-fil-reset" id="bgm-fil-reset">重設</button>
        </div>

        <div class="bgm-fil-meta">
            <span class="bgm-result-count">共 <span id="bgm-visible-count"><?php echo (int) $stat_total; ?></span> / <?php echo (int) $stat_total; ?> 部</span>
            <span class="bgm-fil-hint" id="bgm-fil-hint" hidden>篩選中</span>
        </div>
    </section>

    <!-- ===== 工具列：新作／續作分頁 ===== -->
    <?php if ( $stat_sequel > 0 ) : ?>
    <section class="bgm-toolbar" data-view-show="grid list">
        <div class="bgm-newness-tabs" role="tablist" aria-label="依新作／續作篩選">
            <button class="bgm-newness-tab is-active" data-newness="all" role="tab" aria-selected="true">
                全部<span class="bgm-day-n">(<?php echo (int) $stat_total; ?>)</span>
            </button>
            <button class="bgm-newness-tab" data-newness="brand_new" role="tab" aria-selected="false">
                新作<span class="bgm-day-n">(<?php echo (int) $stat_brand_new; ?>)</span>
            </button>
            <button class="bgm-newness-tab" data-newness="sequel" role="tab" aria-selected="false">
                續作<span class="bgm-day-n">(<?php echo (int) $stat_sequel; ?>)</span>
            </button>
        </div>
    </section>
    <?php endif; ?>

    <!-- ===== 工具列：星期分頁（僅當季） ===== -->
    <?php if ( $show_weekday_tabs ) : ?>
    <section class="bgm-toolbar" data-view-show="grid list">
        <div class="bgm-days" role="tablist" aria-label="按星期篩選">
            <button class="bgm-day is-active" data-day="all" role="tab" aria-selected="true">
                全部<span class="bgm-day-n">(<?php echo (int) $stat_total; ?>)</span>
            </button>
            <?php for ( $d = 1; $d <= 7; $d++ ) : if ( empty( $by_weekday[ $d ] ) ) continue; ?>
                <button class="bgm-day" data-day="<?php echo (int) $d; ?>" role="tab" aria-selected="false">
                    <?php echo esc_html( $weekday_zh[ $d ] ); ?>
                    <span class="bgm-day-n">(<?php echo count( $by_weekday[ $d ] ); ?>)</span>
                </button>
            <?php endfor; ?>
            <?php if ( ! empty( $by_weekday[0] ) ) : ?>
                <button class="bgm-day" data-day="0" role="tab" aria-selected="false">
                    待定<span class="bgm-day-n">(<?php echo count( $by_weekday[0] ); ?>)</span>
                </button>
            <?php endif; ?>
        </div>
    </section>
    <?php endif; ?>

    <!-- ===== Grid / List 視圖 ===== -->
    <section class="bgm-grid-wrap" data-view-show="grid list">
    <?php if ( $show_weekday_tabs ) : ?>
        <?php for ( $d = 1; $d <= 7; $d++ ) :
            $list = $by_weekday[ $d ];
            if ( empty( $list ) ) continue; ?>
            <div class="bgm-group" data-group="<?php echo (int) $d; ?>">
                <h2 class="bgm-group-title"><?php echo esc_html( $weekday_zh[ $d ] ); ?>　<span class="bgm-group-n"><?php echo count( $list ); ?> 部</span></h2>
                <div class="bgm-grid">
                    <?php foreach ( $list as $p ) : ?>
                        <?php echo bgm_render_card( $p, $tw_platform_labels, $weekday_zh ); ?>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endfor; ?>
        <?php if ( ! empty( $by_weekday[0] ) ) : ?>
            <div class="bgm-group" data-group="0">
                <h2 class="bgm-group-title">播出時間待定　<span class="bgm-group-n"><?php echo count( $by_weekday[0] ); ?> 部</span></h2>
                <div class="bgm-grid">
                    <?php foreach ( $by_weekday[0] as $p ) : ?>
                        <?php echo bgm_render_card( $p, $tw_platform_labels, $weekday_zh ); ?>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
    <?php else : ?>
        <div class="bgm-group is-flat" data-group="all">
            <div class="bgm-grid">
                <?php foreach ( $posts as $p ) : ?>
                    <?php echo bgm_render_card( $p, $tw_platform_labels, $weekday_zh ); ?>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <?php if ( $stat_total === 0 ) : ?>
        <div class="bgm-empty">
            <p>本季尚未有作品資料。</p>
            <a class="bgm-nav-btn" href="<?php echo esc_url( home_url( '/bangumi/archive/' ) ); ?>">查看歷年存檔 →</a>
        </div>
    <?php endif; ?>
    </section>

    <!-- ===== Schedule 視圖（7 欄） ===== -->
    <section class="bgm-schedule" data-view-show="schedule" hidden>
        <div class="bgm-sched-grid">
            <?php
            $sched_order = [ 1, 2, 3, 4, 5, 6, 7 ];
            foreach ( $sched_order as $d ) :
                $col_list = isset( $by_weekday[ $d ] ) ? $by_weekday[ $d ] : [];
                usort( $col_list, function( $a, $b ) {
                    return ( $a['na_ts'] ?: PHP_INT_MAX ) <=> ( $b['na_ts'] ?: PHP_INT_MAX );
                } );
                ?>
                <div class="bgm-sched-col<?php echo ( ! empty( $col_list ) && $is_current_season && date_i18n( 'N' ) == $d ) ? ' is-today-col' : ''; ?>"
                     data-day="<?php echo (int) $d; ?>">
                    <h3 class="bgm-sched-h">
                        <?php echo esc_html( $weekday_zh[ $d ] ); ?>
                        <span class="bgm-sched-n"><?php echo count( $col_list ); ?></span>
                    </h3>
                    <div class="bgm-sched-list">
                        <?php if ( empty( $col_list ) ) : ?>
                            <div class="bgm-sched-empty">—</div>
                        <?php else : ?>
                            <?php foreach ( $col_list as $p ) : ?>
                                <?php echo bgm_render_sched_item( $p, $tw_platform_labels ); ?>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <?php if ( ! empty( $by_weekday[0] ) ) : ?>
        <div class="bgm-sched-tba">
            <h3 class="bgm-sched-h">播出時間待定 <span class="bgm-sched-n"><?php echo count( $by_weekday[0] ); ?></span></h3>
            <div class="bgm-sched-list bgm-sched-tba-list">
                <?php foreach ( $by_weekday[0] as $p ) : ?>
                    <?php echo bgm_render_sched_item( $p, $tw_platform_labels ); ?>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </section>

    <!-- 篩選後 0 結果 -->
    <div class="bgm-empty bgm-empty-filter" id="bgm-empty-filter" hidden>
        <p>沒有符合條件的作品。</p>
        <button type="button" class="bgm-nav-btn" id="bgm-empty-reset">清除所有篩選</button>
    </div>

    <div class="bgm-sheet" id="bgm-sheet" role="dialog" aria-modal="true" aria-label="作品詳情" aria-hidden="true">
        <div class="bgm-sheet-backdrop" data-bgm-close></div>
        <div class="bgm-sheet-panel" role="document">
            <button class="bgm-sheet-close" type="button" aria-label="關閉" data-bgm-close>×</button>
            <div class="bgm-sheet-body"></div>
        </div>
    </div>

</main>

<?php
get_footer();

/* ============================================================
 * Helper：渲染單張卡片（grid + list 共用，靠 CSS 切換版型）
 * v2：卡片主體改為 <a href> 直接進作品頁，支援右鍵/中鍵開新分頁
 * ============================================================ */
function bgm_render_card( $p, $tw_platform_labels, $weekday_zh ) {
    $status_label = [
        'watching'  => '追番中',
        'want'      => '想看',
        'completed' => '已完結',
        'paused'    => '暫停',
        'dropped'   => '已棄',
    ];
    $is_hot       = ( $p['score'] !== null && $p['score'] >= 80 );
    $progress_pct = ( $p['ep_total'] > 0 && $p['user_progress'] > 0 )
        ? min( 100, round( $p['user_progress'] / $p['ep_total'] * 100 ) ) : 0;

    $tw_icons = [];
    if ( ! empty( $p['tw_platforms'] ) ) {
        foreach ( (array) $p['tw_platforms'] as $key ) {
            if ( isset( $tw_platform_labels[ $key ] ) ) {
                $tw_icons[] = [ 'key' => $key, 'label' => $tw_platform_labels[ $key ] ];
            }
        }
    }

    $data_platforms = implode( ',', array_map( 'strval', (array) $p['tw_platforms'] ) );
    $data_genres    = implode( '|', $p['genres'] );

    $search_index = mb_strtolower( implode( ' ', array_filter( [
        $p['title_cn'], $p['title_jp'], $p['title_en'], $p['title_romaji'], $p['studios'],
        implode( ' ', array_column( (array) $p['cast'], 'actor' ) ),
        implode( ' ', array_column( (array) $p['staff'], 'name' ) ),
    ] ) ) );

    $card_classes = [ 'bgm-card' ];
    if ( $p['user_status'] ) {
        $card_classes[] = 'has-status';
        $card_classes[] = 'status-' . preg_replace( '/[^a-z]/', '', $p['user_status'] );
    }
    if ( ! empty( $p['is_today'] ) ) {
        $card_classes[] = 'is-today';
    }

    ob_start();
    ?>
    <article class="<?php echo esc_attr( implode( ' ', $card_classes ) ); ?>"
             id="anime-<?php echo (int) $p['id']; ?>"
             data-anime-id="<?php echo (int) $p['id']; ?>"
             data-score="<?php echo esc_attr( (string) ( $p['score'] ?? 0 ) ); ?>"
             data-ep="<?php echo esc_attr( (string) $p['ep_total'] ); ?>"
             data-pop="<?php echo esc_attr( (string) $p['popularity'] ); ?>"
             data-day="<?php echo (int) $p['weekday']; ?>"
             data-newness="<?php echo esc_attr( $p['newness'] ?? 'brand_new' ); ?>"
             data-today="<?php echo ! empty( $p['is_today'] ) ? '1' : '0'; ?>"
             data-platforms="<?php echo esc_attr( $data_platforms ); ?>"
             data-format="<?php echo esc_attr( $p['format'] ); ?>"
             data-source="<?php echo esc_attr( $p['source'] ); ?>"
             data-genres="<?php echo esc_attr( $data_genres ); ?>"
             data-status="<?php echo esc_attr( $p['user_status'] ?: '__none__' ); ?>"
             data-search="<?php echo esc_attr( $search_index ); ?>"
             data-url="<?php echo esc_url( $p['url'] ); ?>">
        <a class="bgm-card-link" href="<?php echo esc_url( $p['url'] ); ?>"
           aria-label="<?php echo esc_attr( $p['title_cn'] ); ?>">
            <div class="bgm-card-cover">
                <?php if ( $p['cover'] ) : ?>
                    <img src="<?php echo esc_url( $p['cover'] ); ?>"
                         alt="<?php echo esc_attr( $p['title_cn'] ); ?>"
                         loading="lazy" decoding="async">
                <?php else : ?>
                    <div class="bgm-card-noimg">?</div>
                <?php endif; ?>

                <?php if ( $p['score_disp'] !== null ) : ?>
                    <span class="bgm-card-score<?php echo $is_hot ? ' is-hot' : ''; ?>">
                        ★ <?php echo esc_html( $p['score_disp'] ); ?>
                    </span>
                <?php endif; ?>

                <?php if ( ! empty( $p['is_today'] ) ) : ?>
                    <span class="bgm-card-today" title="今日更新">🔥 今日</span>
                <?php endif; ?>

                <?php if ( $p['user_status'] && isset( $status_label[ $p['user_status'] ] ) ) : ?>
                    <span class="bgm-card-chip"><?php echo esc_html( $status_label[ $p['user_status'] ] ); ?></span>
                <?php endif; ?>

                <?php if ( $progress_pct > 0 ) : ?>
                    <div class="bgm-card-progress" title="<?php echo esc_attr( $p['user_progress'] . ' / ' . $p['ep_total'] ); ?>">
                        <span style="width:<?php echo (int) $progress_pct; ?>%"></span>
                    </div>
                <?php endif; ?>
            </div>

            <div class="bgm-card-meta">
                <div class="bgm-card-title"><?php echo esc_html( $p['title_cn'] ); ?></div>
                <?php if ( $p['title_jp'] ) : ?>
                    <div class="bgm-card-jp"><?php echo esc_html( $p['title_jp'] ); ?></div>
                <?php endif; ?>

                <div class="bgm-card-bar">
                    <?php if ( $p['format_zh'] ) : ?>
                        <span class="bgm-card-pill"><?php echo esc_html( $p['format_zh'] ); ?></span>
                    <?php endif; ?>
                    <?php if ( $p['ep_total'] > 0 ) : ?>
                        <span class="bgm-card-pill">共 <?php echo (int) $p['ep_total']; ?> 集</span>
                    <?php endif; ?>
                    <?php if ( $p['weekday'] > 0 && isset( $weekday_zh[ $p['weekday'] ] ) ) : ?>
                        <span class="bgm-card-pill is-day"><?php echo esc_html( $weekday_zh[ $p['weekday'] ] ); ?></span>
                    <?php endif; ?>
                    <?php if ( $p['source_zh'] ) : ?>
                        <span class="bgm-card-pill is-source"><?php echo esc_html( $p['source_zh'] ); ?></span>
                    <?php endif; ?>
                </div>

                <?php if ( ! empty( $p['genres'] ) ) : ?>
                    <div class="bgm-card-genres">
                        <?php foreach ( array_slice( $p['genres'], 0, 3 ) as $g ) : ?>
                            <span class="bgm-card-gtag"><?php echo esc_html( $g ); ?></span>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <?php if ( $p['studios'] ) : ?>
                    <div class="bgm-card-studio" title="<?php echo esc_attr( $p['studios'] ); ?>">
                        🎬 <?php echo esc_html( $p['studios'] ); ?>
                    </div>
                <?php endif; ?>

                <?php if ( $tw_icons ) : ?>
                    <div class="bgm-card-plats">
                        <?php
                        $shown = array_slice( $tw_icons, 0, 4 );
                        $extra = count( $tw_icons ) - count( $shown );
                        foreach ( $shown as $plat ) : ?>
                            <span class="bgm-plat-mini bgm-plat-<?php echo esc_attr( $plat['key'] ); ?>"
                                  title="<?php echo esc_attr( $plat['label'] ); ?>">
                                <?php echo esc_html( mb_substr( $plat['label'], 0, 2 ) ); ?>
                            </span>
                        <?php endforeach;
                        if ( $extra > 0 ) : ?>
                            <span class="bgm-plat-mini is-more">+<?php echo (int) $extra; ?></span>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <?php if ( $p['synopsis'] ) : ?>
                    <div class="bgm-card-syn"><?php echo esc_html( wp_strip_all_tags( mb_substr( $p['synopsis'], 0, 120 ) ) ); ?>…</div>
                <?php endif; ?>
            </div>
        </a>
    </article>
    <?php
    return ob_get_clean();
}


/* ============================================================
 * Helper：時間表 item
 * ============================================================ */
function bgm_render_sched_item( $p, $tw_platform_labels ) {
    $time_str = '';
    if ( ! empty( $p['na_ts'] ) ) {
        $time_str = date_i18n( 'H:i', $p['na_ts'] );
    }
    $classes = [ 'bgm-sched-item' ];
    if ( ! empty( $p['is_today'] ) ) $classes[] = 'is-today';
    if ( $p['user_status'] )         $classes[] = 'has-status';

    ob_start();
    ?>
    <a class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>"
       href="#anime-<?php echo (int) $p['id']; ?>"
       data-anime-id="<?php echo (int) $p['id']; ?>">
        <?php if ( $time_str ) : ?>
            <span class="bgm-sched-time"><?php echo esc_html( $time_str ); ?></span>
        <?php elseif ( ! empty( $p['start_ts'] ) ) : /* [1.6.0] 無精確時刻 → 顯示開播日 */ ?>
            <span class="bgm-sched-time is-tba"><?php echo esc_html( date_i18n( 'n/j', $p['start_ts'] ) ); ?> 開播</span>
        <?php else : ?>
            <span class="bgm-sched-time is-tba">未定</span>
        <?php endif; ?>
        <span class="bgm-sched-thumb">
            <?php if ( $p['cover'] ) : ?>
                <img src="<?php echo esc_url( $p['cover'] ); ?>" alt="" loading="lazy" decoding="async">
            <?php endif; ?>
        </span>
        <span class="bgm-sched-info">
            <span class="bgm-sched-title"><?php echo esc_html( $p['title_cn'] ); ?></span>
            <?php if ( $p['na_ep'] > 0 ) : ?>
                <span class="bgm-sched-ep">第 <?php echo (int) $p['na_ep']; ?> 集</span>
            <?php endif; ?>
        </span>
        <?php if ( ! empty( $p['is_today'] ) ) : ?>
            <span class="bgm-sched-today">🔥</span>
        <?php endif; ?>
    </a>
    <?php
    return ob_get_clean();
}

/* ============================================================
 * Helper：渲染長條詳情
 * ============================================================ */
function bgm_render_detail( $p, $tw_platform_labels ) {
    ob_start();
    ?>
    <div class="bgm-d-inner">

        <header class="bgm-d-header">
            <h3 class="bgm-d-title"><?php echo esc_html( $p['title_cn'] ); ?></h3>
            <?php if ( $p['title_jp'] ) : ?>
                <div class="bgm-d-jp"><?php echo esc_html( $p['title_jp'] ); ?></div>
            <?php endif; ?>
            <?php if ( $p['title_en'] ) : ?>
                <div class="bgm-d-en"><?php echo esc_html( $p['title_en'] ); ?></div>
            <?php endif; ?>
        </header>

        <div class="bgm-d-tags">
            <?php if ( $p['source_zh'] ) : ?>
                <span class="bgm-tag bgm-tag-source"><?php echo esc_html( $p['source_zh'] ); ?></span>
            <?php endif; ?>
            <?php if ( $p['format_zh'] ) : ?>
                <span class="bgm-tag"><?php echo esc_html( $p['format_zh'] ); ?></span>
            <?php endif; ?>
            <?php if ( $p['ep_total'] > 0 ) : ?>
                <span class="bgm-tag">共 <?php echo (int) $p['ep_total']; ?> 集</span>
            <?php endif; ?>
            <?php if ( $p['score_disp'] !== null ) : ?>
                <span class="bgm-tag bgm-tag-score">★ <?php echo esc_html( $p['score_disp'] ); ?></span>
            <?php endif; ?>
            <?php if ( $p['popularity'] > 0 ) : ?>
                <span class="bgm-tag">人氣 <?php echo number_format( $p['popularity'] ); ?></span>
            <?php endif; ?>
            <?php foreach ( $p['genres'] as $g ) : ?>
                <span class="bgm-tag bgm-tag-genre"><?php echo esc_html( $g ); ?></span>
            <?php endforeach; ?>
        </div>

        <?php if ( $p['na_human'] || $p['start_date_human'] || $p['tw_broadcast'] ) : ?>
        <div class="bgm-d-row">
            <div class="bgm-d-label">播出時間</div>
            <div class="bgm-d-value">
                <?php
                $airing_lines = [];
                /* [1.6.0] 用格式化後的開播日，不再顯示裸數字 */
                if ( $p['start_date_human'] )  $airing_lines[] = '開播：' . esc_html( $p['start_date_human'] );
                if ( $p['na_human'] ) {
                    $ep_str = $p['na_ep'] > 0 ? ( '第 ' . (int) $p['na_ep'] . ' 集 / ' ) : '';
                    $airing_lines[] = '下集：' . $ep_str . esc_html( $p['na_human'] ) . '（台灣時間）';
                }
                if ( $p['tw_broadcast'] ) $airing_lines[] = '台灣播出：' . esc_html( $p['tw_broadcast'] );
                echo implode( '<br>', $airing_lines );
                ?>
            </div>
        </div>
        <?php endif; ?>

        <?php if ( $p['synopsis'] ) : ?>
        <div class="bgm-d-section">
            <h4 class="bgm-d-h">故事大綱</h4>
            <div class="bgm-d-syn"><?php echo wp_kses_post( wpautop( $p['synopsis'] ) ); ?></div>
        </div>
        <?php endif; ?>

<?php if ( ! empty( $p['themes']['op'] ) || ! empty( $p['themes']['ed'] ) ) : ?>
<div class="bgm-d-section bgm-d-section-themes">
    <h4 class="bgm-d-h">主題曲</h4>
    <div class="bgm-d-themes">
        <?php
        $theme_groups = [
            'op' => [ 'label' => 'OP', 'cls' => 'bgm-theme-op' ],
            'ed' => [ 'label' => 'ED', 'cls' => 'bgm-theme-ed' ],
        ];
        foreach ( $theme_groups as $gk => $gv ) :
            foreach ( $p['themes'][ $gk ] as $t ) : ?>
                <div class="bgm-theme-row">
                    <div class="bgm-theme-head">
                        <span class="bgm-theme-tag <?php echo esc_attr( $gv['cls'] ); ?>"><?php echo esc_html( $t['slug'] ?: $gv['label'] ); ?></span>
                        <span class="bgm-theme-title"><?php echo esc_html( $t['title'] ); ?></span>
                        <?php if ( $t['artist'] ) : ?>
                            <span class="bgm-theme-artist"><?php echo esc_html( $t['artist'] ); ?></span>
                        <?php endif; ?>
                        <?php if ( $t['video_url'] ) : ?>
                            <button type="button" class="bgm-theme-mv"
                                    data-mv="<?php echo esc_url( $t['video_url'] ); ?>"
                                    data-title="<?php echo esc_attr( $t['title'] ); ?>"
                                    aria-label="觀看 <?php echo esc_attr( $t['title'] ); ?> MV">🎬 MV</button>
                        <?php endif; ?>
                    </div>
                    <?php if ( $t['audio_url'] ) : ?>
                        <audio class="bgm-theme-audio" controls preload="none" src="<?php echo esc_url( $t['audio_url'] ); ?>">
                            您的瀏覽器不支援音訊播放。
                            <a href="<?php echo esc_url( $t['audio_url'] ); ?>" target="_blank" rel="noopener">下載音訊</a>
                        </audio>
                    <?php endif; ?>
                </div>
            <?php endforeach;
        endforeach; ?>
    </div>
</div>
<?php endif; ?>


        <?php if ( ! empty( $p['trailers'] ) ) : ?>
        <div class="bgm-d-section">
            <h4 class="bgm-d-h">宣傳片</h4>
            <div class="bgm-d-trailers">
                <?php foreach ( $p['trailers'] as $tr ) : ?>
                    <?php if ( $tr['vid'] ) : ?>
                        <button class="bgm-pv" type="button" data-vid="<?php echo esc_attr( $tr['vid'] ); ?>" aria-label="<?php echo esc_attr( $tr['title'] ); ?>">
                            <img src="<?php echo esc_url( $tr['thumb'] ); ?>" alt="<?php echo esc_attr( $tr['title'] ); ?>" loading="lazy">
                            <span class="bgm-pv-play">▶</span>
                            <span class="bgm-pv-title"><?php echo esc_html( $tr['title'] ); ?></span>
                        </button>
                    <?php else : ?>
                        <a class="bgm-pv" href="<?php echo esc_url( $tr['url'] ); ?>" target="_blank" rel="noopener">
                            <span class="bgm-pv-title"><?php echo esc_html( $tr['title'] ); ?> ↗</span>
                        </a>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php if ( ! empty( $p['tw_platforms'] ) || $p['tw_other'] ) : ?>
        <div class="bgm-d-section">
            <h4 class="bgm-d-h">台灣播放平台</h4>
            <div class="bgm-d-platforms">
                <?php foreach ( (array) $p['tw_platforms'] as $key ) :
                    $label = $tw_platform_labels[ $key ] ?? $key;
                    $url   = $p['tw_urls'][ $key ] ?? ''; ?>
                    <?php if ( $url ) : ?>
                        <a class="bgm-plat bgm-plat-<?php echo esc_attr( $key ); ?>" href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener">
                            <?php echo esc_html( $label ); ?> ↗
                        </a>
                    <?php else : ?>
                        <span class="bgm-plat bgm-plat-<?php echo esc_attr( $key ); ?> is-nolink">
                            <?php echo esc_html( $label ); ?>
                        </span>
                    <?php endif; ?>
                <?php endforeach; ?>
                <?php if ( $p['tw_other'] ) : ?>
                    <span class="bgm-plat is-other"><?php echo esc_html( $p['tw_other'] ); ?></span>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php if ( ! empty( $p['cast'] ) ) : ?>
        <div class="bgm-d-section">
            <h4 class="bgm-d-h">配音員</h4>
            <div class="bgm-d-list">
                <?php foreach ( array_slice( $p['cast'], 0, 12 ) as $c ) : ?>
                    <div class="bgm-d-li">
                        <span class="bgm-d-role"><?php echo esc_html( $c['char'] ); ?>:</span>
                        <span class="bgm-d-name"><?php echo esc_html( $c['actor'] ); ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php if ( ! empty( $p['staff'] ) || $p['studios'] ) : ?>
        <div class="bgm-d-section">
            <h4 class="bgm-d-h">製作人員</h4>
            <div class="bgm-d-list">
                <?php foreach ( array_slice( $p['staff'], 0, 16 ) as $s ) : ?>
                    <div class="bgm-d-li">
                        <span class="bgm-d-role"><?php echo esc_html( $s['role'] ); ?>:</span>
                        <span class="bgm-d-name"><?php echo esc_html( $s['name'] ); ?></span>
                    </div>
                <?php endforeach; ?>
                <?php if ( $p['studios'] ) : ?>
                    <div class="bgm-d-li bgm-d-studios">
                        <span class="bgm-d-role">動畫製作:</span>
                        <span class="bgm-d-name"><?php echo esc_html( $p['studios'] ); ?></span>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php if ( ! empty( $p['streaming'] ) || $p['official'] ) : ?>
        <div class="bgm-d-section">
            <h4 class="bgm-d-h">外部連結</h4>
            <div class="bgm-d-links">
                <?php if ( $p['official'] ) : ?>
                    <a class="bgm-link" href="<?php echo esc_url( $p['official'] ); ?>" target="_blank" rel="noopener">官方網站 ↗</a>
                <?php endif; ?>
                <?php foreach ( $p['streaming'] as $s ) : ?>
                    <a class="bgm-link" href="<?php echo esc_url( $s['url'] ); ?>" target="_blank" rel="noopener">
                        <?php echo esc_html( $s['site'] ); ?> ↗
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <div class="bgm-d-cta">
            <a class="bgm-d-more" href="<?php echo esc_url( $p['url'] ); ?>">查看完整資訊 →</a>
        </div>

    </div>
    <?php
    return ob_get_clean();
}
