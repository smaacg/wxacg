<?php
/**
 * Single Manga Template
 * Plugin: Anime Sync Pro
 * Path: wp-content/plugins/anime-sync-pro/public/templates/single-manga.php
 *
 * @version 1.7.3 — 2026-07-25
 *
 * v1.7.3 變更:
 *   - [新增] 簡體中文標題（anime_title_simplified）支援,對齊 single-anime.php v14.7:
 *            Meta 區統一讀取 $title_simplified;
 *            Hero 於原文名下方顯示簡體(與 $display_title 不同才顯示);
 *            Schema alternateName 納入簡體 + 繁體,並去重、排除主標題。
 *
 * v1.7.2 變更:
 *   - [對齊] 追蹤列 data 屬性 / class 全面對齊 anime-status.js v15.2:
 *            狀態鈕 data-value(want/watching/completed/dropped)、
 *            進度鈕 data-value(1/-1)、容器 data-favorited / data-fullcleared、
 *            進度數 .smacg-prog-current、進度條 .smacg-prog-bar、
 *            百分比 .smacg-prog-pct、標籤 .smacg-prog-label、
 *            收藏鈕 Font Awesome <i class="fa-bookmark">、Toast .smacg-point-toast。
 *   - [對齊] 追蹤資料改用實例方法 get_entry( $user_id, $post_id );
 *            狀態字串 want(非 plan_to_watch);收藏欄位 favorited。
 *   - [調整] 進度單位依 manga_chapters(話)/ manga_volumes(卷)動態顯示,
 *            漫畫不提供「全破」按鈕(JS 對缺失元素已 null 防護)。
 *
 * v1.6.0 變更:
 *   - [新增] 免費閱讀 / 試閱區塊(M4:manga_preview_url / _source_type / _note)。
 *   - [改版] 外部連結區:去重(依 URL) + 黑名單過濾 + 分組排序
 *            (官方連載平台 → 官網 → 其他 → 社群)。
 *   - [新增] 台版集數 fallback:manga_tw_volumes 空時改讀 manga_volumes_tw。
 *   - [修正] 封面牆擋掉無封面的雜卷(如 BGM 多出的第 25 空卡)。
 *   - [清理] 台版資訊 / 免費閱讀 加入 tabs 導航。
 *
 * v1.5.0 變更:
 *   - [刪除] Hero 動作列的「🔵 AniList」按鈕(外部連結區已有)。
 *   - [新增] manga_external_links(AniList externalLinks JSON)+ manga_wikipedia_url。
 *
 * v1.4.0 變更(★單行本封面牆):manga_volume_covers 封面牆 + ISBN 明細折疊。
 * v1.3.1 變更(★MAL 評分):anime_score_mal 0–100 → /10。
 * v1.3.0 變更(★評分系統):站台平均評分、使用者評分、Hero/側欄評分區塊。
 * v1.2.0 變更:維基來源欄位、各地區出版、單行本一覽。
 * v1.1.0 變更:系列作品徽章。
 */

if ( ! defined( 'ABSPATH' ) ) exit;

get_header();

