<?php
/**
 * Plugin Name: 微笑動漫 - 音樂榜單
 * Description: Billboard JAPAN Hot Animation 榜單爬取與展示（獨立模組）
 * Version:     0.1.0
 * Author:      weixiaoacg
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * 註冊自訂文章類型：music_chart（每一筆 = 榜單上的一首歌）
 */
add_action( 'init', function () {
    register_post_type( 'music_chart', [
        'labels' => [
            'name'          => '音樂榜單',
            'singular_name' => '榜單歌曲',
            'menu_name'     => '音樂榜單',
            'add_new'       => '新增歌曲',
            'add_new_item'  => '新增榜單歌曲',
            'edit_item'     => '編輯榜單歌曲',
            'all_items'     => '所有榜單歌曲',
        ],
        'public'       => false,   // 不需要單獨的前台網址
        'show_ui'      => true,    // 後台可見，方便你檢查/手動修正
        'show_in_menu' => true,
        'menu_icon'    => 'dashicons-format-audio',
        'supports'     => [ 'title' ],  // 標題我們拿來存「歌名」
        'has_archive'  => false,
    ] );
} );

/**
 * ============================================================
 *  Billboard JAPAN Hot Animation 爬蟲 + 解析
 * ============================================================
 */

/**
 * 抓取並解析 Billboard 榜單，回傳結構化陣列。
 * 失敗時回傳 WP_Error。
 */
function wxmc_fetch_billboard_anime() {
    $url = 'https://www.billboard-japan.com/charts/detail?a=anime';

    $res = wp_remote_get( $url, [
        'timeout'    => 20,
        'user-agent' => 'Mozilla/5.0 (compatible; WeixiaoacgBot/1.0; +https://weixiaoacg.com)',
    ] );

    if ( is_wp_error( $res ) ) {
        return $res;
    }
    if ( wp_remote_retrieve_response_code( $res ) !== 200 ) {
        return new WP_Error( 'bad_status', 'HTTP ' . wp_remote_retrieve_response_code( $res ) );
    }

    $html = wp_remote_retrieve_body( $res );
    if ( ! $html ) {
        return new WP_Error( 'empty', '抓到空內容' );
    }

    // 用 DOMDocument 解析（抑制 HTML5 標籤的警告）
    $dom = new DOMDocument();
    libxml_use_internal_errors( true );
    $dom->loadHTML( '<?xml encoding="UTF-8">' . $html );
    libxml_clear_errors();
    $xpath = new DOMXPath( $dom );

    // 每一名 = 一個 <tr class="rankN">
    $rows = $xpath->query( '//tr[starts-with(@class, "rank")]' );
    if ( ! $rows || $rows->length === 0 ) {
        return new WP_Error( 'no_rows', '找不到榜單列（可能改版）' );
    }

    $items = [];

    foreach ( $rows as $row ) {
        // 排名：<p class="rank">
        $rank_node = $xpath->query( './/p[@class="rank"]', $row );
        $rank = $rank_node->length ? trim( $rank_node->item(0)->textContent ) : '';
        if ( $rank === '' || ! is_numeric( $rank ) ) {
            continue; // 不是有效的名次列就跳過
        }

        // 歌名：<p class="musuc_title">（注意 Billboard 拼字是 musuc）
        $title_node = $xpath->query( './/p[@class="musuc_title"]', $row );
        $song = $title_node->length ? trim( $title_node->item(0)->textContent ) : '';

        // 藝人：<p class="artist_name">
        $artist_node = $xpath->query( './/p[@class="artist_name"]', $row );
        $artist = $artist_node->length ? trim( $artist_node->item(0)->textContent ) : '';

        // 封面圖：第一個 <img>，排除佔位圖（rankinfo）
        $img_node = $xpath->query( './/td[@headers="name"]//img', $row );
        $cover = '';
        if ( $img_node->length ) {
            $src = $img_node->item(0)->getAttribute( 'src' );
            if ( $src && strpos( $src, 'rankinfo' ) === false ) {
                $cover = 'https://www.billboard-japan.com' . $src;
            }
        }

        // 上榜週數：<td class="times_td">
        $times_node = $xpath->query( './/td[contains(@class,"times_td")]', $row );
        $times = $times_node->length ? trim( $times_node->item(0)->textContent ) : '';

        if ( $song === '' && $artist === '' ) {
            continue; // 完全沒抓到就跳過
        }

        $items[] = [
            'rank'   => (int) $rank,
            'song'   => $song,
            'artist' => $artist,
            'cover'  => $cover,
            'times'  => $times,
        ];
    }

    if ( empty( $items ) ) {
        return new WP_Error( 'parse_empty', '解析後沒有任何資料' );
    }

    return $items;
}