while ( have_posts() ) :
    the_post();

    $post_id = get_the_ID();

    /* ── 輔助函式 ── */
    $get_meta = function ( $key, $default = '' ) use ( $post_id ) {
        $value = get_post_meta( $post_id, $key, true );
        return ( $value === '' || $value === null ) ? $default : $value;
    };

    $format_date = function ( $raw ) {
        if ( empty( $raw ) ) return '';
        $raw = trim( (string) $raw );
        if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $raw ) ) return $raw;
        if ( preg_match( '/^(\d{4})(\d{2})(\d{2})$/', $raw, $m ) ) return "{$m[1]}-{$m[2]}-{$m[3]}";
        $ts = strtotime( $raw );
        return $ts !== false ? gmdate( 'Y-m-d', $ts ) : $raw;
    };

    $substr_safe = function ( $text, $start, $length = null ) {
        $text = (string) $text;
        if ( function_exists( 'mb_substr' ) ) {
            return $length === null ? mb_substr( $text, $start ) : mb_substr( $text, $start, $length );
        }
        return $length === null ? substr( $text, $start ) : substr( $text, $start, $length );
    };

    $fallback_text = function ( $text, $length = 2 ) use ( $substr_safe ) {
        $text = trim( wp_strip_all_tags( (string) $text ) );
        return $text === '' ? 'MG' : $substr_safe( $text, 0, $length );
    };

    /* ── markdown pipe 表格 → HTML table(單行本一覽用,不依賴外部庫)── */
    $md_table_to_html = function ( $md ) {
        $md = trim( (string) $md );
        if ( $md === '' ) return '';
        $lines = preg_split( '/\r?\n/', $md );
        $lines = array_values( array_filter( array_map( 'trim', $lines ), fn( $l ) => $l !== '' ) );
        if ( count( $lines ) < 2 ) return '';

        $parse_row = function ( $line ) {
            $line = trim( $line, "| \t" );
            $cells = array_map( 'trim', explode( '|', $line ) );
            return $cells;
        };

        $header = $parse_row( $lines[0] );
        $body_lines = array_slice( $lines, 2 );

        $html  = '<div class="asd-vol-table-scroll"><table class="asd-vol-table"><thead><tr>';
        foreach ( $header as $h ) {
            $html .= '<th>' . esc_html( $h ) . '</th>';
        }
        $html .= '</tr></thead><tbody>';
        foreach ( $body_lines as $bl ) {
            if ( strpos( $bl, '|' ) === false ) continue;
            $cells = $parse_row( $bl );
            $html .= '<tr>';
            foreach ( $cells as $c ) {
                $html .= '<td>' . esc_html( $c ) . '</td>';
            }
            $html .= '</tr>';
        }
        $html .= '</tbody></table></div>';
        return $html;
    };

    $json_ld_flags =
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
        | JSON_HEX_TAG
        | JSON_HEX_AMP
        | JSON_HEX_APOS
        | JSON_HEX_QUOT;

    /* ── Meta(漫畫)── */
    $anilist_id = (int) $get_meta( 'anime_anilist_id', 0 );
    $mal_id     = (int) $get_meta( 'anime_mal_id', 0 );
    $bangumi_id = (int) $get_meta( 'anime_bangumi_id', 0 );

    $title_chinese    = $get_meta( 'anime_title_chinese' );
    $title_native     = $get_meta( 'anime_title_native' );
    $title_romaji     = $get_meta( 'anime_title_romaji' );
    $title_english    = $get_meta( 'anime_title_english' );
    $title_simplified = $get_meta( 'anime_title_simplified' );   // ★ v1.7.3 簡體標題
    $display_title    = $title_chinese ?: get_the_title();

    $format      = $get_meta( 'anime_format' );
    $status      = $get_meta( 'anime_status' );
    $source      = $get_meta( 'anime_source' );
    $popularity  = (int) $get_meta( 'anime_popularity', 0 );

    // 漫畫獨有
    $chapters    = $get_meta( 'manga_chapters' );
    $volumes     = $get_meta( 'manga_volumes' );
    $author      = $get_meta( 'manga_author' );
    $artist      = $get_meta( 'manga_artist' );

    // ── 維基來源欄位(M2)──
    $volumes_jp   = $get_meta( 'manga_volumes_jp' );
    $volumes_tw_m = $get_meta( 'manga_volumes_tw' );
    $volumes_hk_m = $get_meta( 'manga_volumes_hk' );
    $volumes_cn_m = $get_meta( 'manga_volumes_cn' );
    $jp_publisher = $get_meta( 'manga_jp_publishers' );
    $hk_publisher = $get_meta( 'manga_hk_publisher' );
    $cn_publisher = $get_meta( 'manga_cn_publisher' );
    $magazine     = $get_meta( 'manga_magazine' );
    $first_publish   = $format_date( $get_meta( 'manga_first_publish_date' ) );
    $volumes_summary = $get_meta( 'manga_volumes_summary' );

    // ── 單行本封面牆(v1.4.0,Bangumi 分卷)──
    $volume_covers_raw = $get_meta( 'manga_volume_covers' );
    $volume_covers     = $volume_covers_raw ? json_decode( $volume_covers_raw, true ) : [];
    $volume_covers     = is_array( $volume_covers ) ? $volume_covers : [];

    // ── 外部連結清單(v1.5.0,AniList externalLinks)──
    $external_links_raw = $get_meta( 'manga_external_links' );
    $external_links     = $external_links_raw ? json_decode( $external_links_raw, true ) : [];
    $external_links     = is_array( $external_links ) ? $external_links : [];

    // ── Wikipedia URL(維基 cron 寫入)──
    $wikipedia_url = $get_meta( 'manga_wikipedia_url' );

    // ── 免費閱讀 / 試閱(v1.6.0,M4)──
    $preview_url         = $get_meta( 'manga_preview_url' );
    $preview_source_type = $get_meta( 'manga_preview_source_type' );
    $preview_note        = $get_meta( 'manga_preview_note' );
    $has_preview         = ( $preview_url !== '' );

    // 台版代理
    $tw_publisher    = $get_meta( 'manga_tw_publisher' );
    $tw_translator   = $get_meta( 'manga_tw_translator' );
    $tw_volumes      = $get_meta( 'manga_tw_volumes' );
    $tw_release_date = $format_date( $get_meta( 'manga_tw_release_date' ) );
    $purchase_url    = $get_meta( 'manga_purchase_url' );

    // 關聯動畫
    $related_anime_id = (int) $get_meta( 'manga_related_anime', 0 );

    $start_date = $format_date( $get_meta( 'anime_start_date' ) );
    $end_date   = $format_date( $get_meta( 'anime_end_date' ) );

    /* ── 評分(外站)── */
    $score_anilist_raw = $get_meta( 'anime_score_anilist' );
    $score_anilist_num = is_numeric( $score_anilist_raw ) ? (float) $score_anilist_raw : 0;
    $score_anilist     = $score_anilist_num > 0 ? number_format( $score_anilist_num / 10, 1 ) : '';

    $score_bangumi_raw = $get_meta( 'anime_score_bangumi' );
    $score_bangumi_num = is_numeric( $score_bangumi_raw ) ? (float) $score_bangumi_raw : 0;
    $score_bangumi     = $score_bangumi_num > 0 ? number_format( $score_bangumi_num / 10, 1 ) : '';

    // ── MAL 分數(v1.3.1,0–100 制 → /10)──
    $score_mal_raw = $get_meta( 'anime_score_mal' );
    $score_mal_num = is_numeric( $score_mal_raw ) ? (float) $score_mal_raw : 0;
    $score_mal     = $score_mal_num > 0 ? number_format( $score_mal_num / 10, 1 ) : '';

    /* ── 站台平均評分(站內 WeixiaoACG+)── */
    $site_score = $site_story = $site_music = $site_animation = $site_voice = 0.0;
    $site_count = 0;

    if ( class_exists( 'Anime_Sync_Rating_Manager' ) ) {
        $rating_manager = new Anime_Sync_Rating_Manager();
        $site_stats     = $rating_manager->get_stats( $post_id );

        if ( is_array( $site_stats ) ) {
            $site_score     = (float) ( $site_stats['score']         ?? 0 );
            $site_story     = (float) ( $site_stats['avg_story']     ?? 0 );
            $site_music     = (float) ( $site_stats['avg_music']     ?? 0 );
            $site_animation = (float) ( $site_stats['avg_animation'] ?? 0 );
            $site_voice     = (float) ( $site_stats['avg_voice']     ?? 0 );
            $site_count     = (int)   ( $site_stats['vote_count']    ?? 0 );
        }
    }

    if ( $site_score <= 0 ) {
        $site_score = (float) get_post_meta( $post_id, 'anime_score_site', true );
    }
    if ( $site_count <= 0 ) {
        $site_count = (int) get_post_meta( $post_id, 'anime_score_site_count', true );
    }

    if ( $site_score <= 0 ) {
        $site_score     = (float) get_post_meta( $post_id, 'smacg_site_score',           true );
        $site_story     = (float) get_post_meta( $post_id, 'smacg_site_score_story',     true );
        $site_music     = (float) get_post_meta( $post_id, 'smacg_site_score_music',     true );
        $site_animation = (float) get_post_meta( $post_id, 'smacg_site_score_animation', true );
        $site_voice     = (float) get_post_meta( $post_id, 'smacg_site_score_voice',     true );
        if ( $site_count <= 0 ) {
            $site_count = (int) get_post_meta( $post_id, 'smacg_site_score_count', true );
        }
    }

    /* ── 使用者既有評分(預設值,前端 JS 動態覆寫)── */
    $user_rating = [ 'story' => 5.0, 'music' => 5.0, 'animation' => 5.0, 'voice' => 5.0 ];

    /* ─────────────────────────────────────────────
     * ★ v1.7.2 追蹤資料(想看 / 在看 / 看完 / 棄坑 / 進度 / 收藏)
     *   復用動畫的 Anime_Sync_User_Status_Manager;資料表 anime_id 欄位
     *   直接存漫畫 post_id。進度單位:優先「話」(manga_chapters),
     *   無則「卷」(manga_volumes)。
     *
     *   依實際後端(class-user-status-manager.php v1.1.0):
     *     - get_entry() 為實例方法,簽章 get_entry( $user_id, $anime_id )。
     *     - 狀態字串 want / watching / completed / dropped(非 plan_to_watch)。
     *     - 收藏欄位為 favorited(非 favorite)。
     * ───────────────────────────────────────────── */
    $user_anime_entry = null;
    if ( is_user_logged_in() && class_exists( 'Anime_Sync_User_Status_Manager' ) ) {
        $status_mgr       = new Anime_Sync_User_Status_Manager();
        // 注意:user_id 在前,anime_id(=post_id)在後
        $user_anime_entry = $status_mgr->get_entry( get_current_user_id(), $post_id );
    }

    // 進度上限與單位:話優先,卷次之
    $manga_chapters_int = ( $chapters !== '' && (int) $chapters > 0 ) ? (int) $chapters : 0;
    $manga_volumes_int  = ( $volumes  !== '' && (int) $volumes  > 0 ) ? (int) $volumes  : 0;
    if ( $manga_chapters_int > 0 ) {
        $manga_total_units = $manga_chapters_int;
        $manga_unit_label  = '話';
    } elseif ( $manga_volumes_int > 0 ) {
        $manga_total_units = $manga_volumes_int;
        $manga_unit_label  = '卷';
    } else {
        $manga_total_units = 0;   // 未知:JS 不設上限
        $manga_unit_label  = '話';
    }

    // 目前使用者狀態 / 進度 / 收藏(依 normalize_row 實際欄位名)
    $manga_cur_status = '';
    $manga_prog_val   = 0;
    $manga_is_fav     = 0;
    if ( is_array( $user_anime_entry ) ) {
        // status:want / watching / completed / dropped 或 null
        $manga_cur_status = (string) ( $user_anime_entry['status'] ?? '' );
        $manga_prog_val   = (int)    ( $user_anime_entry['progress'] ?? 0 );
        // 欄位為 favorited(bool)
        $manga_is_fav     = ! empty( $user_anime_entry['favorited'] ) ? 1 : 0;
    }
    $manga_prog_pct = ( $manga_total_units > 0 )
        ? min( 100, round( $manga_prog_val / $manga_total_units * 100 ) )
        : 0;

    $cover_image  = $get_meta( 'anime_cover_image' );
    $banner_image = $get_meta( 'anime_banner_image' );

    $synopsis_raw = $get_meta( 'anime_synopsis_chinese' );
    if ( empty( $synopsis_raw ) ) $synopsis_raw = get_the_content();
    $synopsis = trim( (string) $synopsis_raw );

    /* ── Labels ── */
    $format_labels = [
        'MANGA' => '漫畫', 'ONE_SHOT' => '短篇', 'NOVEL' => '小說',
        'LIGHT_NOVEL' => '輕小說', 'MANHWA' => '韓漫', 'MANHUA' => '國漫',
    ];
    $status_labels = [
        'FINISHED' => '已完結', 'RELEASING' => '連載中', 'NOT_YET_RELEASED' => '未發售',
        'CANCELLED' => '已腰斬', 'HIATUS' => '休刊中',
    ];
    $status_classes = [
        'FINISHED' => 's-fin', 'RELEASING' => 's-rel', 'NOT_YET_RELEASED' => 's-pre',
        'CANCELLED' => 's-can', 'HIATUS' => 's-hia',
    ];
    $source_labels = [
        'ORIGINAL' => '原創', 'MANGA' => '漫畫', 'LIGHT_NOVEL' => '輕小說改編',
        'NOVEL' => '小說改編', 'OTHER' => '其他',
    ];

    $format_label = $format_labels[ $format ] ?? $format;
    $status_label = $status_labels[ $status ] ?? $status;
    $status_class = $status_classes[ $status ] ?? '';
    $source_label = $source_labels[ $source ] ?? $source;

    // 卷數(日版):維基 manga_volumes_jp 優先,AniList manga_volumes fallback
    $volumes_effective = ( $volumes_jp !== '' && (int) $volumes_jp > 0 ) ? (int) $volumes_jp : (int) $volumes;
    $volumes_str  = ( $volumes_effective > 0 ) ? $volumes_effective . ' 卷' : '';
    $chapters_str = ( $chapters !== '' && (int) $chapters > 0 ) ? (int) $chapters . ' 話' : '';

    // 開始連載:維基首刊日優先,AniList start_date fallback
    $start_effective = $first_publish ?: $start_date;

    $genre_terms = get_the_terms( $post_id, 'genre' );
    $genre_terms = is_array( $genre_terms ) ? $genre_terms : [];

    /* ── 關聯動畫 ── */
    $related_anime = null;
    if ( $related_anime_id > 0 ) {
        $ra_post = get_post( $related_anime_id );
        if ( $ra_post && $ra_post->post_status === 'publish' && $ra_post->post_type === 'anime' ) {
            $related_anime = [
                'title' => get_post_meta( $ra_post->ID, 'anime_title_chinese', true ) ?: get_the_title( $ra_post->ID ),
                'cover' => get_post_meta( $ra_post->ID, 'anime_cover_image', true ),
                'url'   => get_permalink( $ra_post->ID ),
            ];
        }
    }

    /* ── Schema(Book)── */
    $schema_genres      = array_values( array_filter( array_map( fn($t) => $t->name, $genre_terms ) ) );

    // ★ v1.7.3 alternateName:納入簡體 + 繁體,去重後排除主標題
    $alternate_names    = array_values( array_unique( array_filter( [
        $title_chinese,
        $title_simplified,
        $title_native,
        $title_romaji,
        $title_english,
    ] ) ) );
    $alternate_names    = array_values( array_filter(
        $alternate_names,
        function ( $n ) use ( $display_title ) { return $n !== $display_title; }
    ) );

    $schema_description = trim( $substr_safe( wp_strip_all_tags( $synopsis ), 0, 200 ) );
    $schema_image       = $cover_image ?: get_the_post_thumbnail_url( $post_id, 'large' );

    $schema = [
        '@context' => 'https://schema.org',
        '@type'    => 'Book',
        'bookFormat' => 'https://schema.org/GraphicNovel',
        'name'     => $display_title,
        'url'      => get_permalink( $post_id ),
    ];
    if ( $schema_description !== '' ) $schema['description'] = $schema_description;
    if ( $schema_image )              $schema['image']       = $schema_image;
    if ( ! empty( $schema_genres ) )  $schema['genre']       = $schema_genres;
    if ( $start_effective )           $schema['datePublished'] = $start_effective;
    if ( ! empty( $alternate_names ) ) $schema['alternateName'] = $alternate_names;
    if ( $author )                    $schema['author']  = [ '@type' => 'Person', 'name' => $author ];
    if ( $artist )                    $schema['illustrator'] = [ '@type' => 'Person', 'name' => $artist ];
    if ( $volumes_effective > 0 )     $schema['numberOfPages'] = $volumes_effective;
    if ( $jp_publisher )              $schema['publisher'] = [ '@type' => 'Organization', 'name' => $jp_publisher ];

    $schema['inLanguage']      = 'ja';
    $schema['countryOfOrigin'] = 'JP';

    if ( $site_score > 0 && $site_count > 0 ) {
        $schema['aggregateRating'] = [
            '@type'       => 'AggregateRating',
            'ratingValue' => number_format( $site_score, 1 ),
            'ratingCount' => $site_count,
            'bestRating'  => 10,
            'worstRating' => 1,
        ];
    }

    $schema_same_as = [];
    if ( $anilist_id )    $schema_same_as[] = 'https://anilist.co/manga/' . $anilist_id;
    if ( $mal_id )        $schema_same_as[] = 'https://myanimelist.net/manga/' . $mal_id;
    if ( $bangumi_id )    $schema_same_as[] = 'https://bgm.tv/subject/' . $bangumi_id;
    if ( $wikipedia_url ) $schema_same_as[] = $wikipedia_url;
    foreach ( $external_links as $el ) {
        if ( ! empty( $el['url'] ) ) $schema_same_as[] = $el['url'];
    }
    $schema_same_as = array_values( array_unique( array_filter( array_map( 'esc_url_raw', $schema_same_as ) ) ) );
    if ( ! empty( $schema_same_as ) ) $schema['sameAs'] = $schema_same_as;

    $poster_fallback = $fallback_text( $display_title, 2 );

    /* ── 分享 URL ── */
    $share_permalink = get_permalink();
    $share_text_x    = $display_title . ' | 微笑動漫 ' . $share_permalink;
    $share_url_x     = 'https://twitter.com/intent/tweet?text=' . rawurlencode( $share_text_x );
    $share_url_fb    = 'https://www.facebook.com/sharer/sharer.php?u=' . rawurlencode( $share_permalink );
    $contact_url     = home_url( '/contact/' ) . '?type=bug&ref=' . rawurlencode( $share_permalink );

    // 是否有任何地區出版資料
    $has_publish_region = ( $jp_publisher || $tw_publisher || $hk_publisher || $cn_publisher
        || ( $volumes_jp !== '' && (int) $volumes_jp > 0 )
        || ( $volumes_tw_m !== '' && (int) $volumes_tw_m > 0 )
        || ( $volumes_hk_m !== '' && (int) $volumes_hk_m > 0 )
        || ( $volumes_cn_m !== '' && (int) $volumes_cn_m > 0 )
        || $magazine );

    $vol_table_html = $md_table_to_html( $volumes_summary );

    // 單行本區塊是否顯示(封面牆 或 ISBN 表 任一有即顯示)
    $has_vol_section = ( ! empty( $volume_covers ) || $vol_table_html !== '' );

    // 台版集數 fallback:manga_tw_volumes 空時改讀維基 manga_volumes_tw
    $tw_vol_effective = ( $tw_volumes !== '' && (int) $tw_volumes > 0 )
        ? (int) $tw_volumes
        : ( ( $volumes_tw_m !== '' && (int) $volumes_tw_m > 0 ) ? (int) $volumes_tw_m : 0 );

    // 外部連結區是否顯示(三大 ID / wiki / externalLinks 任一有即顯示)
    $clean_anilist = $anilist_id ? preg_replace( '/\D/', '', (string) $anilist_id ) : '';
    $clean_mal     = $mal_id     ? preg_replace( '/\D/', '', (string) $mal_id )     : '';
    $clean_bangumi = $bangumi_id ? preg_replace( '/\D/', '', (string) $bangumi_id ) : '';
    $has_links = ( $clean_anilist || $clean_mal || $clean_bangumi || $wikipedia_url || ! empty( $external_links ) );

    /* ── 外部連結:去重 + 過濾 + 分組排序 ── */
    $link_group_weight = function ( $site, $type ) {
        $s = mb_strtolower( trim( (string) $site ) );
        $t = strtoupper( trim( (string) $type ) );
        $platform = [ 'manga plus', 'shonen jump', 'shonen jump+', 'jump', 'viz', 'naver', 'comikey', 'bookwalker', 'kmanga', 'k manga' ];
        foreach ( $platform as $p ) {
            if ( strpos( $s, $p ) !== false ) return 10;
        }
        if ( strpos( $s, 'official' ) !== false || $t === 'INFO' ) return 20;
        $social = [ 'twitter', 'x ', 'instagram', 'facebook', 'youtube', 'tiktok' ];
        foreach ( $social as $so ) {
            if ( strpos( $s, $so ) !== false || $t === 'SOCIAL' ) return 90;
        }
        return 50;
    };
    $link_blacklist = [
        // 'amazon', 'ebay',
    ];
    $seen_urls  = [];
    $links_norm = [];
    foreach ( $external_links as $el ) {
        $url  = trim( (string) ( $el['url']  ?? '' ) );
        $site = trim( (string) ( $el['site'] ?? '' ) );
        $type = (string) ( $el['type']     ?? '' );
        $lang = (string) ( $el['language'] ?? '' );
        if ( $url === '' || $site === '' ) continue;

        $url_key = rtrim( $url, '/' );
        if ( isset( $seen_urls[ $url_key ] ) ) continue;
        $seen_urls[ $url_key ] = true;

        $skip  = false;
        $s_low = mb_strtolower( $site );
        foreach ( $link_blacklist as $bl ) {
            if ( $bl !== '' && strpos( $s_low, mb_strtolower( $bl ) ) !== false ) { $skip = true; break; }
        }
        if ( $skip ) continue;

        $links_norm[] = [
            'url'    => $url,
            'site'   => $site,
            'lang'   => $lang,
            'weight' => $link_group_weight( $site, $type ),
        ];
    }
    usort( $links_norm, function ( $a, $b ) {
        if ( $a['weight'] !== $b['weight'] ) return $a['weight'] <=> $b['weight'];
        $c = strcasecmp( $a['site'], $b['site'] );
        if ( $c !== 0 ) return $c;
        return strcasecmp( $a['lang'], $b['lang'] );
    } );

?>
<script type="application/ld+json"><?php echo wp_json_encode( $schema, $json_ld_flags ); ?></script>

<?php /* 預設使用者評分(HTML 對所有人一致,可被 LiteSpeed 快取) */ ?>
<script>
window.SmacgUserRating = <?php echo wp_json_encode( $user_rating ); ?>;
<?php if ( is_user_logged_in() ) : ?>
(function(){
    var url = '<?php echo esc_url_raw( admin_url( 'admin-ajax.php' ) ); ?>'
            + '?action=smacg_get_my_rating&post_id=<?php echo (int) $post_id; ?>';
    fetch(url, { credentials: 'same-origin' })
    .then(function(r){ return r.ok ? r.json() : null; })
    .then(function(res){
        if (!res || !res.success || !res.data || !res.data.rated) return;
        var d = res.data;
        window.SmacgUserRating = {
            story:     parseFloat(d.story)     || 5,
            music:     parseFloat(d.music)     || 5,
            animation: parseFloat(d.animation) || 5,
            voice:     parseFloat(d.voice)     || 5
        };
        document.dispatchEvent(new CustomEvent('smacg:userRatingReady', {
            detail: window.SmacgUserRating
        }));
    })
    .catch(function(){});
})();
<?php endif; ?>
</script>

<div class="asd-wrap">

    <?php /* Banner */ ?>
    <?php if ( $banner_image ) : ?>
        <div class="asd-banner" style="background-image:url(<?php echo esc_url( $banner_image ); ?>)">
            <div class="asd-banner-fade"></div>
        </div>
    <?php else : ?>
        <div class="asd-banner asd-banner--fallback"></div>
    <?php endif; ?>

    <?php /* Hero */ ?>
    <div class="asd-hero-new">

        <div class="asd-hero-poster-wrap">
            <div class="asd-hero-poster">
                <?php if ( $cover_image ) : ?>
                    <img src="<?php echo esc_url( $cover_image ); ?>"
                         alt="<?php echo esc_attr( $display_title ); ?> 封面"
                         class="asd-poster-img" loading="eager"
                         onerror="this.onerror=null;this.style.display='none';this.nextElementSibling.style.display='flex';">
                    <div class="asd-poster-fallback" style="display:none"><span><?php echo esc_html( $poster_fallback ); ?></span></div>
                <?php elseif ( has_post_thumbnail() ) : ?>
                    <?php echo get_the_post_thumbnail( $post_id, 'large', [ 'class' => 'asd-poster-img', 'loading' => 'eager', 'alt' => $display_title . ' 封面' ] ); ?>
                <?php else : ?>
                    <div class="asd-poster-fallback"><span><?php echo esc_html( $poster_fallback ); ?></span></div>
                <?php endif; ?>
            </div>
        </div>

        <div class="asd-hero-body">

            <div class="asd-hero-breadcrumb">
                <span>漫畫</span>
                <?php if ( ! empty( $genre_terms ) ) : ?><span class="asd-hbc-sep">›</span><span><?php echo esc_html( $genre_terms[0]->name ); ?></span><?php endif; ?>
            </div>

            <h1 class="asd-hero-title"><?php echo esc_html( $display_title ); ?></h1>

            <?php if ( $title_native ) : ?>
                <p class="asd-hero-native"><?php echo esc_html( $title_native ); ?></p>
            <?php endif; ?>
            <?php /* ★ v1.7.3 簡體標題(與主標題不同才顯示)*/ ?>
            <?php if ( $title_simplified && $title_simplified !== $display_title ) : ?>
                <p class="asd-hero-native asd-hero-simplified"><?php echo esc_html( $title_simplified ); ?></p>
            <?php endif; ?>
            <?php if ( $title_romaji && $title_romaji !== $title_native ) : ?>
                <p class="asd-hero-native asd-hero-romaji"><?php echo esc_html( $title_romaji ); ?></p>
            <?php endif; ?>

            <?php
            /* ── 系列作品徽章 ── */
            $series_tax_terms = get_the_terms( $post_id, 'anime_series_tax' );
            if ( ! empty( $series_tax_terms ) && ! is_wp_error( $series_tax_terms ) ) :
                $series_tax     = $series_tax_terms[0];
                $series_tax_url = get_term_link( $series_tax );
                if ( $series_tax->count >= 2 && ! is_wp_error( $series_tax_url ) ) :
            ?>
                <a href="<?php echo esc_url( $series_tax_url ); ?>" class="asd-series-entry-badge asd-series-entry-badge--hero">
                    <span class="asd-series-badge-icon">📺</span>
                    <span class="asd-series-badge-text">
                        <span class="asd-series-badge-label">系列作品</span>
                        <span class="asd-series-badge-name"><?php echo esc_html( $series_tax->name ); ?></span>
                    </span>
                    <span class="asd-series-badge-count"><?php echo (int) $series_tax->count; ?> 部</span>
                    <span class="asd-series-badge-arrow">→</span>
                </a>
            <?php endif; endif; ?>

            <div class="asd-hero-badges">
                <?php
                if ( $status_label ) echo '<span class="asd-hbadge' . ( $status_class ? ' asd-hbadge--' . esc_attr( $status_class ) : '' ) . '">' . esc_html( $status_label ) . '</span>';
                if ( $format_label ) echo '<span class="asd-hbadge">' . esc_html( $format_label ) . '</span>';
                if ( $volumes_str )  echo '<span class="asd-hbadge">' . esc_html( $volumes_str ) . '</span>';
                if ( $chapters_str ) echo '<span class="asd-hbadge">' . esc_html( $chapters_str ) . '</span>';
                foreach ( array_slice( $genre_terms, 0, 3 ) as $gt ) {
                    echo '<span class="asd-hbadge asd-hbadge--genre">' . esc_html( $gt->name ) . '</span>';
                }
                ?>
            </div>

            <div class="asd-hero-scores-new">
                <?php if ( $score_anilist ) : ?>
                    <div class="asd-score-pill asd-score-pill--al">
                        <span class="asd-sp-dot"></span>
                        <span class="asd-sp-val"><?php echo esc_html( $score_anilist ); ?></span>
                        <span class="asd-sp-label">AniList</span>
                    </div>
                <?php endif; ?>
                <?php if ( $score_mal ) : ?>
                    <div class="asd-score-pill asd-score-pill--mal">
                        <span class="asd-sp-dot"></span>
                        <span class="asd-sp-val"><?php echo esc_html( $score_mal ); ?></span>
                        <span class="asd-sp-label">MAL</span>
                    </div>
                <?php endif; ?>
                <?php if ( $score_bangumi ) : ?>
                    <div class="asd-score-pill asd-score-pill--bgm">
                        <span class="asd-sp-dot"></span>
                        <span class="asd-sp-val"><?php echo esc_html( $score_bangumi ); ?></span>
                        <span class="asd-sp-label">Bangumi</span>
                    </div>
                <?php endif; ?>
                <div class="asd-score-pill asd-score-pill--site">
                    <span class="asd-sp-dot"></span>
                    <span class="asd-sp-val wacg-hero-score"><?php echo $site_score > 0 ? esc_html( number_format( $site_score, 1 ) ) : '暫無'; ?></span>
                    <span class="asd-sp-label">WeixiaoACG+</span>
                </div>
            </div>

            <div class="asd-hero-actions">
                <?php if ( $has_preview ) : ?>
                    <a href="<?php echo esc_url( $preview_url ); ?>" target="_blank" rel="noopener noreferrer" class="asd-action-btn asd-action-btn--primary">📖 免費閱讀</a>
                <?php endif; ?>
                <?php if ( $purchase_url ) : ?>
                    <a href="<?php echo esc_url( $purchase_url ); ?>" target="_blank" rel="noopener noreferrer sponsored" class="asd-action-btn asd-action-btn--primary" title="<?php echo esc_attr( $display_title ); ?> 購買">🛒 購買台版</a>
                <?php endif; ?>
                <?php if ( $related_anime ) : ?>
                    <a href="<?php echo esc_url( $related_anime['url'] ); ?>" class="asd-action-btn asd-action-btn--ghost">📺 看動畫版</a>
                <?php endif; ?>
   <?php if ( is_user_logged_in() ) : ?>
                    <a href="#asd-sec-corrections" class="asd-action-btn asd-action-btn--ghost" id="asd-hero-corr-btn">✏ 糾錯回報</a>
                <?php else : ?>
                    <a href="<?php echo esc_url( wp_login_url( get_permalink() ) ); ?>" class="asd-action-btn asd-action-btn--ghost">✏ 糾錯回報</a>
                <?php endif; ?>            </div>

        </div><!-- /.asd-hero-body -->

        <?php /* 右側評分區塊 */ ?>
        <div class="asd-hside-block" id="wacg-rating-block">

            <div class="asd-hside-title">評分</div>

            <?php if ( $score_anilist ) : ?>
                <div class="asd-hside-row">
                    <span class="asd-hside-dot" style="background:var(--asd-score-al)"></span>
                    <span class="asd-hside-key">AniList</span>
                    <span class="asd-hside-val"><?php echo esc_html( $score_anilist ); ?></span>
                </div>
            <?php endif; ?>
            <?php if ( $score_mal ) : ?>
                <div class="asd-hside-row">
                    <span class="asd-hside-dot" style="background:var(--asd-score-mal)"></span>
                    <span class="asd-hside-key">MyAnimeList</span>
                    <span class="asd-hside-val"><?php echo esc_html( $score_mal ); ?></span>
                </div>
            <?php endif; ?>
            <?php if ( $score_bangumi ) : ?>
                <div class="asd-hside-row">
                    <span class="asd-hside-dot" style="background:var(--asd-score-bgm)"></span>
                    <span class="asd-hside-key">Bangumi</span>
                    <span class="asd-hside-val"><?php echo esc_html( $score_bangumi ); ?></span>
                </div>
            <?php endif; ?>

            <div class="wacg-rating-divider"></div>

            <div id="wacg-rating-stats" class="wacg-rating-stats">
                <div class="wacg-score-row">
                    <span class="asd-hside-dot wacg-dot-site"></span>
                    <span class="asd-hside-key">WeixiaoACG+</span>
                    <span class="asd-hside-val wacg-score-main"><?php echo $site_score > 0 ? esc_html( number_format( $site_score, 1 ) ) : '—'; ?></span>
                </div>
                <div class="wacg-vote-count"><?php echo $site_count > 0 ? esc_html( $site_count . ' 人評分' ) : ''; ?></div>
                <div class="wacg-cats">
                    <div class="wacg-cat-row"><span class="wacg-cat-label">劇情</span><span class="wacg-cat-val wacg-cat-story"><?php echo $site_story > 0 ? esc_html( number_format( $site_story, 1 ) ) : '—'; ?></span></div>
                    <div class="wacg-cat-row"><span class="wacg-cat-label">音樂</span><span class="wacg-cat-val wacg-cat-music"><?php echo $site_music > 0 ? esc_html( number_format( $site_music, 1 ) ) : '—'; ?></span></div>
                    <div class="wacg-cat-row"><span class="wacg-cat-label">作畫</span><span class="wacg-cat-val wacg-cat-animation"><?php echo $site_animation > 0 ? esc_html( number_format( $site_animation, 1 ) ) : '—'; ?></span></div>
                    <div class="wacg-cat-row"><span class="wacg-cat-label">聲優</span><span class="wacg-cat-val wacg-cat-voice"><?php echo $site_voice > 0 ? esc_html( number_format( $site_voice, 1 ) ) : '—'; ?></span></div>
                </div>
            </div>

            <?php if ( is_user_logged_in() ) : ?>
                <form id="wacg-rating-form" class="wacg-rating-form">
                    <?php
                    $sliders = [
                        [ 'key' => 'story',     'label' => '劇情' ],
                        [ 'key' => 'music',     'label' => '音樂' ],
                        [ 'key' => 'animation', 'label' => '作畫' ],
                        [ 'key' => 'voice',     'label' => '聲優' ],
                    ];
                    foreach ( $sliders as $s ) :
                        $init_val = $user_rating[ $s['key'] ];
                    ?>
                        <div class="wacg-slider-row">
                            <label class="wacg-slider-label" for="slider-<?php echo esc_attr( $s['key'] ); ?>"><?php echo esc_html( $s['label'] ); ?></label>
                            <input type="range"
                                   id="slider-<?php echo esc_attr( $s['key'] ); ?>"
                                   class="wacg-slider"
                                   min="1" max="10" step="0.1"
                                   value="<?php echo esc_attr( $init_val ); ?>">
                            <span id="slider-<?php echo esc_attr( $s['key'] ); ?>-val" class="wacg-slider-val"><?php echo esc_html( number_format( $init_val, 1 ) ); ?></span>
                        </div>
                    <?php endforeach; ?>
                    <button type="submit" id="wacg-submit-btn" class="wacg-submit-btn">送出評分</button>
                </form>
            <?php else : ?>
                <button type="button" class="wacg-login-prompt" data-action="smacg-login-prompt">
                    登入後即可評分
                </button>
            <?php endif; ?>

            <div class="wacg-rating-divider"></div>

            <?php
            $meta_rows = [
                '連載狀態'   => $status_label,
                '話數'       => $chapters_str,
                '卷數(日版)' => $volumes_str,
                '作者'       => $author,
                '作畫'       => ( $artist && $artist !== $author ) ? $artist : '',
                '開始連載'   => $start_effective,
                '完結日期'   => ( $end_date && $status === 'FINISHED' ) ? $end_date : '',
                '原作來源'   => $source_label,
            ];
            $has_any_meta = false;
            foreach ( $meta_rows as $mk => $mv ) :
                if ( ! strlen( (string) $mv ) ) continue;
                $has_any_meta = true;
            ?>
                <div class="asd-hside-info-row">
                    <span class="asd-hside-info-key"><?php echo esc_html( $mk ); ?></span>
                    <span class="asd-hside-info-val"><?php echo esc_html( $mv ); ?></span>
                </div>
            <?php endforeach; ?>
            <?php if ( ! $has_any_meta ) : ?>
                <p style="font-size:12px;color:var(--asd-text-muted);text-align:center;padding:8px 0;margin:0;">暫無資料</p>
            <?php endif; ?>

        </div><!-- /.asd-hside-block -->

    </div><!-- /.asd-hero-new -->

    <?php /* ─────────────────────────────────────────────
         * ★ v1.7.2 追蹤列 — data 屬性 / class 全面對齊 anime-status.js v15.2
         *   容器 .smacg-track-bar[data-post-id][data-episodes]
         *        [data-status][data-progress][data-favorited][data-fullcleared]
         *   狀態鈕 .smacg-status-btn[data-value=want|watching|completed|dropped]
         *   進度鈕 .smacg-prog-btn[data-value=1|-1]
         *   進度數 .smacg-prog-current  進度條 .smacg-prog-bar
         *   百分比 .smacg-prog-pct       標籤 .smacg-prog-label
         *   收藏   .smacg-fav-btn(內含 Font Awesome <i>)
         *   分享   .smacg-share-btn[data-title][data-url]
         *   Toast  .smacg-point-toast
         *   註:漫畫不提供「全破 .smacg-clear-btn」(JS 對缺失元素已 null 防護)
         * ───────────────────────────────────────────── */ ?>
    <div class="smacg-track-bar"
         data-post-id="<?php echo (int) $post_id; ?>"
         data-episodes="<?php echo (int) $manga_total_units; ?>"
         data-status="<?php echo esc_attr( $manga_cur_status ); ?>"
         data-progress="<?php echo (int) $manga_prog_val; ?>"
         data-favorited="<?php echo $manga_is_fav ? '1' : '0'; ?>"
         data-fullcleared="0"
         data-unit="<?php echo esc_attr( $manga_unit_label ); ?>">

        <?php if ( is_user_logged_in() ) : ?>

            <div class="smacg-track-main">

                <?php /* 狀態按鈕群(want / watching / completed / dropped)*/ ?>
                <div class="smacg-status-group">
                    <?php
                    $status_buttons = [
                        'want'      => [ 'label' => '想看', 'ico' => '📌' ],
                        'watching'  => [ 'label' => '在看', 'ico' => '📖' ],
                        'completed' => [ 'label' => '看完', 'ico' => '✅' ],
                        'dropped'   => [ 'label' => '棄坑', 'ico' => '🚫' ],
                    ];
                    foreach ( $status_buttons as $st_key => $st ) :
                        $is_active = ( $manga_cur_status === $st_key );
                    ?>
                        <button class="smacg-status-btn<?php echo $is_active ? ' is-active' : ''; ?>"
                                data-value="<?php echo esc_attr( $st_key ); ?>"
                                type="button"
                                title="<?php echo esc_attr( $st['label'] ); ?>">
                            <span class="smacg-ico"><?php echo $st['ico']; ?></span>
                            <span class="smacg-status-label"><?php echo esc_html( $st['label'] ); ?></span>
                        </button>
                    <?php endforeach; ?>
                </div>

                <?php /* 進度控制(話 / 卷)*/ ?>
                <div class="smacg-progress-group">
                    <button class="smacg-prog-btn smacg-prog-minus"
                            data-value="-1" type="button" title="減少進度">−</button>
                    <span class="smacg-progress-display">
                        <span class="smacg-prog-current"><?php echo (int) $manga_prog_val; ?></span>
                        <?php if ( $manga_total_units > 0 ) : ?>
                            <span class="smacg-prog-sep">/</span>
                            <span class="smacg-prog-total"><?php echo (int) $manga_total_units; ?></span>
                        <?php endif; ?>
                        <span class="smacg-prog-unit"><?php echo esc_html( $manga_unit_label ); ?></span>
                    </span>
                    <button class="smacg-prog-btn smacg-prog-plus"
                            data-value="1" type="button" title="增加進度">＋</button>
                </div>

                <?php /* 進度條 + 標籤 + 百分比 */ ?>
                <div class="smacg-prog-track">
                    <div class="smacg-prog-bar" style="width:<?php echo (int) $manga_prog_pct; ?>%"></div>
                </div>
                <div class="smacg-prog-meta">
                    <span class="smacg-prog-label">&nbsp;</span>
                    <span class="smacg-prog-pct"><?php echo (int) $manga_prog_pct; ?>%</span>
                </div>

                <?php /* 動作群(收藏 / 分享)— 收藏用 Font Awesome,與 JS renderFav 對齊 */ ?>
                <div class="smacg-action-group">
                    <button class="smacg-icon-btn smacg-fav-btn<?php echo $manga_is_fav ? ' is-active' : ''; ?>"
                            type="button" title="<?php echo $manga_is_fav ? '取消收藏' : '收藏'; ?>">
                        <i class="<?php echo $manga_is_fav ? 'fa-solid fa-bookmark' : 'fa-regular fa-bookmark'; ?>"></i>
                        <span class="smacg-icon-label">收藏</span>
                    </button>
                    <button class="smacg-icon-btn smacg-share-btn"
                            data-title="<?php echo esc_attr( $display_title ); ?>"
                            data-url="<?php echo esc_attr( $share_permalink ); ?>"
                            title="分享">
                        <span class="smacg-ico">🔗</span>
                        <span class="smacg-icon-label">分享</span>
                    </button>
                </div>

                <?php /* 積分 Toast(JS showPointToast / showTip 使用)*/ ?>
                <div class="smacg-point-toast" aria-live="polite"></div>

            </div><!-- /.smacg-track-main -->

        <?php else : ?>

            <?php /* 未登入:分享可用;狀態/進度/收藏交給 JS requireLogin() 攔截 */ ?>
            <div class="smacg-track-main">
                <button type="button" class="smacg-login-prompt" data-action="smacg-login-prompt">
                    登入後即可加入清單 / 追蹤進度
                </button>
                <div class="smacg-action-group">
                    <button class="smacg-icon-btn smacg-share-btn"
                            data-title="<?php echo esc_attr( $display_title ); ?>"
                            data-url="<?php echo esc_attr( $share_permalink ); ?>"
                            title="分享">
                        <span class="smacg-ico">🔗</span>
                        <span class="smacg-icon-label">分享</span>
                    </button>
                </div>
            </div>

        <?php endif; ?>

    </div><!-- /.smacg-track-bar -->

    <?php /* 分享浮窗 */ ?>
    <div class="smacg-share-modal" id="smacg-share-modal" role="dialog" aria-modal="true" style="display:none">
        <div class="smacg-share-inner">
            <p class="smacg-share-title">分享《<?php echo esc_html( $display_title ); ?>》</p>
            <div class="smacg-share-btns">
                <a class="smacg-share-link smacg-share-x" href="<?php echo esc_url( $share_url_x ); ?>" target="_blank" rel="noopener">𝕏 / Twitter</a>
                <a class="smacg-share-link smacg-share-fb" href="<?php echo esc_url( $share_url_fb ); ?>" target="_blank" rel="noopener">Facebook</a>
                <button class="smacg-share-link smacg-share-copy" id="smacg-copy-link">📋 複製連結</button>
            </div>
            <button class="smacg-share-close" id="smacg-share-close">✕</button>
        </div>
    </div>

    <?php /* Tabs */ ?>
    <div class="asd-tabs-wrap">
        <nav class="asd-tabs" id="asd-tabs" aria-label="頁面導航">
            <a class="asd-tab" href="#asd-sec-info">📋 基本資訊</a>
            <?php if ( $synopsis ) : ?><a class="asd-tab" href="#asd-sec-synopsis">📝 劇情簡介</a><?php endif; ?>
            <?php if ( $has_publish_region ) : ?><a class="asd-tab" href="#asd-sec-region">🌏 各地區出版</a><?php endif; ?>
            <?php if ( $tw_publisher || $tw_translator || $tw_release_date || $purchase_url ) : ?><a class="asd-tab" href="#asd-sec-tw">🇹🇼 台版資訊</a><?php endif; ?>
            <?php if ( $has_preview ) : ?><a class="asd-tab" href="#asd-sec-preview">📖 免費閱讀</a><?php endif; ?>
            <?php if ( $has_vol_section ) : ?><a class="asd-tab" href="#asd-sec-vols">📚 單行本</a><?php endif; ?>
            <?php if ( $has_links ) : ?><a class="asd-tab" href="#asd-sec-links">🔗 外部連結</a><?php endif; ?>
            <a class="asd-tab" href="#asd-sec-comments">💬 留言</a>
        </nav>

        <div class="asd-container asd-container--has-sidebar">
            <main class="asd-main" id="asd-main">

                <section class="asd-section" id="asd-sec-info">
                    <h2 class="asd-section-title">📋 基本資訊</h2>
                    <div class="asd-info-grid">
                        <?php
                        $info_rows = [
                            '類型'       => $format_label,
                            '連載狀態'   => $status_label,
                            '話數'       => $chapters_str,
                            '卷數(日版)' => $volumes_str,
                            '作者'       => $author,
                            '作畫'       => ( $artist && $artist !== $author ) ? $artist : '',
                            '日本出版社' => $jp_publisher,
                            '連載雜誌'   => $magazine,
                            '開始連載'   => $start_effective,
                            '完結日期'   => ( $end_date && $status === 'FINISHED' ) ? $end_date : '',
                            '原作來源'   => $source_label,
                            '台灣出版社' => $tw_publisher,
                            '最後更新'   => get_the_modified_date( 'Y-m-d' ),
                        ];
                        foreach ( $info_rows as $label => $val ) :
                            if ( $val === '' || $val === null ) continue;
                        ?>
                            <div class="asd-info-row">
                                <span class="asd-info-label"><?php echo esc_html( $label ); ?></span>
                                <span class="asd-info-val"><?php echo esc_html( $val ); ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </section>

                <?php if ( $synopsis ) : ?>
                    <section class="asd-section" id="asd-sec-synopsis">
                        <h2 class="asd-section-title">📝 劇情簡介</h2>
                        <div class="asd-synopsis"><?php echo wp_kses_post( wpautop( $synopsis ) ); ?></div>
                    </section>
                <?php endif; ?>

                <?php /* 各地區出版 */ ?>
                <?php if ( $has_publish_region ) : ?>
                    <section class="asd-section" id="asd-sec-region">
                        <h2 class="asd-section-title">🌏 各地區出版</h2>
                        <div class="asd-region-grid">
                            <?php
                            $regions = [
                                [ 'flag' => '🇯🇵', 'name' => '日本', 'pub' => $jp_publisher, 'vol' => $volumes_jp,   'mag' => $magazine ],
                                [ 'flag' => '🇹🇼', 'name' => '台灣', 'pub' => $tw_publisher, 'vol' => ( $volumes_tw_m !== '' ? $volumes_tw_m : $tw_volumes ), 'mag' => '' ],
                                [ 'flag' => '🇭🇰', 'name' => '香港', 'pub' => $hk_publisher, 'vol' => $volumes_hk_m, 'mag' => '' ],
                                [ 'flag' => '🇨🇳', 'name' => '中國大陸', 'pub' => $cn_publisher, 'vol' => $volumes_cn_m, 'mag' => '' ],
                            ];
                            foreach ( $regions as $r ) :
                                $has_pub = ( $r['pub'] !== '' );
                                $has_vol = ( $r['vol'] !== '' && (int) $r['vol'] > 0 );
                                $has_mag = ( $r['mag'] !== '' );
                                if ( ! $has_pub && ! $has_vol && ! $has_mag ) continue;
                            ?>
                                <div class="asd-region-card">
                                    <span class="asd-region-card__flag"><?php echo esc_html( $r['flag'] ); ?></span>
                                    <div class="asd-region-card__region"><?php echo esc_html( $r['name'] ); ?></div>
                                    <?php if ( $has_pub ) : ?>
                                        <div class="asd-region-card__row"><span class="asd-region-card__k">出版社</span><span class="asd-region-card__v"><?php echo esc_html( $r['pub'] ); ?></span></div>
                                    <?php endif; ?>
                                    <?php if ( $has_vol ) : ?>
                                        <div class="asd-region-card__row"><span class="asd-region-card__k">已出卷數</span><span class="asd-region-card__v"><?php echo (int) $r['vol']; ?> 卷</span></div>
                                    <?php endif; ?>
                                    <?php if ( $has_mag ) : ?>
                                        <div class="asd-region-card__row"><span class="asd-region-card__k">連載</span><span class="asd-region-card__v"><?php echo esc_html( $r['mag'] ); ?></span></div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </section>
                <?php endif; ?>

                <?php if ( $tw_publisher || $tw_translator || $tw_release_date || $tw_volumes || $purchase_url ) : ?>
                    <section class="asd-section" id="asd-sec-tw">
                        <h2 class="asd-section-title">🇹🇼 台版資訊</h2>
                        <div class="asd-info-grid">
                            <?php
                            $tw_rows = [
                                '台灣出版社' => $tw_publisher,
                                '譯者'       => $tw_translator,
                                '台版集數'   => $tw_vol_effective > 0 ? $tw_vol_effective . ' 卷' : '',
                                '台版發售日' => $tw_release_date,
                            ];
                            foreach ( $tw_rows as $label => $val ) :
                                if ( $val === '' || $val === null ) continue;
                            ?>
                                <div class="asd-info-row">
                                    <span class="asd-info-label"><?php echo esc_html( $label ); ?></span>
                                    <span class="asd-info-val"><?php echo esc_html( $val ); ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <?php if ( $purchase_url ) : ?>
                            <div style="margin-top:16px;">
                                <a href="<?php echo esc_url( $purchase_url ); ?>" target="_blank" rel="noopener noreferrer sponsored" class="asd-action-btn asd-action-btn--primary">🛒 前往購買台版</a>
                            </div>
                        <?php endif; ?>
                    </section>
                <?php endif; ?>

                <?php /* 免費閱讀 / 試閱(M4)★v1.6.0 */ ?>
                <?php if ( $has_preview ) : ?>
                    <?php
                    $preview_type_labels = [
                        'trial'             => '試閱',
                        'official_free'     => '官方完全免費',
                        'limited_time_free' => '期間限定免費',
                        'aggregator'        => '線上閱讀',
                    ];
                    $preview_type_badges = [
                        'trial'             => 'asd-preview-badge--trial',
                        'official_free'     => 'asd-preview-badge--free',
                        'limited_time_free' => 'asd-preview-badge--limited',
                        'aggregator'        => 'asd-preview-badge--agg',
                    ];
                    $preview_type_label = $preview_type_labels[ $preview_source_type ] ?? '';
                    $preview_type_class = $preview_type_badges[ $preview_source_type ] ?? '';
                    ?>
                    <section class="asd-section" id="asd-sec-preview">
                        <h2 class="asd-section-title">📖 免費閱讀 / 試閱</h2>
                        <div class="asd-preview-box">
                            <div class="asd-preview-info">
                                <?php if ( $preview_type_label ) : ?>
                                    <span class="asd-preview-badge <?php echo esc_attr( $preview_type_class ); ?>"><?php echo esc_html( $preview_type_label ); ?></span>
                                <?php endif; ?>
                                <?php if ( $preview_note ) : ?>
                                    <span class="asd-preview-note"><?php echo esc_html( $preview_note ); ?></span>
                                <?php endif; ?>
                            </div>
                            <a href="<?php echo esc_url( $preview_url ); ?>" target="_blank" rel="noopener noreferrer" class="asd-action-btn asd-action-btn--primary asd-preview-btn">
                                📖 前往閱讀
                            </a>
                        </div>
                    </section>
                <?php endif; ?>

                <?php /* 單行本:封面牆(常駐) + ISBN 明細(可折疊)  ★v1.4.0 */ ?>
                <?php if ( $has_vol_section ) : ?>
                    <section class="asd-section" id="asd-sec-vols">
                        <h2 class="asd-section-title">📚 單行本</h2>

                        <?php if ( ! empty( $volume_covers ) ) : ?>
                            <div class="asd-vol-cover-grid">
                                <?php foreach ( $volume_covers as $vc ) :
                                    $vc_vol   = (int) ( $vc['vol'] ?? 0 );
                                    $vc_cover = (string) ( $vc['cover'] ?? '' );
                                    $vc_bgm   = (int) ( $vc['bgm_id'] ?? 0 );
                                    if ( $vc_vol <= 0 ) continue;
                                    if ( $vc_cover === '' ) continue;   // ★ 沒封面就不顯示,擋掉雜卷空卡
                                    $vc_link  = $vc_bgm > 0 ? 'https://bgm.tv/subject/' . $vc_bgm : '';
                                ?>
                                    <?php if ( $vc_link ) : ?>
                                        <a class="asd-vol-cover" href="<?php echo esc_url( $vc_link ); ?>" target="_blank" rel="noopener noreferrer" title="第 <?php echo $vc_vol; ?> 卷">
                                    <?php else : ?>
                                        <div class="asd-vol-cover">
                                    <?php endif; ?>
                                            <div class="asd-vol-cover__img">
                                                <img src="<?php echo esc_url( $vc_cover ); ?>" alt="第 <?php echo $vc_vol; ?> 卷封面" loading="lazy">
                                            </div>
                                            <span class="asd-vol-cover__num"><?php echo $vc_vol; ?></span>
                                    <?php echo $vc_link ? '</a>' : '</div>'; ?>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                        <?php if ( $vol_table_html ) : ?>
                            <details class="asd-vol-details">
                                <summary>
                                    <h3 class="asd-section-title asd-vol-toggle" style="margin:0;font-size:1rem;">📋 各卷發售日 / ISBN 明細</h3>
                                </summary>
                                <?php echo $vol_table_html; // 已於 $md_table_to_html 內 esc_html 逐格處理 ?>
                            </details>
                        <?php endif; ?>
                    </section>
                <?php endif; ?>

                <?php /* 外部連結 ★v1.6.0:三大 ID + Wikipedia + externalLinks(去重/過濾/排序) */ ?>
                <?php if ( $has_links ) : ?>
                    <section class="asd-section" id="asd-sec-links">
                        <h2 class="asd-section-title">🔗 外部連結</h2>
                        <div class="asd-ext-links-grid">
                            <?php if ( $clean_anilist ) : ?><a href="<?php echo esc_url( 'https://anilist.co/manga/' . $clean_anilist ); ?>" target="_blank" rel="noopener noreferrer" class="asd-ext-link-card asd-ext--al"><span class="asd-ext-site">🔵 AniList</span><span class="asd-ext-arrow">→</span></a><?php endif; ?>
                            <?php if ( $clean_mal ) : ?><a href="<?php echo esc_url( 'https://myanimelist.net/manga/' . $clean_mal ); ?>" target="_blank" rel="noopener noreferrer" class="asd-ext-link-card asd-ext--mal"><span class="asd-ext-site">🔵 MyAnimeList</span><span class="asd-ext-arrow">→</span></a><?php endif; ?>
                            <?php if ( $clean_bangumi ) : ?><a href="<?php echo esc_url( 'https://bgm.tv/subject/' . $clean_bangumi ); ?>" target="_blank" rel="noopener noreferrer" class="asd-ext-link-card asd-ext--bgm"><span class="asd-ext-site">🍡 Bangumi</span><span class="asd-ext-arrow">→</span></a><?php endif; ?>
                            <?php if ( $wikipedia_url ) : ?><a href="<?php echo esc_url( $wikipedia_url ); ?>" target="_blank" rel="noopener noreferrer" class="asd-ext-link-card asd-ext--wiki"><span class="asd-ext-site">📖 維基百科</span><span class="asd-ext-arrow">→</span></a><?php endif; ?>

                            <?php
                            foreach ( $links_norm as $el ) :
                                $el_url   = $el['url'];
                                $el_site  = $el['site'];
                                $el_lang  = $el['lang'];
                                $el_label = $el_lang !== '' ? $el_site . ' ' . $el_lang : $el_site;
                            ?>
                                <a href="<?php echo esc_url( $el_url ); ?>" target="_blank" rel="noopener noreferrer" class="asd-ext-link-card asd-ext--ext">
                                    <span class="asd-ext-site"><?php echo esc_html( $el_label ); ?></span>
                                    <span class="asd-ext-arrow">→</span>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </section>
                <?php endif; ?>

                <?php /* 留言 */ ?>
                <section class="asd-section asd-comments" id="asd-sec-comments">
                    <h2 class="asd-section-title">💬 留言</h2>
                    <div class="asd-comments-inner">
                        <?php comments_template(); ?>
                    </div>
                </section>

                <?php /* 會員資料回報表單（wxacg-social corrections） */ ?>