/**
 * 後台：音樂榜單選單下加一個「更新 Billboard 榜單」的子頁面
 */
add_action( 'admin_menu', function () {
    add_submenu_page(
        'edit.php?post_type=music_chart',
        '更新 Billboard 榜單',
        '↻ 更新榜單',
        'manage_options',
        'wxmc-update',
        'wxmc_update_page'
    );
} );

/**
 * 後台頁面：按鈕觸發抓取 + 顯示結果（這一步先只「預覽」，還不寫入資料庫）
 */
function wxmc_update_page() {
    echo '<div class="wrap"><h1>Billboard 榜單更新</h1>';

    if ( isset( $_POST['wxmc_save'] ) && check_admin_referer( 'wxmc_save_action' ) ) {
        $result = wxmc_save_billboard_chart();
        if ( is_wp_error( $result ) ) {
            echo '<div class="notice notice-error"><p>更新失敗：'
                . esc_html( $result->get_error_message() ) . '</p></div>';
        } else {
            echo '<div class="notice notice-success"><p>已成功寫入 '
                . intval( $result ) . ' 筆榜單資料到資料庫。</p></div>';
        }
    }

    // 顯示目前資料庫裡的榜單
    $current = get_posts( [
        'post_type'      => 'music_chart',
        'posts_per_page' => -1,
        'post_status'    => 'any',
        'meta_key'       => 'chart_rank',
        'orderby'        => 'meta_value_num',
        'order'          => 'ASC',
        'meta_query'     => [
            [ 'key' => 'chart_source', 'value' => 'billboard_anime' ],
        ],
    ] );

    echo '<form method="post" style="margin:20px 0">';
    wp_nonce_field( 'wxmc_save_action' );
    echo '<button type="submit" name="wxmc_save" class="button button-primary button-hero">↻ 更新 Billboard 榜單（寫入資料庫）</button>';
    echo '</form>';

    if ( $current ) {
        echo '<h2>目前資料庫榜單（' . count( $current ) . ' 筆）</h2>';
        echo '<table class="widefat striped"><thead><tr>'
            . '<th>排名</th><th>歌名</th><th>藝人</th><th>週數</th>'
            . '</tr></thead><tbody>';
        foreach ( $current as $p ) {
            echo '<tr>';
            echo '<td>' . esc_html( get_post_meta( $p->ID, 'chart_rank', true ) ) . '</td>';
            echo '<td>' . esc_html( $p->post_title ) . '</td>';
            echo '<td>' . esc_html( get_post_meta( $p->ID, 'artist', true ) ) . '</td>';
            echo '<td>' . esc_html( get_post_meta( $p->ID, 'chart_weeks', true ) ) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    } else {
        echo '<p>資料庫目前沒有榜單資料，點上方按鈕抓取。</p>';
    }

    echo '</div>';
}



/**
 * ============================================================
 *  寫入資料庫：清掉舊榜 → 存入新榜（去重更新）
 * ============================================================
 */
function wxmc_save_billboard_chart() {
    $items = wxmc_fetch_billboard_anime();
    if ( is_wp_error( $items ) ) {
        return $items;
    }

    // 1) 先刪掉舊的 billboard 榜資料（用 meta 標記篩選）
    $old = get_posts( [
        'post_type'      => 'music_chart',
        'posts_per_page' => -1,
        'post_status'    => 'any',
        'fields'         => 'ids',
        'meta_query'     => [
            [ 'key' => 'chart_source', 'value' => 'billboard_anime' ],
        ],
    ] );
    foreach ( $old as $old_id ) {
        wp_delete_post( $old_id, true ); // true = 直接刪除，不進垃圾桶
    }

    // 2) 寫入新資料
    $count = 0;
    foreach ( $items as $it ) {
        $post_id = wp_insert_post( [
            'post_type'   => 'music_chart',
            'post_status' => 'publish',
            'post_title'  => $it['song'] !== '' ? $it['song'] : '(無歌名)',
        ], true );

        if ( is_wp_error( $post_id ) ) {
            continue;
        }

        update_post_meta( $post_id, 'chart_source', 'billboard_anime' );
        update_post_meta( $post_id, 'chart_rank',   $it['rank'] );
        update_post_meta( $post_id, 'artist',       $it['artist'] );
        update_post_meta( $post_id, 'cover_url',    $it['cover'] );
        update_post_meta( $post_id, 'chart_weeks',  $it['times'] );
        update_post_meta( $post_id, 'updated_at',   current_time( 'mysql' ) );
        // 以下兩個「對接」欄位先留空，第四步再填
        update_post_meta( $post_id, 'youtube_id',   '' );
        update_post_meta( $post_id, 'anime_post_id', 0 );

        $count++;
    }

    return $count;
}

/**
 * 華人動漫歌曲人氣榜
 * 排序：popularity 正規化 ×60% + Bangumi 評分正規化 ×40%
 * 資料來源：全站 anime CPT 的 anime_themes（AnimeThemes 匯入的 OP/ED）
 */
function wxmc_build_chinese_chart( $limit = 50 ) {
    // 1) 撈全站有 anime_themes 的動畫
    $anime_posts = get_posts([
        'post_type'      => 'anime',
        'posts_per_page' => -1,
        'post_status'    => 'publish',
        'fields'         => 'ids',
        'meta_query'     => [
            [ 'key' => 'anime_themes', 'compare' => 'EXISTS' ],
        ],
    ]);
    if ( empty( $anime_posts ) ) return [];

    // 2) 先蒐集每部的 popularity 與 bangumi 分數，找出最大值供正規化
    $rows = [];
    $max_pop = 0;
    $max_bgm = 0;
    foreach ( $anime_posts as $pid ) {
        $themes_raw = get_post_meta( $pid, 'anime_themes', true );
        if ( ! $themes_raw ) continue;
        $themes = json_decode( $themes_raw, true );
        if ( ! is_array( $themes ) || empty( $themes ) ) continue;

        $pop = (float) get_post_meta( $pid, 'anime_popularity', true );
        $bgm = (float) get_post_meta( $pid, 'anime_score_bangumi', true );

        if ( $pop > $max_pop ) $max_pop = $pop;
        if ( $bgm > $max_bgm ) $max_bgm = $bgm;

        $rows[] = [
            'pid'    => $pid,
            'pop'    => $pop,
            'bgm'    => $bgm,
            'themes' => $themes,
        ];
    }
    if ( empty( $rows ) ) return [];
    if ( $max_pop <= 0 ) $max_pop = 1; // 防除以 0
    if ( $max_bgm <= 0 ) $max_bgm = 1;

    // 3) 算每部的綜合分（0~1 正規化後加權）
    foreach ( $rows as &$r ) {
        $norm_pop = $r['pop'] / $max_pop;      // 0~1
        $norm_bgm = $r['bgm'] / $max_bgm;      // 0~1
        $r['score'] = $norm_pop * 0.6 + $norm_bgm * 0.4;
    }
    unset( $r );

    // 4) 依綜合分排序（高到低）
    usort( $rows, function( $a, $b ) {
        return $b['score'] <=> $a['score'];
    });
    // 5) 每部只取一首「代表曲」：OP1 優先 → 第一首 OP → 第一首 ED
    $songs = [];
    foreach ( $rows as $r ) {
        $anime_title = get_post_meta( $r['pid'], 'anime_title_chinese', true );
        if ( ! $anime_title ) $anime_title = get_the_title( $r['pid'] );
        $anime_url   = get_permalink( $r['pid'] );
        $cover       = get_post_meta( $r['pid'], 'anime_cover_image', true );

        // 從這部的 themes 裡挑代表曲
        $pick = null;
        $first_op = null;
        $first_ed = null;
        foreach ( $r['themes'] as $t ) {
            $type = $t['type'] ?? '';
            $slug = $t['slug'] ?? '';
            if ( $type === 'OP' && $slug === 'OP1' ) { $pick = $t; break; } // OP1 最優先，找到就停
            if ( $type === 'OP' && $first_op === null ) $first_op = $t;
            if ( $type === 'ED' && $first_ed === null ) $first_ed = $t;
        }
        if ( $pick === null ) $pick = $first_op ?: $first_ed; // 沒 OP1 就退而求其次
        if ( $pick === null ) continue; // 這部沒有任何可用主題曲，跳過

        // 藝人名（取第一位，優先原文名）
        $artist = '';
        if ( ! empty( $pick['artists'][0] ) ) {
            $a0 = $pick['artists'][0];
            $artist = $a0['name_native'] ?: $a0['name'];
        }

        $songs[] = [
            'anime_title' => $anime_title,
            'anime_url'   => $anime_url,
            'anime_score' => round( $r['score'], 4 ),
            'cover'       => $cover,
            'type'        => $pick['type']  ?? '',
            'slug'        => $pick['slug']  ?? '',
            'song'        => $pick['title'] ?? '',
            'artist'      => $artist,
            'audio_url'   => $pick['audio_url'] ?? '',
        ];
        if ( count( $songs ) >= $limit ) break; // 一部一首，到上限就停
    }
    return $songs;
}

/**
 * 後台預覽：華人榜
 */
add_action('admin_menu', function(){
    add_submenu_page(
        'edit.php?post_type=music_chart',
        '華人人氣榜（預覽）',
        '華人人氣榜',
        'manage_options',
        'wxmc-chinese-preview',
        'wxmc_chinese_preview_page'
    );
});

function wxmc_chinese_preview_page(){
    echo '<div class="wrap"><h1>華人動漫歌曲人氣榜 — 預覽</h1>';
    echo '<p>排序：人氣 60% + Bangumi 評分 40%（皆正規化）。此頁僅預覽，不寫入資料庫。</p>';
    $songs = wxmc_build_chinese_chart( 50 );
    if ( empty( $songs ) ) {
        echo '<div class="notice notice-error"><p>沒有撈到任何資料。請確認 anime 文章有 anime_themes 且有 anime_popularity。</p></div>';
        echo '</div>';
        return;
    }
    echo '<p>共 '.count($songs).' 首。</p>';
    echo '<table class="widefat striped"><thead><tr>';
    echo '<th>#</th><th>歌名</th><th>類型</th><th>藝人</th><th>來自作品</th><th>綜合分</th><th>試聽</th></tr></thead><tbody>';
    $i = 1;
    foreach ( $songs as $s ) {
        echo '<tr>';
        echo '<td>'.$i.'</td>';
        echo '<td>'.esc_html($s['song']).'</td>';
        echo '<td>'.esc_html($s['type'].' '.$s['slug']).'</td>';
        echo '<td>'.esc_html($s['artist']).'</td>';
        echo '<td><a href="'.esc_url($s['anime_url']).'" target="_blank">'.esc_html($s['anime_title']).'</a></td>';
        echo '<td>'.esc_html($s['anime_score']).'</td>';
        echo '<td>'.( $s['audio_url'] ? '<audio controls preload="none" style="height:32px" src="'.esc_url($s['audio_url']).'"></audio>' : '—' ).'</td>';
        echo '</tr>';
        $i++;
    }
    echo '</tbody></table></div>';
}

/**
 * 本季新番 OP/ED 榜（可跨多季：滾動最近兩季）
 * 與華人榜同樣的綜合分排序 + 一部一首代表曲。
 * 換季時只需改下方 $seasons / $year。
 */
function wxmc_build_season_chart( $limit = 30 ) {
    // ★ 換季就改這裡（季節大寫：WINTER / SPRING / SUMMER / FALL）
    //   要單季就只留一個，例如 [ 'SUMMER' ]
    $seasons = [ 'SPRING', 'SUMMER' ];
    $year    = 2026;

    // 動態組季節條件（IN）
    $season_clause = [
        'key'     => 'anime_season',
        'value'   => $seasons,
        'compare' => 'IN',
    ];

    $anime_posts = get_posts([
        'post_type'      => 'anime',
        'posts_per_page' => -1,
        'post_status'    => 'publish',
        'fields'         => 'ids',
        'meta_query'     => [
            'relation' => 'AND',
            [ 'key' => 'anime_themes',      'compare' => 'EXISTS' ],
            $season_clause,
            [ 'key' => 'anime_season_year', 'value' => $year, 'compare' => '=' ],
        ],
    ]);

    // 給前端顯示用的季節標籤
    $season_map = [ 'WINTER' => '冬季', 'SPRING' => '春季', 'SUMMER' => '夏季', 'FALL' => '秋季' ];
    $label_parts = [];
    foreach ( $seasons as $s ) {
        $label_parts[] = $season_map[ $s ] ?? $s;
    }
    $season_label = $year . ' ' . implode( '・', $label_parts );

    if ( empty( $anime_posts ) ) {
        return [ 'season' => implode( ',', $seasons ), 'year' => $year, 'label' => $season_label, 'songs' => [] ];
    }

    // 蒐集 + 找正規化最大值
    $rows = [];
    $max_pop = 0;
    $max_bgm = 0;
    foreach ( $anime_posts as $pid ) {
        $themes = json_decode( (string) get_post_meta( $pid, 'anime_themes', true ), true );
        if ( ! is_array( $themes ) || empty( $themes ) ) continue;
        $pop = (float) get_post_meta( $pid, 'anime_popularity', true );
        $bgm = (float) get_post_meta( $pid, 'anime_score_bangumi', true );
        if ( $pop > $max_pop ) $max_pop = $pop;
        if ( $bgm > $max_bgm ) $max_bgm = $bgm;
        $rows[] = [ 'pid' => $pid, 'pop' => $pop, 'bgm' => $bgm, 'themes' => $themes ];
    }
    if ( empty( $rows ) ) {
        return [ 'season' => implode( ',', $seasons ), 'year' => $year, 'label' => $season_label, 'songs' => [] ];
    }
    if ( $max_pop <= 0 ) $max_pop = 1;
    if ( $max_bgm <= 0 ) $max_bgm = 1;

    foreach ( $rows as &$r ) {
        $r['score'] = ( $r['pop'] / $max_pop ) * 0.6 + ( $r['bgm'] / $max_bgm ) * 0.4;
    }
    unset( $r );
    usort( $rows, function( $a, $b ) { return $b['score'] <=> $a['score']; });

    // 一部一首代表曲：OP1 → 第一首 OP → 第一首 ED
    $songs = [];
    foreach ( $rows as $r ) {
        $anime_title = get_post_meta( $r['pid'], 'anime_title_chinese', true ) ?: get_the_title( $r['pid'] );
        $anime_url   = get_permalink( $r['pid'] );
        $cover       = get_post_meta( $r['pid'], 'anime_cover_image', true );

        $pick = null; $first_op = null; $first_ed = null;
        foreach ( $r['themes'] as $t ) {
            $type = $t['type'] ?? ''; $slug = $t['slug'] ?? '';
            if ( $type === 'OP' && $slug === 'OP1' ) { $pick = $t; break; }
            if ( $type === 'OP' && $first_op === null ) $first_op = $t;
            if ( $type === 'ED' && $first_ed === null ) $first_ed = $t;
        }
        if ( $pick === null ) $pick = $first_op ?: $first_ed;
        if ( $pick === null ) continue;

        $artist = '';
        if ( ! empty( $pick['artists'][0] ) ) {
            $a0 = $pick['artists'][0];
            $artist = $a0['name_native'] ?: $a0['name'];
        }
        $songs[] = [
            'anime_title' => $anime_title,
            'anime_url'   => $anime_url,
            'cover'       => $cover,
            'type'        => $pick['type']  ?? '',
            'slug'        => $pick['slug']  ?? '',
            'song'        => $pick['title'] ?? '',
            'artist'      => $artist,
            'audio_url'   => $pick['audio_url'] ?? '',
        ];
        if ( count( $songs ) >= $limit ) break;
    }
    return [ 'season' => implode( ',', $seasons ), 'year' => $year, 'label' => $season_label, 'songs' => $songs ];
}

/**
 * ============================================================
 *  演唱會 & MV 精選：手動維護用的自訂文章類型
 *  註冊一次，之後全在後台填，不用再改 PHP
 * ============================================================
 */
add_action( 'init', function () {

    // 演唱會
    register_post_type( 'music_concert', [
        'labels' => [
            'name'         => '演唱會',
            'singular_name'=> '演唱會',
            'menu_name'    => '演唱會',
            'add_new'      => '新增演唱會',
            'add_new_item' => '新增演唱會',
            'edit_item'    => '編輯演唱會',
            'all_items'    => '所有演唱會',
        ],
        'public'       => false,
        'show_ui'      => true,
        'show_in_menu' => 'edit.php?post_type=music_chart', // 掛在「音樂榜單」選單下
        'menu_icon'    => 'dashicons-tickets-alt',
        'supports'     => [ 'title', 'thumbnail' ], // 標題=演唱會名稱、特色圖片=主視覺
    ] );

    // MV 精選
    register_post_type( 'music_mv', [
        'labels' => [
            'name'         => 'MV 精選',
            'singular_name'=> 'MV',
            'menu_name'    => 'MV 精選',
            'add_new'      => '新增 MV',
            'add_new_item' => '新增 MV',
            'edit_item'    => '編輯 MV',
            'all_items'    => '所有 MV',
        ],
        'public'       => false,
        'show_ui'      => true,
        'show_in_menu' => 'edit.php?post_type=music_chart',
        'menu_icon'    => 'dashicons-video-alt3',
        'supports'     => [ 'title' ], // 標題=MV 名稱
    ] );
} );

/**
 * 演唱會：新增自訂欄位（日期／地點／售票連結）
 */
add_action( 'add_meta_boxes', function () {
    add_meta_box( 'wxmc_concert_fields', '演唱會資訊', 'wxmc_concert_box', 'music_concert', 'normal', 'high' );
    add_meta_box( 'wxmc_mv_fields', 'MV 資訊', 'wxmc_mv_box', 'music_mv', 'normal', 'high' );
} );

function wxmc_concert_box( $post ) {
    wp_nonce_field( 'wxmc_concert_save', 'wxmc_concert_nonce' );
    $date   = get_post_meta( $post->ID, 'concert_date', true );
    $place  = get_post_meta( $post->ID, 'concert_place', true );
    $ticket = get_post_meta( $post->ID, 'concert_ticket', true );
    $artist = get_post_meta( $post->ID, 'concert_artist', true );
    echo '<p><label>演出者／樂團：<br><input type="text" name="concert_artist" value="' . esc_attr( $artist ) . '" style="width:100%"></label></p>';
    echo '<p><label>日期（例：2026-08-15）：<br><input type="text" name="concert_date" value="' . esc_attr( $date ) . '" style="width:100%"></label></p>';
    echo '<p><label>地點：<br><input type="text" name="concert_place" value="' . esc_attr( $place ) . '" style="width:100%"></label></p>';
    echo '<p><label>售票連結（網址）：<br><input type="url" name="concert_ticket" value="' . esc_attr( $ticket ) . '" style="width:100%"></label></p>';
    echo '<p style="color:#888">主視覺請用右側「特色圖片」上傳。</p>';
}

function wxmc_mv_box( $post ) {
    wp_nonce_field( 'wxmc_mv_save', 'wxmc_mv_nonce' );
    $yt     = get_post_meta( $post->ID, 'mv_youtube', true );
    $artist = get_post_meta( $post->ID, 'mv_artist', true );
    $anime  = get_post_meta( $post->ID, 'mv_anime', true );
    echo '<p><label>YouTube 連結：<br><input type="url" name="mv_youtube" value="' . esc_attr( $yt ) . '" style="width:100%" placeholder="https://www.youtube.com/watch?v=xxxx"></label></p>';
    echo '<p><label>演唱者：<br><input type="text" name="mv_artist" value="' . esc_attr( $artist ) . '" style="width:100%"></label></p>';
    echo '<p><label>對應作品（選填）：<br><input type="text" name="mv_anime" value="' . esc_attr( $anime ) . '" style="width:100%"></label></p>';
}

/**
 * 儲存自訂欄位
 */
add_action( 'save_post', function ( $post_id ) {
    // 演唱會
    if ( isset( $_POST['wxmc_concert_nonce'] ) && wp_verify_nonce( $_POST['wxmc_concert_nonce'], 'wxmc_concert_save' ) ) {
        foreach ( [ 'concert_artist', 'concert_date', 'concert_place', 'concert_ticket' ] as $k ) {
            if ( isset( $_POST[ $k ] ) ) update_post_meta( $post_id, $k, sanitize_text_field( $_POST[ $k ] ) );
        }
    }
    // MV
    if ( isset( $_POST['wxmc_mv_nonce'] ) && wp_verify_nonce( $_POST['wxmc_mv_nonce'], 'wxmc_mv_save' ) ) {
        foreach ( [ 'mv_youtube', 'mv_artist', 'mv_anime' ] as $k ) {
            if ( isset( $_POST[ $k ] ) ) update_post_meta( $post_id, $k, sanitize_text_field( $_POST[ $k ] ) );
        }
    }
} );

/**
 * 從 YouTube 網址抽出影片 ID（支援 watch?v= 與 youtu.be/）
 */
function wxmc_youtube_id( $url ) {
    if ( ! $url ) return '';
    if ( preg_match( '/(?:v=|youtu\.be\/|embed\/)([A-Za-z0-9_-]{11})/', $url, $m ) ) {
        return $m[1];
    }
    return '';
}

/**
 * 讀取：即將舉辦的演唱會（依日期排序，只顯示未過期）
 */
function wxmc_get_concerts( $limit = 6 ) {
    $posts = get_posts([
        'post_type'      => 'music_concert',
        'post_status'    => 'publish',
        'posts_per_page' => $limit,
        'meta_key'       => 'concert_date',
        'orderby'        => 'meta_value',
        'order'          => 'ASC',
    ]);
    $out = [];
    $today = date( 'Y-m-d' );
    foreach ( $posts as $p ) {
        $date = get_post_meta( $p->ID, 'concert_date', true );
        if ( $date && $date < $today ) continue; // 已過期就跳過
        $out[] = [
            'title'  => get_the_title( $p ),
            'artist' => get_post_meta( $p->ID, 'concert_artist', true ),
            'date'   => $date,
            'place'  => get_post_meta( $p->ID, 'concert_place', true ),
            'ticket' => get_post_meta( $p->ID, 'concert_ticket', true ),
            'cover'  => get_the_post_thumbnail_url( $p->ID, 'medium' ),
        ];
    }
    return $out;
}

/**
 * 讀取：MV 精選
 */
function wxmc_get_mvs( $limit = 8 ) {
    $posts = get_posts([
        'post_type'      => 'music_mv',
        'post_status'    => 'publish',
        'posts_per_page' => $limit,
        'orderby'        => 'date',
        'order'          => 'DESC',
    ]);
    $out = [];
    foreach ( $posts as $p ) {
        $yt = get_post_meta( $p->ID, 'mv_youtube', true );
        $vid = wxmc_youtube_id( $yt );
        if ( ! $vid ) continue; // 沒有有效 YT 連結就跳過
        $out[] = [
            'title'  => get_the_title( $p ),
            'artist' => get_post_meta( $p->ID, 'mv_artist', true ),
            'anime'  => get_post_meta( $p->ID, 'mv_anime', true ),
            'yt_id'  => $vid,
            'yt_url' => $yt,
            'thumb'  => 'https://img.youtube.com/vi/' . $vid . '/hqdefault.jpg', // YT 自動縮圖
        ];
    }
    return $out;
}