<section class="asd-section asd-corrections" id="asd-sec-corrections">
    <?php echo do_shortcode( '[wxacg_correction_form]' ); ?>
</section>

            </main><!-- /.asd-main -->

            <aside class="asd-sidebar" aria-label="側邊欄">

                <?php if ( $related_anime ) : ?>
                    <div class="asd-side-section">
                        <div class="asd-side-section__head"><h3>📺 動畫版</h3></div>
                        <div class="asd-side-cards">
                            <a href="<?php echo esc_url( $related_anime['url'] ); ?>" class="asd-mini-card">
                                <div class="asd-mini-card__thumb">
                                    <?php if ( ! empty( $related_anime['cover'] ) ) : ?>
                                        <img src="<?php echo esc_url( $related_anime['cover'] ); ?>" alt="<?php echo esc_attr( $related_anime['title'] ); ?>" loading="lazy">
                                    <?php else : ?>
                                        <div class="asd-mini-card__thumb-fb"><span><?php echo esc_html( mb_substr( $related_anime['title'], 0, 2 ) ); ?></span></div>
                                    <?php endif; ?>
                                </div>
                                <div class="asd-mini-card__body">
                                    <span class="asd-mini-card__title"><?php echo esc_html( $related_anime['title'] ); ?></span>
                                    <span class="asd-mini-card__meta">動畫化作品 →</span>
                                </div>
                            </a>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="asd-side-section">
                    <div class="asd-side-section__head"><h3>🏷️ 作品標籤</h3></div>
                    <div class="asd-tags-wrap">
                        <?php foreach ( $genre_terms as $gt ) : ?>
                            <a href="<?php echo esc_url( get_term_link( $gt ) ); ?>" class="asd-tag-item"><?php echo esc_html( $gt->name ); ?></a>
                        <?php endforeach; ?>
                        <?php if ( empty( $genre_terms ) ) : ?>
                            <p class="asd-side-empty">暫無標籤資料</p>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ( $has_preview ) : ?>
                    <div class="asd-side-section">
                        <div class="asd-side-section__head"><h3>📖 線上閱讀</h3></div>
                        <div class="asd-affiliate-box">
                            <a href="<?php echo esc_url( $preview_url ); ?>" target="_blank" rel="noopener noreferrer" class="asd-action-btn asd-action-btn--primary" style="width:100%;text-align:center;">前往閱讀</a>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ( $purchase_url ) : ?>
                    <div class="asd-side-section">
                        <div class="asd-side-section__head"><h3>🛒 購買連結</h3></div>
                        <div class="asd-affiliate-box">
                            <a href="<?php echo esc_url( $purchase_url ); ?>" target="_blank" rel="noopener noreferrer sponsored" class="asd-action-btn asd-action-btn--primary" style="width:100%;text-align:center;">前往購買台版</a>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="asd-side-section asd-sponsor-block">
                    <div class="asd-sponsor-title">支持微笑動漫</div>
                    <div class="asd-sponsor-desc">喜歡這部作品的資訊嗎?微笑動漫每天整合來自全球三大資料庫的動漫情報,你的咖啡讓我們繼續走下去 ☕</div>
                    <a href="/sponsor/" target="_blank" rel="noopener noreferrer" class="asd-sponsor-btn">贊助微笑動漫</a>
                    <div class="asd-sponsor-note">贊助費用於伺服器維護,感謝每一位支持者</div>
                </div>

                <div class="asd-ad-placeholder" aria-label="廣告版位" role="complementary">
                    <div class="asd-ad-inner"></div>
                </div>

            </aside><!-- /.asd-sidebar -->

        </div><!-- /.asd-container -->

    </div><!-- /.asd-tabs-wrap -->

</div><!-- /.asd-wrap -->

<?php endwhile; get_footer(); ?>
