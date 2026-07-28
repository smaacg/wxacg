<?php
/**
 * Single Anime Template
 * Plugin: Anime Sync Pro
 * Path: wp-content/plugins/anime-sync-pro/public/templates/single-anime.php
 *
 * @version 14.8 — 2026-07-28
 *
 * Changelog:
 *   14.8 — CAST/STAFF 角色與聲優/製作人員連結化
 *          - [新增] $entity_url helper：產生 /person/{id}/{slug} 與
 *                   /character/{id}/{slug} 連結，slug 產生邏輯對齊
 *                   Anime_Sync_Entity_Repository::person_url() /
 *                   character_url()（空格轉 - 再 rawurlencode），
 *                   避免同一站出現兩套 URL 格式。
 *          - [新增] CAST 區塊：角色名（asd-cast-char）與 CV 名
 *                   （asd-cast-va-name）在 id > 0 時包成對應連結；
 *                   id <= 0（無 bgm_id 對應）時維持純文字，不產生連結。
 *          - [新增] STAFF 區塊：staff 名稱（asd-staff-name）同樣在
 *                   id > 0 時包成 /person/{id}/ 連結（staff 走
 *                   upsert_person，與聲優共用同一張 person 表）。
 *          - [說明] CAST/STAFF JSON 的 id 欄位已由 dump 實測確認：
 *                   角色/聲優/staff 皆為 id（純數字 bgm_id），無 fallback
 *                   欄位名問題。
 *   14.7 — 簡體中文標題（anime_title_simplified）支援
 *          - [新增] Meta 區統一讀取 $title_simplified。
 *          - [新增] Hero 標題區塊在日文原名下方顯示簡體標題（讀者可見）。
 *          - [新增] Schema alternateName 納入繁體 + 簡體中文，並去重、排除主標題。
 *          - [說明] 資料來自 Bangumi name_cn，寫入邏輯已於 class-api-handler.php 補上。
 *
 *   14.6 — 線上看支援 YouTube 播放清單（playlist）
 *          - [新增] anime_online_watch 解析新增播放清單判斷：網址含 list= 參數但無
 *                   單一 video ID 時，標記 type = playlist，改用
 *                   embed/videoseries?list=xxx 嵌入整個清單。
 *          - [說明] 若網址同時含 v= 與 list=（例如從清單分享出的單集連結），
 *                   仍優先判定為單支影片（type = video），只嵌入該集，
 *                   避免使用者標題寫「第X話」卻嵌出整個清單。
 *          - [相容] 原本已存在的純影片格式（watch?v=、youtu.be/、/embed/、/shorts/）
 *                   判斷順序與行為完全不變。
 *   14.5 — Hotfix：安全、SEO、明顯 bug 修正
 *          - [修正] 移除倒數 debug HTML comment，避免前台輸出除錯資料。
 *          - [安全] JSON-LD wp_json_encode 加入 JSON_HEX_TAG / JSON_HEX_AMP / JSON_HEX_APOS / JSON_HEX_QUOT。
 *          - [修正] CAST 顯示全部按鈕條件改為 count > 6，與前端 visibleCount=6 對齊。
 *          - [優化] Schema 空值清理，無值欄位不輸出，避免 description/image/genre/datePublished 空值。
 *          - [防呆] Anime_Sync_Streaming_Registry 加 class_exists 判斷，避免 class 未載入 fatal error。
 *   14.4 — Schema 補 sameAs（實體消歧義）
 *          - [新增] TVSeries/Movie schema 加入 sameAs，收錄官網、Wikipedia、
 *                   Twitter/X、TikTok、AniList、MAL、Bangumi 等權威連結，
 *                   協助 Google / AI 確認作品實體，提升知識圖譜辨識度。
 *          - [說明] 直接使用 Meta 區已轉 (int) 的 ID，不依賴頁面後段才清洗的
 *                   $clean_* 變數，避免變數順序耦合；所有欄位帶 if 判斷，
 *                   無資料即不輸出，維持 schema 乾淨。
 *   14.3 — Schema 欄位補強
 *          - [新增] TVSeries/Movie schema 補上 inLanguage(ja)、countryOfOrigin(JP)、
 *                   endDate(僅 FINISHED 輸出)、productionCompany(來自 $studio)、
 *                   director(從 staff 比對導演/監督/Director)、actor(CV 前 10 位，
 *                   主角優先)、trailer(VideoObject，取第一支 PV)。
 *          - [新增] FAQPage schema（有 anime_faq_json 才輸出）。
 *          - [規範] aggregateRating 僅採站內真實評分（site_score + site_count），
 *                   無站內評分則不輸出，避免引用第三方分數造假。
 *          - [說明] inLanguage 指「作品語言」固定 ja，非介面語言 zh-TW。
 *   14.2 — Schema 去重 + 效能
 *          - [修正] 移除模板輸出的 BreadcrumbList JSON-LD（與 Rank Math 重複，
 *                   經 Google Rich Results Test 確認頁面有兩個 BreadcrumbList）。
 *                   麵包屑結構化資料統一由 Rank Math 提供。
 *          - [清理] 移除不再使用的 $breadcrumb_schema 變數定義。
 *          - [效能] Relations 區段改為單次 IN 查詢（原本 N 個關聯 = N 次 get_posts，
 *                   現降為 1 次），輸出內容與排序維持不變。
 *          - [清理] $relation_labels 對照陣列移出迴圈，只定義一次。
 *   14.1 — 安全性與清理
 *          - [修正] 海外平台 icon onerror 改 CSS-only fallback，移除 esc_js outerHTML 注入（XSS 風險）
 *          - [修正] Twitter share URL 整段加 esc_url 包裹
 *          - [修正] 登入提示改 data-action，移除 inline onclick（為未來 CSP nonce 鋪路）
 *          - [清理] 移除 $auto_crunchyroll_item dead code
 *          - [清理] $bangumi_id 統一在 Meta 區讀取一次
 *          - [補強] 補上所有 echo 的 esc_html / esc_url 包裹
 *          - [保留] enqueue 已移至 functions.php
 *          - [說明] wpDiscuz 完整 UI 是否顯示，取決於 wpDiscuz Settings → General → 載入文章類型是否勾選 anime
 *   14.0 — 移除重複 enqueue，加入使用者評分注入
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

    $decode_json = function ( $raw ) {
        if ( is_array( $raw ) ) return $raw;
        if ( ! is_string( $raw ) || $raw === '' ) return [];
        $decoded = json_decode( $raw, true );
        return is_array( $decoded ) ? $decoded : [];
    };

    $format_date = function ( $raw ) {
        if ( empty( $raw ) ) return '';
        $raw = trim( (string) $raw );
        if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $raw ) ) return $raw;
        if ( preg_match( '/^(\d{4})(\d{2})(\d{2})$/', $raw, $m ) ) return "{$m[1]}-{$m[2]}-{$m[3]}";
        $ts = strtotime( $raw );
        return $ts !== false ? gmdate( 'Y-m-d', $ts ) : $raw;
    };

    $starts_with = function ( $haystack, $needle ) {
        return $needle !== '' && strpos( $haystack, $needle ) === 0;
    };

    // [14.8] 產生 person/character 詳情頁連結，id <= 0 時回傳空字串（不產生連結）。
    // slug 產生邏輯對齊 Anime_Sync_Entity_Repository::person_url() / character_url()：
    // 空格轉 - 再 rawurlencode，避免同一站兩套 URL 格式並存。
    $entity_url = function ( $type, $id, $name ) {
        $id = (int) $id;
        if ( $id <= 0 ) return '';
        $name = trim( (string) $name );
        $slug = $name !== '' ? rawurlencode( str_replace( ' ', '-', $name ) ) : '';
        return home_url( '/' . $type . '/' . $id . '/' . $slug );
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
        return $text === '' ? 'AN' : $substr_safe( $text, 0, $length );
    };

    $normalize_news_item = function ( $item ) {
        if ( ! is_array( $item ) ) return null;
        $title = $item['title'] ?? $item['name'] ?? $item['headline'] ?? '';
        $url   = $item['url']   ?? $item['link']  ?? '';
        $title = trim( (string) $title );
        $url   = trim( (string) $url );
        return $title !== '' ? [ 'title' => $title, 'url' => $url ] : null;
    };

    // [14.5] JSON-LD 安全輸出 flags：避免特殊字元破壞 <script type="application/ld+json">
    $json_ld_flags =
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
        | JSON_HEX_TAG
        | JSON_HEX_AMP
        | JSON_HEX_APOS
        | JSON_HEX_QUOT;

    /* ── Meta ── */
    $anilist_id = (int) $get_meta( 'anime_anilist_id', 0 );
    $mal_id     = (int) $get_meta( 'anime_mal_id', 0 );

    // [14.1] 統一在這裡讀 bangumi_id，含 legacy fallback
    $bangumi_id = (int) $get_meta( 'anime_bangumi_id', 0 );
    if ( ! $bangumi_id ) {
        $bangumi_id = (int) $get_meta( 'bangumi_id', 0 );
    }

    $title_chinese    = $get_meta( 'anime_title_chinese' );
    $title_simplified = $get_meta( 'anime_title_simplified' );
    $title_native     = $get_meta( 'anime_title_native' );
    $title_romaji     = $get_meta( 'anime_title_romaji' );
    $title_english    = $get_meta( 'anime_title_english' );
    $display_title    = $title_chinese ?: get_the_title();

    $format      = $get_meta( 'anime_format' );
    $status      = $get_meta( 'anime_status' );
    $season      = $get_meta( 'anime_season' );
    $season_year = (int) $get_meta( 'anime_season_year', 0 );
    $episodes    = (int) $get_meta( 'anime_episodes', 0 );
    $ep_aired    = (int) $get_meta( 'anime_episodes_aired', 0 );
    $duration    = (int) $get_meta( 'anime_duration', 0 );
    $source      = $get_meta( 'anime_source' );
    $studio      = $get_meta( 'anime_studios' );
    $popularity  = (int) $get_meta( 'anime_popularity', 0 );

    // [14.1] 提早判斷未播出狀態
    $is_not_aired = ( $status === 'NOT_YET_RELEASED' );

    $tw_streaming_raw   = $get_meta( 'anime_tw_streaming' );
    $tw_streaming_other = $get_meta( 'anime_tw_streaming_other' );
    $tw_distributor     = $get_meta( 'anime_tw_distributor' );
    $tw_dist_custom     = $get_meta( 'anime_tw_distributor_custom' );
    $tw_broadcast       = $get_meta( 'anime_tw_broadcast' );
    $tw_no_stream_google = $get_meta( 'anime_no_streaming_google' );


    // 配音語言版本（國語 / 台語）
    $dub_raw    = $get_meta( 'anime_dub_language' );
    $dub_arr    = is_array( $dub_raw ) ? $dub_raw : ( $dub_raw ? [ $dub_raw ] : [] );
    $dub_labels = [ 'mandarin' => '國語配音', 'taigi' => '台語配音' ];
    $dub_display = [];
    foreach ( $dub_arr as $d ) {
        $d = trim( (string) $d );
        if ( isset( $dub_labels[ $d ] ) ) {
            $dub_display[] = $dub_labels[ $d ];
        }
    }
    // 配音版本觀看連結（台語 / 國語）
    $dub_url_taigi    = $get_meta( 'anime_dub_url_taigi' );
    $dub_url_mandarin = $get_meta( 'anime_dub_url_mandarin' );
    
    // ✅ [14.5] Registry 防呆：避免 class 未載入時 fatal error
    $has_streaming_registry = class_exists( 'Anime_Sync_Streaming_Registry' );

    // ✅ [Registry] 動態從所有平台 key 讀取 URL meta，新增平台自動生效
    $tw_stream_url_map = [];
    if ( $has_streaming_registry ) {
        foreach ( Anime_Sync_Streaming_Registry::all() as $_p ) {
            if ( empty( $_p['key'] ) ) continue;
            $tw_stream_url_map[ $_p['key'] ] = $get_meta( 'anime_tw_streaming_url_' . $_p['key'] );
        }
    }

    $tw_dist_labels = [
        'muse'      => '木棉花', 'medialink' => '曼迪傳播', 'linbang'   => '羚邦',
        'tropic'    => '回歸線娛樂', 'proware' => '普威爾', 'kadokawa' => '台灣角川',
        'gungho'    => '群英社', 'tien'     => '提恩傳媒', 'garage'    => '車庫娛樂',
        'carsun'    => '采昌國際', 'jbf'    => '日本橋文化（JBF）', 'righttime' => '利得時代',
        'aniplus'   => 'ANIPLUS Asia', 'tongli' => '東立出版社', 'remow'  => 'REMOW',
        'gaga'      => 'GaGa OOLala', 'other' => '',
    ];

    $tw_dist_display = '';
    if ( $tw_distributor === 'other' ) {
        $tw_dist_display = $tw_dist_custom ?: '';
    } elseif ( $tw_distributor ) {
        $tw_dist_display = $tw_dist_labels[ $tw_distributor ] ?? $tw_distributor;
    }

    $provider_icon_base = trailingslashit( ANIME_SYNC_PRO_URL . 'public/assets/img/providers' );
    $provider_icon_map  = $has_streaming_registry ? Anime_Sync_Streaming_Registry::get_icon_map_flat() : [];

    $tw_stream_labels = $has_streaming_registry ? Anime_Sync_Streaming_Registry::get_acf_choices() : [];

    $tw_stream_legacy_aliases = [
        'ani-one'  => 'ani_one',
        'myVideo'  => 'myvideo',
        'my_video' => 'myvideo',
        'line_tv'  => 'linetv',
    ];

    $streaming_list = $decode_json( $get_meta( 'anime_streaming' ) );

    $tw_streaming_items = [];
    $tw_streaming_keys  = [];
    if ( ! empty( $tw_streaming_raw ) ) {
        $raw_arr = is_array( $tw_streaming_raw ) ? $tw_streaming_raw : [ $tw_streaming_raw ];
        foreach ( $raw_arr as $key ) {
            $key = trim( (string) $key );
            if ( isset( $tw_stream_legacy_aliases[ $key ] ) ) {
                $key = $tw_stream_legacy_aliases[ $key ];
            }
            if ( $key === '' || isset( $tw_streaming_keys[ $key ] ) ) continue;

            // [14.5] Registry 未載入時，至少用 key 當 label，避免平台整個消失
            $label = $tw_stream_labels[ $key ] ?? $key;

            $tw_streaming_keys[ $key ] = true;
            $tw_streaming_items[] = [
                'key'       => $key,
                'label'     => $label,
                'url'       => $tw_stream_url_map[ $key ] ?? '',
                'icon_url'  => isset( $provider_icon_map[ $key ] ) ? $provider_icon_base . $provider_icon_map[ $key ] : '',
                'icon_only' => false,
            ];
        }
    }
    if ( $tw_streaming_other ) {
        foreach ( array_map( 'trim', explode( ',', $tw_streaming_other ) ) as $extra ) {
            if ( $extra !== '' ) {
                $tw_streaming_items[] = [
                    'key'       => '',
                    'label'     => $extra,
                    'url'       => '',
                    'icon_url'  => '',
                    'icon_only' => false,
                ];
            }
        }
    }

    /* ── 攤平 streaming 資料：相容舊格式（一維）與新格式（taiwan/overseas）── */
    $streaming_flat = [];
    if ( isset( $streaming_list['taiwan'] ) || isset( $streaming_list['overseas'] ) ) {
        $streaming_flat = array_merge(
            is_array( $streaming_list['taiwan'] ?? null )   ? $streaming_list['taiwan']   : [],
            is_array( $streaming_list['overseas'] ?? null ) ? $streaming_list['overseas'] : []
        );
    } else {
        foreach ( $streaming_list as $sl ) {
            if ( is_array( $sl ) && isset( $sl['site'] ) ) {
                $streaming_flat[] = $sl;
            }
        }
    }

    /* ── 海外串流 ── */
    $overseas_streams = [];
    if ( isset( $streaming_list['overseas'] ) && is_array( $streaming_list['overseas'] ) ) {
        $overseas_streams = $streaming_list['overseas'];
    } else {
        $os_blacklist = [ 'crunchyroll', 'funimation', 'hidive', 'vrv', 'hulu', 'wakanim' ];
        foreach ( $streaming_flat as $sl ) {
            $sl_site = strtolower( trim( $sl['site'] ?? '' ) );
            if ( in_array( $sl_site, $os_blacklist, true ) ) {
                $overseas_streams[] = $sl;
            }
        }
    }

    /* [14.1] 移除 $auto_crunchyroll_item dead code */

    $start_date = $format_date( $get_meta( 'anime_start_date' ) );
    $end_date   = $format_date( $get_meta( 'anime_end_date' ) );

    $score_anilist_raw = $get_meta( 'anime_score_anilist' );
    $score_anilist_num = is_numeric( $score_anilist_raw ) ? (float) $score_anilist_raw : 0;
    $score_anilist     = $score_anilist_num > 0 ? number_format( $score_anilist_num / 10, 1 ) : '';

    $score_mal_raw = $get_meta( 'anime_score_mal' );
    $score_mal_num = is_numeric( $score_mal_raw ) ? (float) $score_mal_raw : 0;
    $score_mal     = $score_mal_num > 0 ? number_format( $score_mal_num / 10, 1 ) : '';

    $score_bangumi_raw = $get_meta( 'anime_score_bangumi' );
    $score_bangumi_num = is_numeric( $score_bangumi_raw ) ? (float) $score_bangumi_raw : 0;
    $score_bangumi     = $score_bangumi_num > 0 ? number_format( $score_bangumi_num / 10, 1 ) : '';

    $cover_image  = $get_meta( 'anime_cover_image' );
    $banner_image = $get_meta( 'anime_banner_image' );
    $trailer_url  = $get_meta( 'anime_trailer_url' );
    $online_watch_raw = $get_meta( 'anime_online_watch' );


    /* ── 解析多支 PV ── */
    $trailer_items = [];
    if ( $trailer_url ) {
        $idx = 0;
        foreach ( preg_split( '/[,，、;；\r\n]+/u', (string) $trailer_url ) as $t_url ) {
            $t_url = trim( $t_url );
            if ( $t_url === '' ) continue;

            $custom_label = '';
            if ( strpos( $t_url, '|' ) !== false ) {
                list( $t_url, $custom_label ) = array_map( 'trim', explode( '|', $t_url, 2 ) );
            }

            $vid = '';
            if ( preg_match( '/(?:youtu\.be\/|youtube\.com\/(?:watch\?v=|embed\/|v\/|shorts\/))([A-Za-z0-9_-]{11})/', $t_url, $m ) ) {
                $vid = $m[1];
            } elseif ( preg_match( '/^[A-Za-z0-9_-]{11}$/', $t_url ) ) {
                $vid = $t_url;
            }
            if ( $vid === '' ) continue;

            foreach ( $trailer_items as $exist ) {
                if ( $exist['id'] === $vid ) { $vid = ''; break; }
            }
            if ( $vid === '' ) continue;

            $idx++;
            $trailer_items[] = [
                'id'    => $vid,
                'label' => $custom_label !== '' ? $custom_label : ( 'PV ' . $idx ),
            ];
        }
    }
    $youtube_id  = ! empty( $trailer_items ) ? $trailer_items[0]['id'] : '';
    $has_trailer = ! empty( $trailer_items );
    
        /* ── 解析「線上看」YouTube 嵌入（格式同 PV：一行一筆，可選 標題|網址）──
         * [14.6] 新增播放清單（playlist）支援：
         *   1) 先判斷是否為「單支影片」網址（watch?v=/youtu.be//embed//shorts）→ type = video
         *   2) 若不是，再判斷網址是否含 list= 參數（播放清單）→ type = playlist
         *   兩者皆非才捨棄該行。若網址同時含 v= 與 list=，優先判定為 video，
         *   只嵌入該集，避免使用者標題寫「第X話」卻嵌出整個清單。
         */
    $online_watch_items = [];
    if ( $online_watch_raw ) {
        $ow_idx = 0;
        foreach ( preg_split( '/[,，、;；\r\n]+/u', (string) $online_watch_raw ) as $ow_url ) {
            $ow_url = trim( $ow_url );
            if ( $ow_url === '' ) continue;

            $ow_label = '';
            if ( strpos( $ow_url, '|' ) !== false ) {
                list( $ow_label, $ow_url ) = array_map( 'trim', explode( '|', $ow_url, 2 ) );
            }

            $ow_vid  = '';
            $ow_type = 'video';

            if ( preg_match( '/(?:youtu\.be\/|youtube\.com\/(?:watch\?v=|embed\/|v\/|shorts\/))([A-Za-z0-9_-]{11})/', $ow_url, $mm ) ) {
                $ow_vid = $mm[1];
            } elseif ( preg_match( '/^[A-Za-z0-9_-]{11}$/', $ow_url ) ) {
                $ow_vid = $ow_url;
            } elseif ( preg_match( '/[?&]list=([A-Za-z0-9_-]+)/', $ow_url, $lm ) ) {
                // 播放清單網址（例：youtube.com/playlist?list=xxx），沒有單一 video ID
                $ow_vid  = $lm[1];
                $ow_type = 'playlist';
            }
            if ( $ow_vid === '' ) continue;

            foreach ( $online_watch_items as $ex ) {
                if ( $ex['id'] === $ow_vid ) { $ow_vid = ''; break; }
            }
            if ( $ow_vid === '' ) continue;

            $ow_idx++;
            $online_watch_items[] = [
                'id'    => $ow_vid,
                'type'  => $ow_type,
                'label' => $ow_label !== '' ? $ow_label : ( '第 ' . $ow_idx . ' 部' ),
            ];
        }
    }
    $has_online_watch = ! empty( $online_watch_items );


    $official_site  = $get_meta( 'anime_official_site' );
    $twitter_url    = $get_meta( 'anime_twitter_url' );
    $wikipedia_url  = $get_meta( 'anime_wikipedia_url' );
    $tiktok_url     = $get_meta( 'anime_tiktok_url' );
    $affiliate_html = $get_meta( 'anime_affiliate_html' );

    // anime_next_airing 由 cron sync_dynamic_for_post 寫入「純 UTC timestamp」。
    // 舊版可能曾以 JSON 陣列儲存，故兩種格式都相容處理。
    $next_airing_raw = $get_meta( 'anime_next_airing' );
    $airing_data     = [];

    if ( $next_airing_raw ) {
        if ( is_numeric( $next_airing_raw ) ) {
            // 現行格式：純 timestamp。episode 用「已播集數 + 1」推算下一集。
            $aired_now   = (int) $get_meta( 'anime_episodes_aired' );
            $airing_data = [
                'airingAt' => (int) $next_airing_raw,
                'episode'  => $aired_now > 0 ? $aired_now + 1 : '',
            ];
        } else {
            // 相容舊資料：JSON 陣列格式。
            $decoded = is_array( $next_airing_raw ) ? $next_airing_raw : json_decode( $next_airing_raw, true );
            if ( is_array( $decoded ) ) {
                $airing_data = $decoded;
            }
        }
    }

    $synopsis_raw = $get_meta( 'anime_synopsis_chinese' );
    if ( empty( $synopsis_raw ) ) $synopsis_raw = $get_meta( 'anime_synopsis' );
    if ( empty( $synopsis_raw ) ) $synopsis_raw = get_the_content();
    $synopsis = trim( (string) $synopsis_raw );

    $themes_list    = $decode_json( $get_meta( 'anime_themes' ) );
    $cast_list      = $decode_json( $get_meta( 'anime_cast_json' ) );
    $staff_list     = $decode_json( $get_meta( 'anime_staff_json' ) );
    $relations_list = $decode_json( $get_meta( 'anime_relations_json' ) );
    $episodes_list  = $decode_json( $get_meta( 'anime_episodes_json' ) );

    $news_items = $decode_json( $get_meta( 'anime_related_news_json' ) );
    if ( empty( $news_items ) ) $news_items = $decode_json( $get_meta( 'anime_news_json' ) );
    $normalized_news = [];
    foreach ( $news_items as $ni ) {
        $n = $normalize_news_item( $ni );
        if ( $n ) $normalized_news[] = $n;
    }
    $news_items = $normalized_news;

    /* ── 自動帶入站內相關新聞（用原生標籤比對動畫標題）── */
    $news_match_titles = array_filter( array_map( 'trim', [
        $display_title,
        $title_chinese,
        $title_native,
        $title_romaji,
        $title_english,
    ] ) );

    if ( ! empty( $news_match_titles ) ) {
        // 撈出所有有掛文章的標籤，找出「標籤名包含在動畫標題裡」的
        $all_tags = get_terms( [
            'taxonomy'   => 'post_tag',
            'hide_empty' => true,
        ] );

        $matched_tag_ids = [];
        if ( ! is_wp_error( $all_tags ) ) {
            foreach ( $all_tags as $tag ) {
                $tag_name = trim( $tag->name );
                if ( $tag_name === '' ) continue;
                foreach ( $news_match_titles as $t ) {
                    // 標籤名出現在標題中（例：標籤「咒術迴戰」⊂ 標題「咒術迴戰 死滅迴游 後篇」）
                    if ( mb_strlen( $tag_name ) >= 2 && mb_strpos( $t, $tag_name ) !== false ) {
                        $matched_tag_ids[] = (int) $tag->term_id;
                        break;
                    }
                }
            }
        }
        $matched_tag_ids = array_unique( $matched_tag_ids );

        if ( ! empty( $matched_tag_ids ) ) {
            $news_query = new WP_Query( [
                'post_type'      => 'post',
                'post_status'    => 'publish',
                'posts_per_page' => 6,
                'orderby'        => 'date',
                'order'          => 'DESC',
                'no_found_rows'  => true,
                'tax_query'      => [
                    [
                        'taxonomy' => 'post_tag',
                        'field'    => 'term_id',
                        'terms'    => $matched_tag_ids,
                    ],
                ],
            ] );

            if ( $news_query->have_posts() ) {
                $seen_urls = [];
                foreach ( $news_items as $exist ) {
                    if ( ! empty( $exist['url'] ) ) $seen_urls[ $exist['url'] ] = true;
                }
                foreach ( $news_query->posts as $np ) {
                    $np_url = get_permalink( $np );
                    if ( isset( $seen_urls[ $np_url ] ) ) continue;
                    $seen_urls[ $np_url ] = true;
                    $news_items[] = [
                        'title' => get_the_title( $np ),
                        'url'   => $np_url,
                    ];
                }
            }
            wp_reset_postdata();
        }
    }

    /* ── Themes ── */
    $seen = []; $openings = []; $endings = [];
    foreach ( $themes_list as $t ) {
        $type   = strtoupper( trim( $t['type']  ?? '' ) );
        $slug   = trim( $t['slug']  ?? '' );
        $stitle = trim( $t['title'] ?? '' );
        $key    = $slug !== '' ? $slug : ( $type . '||' . $stitle );
        if ( isset( $seen[ $key ] ) ) continue;
        $seen[ $key ] = true;
        if ( $starts_with( $type, 'OP' ) )       $openings[] = $t;
        elseif ( $starts_with( $type, 'ED' ) )   $endings[]  = $t;
    }

    /* ── Labels ── */
    $season_labels  = [ 'WINTER' => '冬季', 'SPRING' => '春季', 'SUMMER' => '夏季', 'FALL' => '秋季' ];
    $format_labels  = [ 'TV' => 'TV', 'TV_SHORT' => 'TV', 'MOVIE' => '劇場版', 'OVA' => 'OVA', 'ONA' => 'ONA', 'SPECIAL' => '特別篇', 'MUSIC' => 'MV' ];
    $status_labels  = [ 'FINISHED' => '已完結', 'RELEASING' => '連載中', 'NOT_YET_RELEASED' => '尚未播出', 'CANCELLED' => '已取消', 'HIATUS' => '暫停中' ];
    $status_classes = [ 'FINISHED' => 's-fin', 'RELEASING' => 's-rel', 'NOT_YET_RELEASED' => 's-pre', 'CANCELLED' => 's-can', 'HIATUS' => 's-hia' ];
    $source_labels  = [
        'ORIGINAL' => '原創', 'MANGA' => '漫畫改編', 'LIGHT_NOVEL' => '輕小說改編',
        'NOVEL' => '小說改編', 'VISUAL_NOVEL' => '視覺小說改編', 'VIDEO_GAME' => '電玩改編',
        'WEB_MANGA' => '網路漫畫改編', 'BOOK' => '書籍改編', 'MUSIC' => '音樂改編',
        'GAME' => '遊戲改編', 'LIVE_ACTION' => '真人改編', 'MULTIMEDIA_PROJECT' => '跨媒體企劃', 'OTHER' => '其他',
    ];

    $season_label = $season_labels[ $season ] ?? $season;
    $format_label = $format_labels[ $format ] ?? $format;
    $status_label = $status_labels[ $status ] ?? $status;
    $status_class = $status_classes[ $status ] ?? '';
    $source_label = $source_labels[ $source ] ?? $source;

    $ep_str = '';
    if ( $episodes ) {
        $ep_str = ( $ep_aired && $ep_aired < $episodes )
            ? $ep_aired . ' / ' . $episodes . ' 集'
            : $episodes . ' 集';
    }

    $season_str = '';
    if ( $season_year && $season_label ) $season_str = $season_year . ' ' . $season_label;
    elseif ( $season_year )              $season_str = (string) $season_year;

    $genre_terms  = get_the_terms( $post_id, 'genre' );
    $season_terms = get_the_terms( $post_id, 'anime_season_tax' );
    $genre_terms  = is_array( $genre_terms )  ? $genre_terms  : [];
    $season_terms = is_array( $season_terms ) ? $season_terms : [];
    $season_child_terms = [];
    foreach ( $season_terms as $term ) {
        if ( ! empty( $term->parent ) ) $season_child_terms[] = $term;
    }

    /* ── Relations ──
     * [14.2] 改為單次 IN 查詢：先收集所有 anilist_id，一次撈回對應的 anime 文章，
     *        再用 map 對應，避免 N 個關聯 = N 次 get_posts 的 N+1 問題。
     */
    $relation_labels = [
        'PREQUEL' => '前作', 'SEQUEL' => '續作', 'PARENT' => '本篇', 'SIDE_STORY' => '外傳',
        'CHARACTER' => '角色', 'SUMMARY' => '總集篇', 'ALTERNATIVE' => '替代版本', 'SPIN_OFF' => '衍生作',
        'OTHER' => '相關', 'SOURCE' => '原作', 'COMPILATION' => '編輯版', 'CONTAINS' => '收錄', 'ANIME' => '動畫',
    ];

    $site_relations = [];

    // 1) 收集本作所有 relation 的 anilist_id（保留順序、去重）
    $rel_anilist_ids = [];
    foreach ( $relations_list as $rel ) {
        $rid = (int) ( $rel['anilist_id'] ?? $rel['id'] ?? 0 );
        if ( $rid && ! in_array( $rid, $rel_anilist_ids, true ) ) {
            $rel_anilist_ids[] = $rid;
        }
    }

    if ( ! empty( $rel_anilist_ids ) ) {
        // 2) 單次查詢：一次撈回所有對應的 anime 文章
        $rel_posts = get_posts( [
            'post_type'      => 'anime',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'no_found_rows'  => true,
            'meta_query'     => [
                [
                    'key'     => 'anime_anilist_id',
                    'value'   => $rel_anilist_ids,
                    'compare' => 'IN',
                    'type'    => 'NUMERIC',
                ],
            ],
        ] );

        // 3) 建立 anilist_id → post 的對照表
        $rel_post_map = [];
        foreach ( $rel_posts as $rp ) {
            $rp_aid = (int) get_post_meta( $rp->ID, 'anime_anilist_id', true );
            if ( $rp_aid && ! isset( $rel_post_map[ $rp_aid ] ) ) {
                $rel_post_map[ $rp_aid ] = $rp;
            }
        }

        // 4) 依原本 relations_list 的順序組裝輸出（與舊版一致）
        foreach ( $relations_list as $rel ) {
            $rel_anilist_id = (int) ( $rel['anilist_id'] ?? $rel['id'] ?? 0 );
            if ( ! $rel_anilist_id || ! isset( $rel_post_map[ $rel_anilist_id ] ) ) continue;
            $rp = $rel_post_map[ $rel_anilist_id ];

            $raw_label = $rel['relation_type'] ?? $rel['relation_label'] ?? $rel['type'] ?? '';
            $site_relations[] = [
                'title_zh'       => get_post_meta( $rp->ID, 'anime_title_chinese', true ) ?: ( $rel['title_zh'] ?? $rel['title'] ?? '' ),
                'title_native'   => $rel['title_native'] ?? $rel['native'] ?? '',
                'relation_label' => $relation_labels[ $raw_label ] ?? $raw_label,
                'format'         => $rel['format'] ?? '',
                'cover_image'    => get_post_meta( $rp->ID, 'anime_cover_image', true ) ?: ( $rel['cover_image'] ?? '' ),
                'url'            => get_permalink( $rp->ID ),
            ];
        }
    }

    /* ── 站台平均評分 ── */
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

    /* ── Schema ──
     * [14.3] 補強 TVSeries/Movie schema 欄位，全部帶 if 判斷，無資料就不輸出：
     *        inLanguage(ja)、countryOfOrigin(JP)、endDate(完結才加)、
     *        productionCompany(來自 $studio)、director(staff 導演)、
     *        actor(最多 10 位 CV)、trailer(VideoObject，取第一支 PV)。
     *        繁中站：inLanguage 指「作品語言」用 ja，不是介面語言 zh-TW。
     */
    $schema_type = 'TVSeries';
    if ( $format === 'MOVIE' ) $schema_type = 'Movie';
    elseif ( $format === 'MUSIC' ) $schema_type = 'MusicVideoObject';

    $schema_genres       = array_values( array_filter( array_map( fn($t) => $t->name, $genre_terms ) ) );
    $alternate_names     = array_values( array_unique( array_filter( [
        $title_chinese,
        $title_simplified,
        $title_native,
        $title_romaji,
        $title_english,
    ] ) ) );
    // 移除與主標題重複的項目，避免 alternateName 出現和 name 相同的值
    $alternate_names     = array_values( array_filter(
        $alternate_names,
        function ( $n ) use ( $display_title ) {
            return $n !== $display_title;
        }
    ) );
    $schema_description  = trim( $substr_safe( wp_strip_all_tags( $synopsis ), 0, 200 ) );
    $schema_image        = $cover_image ?: get_the_post_thumbnail_url( $post_id, 'large' );

    // [14.5] Schema 空值清理：只輸出有值欄位，避免 description/image/genre/datePublished 空值
    $schema = [
        '@context' => 'https://schema.org',
        '@type'    => $schema_type,
        'name'     => $display_title,
        'url'      => get_permalink( $post_id ),
    ];

    if ( $schema_description !== '' ) {
        $schema['description'] = $schema_description;
    }

    if ( $schema_image ) {
        $schema['image'] = $schema_image;
    }

    if ( ! empty( $schema_genres ) ) {
        $schema['genre'] = $schema_genres;
    }

    if ( $start_date ) {
        $schema['datePublished'] = $start_date;
    }

    if ( ! empty( $alternate_names ) ) {
        $schema['alternateName'] = $alternate_names;
    }

    if ( $episodes ) {
        $schema['numberOfEpisodes'] = $episodes;
    }

      // [14.4] sameAs：作品在各大資料庫／官方的權威連結，協助 Google / AI 實體消歧義
    //        直接用 Meta 區已轉 (int) 的 ID，不依賴頁面後段才清洗的 $clean_* 變數
    $schema_same_as = [];
    if ( $official_site ) $schema_same_as[] = $official_site;
    if ( $wikipedia_url ) $schema_same_as[] = $wikipedia_url;
    if ( $twitter_url )   $schema_same_as[] = $twitter_url;
    if ( $tiktok_url )    $schema_same_as[] = $tiktok_url;
    if ( $anilist_id )    $schema_same_as[] = 'https://anilist.co/anime/' . $anilist_id;
    if ( $mal_id )        $schema_same_as[] = 'https://myanimelist.net/anime/' . $mal_id;
    if ( $bangumi_id )    $schema_same_as[] = 'https://bgm.tv/subject/' . $bangumi_id;

    // [14.5] sameAs 去重與空值清理
    $schema_same_as = array_values( array_unique( array_filter( array_map( 'esc_url_raw', $schema_same_as ) ) ) );

    if ( ! empty( $schema_same_as ) ) {
        $schema['sameAs'] = $schema_same_as;
    }

    // [14.3] 作品語言與產地（日本動畫固定值，安全）
    // 配音版本：日語原音為主，額外加上台灣提供的國語 / 台語配音（BCP-47 語言碼）
    $schema_languages = [ 'ja' ];
    $dub_lang_codes   = [ 'mandarin' => 'zh-TW', 'taigi' => 'nan-TW' ];
    foreach ( $dub_arr as $d ) {
        $d = trim( (string) $d );
        if ( isset( $dub_lang_codes[ $d ] ) ) {
            $schema_languages[] = $dub_lang_codes[ $d ];
        }
    }
    $schema_languages = array_values( array_unique( $schema_languages ) );
    $schema['inLanguage']      = count( $schema_languages ) === 1 ? $schema_languages[0] : $schema_languages;
    $schema['countryOfOrigin'] = 'JP';

    // [14.3] 完結日期（只在已完結且有 end_date 時輸出）
    if ( $end_date && $status === 'FINISHED' ) {
        $schema['endDate'] = $end_date;
    }

    // [14.3] 製作公司
    if ( ! empty( $studio ) ) {
        $schema['productionCompany'] = [
            '@type' => 'Organization',
            'name'  => $studio,
        ];
    }

    // [14.3] 導演（從 staff 找 role 含「導演 / 監督 / Director」者）
    $schema_directors = [];
    foreach ( $staff_list as $s ) {
        $s_name = trim( $s['name'] ?? '' );
        $s_role = trim( $s['role'] ?? '' );
        if ( $s_name === '' ) continue;
        if (
            mb_strpos( $s_role, '導演' ) !== false ||
            mb_strpos( $s_role, '監督' ) !== false ||
            stripos( $s_role, 'director' ) !== false
        ) {
            $schema_directors[] = [ '@type' => 'Person', 'name' => $s_name ];
        }
    }
    if ( ! empty( $schema_directors ) ) {
        $schema['director'] = $schema_directors;
    }

    // [14.3] 聲優（取 cast 前 10 位）
    // 註：$cast_to_display 在本區塊「之後」才計算，這裡不能用它，
    //     改用 Meta 區已就緒的 $cast_list，並就地做主角優先排序。
    $schema_actors = [];
    if ( ! empty( $cast_list ) && is_array( $cast_list ) ) {
        // 主角優先：role = 主角 / MAIN 排前面
        $cast_main = [];
        $cast_rest = [];
        foreach ( $cast_list as $c ) {
            if ( empty( $c['name'] ) ) continue;
            $role = trim( $c['role'] ?? '' );
            if ( $role === '主角' || strtoupper( $role ) === 'MAIN' ) {
                $cast_main[] = $c;
            } else {
                $cast_rest[] = $c;
            }
        }
        $cast_for_schema = array_merge( $cast_main, $cast_rest );

        foreach ( array_slice( $cast_for_schema, 0, 10 ) as $c ) {
            $va = ( ! empty( $c['voice_actors'] ) && is_array( $c['voice_actors'] ) ) ? $c['voice_actors'][0] : [];
            $va_name = trim( $va['name'] ?? '' );
            if ( $va_name !== '' ) {
                $schema_actors[] = [ '@type' => 'Person', 'name' => $va_name ];
            }
        }
    }
    if ( ! empty( $schema_actors ) ) {
        $schema['actor'] = $schema_actors;
    }

    // [14.3] 預告片（取第一支 PV，組 VideoObject）
    // [14.5] uploadDate 僅在有 start_date 時輸出，避免用文章日期冒充影片上傳日
    // [14.6] uploadDate 補上時區（+09:00 日本時區），修正 GSC「datetime 無效／缺時區」警告
    if ( $youtube_id ) {
        $schema_trailer = [
            '@type'        => 'VideoObject',
            'name'         => $display_title . ' 預告片',
            'description'  => $schema_description !== '' ? $schema_description : ( $display_title . ' 預告片' ),
            'thumbnailUrl' => 'https://i.ytimg.com/vi/' . $youtube_id . '/hqdefault.jpg',
            'embedUrl'     => 'https://www.youtube.com/embed/' . $youtube_id,
        ];

        if ( $start_date ) {
            $schema_trailer['uploadDate'] = $start_date . 'T00:00:00+09:00';
        }

        $schema['trailer'] = $schema_trailer;
    }

    // 只用「站內真實評分」做 aggregateRating，符合 Google 結構化資料規範
    // 沒有站內評分時不輸出（寧缺勿濫，避免拿第三方分數造假）
    if ( $site_score > 0 && $site_count > 0 ) {
        $schema['aggregateRating'] = [
            '@type'       => 'AggregateRating',
            'ratingValue' => number_format( $site_score, 1 ),
            'bestRating'  => '10',
            'worstRating' => '1',
            'ratingCount' => (int) $site_count,
        ];
    }

    // [14.2] $breadcrumb_schema 已移除：麵包屑 JSON-LD 統一由 Rank Math 輸出，
    //        避免頁面出現兩個 BreadcrumbList（經 Rich Results Test 確認的重複）。

    $faq_items = [];
    $faq_json_raw = $get_meta( 'anime_faq_json' );
    if ( $faq_json_raw ) {
        $faq_decoded = json_decode( $faq_json_raw, true );
        if ( is_array( $faq_decoded ) ) $faq_items = $faq_decoded;
    }
    $faq_schema = null;
    if ( ! empty( $faq_items ) ) {
        $faq_main = [];
        foreach ( $faq_items as $f ) {
            if ( empty( $f['q'] ) || empty( $f['a'] ) ) continue;
            $faq_main[] = [
                '@type' => 'Question',
                'name'  => $f['q'],
                'acceptedAnswer' => [
                    '@type' => 'Answer',
                    'text'  => wp_strip_all_tags( $f['a'] ),
                ],
            ];
        }
        if ( ! empty( $faq_main ) ) {
            $faq_schema = [
                '@context'   => 'https://schema.org',
                '@type'      => 'FAQPage',
                'mainEntity' => $faq_main,
            ];
        }
    }

    /* ── Cast ── */
    $cast_to_display = []; $cast_seen = [];
    foreach ( $cast_list as $c ) {
        $name = trim( $c['name'] ?? '' );
        $role = trim( $c['role'] ?? '' );
        $key  = md5( wp_json_encode( $c ) );
        if ( $name === '' || isset( $cast_seen[ $key ] ) ) continue;
        if ( $role === '主角' || strtoupper( $role ) === 'MAIN' ) { $cast_to_display[] = $c; $cast_seen[ $key ] = true; }
    }
    foreach ( $cast_list as $c ) {
        $name = trim( $c['name'] ?? '' );
        $key  = md5( wp_json_encode( $c ) );
        if ( $name === '' || isset( $cast_seen[ $key ] ) ) continue;
        $cast_to_display[] = $c; $cast_seen[ $key ] = true;
    }

    $poster_fallback = $fallback_text( $display_title, 2 );

    /* ── 追蹤資料 ── */
    $uid              = get_current_user_id();
    $user_anime_entry = [ 'status' => null, 'progress' => 0, 'favorited' => false, 'fullcleared' => false ];
    if ( $uid && class_exists( 'Anime_Sync_User_Status_Manager' ) ) {
        $usm   = new Anime_Sync_User_Status_Manager();
        $entry = $usm->get_entry( (int) $uid, (int) $post_id );
        $user_anime_entry = [
            'status'      => $entry['status'],
            'progress'    => (int) $entry['progress'],
            'favorited'   => (bool) $entry['favorited'],
            'fullcleared' => (bool) $entry['fullcleared'],
        ];
    }

    /* ── 使用者既有評分（預設值，前端 JS 動態覆寫，避免破壞 LiteSpeed 快取） ── */
    $user_rating = [ 'story' => 5.0, 'music' => 5.0, 'animation' => 5.0, 'voice' => 5.0 ];

    /* ── 預先準備分享 URL（含安全轉義）── */
    $share_permalink   = get_permalink();
    $share_text_x      = $display_title . ' | 微笑動漫 ' . $share_permalink;
    $share_url_x       = 'https://twitter.com/intent/tweet?text=' . rawurlencode( $share_text_x );
    $share_url_fb      = 'https://www.facebook.com/sharer/sharer.php?u=' . rawurlencode( $share_permalink );
    $contact_url       = home_url( '/contact/' ) . '?type=bug&ref=' . rawurlencode( $share_permalink );

?>
<script type="application/ld+json"><?php echo wp_json_encode( $schema, $json_ld_flags ); ?></script>
<?php if ( $faq_schema ) : ?>
<script type="application/ld+json"><?php echo wp_json_encode( $faq_schema, $json_ld_flags ); ?></script>
<?php endif; ?>

<?php /* 預設使用者評分（HTML 對所有人一致，可被 LiteSpeed 快取） */ ?>
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
<style>
/* 封面高度跟隨右側內容自動拉長 */
.asd-wrap .asd-hero-poster {
    height: 100%;
    display: flex;
}
.asd-wrap .asd-hero-poster-wrap {
    align-self: stretch !important;
}
.asd-wrap .asd-poster-img,
.asd-wrap .asd-poster-fallback {
    height: 100% !important;
    min-height: 424px;
    width: 100% !important;
    object-fit: cover !important;
}
</style>
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
                <span>動畫</span>
                <?php if ( $season_str ) : ?><span class="asd-hbc-sep">›</span><span><?php echo esc_html( $season_str ); ?></span><?php endif; ?>
                <?php if ( ! empty( $genre_terms ) ) : ?><span class="asd-hbc-sep">›</span><span><?php echo esc_html( $genre_terms[0]->name ); ?></span><?php endif; ?>
            </div>

            <h1 class="asd-hero-title"><?php echo esc_html( $display_title ); ?></h1>

            <?php if ( $title_native ) : ?>
                <p class="asd-hero-native"><?php echo esc_html( $title_native ); ?></p>
            <?php endif; ?>
            <?php if ( $title_simplified && $title_simplified !== $display_title ) : ?>
                <p class="asd-hero-native asd-hero-simplified"><?php echo esc_html( $title_simplified ); ?></p>
            <?php endif; ?>
            <?php
            // 優先顯示英文標題；若無英文標題，才 fallback 顯示羅馬字拼音
            $title_en_or_romaji = $title_english ?: $title_romaji;
            ?>
            <?php if ( $title_en_or_romaji && $title_en_or_romaji !== $title_native ) : ?>
                <p class="asd-hero-native asd-hero-romaji"><?php echo esc_html( $title_en_or_romaji ); ?></p>
            <?php endif; ?>


            <?php
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
                if ( $season_str )   echo '<span class="asd-hbadge">' . esc_html( $season_str ) . '</span>';
                if ( $ep_str )       echo '<span class="asd-hbadge">' . esc_html( $ep_str ) . '</span>';
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
                    <span class="asd-sp-val wacg-hero-score"><?php echo $site_score > 0 ? esc_html( number_format( $site_score, 1 ) ) : '—'; ?></span>
                    <span class="asd-sp-label">WeixiaoAcg</span>
                </div>
            </div>
            
            <div class="asd-hero-actions">
                <?php
                /* [Hero 按鈕] 是否顯示「線上觀看」：
                 * 台灣串流 / 配音(台語·國語) / 後台手動填的 Google 網址，任一有就顯示。
                 * 注意：$dub_items_all、$google_search_url 於後面「串流 section」才計算，
                 * 這裡在前面，不能引用那些變數，改用原始 meta 值自行判斷。
                 */
                $hero_has_dub = ( in_array( 'taigi', $dub_arr, true )    && trim( (string) $dub_url_taigi )    !== '' )
                             || ( in_array( 'mandarin', $dub_arr, true ) && trim( (string) $dub_url_mandarin ) !== '' );

                $hero_has_watch = ( ! empty( $tw_streaming_items ) )
                               || $hero_has_dub
                               || ( ! empty( $tw_no_stream_google ) );
                ?>
                <?php if ( $hero_has_watch ) : ?>
                    <a href="#asd-sec-stream" class="asd-action-btn asd-action-btn--primary" title="<?php echo esc_attr( $display_title ); ?> 線上觀看">📺 線上觀看</a>
                <?php endif; ?>
                <?php if ( $youtube_id ) : ?>
                    <a href="#asd-sec-trailer" class="asd-action-btn asd-action-btn--ghost">▶ 觀看預告</a>
                <?php endif; ?>
                <?php if ( is_user_logged_in() ) : ?>
                    <a href="#asd-sec-corrections" class="asd-action-btn asd-action-btn--ghost" id="asd-hero-corr-btn">✏ 糾錯回報</a>
                <?php else : ?>
                    <a href="<?php echo esc_url( wp_login_url( get_permalink() ) ); ?>" class="asd-action-btn asd-action-btn--ghost">✏ 糾錯回報</a>
                <?php endif; ?>
                <?php if ( $official_site ) : ?>
                    <a href="<?php echo esc_url( $official_site ); ?>" target="_blank" rel="noopener noreferrer" class="asd-action-btn asd-action-btn--ghost">🌐 官方網站</a>
                <?php endif; ?>
                <?php if ( $bangumi_id > 0 ) : ?>
                    <a href="<?php echo esc_url( 'https://www.anitabi.cn/map?bangumiId=' . $bangumi_id ); ?>"
                       target="_blank"
                       rel="noopener noreferrer"
                       data-go-confirm="1"
                       class="asd-action-btn asd-action-btn--ghost"
                       title="<?php echo esc_attr( $display_title ); ?> 動漫巡禮地圖（資料來源：anitabi.cn）">
                        🗺 巡禮地圖
                    </a>
                <?php endif; ?>

                <?php
                /* v14.2 新增：無雷前導 / 有雷影評 按鈕
                 * 依 ACF related_anime 反查文章篇數 → 有才顯示
                 * helper 在 blocksy-child/functions.php v2.19.0
                 */
                if ( function_exists( 'smacg_get_anime_articles_count' ) ) :
                    $feature_count = smacg_get_anime_articles_count( $post_id, 'feature' );
                    $review_count  = smacg_get_anime_articles_count( $post_id, 'review' );
                    $feature_url   = add_query_arg( 'related_anime', $post_id, home_url( '/feature/anime/' ) );
                    $review_url    = add_query_arg( 'related_anime', $post_id, home_url( '/review/anime/' ) );
                ?>
                    <?php if ( $feature_count > 0 ) : ?>
                        <a href="<?php echo esc_url( $feature_url ); ?>"
                           class="asd-action-btn asd-action-btn--ghost"
                           title="<?php echo esc_attr( $display_title ); ?> 無雷前導文章">
                            🎬無雷前導
                        </a>
                    <?php endif; ?>
                    <?php if ( $review_count > 0 ) : ?>
                        <a href="<?php echo esc_url( $review_url ); ?>"
                           class="asd-action-btn asd-action-btn--ghost"
                           title="<?php echo esc_attr( $display_title ); ?> 有雷影評文章">
                            📝有雷影評
                        </a>
                    <?php endif; ?>
                <?php endif; ?>

            </div><!-- /.asd-hero-actions -->

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
                    <span class="asd-hside-key">MAL</span>
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
                    <span class="asd-hside-key">WeixiaoAcg</span>
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
            <?php if ( $site_count <= 0 ) : ?>
                <p class="wacg-be-first">✨尚無評分，快來搶頭香！</p>
            <?php endif; ?>

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
                    <div id="wacg-rated-actions" class="wacg-rated-actions" style="display:none;">
                        <span class="wacg-rated-badge">✓ 你已評分</span>
                        <button type="button" id="wacg-delete-btn" class="wacg-delete-btn">🗑 刪除評分</button>
                    </div>
                </form>

            <?php else : ?>
                <?php /* [14.1] 移除 inline onclick，改用 data-action 由 anime-rating.js 委派監聽 */ ?>
                <button type="button" class="wacg-login-prompt" data-action="smacg-login-prompt">
                    登入後即可評分
                </button>
            <?php endif; ?>


            <div class="wacg-rating-divider"></div>

            <?php
            $meta_rows = [
                '集數' => $ep_str,
                '時長' => $duration ? $duration . ' 分鐘' : '',
                '原作' => $source_label,
                '季度' => $season_str,
                '製作' => $studio,
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

    <?php /* 追蹤列 */ ?>
    <div class="smacg-track-bar"
         data-post-id="<?php echo esc_attr( $post_id ); ?>"
         data-episodes="<?php echo esc_attr( $episodes ); ?>"
         data-status="<?php echo esc_attr( $user_anime_entry['status'] ?? '' ); ?>"
         data-progress="<?php echo esc_attr( $user_anime_entry['progress'] ?? 0 ); ?>"
         data-favorited="<?php echo ( $user_anime_entry['favorited'] ?? false ) ? '1' : '0'; ?>"
         data-fullcleared="<?php echo ( $user_anime_entry['fullcleared'] ?? false ) ? '1' : '0'; ?>">

        <div class="smacg-track-main">

            <div class="smacg-status-group">
                <button class="smacg-status-btn <?php echo ( $user_anime_entry['status'] ?? '' ) === 'want' ? 'is-active' : ''; ?>" data-action="status" data-value="want" title="想看"><span class="smacg-ico">🔖</span><span>想看</span></button>

                <button class="smacg-status-btn <?php echo ( $user_anime_entry['status'] ?? '' ) === 'watching' ? 'is-active' : ''; ?>"
                        data-action="status" data-value="watching"
                        <?php echo $is_not_aired ? 'disabled aria-disabled="true" title="尚未播出，無法追番"' : 'title="追番中"'; ?>>
                    <span class="smacg-ico">▶</span><span>追番中</span>
                </button>

                <button class="smacg-status-btn <?php echo ( $user_anime_entry['status'] ?? '' ) === 'completed' ? 'is-active' : ''; ?>"
                        data-action="status" data-value="completed"
                        <?php echo $is_not_aired ? 'disabled aria-disabled="true" title="尚未播出，無法標記已看完"' : 'title="已看完"'; ?>>
                    <span class="smacg-ico">✓</span><span>已看完</span>
                </button>

                <button class="smacg-status-btn <?php echo ( $user_anime_entry['status'] ?? '' ) === 'dropped' ? 'is-active' : ''; ?>" data-action="status" data-value="dropped" title="棄坑"><span class="smacg-ico">✕</span><span>棄坑</span></button>
            </div>

            <div class="smacg-track-sep"></div>

            <?php
                $prog_val      = intval( $user_anime_entry['progress'] ?? 0 );
                $is_full       = ! empty( $user_anime_entry['fullcleared'] );
                $has_total     = ( $episodes > 0 );
                $prog_pct      = $has_total ? min( 100, round( ( $prog_val / max( 1, $episodes ) ) * 100 ) ) : 0;
                $display_total = $has_total ? $episodes : ( $ep_aired > 0 ? $ep_aired : '?' );
            ?>
            <div class="smacg-progress-group">
                <div class="smacg-prog-top">
                    <span class="smacg-prog-label">
                        <?php
                        if ( $is_full ) {
                            echo '🎉 已看完！';
                        } elseif ( ! $has_total && $ep_aired > 0 ) {
                            echo '📡 連載中（已播 ' . esc_html( $ep_aired ) . ' 集）';
                        } elseif ( ! $has_total ) {
                            echo '📡 連載中';
                        } elseif ( $prog_val > 0 ) {
                            echo '📺 觀看中';
                        } else {
                            echo '&nbsp;';
                        }
                        ?>
                    </span>
                    <?php if ( $has_total ) : ?>
                        <span class="smacg-prog-pct"><?php echo esc_html( $prog_pct ); ?>%</span>
                    <?php else : ?>
                        <span class="smacg-prog-pct">—</span>
                    <?php endif; ?>
                </div>

                <?php if ( $has_total ) : ?>
                    <div class="smacg-prog-bar-wrap">
                        <div class="smacg-prog-bar" style="width:<?php echo esc_attr( $prog_pct ); ?>%"></div>
                    </div>
                <?php endif; ?>

                <div class="smacg-prog-controls">
                    <button class="smacg-prog-btn" data-action="progress" data-value="-1">−</button>
                    <span class="smacg-prog-display">
                        <span class="smacg-prog-current"><?php echo esc_html( $prog_val ); ?></span>
                        <span class="smacg-prog-sep"> / </span>
                        <span class="smacg-prog-total"><?php echo esc_html( $display_total ); ?></span>
                        <span class="smacg-prog-unit"> 集</span>
                    </span>
                    <button class="smacg-prog-btn" data-action="progress" data-value="1">＋</button>
                </div>
            </div>
            <div class="smacg-track-sep"></div>

            <div class="smacg-action-group">
                <button class="smacg-icon-btn smacg-fav-btn <?php echo ( $user_anime_entry['favorited'] ?? false ) ? 'is-active' : ''; ?>" data-action="favorite" title="收藏">
                    <span class="smacg-ico"><?php echo ( $user_anime_entry['favorited'] ?? false ) ? '⭐' : '☆'; ?></span>
                    <span class="smacg-icon-label">收藏</span>
                </button>
                <?php /* 全破按鈕已移除（v15.3，2026-05-24） */ ?>
                <button class="smacg-icon-btn smacg-share-btn"
                        data-action="share"
                        data-title="<?php echo esc_attr( $display_title ); ?>"
                        data-url="<?php echo esc_attr( $share_permalink ); ?>"
                        title="分享">
                    <span class="smacg-ico">🔗</span>
                    <span class="smacg-icon-label">分享</span>
                </button>
            </div>

        </div><!-- /.smacg-track-main -->

        <div class="smacg-point-toast" aria-live="polite"></div>

    </div><!-- /.smacg-track-bar -->


    <?php /* 分享浮窗 */ ?>
    <div class="smacg-share-modal" id="smacg-share-modal" role="dialog" aria-modal="true" style="display:none">
        <div class="smacg-share-inner">
            <p class="smacg-share-title">分享《<?php echo esc_html( $display_title ); ?>》</p>
            <div class="smacg-share-btns">
                <a class="smacg-share-link smacg-share-x"
                   href="<?php echo esc_url( $share_url_x ); ?>"
                   target="_blank" rel="noopener">𝕏 / Twitter</a>
                <a class="smacg-share-link smacg-share-fb"
                   href="<?php echo esc_url( $share_url_fb ); ?>"
                   target="_blank" rel="noopener">Facebook</a>
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
            <?php if ( $youtube_id ) : ?><a class="asd-tab" href="#asd-sec-trailer">🎞 預告片</a><?php endif; ?>
            <?php if ( ! empty( $episodes_list ) ) : ?><a class="asd-tab" href="#asd-sec-episodes">📺 集數列表</a><?php endif; ?>
            <?php if ( ! empty( $staff_list ) ) : ?><a class="asd-tab" href="#asd-sec-staff">🎬 STAFF</a><?php endif; ?>
            <?php if ( ! empty( $cast_to_display ) ) : ?><a class="asd-tab" href="#asd-sec-cast">🎭 CAST</a><?php endif; ?>
            <?php if ( ! empty( $openings ) || ! empty( $endings ) ) : ?><a class="asd-tab" href="#asd-sec-music">🎵 主題曲</a><?php endif; ?>
            <?php if ( ! empty( $tw_streaming_items ) || ! empty( $overseas_streams ) ) : ?><a class="asd-tab" href="#asd-sec-stream">📺 串流</a><?php endif; ?>
            <?php if ( ! empty( $faq_items ) ) : ?><a class="asd-tab" href="#asd-sec-faq">❓ 常見問題</a><?php endif; ?>
            <?php if ( $official_site || $twitter_url || $wikipedia_url || $tiktok_url || $anilist_id || $mal_id || $bangumi_id ) : ?>
                <a class="asd-tab" href="#asd-sec-links">🔗 外部連結</a>
            <?php endif; ?>
            <a class="asd-tab" href="#asd-sec-comments">💬 留言</a>
        </nav>

        <div class="asd-container asd-container--has-sidebar">
            <main class="asd-main" id="asd-main">

                <?php /* 基本資訊 */ ?>
                <section class="asd-section" id="asd-sec-info">
                    <h2 class="asd-section-title">📋 基本資訊</h2>
                    <div class="asd-info-grid">
                        <?php
                        $info_rows = [
                            '類型'     => $format_label,
                            '集數'     => $ep_str,
                            '狀態'     => $status_label,
                            '播出季度' => $season_str,
                            '每集時長' => $duration ? $duration . ' 分鐘' : '',
                            '開始日期' => $start_date,
                            '結束日期' => ( $end_date && $status === 'FINISHED' ) ? $end_date : '',
                            '原作來源' => $source_label,
                            '製作公司' => $studio,
                            '台灣代理' => $tw_dist_display,
                            '播出頻道' => $tw_broadcast,
                            '配音版本' => ! empty( $dub_display ) ? implode( '、', $dub_display ) : '',
                            '最後更新' => get_the_modified_date( 'Y-m-d' ),
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

                    <?php
                    // ===== 倒數：直接在此重新讀取，不依賴上方變數，避免變數被覆蓋 =====
                    $cd_next  = $get_meta( 'anime_next_airing' );
                    $cd_ts    = is_numeric( $cd_next ) ? (int) $cd_next : 0;
                    $cd_aired = (int) $get_meta( 'anime_episodes_aired' );
                    $cd_ep    = $cd_aired > 0 ? $cd_aired + 1 : '';

                    if ( $status === 'RELEASING' && $cd_ts > time() ) :
                    ?>
                        <div class="asd-airing-bar">
                            <span>第 <?php echo esc_html( $cd_ep ); ?> 集播出倒數</span>
                            <strong class="asd-countdown" data-ts="<?php echo esc_attr( $cd_ts ); ?>"></strong>
                        </div>
                    <?php endif; ?>
                </section>


                <?php /* 劇情簡介 */ ?>
                <?php if ( $synopsis ) : ?>
                    <section class="asd-section" id="asd-sec-synopsis">
                        <h2 class="asd-section-title">📝 劇情簡介</h2>
                        <div class="asd-synopsis"><?php echo wp_kses_post( wpautop( $synopsis ) ); ?></div>
                    </section>
                <?php endif; ?>

                <?php /* 預告片 */ ?>
                <?php if ( $has_trailer ) : ?>
                    <section class="asd-section" id="asd-sec-trailer">
                        <h2 class="asd-section-title">🎞 預告片<?php echo count( $trailer_items ) > 1 ? ' <span class="asd-pv-count">（' . esc_html( count( $trailer_items ) ) . '）</span>' : ''; ?></h2>

                        <div class="asd-pv-box" data-pv-count="<?php echo esc_attr( count( $trailer_items ) ); ?>">

                            <?php if ( count( $trailer_items ) > 1 ) : ?>
                                <div class="asd-pv-tabs" role="tablist" aria-label="預告片切換">
                                    <?php foreach ( $trailer_items as $i => $pv ) : ?>
                                        <button type="button"
                                                class="asd-pv-tab<?php echo $i === 0 ? ' is-active' : ''; ?>"
                                                role="tab"
                                                aria-selected="<?php echo $i === 0 ? 'true' : 'false'; ?>"
                                                aria-controls="asd-pv-panel-<?php echo (int) $i; ?>"
                                                data-pv-index="<?php echo (int) $i; ?>"
                                                data-pv-id="<?php echo esc_attr( $pv['id'] ); ?>">
                                            <span class="asd-pv-tab-icon">▶</span>
                                            <span class="asd-pv-tab-label"><?php echo esc_html( $pv['label'] ); ?></span>
                                        </button>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>

                            <div class="asd-pv-panels">
                                <?php foreach ( $trailer_items as $i => $pv ) : ?>
                                    <div class="asd-pv-panel<?php echo $i === 0 ? ' is-active' : ''; ?>"
                                         id="asd-pv-panel-<?php echo (int) $i; ?>"
                                         role="tabpanel"
                                         data-pv-index="<?php echo (int) $i; ?>"
                                         data-pv-id="<?php echo esc_attr( $pv['id'] ); ?>">
                                        <div class="asd-trailer-wrap">
                                            <iframe src="https://www.youtube.com/embed/<?php echo esc_attr( $pv['id'] ); ?>"
                                                    title="<?php echo esc_attr( $display_title . ' ' . $pv['label'] ); ?>"
                                                    frameborder="0"
                                                    allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
                                                    allowfullscreen loading="lazy"></iframe>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>

                        </div>
                    </section>
                <?php endif; ?>

                <?php /* 集數列表 */ ?>
                <?php if ( ! empty( $episodes_list ) ) : ?>
                    <section class="asd-section" id="asd-sec-episodes">
                        <h2 class="asd-section-title">📺 集數列表</h2>
                        <div class="asd-ep-list" id="asd-ep-list">
                            <?php foreach ( $episodes_list as $i => $ep ) :
                                $ep_num     = (float) ( $ep['ep']      ?? 0 );
                                $ep_name_cn = trim( $ep['name_cn']   ?? '' );
                                $ep_name_ja = trim( $ep['name']      ?? '' );
                                $ep_airdate = $ep['airdate']          ?? '';
                                if ( $ep_name_cn !== '' && class_exists( 'Anime_Sync_CN_Converter' ) ) {
                                    $ep_name_cn = Anime_Sync_CN_Converter::static_convert( $ep_name_cn );
                                }
                                $ep_name     = $ep_name_cn ?: $ep_name_ja;
                                $ep_num_disp = ( floor($ep_num) == $ep_num ) ? (int)$ep_num : $ep_num;
                                $ep_display  = $ep_num > 0 ? '第' . $ep_num_disp . '集' : '第' . ( $i + 1 ) . '集';
                            ?>
                                <div class="asd-ep-row<?php echo $i >= 3 ? ' asd-ep-hidden' : ''; ?>">
                                    <span class="asd-ep-num"><?php echo esc_html( $ep_display ); ?></span>
                                    <div class="asd-ep-body">
                                        <?php if ( $ep_name ) : ?><span class="asd-ep-title"><?php echo esc_html( $ep_name ); ?></span><?php endif; ?>
                                        <?php if ( $ep_name_ja && $ep_name_cn && $ep_name_ja !== $ep_name_cn ) : ?>
                                            <span class="asd-ep-title-ja"><?php echo esc_html( $ep_name_ja ); ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <?php if ( $ep_airdate ) : ?><span class="asd-ep-date"><?php echo esc_html( $ep_airdate ); ?></span><?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <?php if ( count( $episodes_list ) > 3 ) : ?>
                            <div style="display:flex;justify-content:center;margin-top:12px;">
                                <button class="asd-ep-toggle" type="button">顯示全部 <?php echo esc_html( count( $episodes_list ) ); ?> 集▼</button>
                            </div>
                        <?php endif; ?>
                    </section>
                <?php endif; ?>

                <?php /* Staff */ ?>
                <?php if ( ! empty( $staff_list ) ) : ?>
                    <section class="asd-section" id="asd-sec-staff">
                        <h2 class="asd-section-title">🎬 STAFF</h2>
                        <div class="asd-staff-grid-v2" id="asd-staff-grid">
                            <?php foreach ( $staff_list as $i => $s ) :
                                $s_id     = (int) ( $s['id'] ?? 0 );
                                $s_name   = trim( $s['name']   ?? '' );
                                $s_native = trim( $s['native'] ?? '' );
                                $s_role   = wxacg_staff_role( $s['role'] ?? '' );
                                $s_url    = $entity_url( 'person', $s_id, $s_name );
                            ?>
                                <div class="asd-staff-card-v2<?php echo $i >= 10 ? ' asd-staff-hidden' : ''; ?>">
                                    <div class="asd-staff-info">
                                        <span class="asd-staff-role"><?php echo esc_html( $s_role ); ?></span>
                                        <span class="asd-staff-name"><?php
                                            if ( $s_url ) {
                                                echo '<a href="' . esc_url( $s_url ) . '">' . esc_html( $s_name ) . '</a>';
                                            } else {
                                                echo esc_html( $s_name );
                                            }
                                        ?></span>
                                        <?php if ( $s_native && $s_native !== $s_name ) : ?>
                                            <span class="asd-staff-native"><?php echo esc_html( $s_native ); ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <?php if ( count( $staff_list ) > 10 ) : ?>
                            <div style="display:flex;justify-content:center;margin-top:12px;">
                                <button class="asd-staff-toggle" id="asd-staff-toggle" type="button">顯示全部 <?php echo esc_html( count( $staff_list ) ); ?> 人 ▼</button>
                            </div>
                        <?php endif; ?>
                    </section>
                <?php endif; ?>

                <?php /* Cast */ ?>
                <?php if ( ! empty( $cast_to_display ) ) : ?>
                    <section class="asd-section" id="asd-sec-cast">
                        <h2 class="asd-section-title">🎭 CAST</h2>
                        <div class="asd-cast-grid" id="asd-cast-grid">
                            <?php foreach ( $cast_to_display as $i => $c ) :
                                $c_char_id     = (int) ( $c['id'] ?? 0 );
                                $c_char_name   = trim( $c['name']   ?? '' );
                                $c_char_native = trim( $c['native'] ?? '' );
                                $c_char_image  = trim( $c['image']  ?? '' );
                                $va            = ( ! empty( $c['voice_actors'] ) && is_array( $c['voice_actors'] ) ) ? $c['voice_actors'][0] : [];
                                $c_va_id       = (int) ( $va['id'] ?? 0 );
                                $c_va_name     = trim( $va['name']   ?? '' );
                                $c_va_native   = trim( $va['native'] ?? '' );
                                $c_fb          = function_exists( 'mb_substr' ) ? mb_substr( $c_char_name, 0, 2 ) : substr( $c_char_name, 0, 2 );
                                $c_char_url    = $entity_url( 'character', $c_char_id, $c_char_name );
                                $c_va_url      = $entity_url( 'person', $c_va_id, $c_va_name );
                            ?>
                                <div class="asd-cast-card<?php echo $i >= 6 ? ' asd-cast-hidden' : ''; ?>">
                                    <div class="asd-cast-avatar-wrap">
                                        <?php if ( $c_char_image ) : ?>
                                            <img src="<?php echo esc_url( $c_char_image ); ?>"
                                                 alt="<?php echo esc_attr( $c_char_name ); ?>"
                                                 loading="lazy"
                                                 onerror="this.onerror=null;this.style.display='none';this.nextElementSibling.style.display='flex';">
                                            <div class="asd-cast-avatar-fb" style="display:none"><span><?php echo esc_html( $c_fb ); ?></span></div>
                                        <?php else : ?>
                                            <div class="asd-cast-avatar-fb"><span><?php echo esc_html( $c_fb ); ?></span></div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="asd-cast-info">
                                        <span class="asd-cast-char"><?php
                                            if ( $c_char_url ) {
                                                echo '<a href="' . esc_url( $c_char_url ) . '">' . esc_html( $c_char_name ) . '</a>';
                                            } else {
                                                echo esc_html( $c_char_name );
                                            }
                                        ?></span>
                                        <?php if ( $c_char_native && $c_char_native !== $c_char_name ) : ?>
                                            <span class="asd-cast-char-native"><?php echo esc_html( $c_char_native ); ?></span>
                                        <?php endif; ?>
                                        <?php if ( $c_va_name ) : ?>
                                            <div class="asd-cast-va">
                                                <div class="asd-cast-va-info">
                                                    <span class="asd-cast-va-name">CV.<?php
                                                        if ( $c_va_url ) {
                                                            echo '<a href="' . esc_url( $c_va_url ) . '">' . esc_html( $c_va_name ) . '</a>';
                                                        } else {
                                                            echo esc_html( $c_va_name );
                                                        }
                                                    ?></span>
                                                    <?php if ( $c_va_native && $c_va_native !== $c_va_name ) : ?>
                                                        <span class="asd-cast-va-native"><?php echo esc_html( $c_va_native ); ?></span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <?php if ( count( $cast_to_display ) > 6 ) : ?>
                            <div style="display:flex;justify-content:center;margin-top:12px;">
                                <button class="asd-cast-toggle" id="asd-cast-toggle" type="button">顯示全部 <?php echo esc_html( count( $cast_to_display ) ); ?> 人 ▼</button>
                            </div>
                        <?php endif; ?>
                    </section>
                <?php endif; ?>
             
               <?php /* 主題曲 */ ?>
                <?php if ( ! empty( $openings ) || ! empty( $endings ) ) : ?>
                    <section class="asd-section" id="asd-sec-music">
                        <h2 class="asd-section-title">🎵 主題曲</h2>
                        <?php foreach ( [ 'OP' => $openings, 'ED' => $endings ] as $music_type => $music_list ) : ?>
                            <?php if ( empty( $music_list ) ) continue; ?>
                            <div class="asd-music-group">
                                <h3 class="asd-music-group-title"><?php echo $music_type === 'OP' ? '片頭曲 OP' : '片尾曲 ED'; ?></h3>
                                <?php foreach ( $music_list as $t ) :
                                    $t_type      = strtoupper( trim( $t['type'] ?? '' ) );
                                    $t_title     = trim( $t['title'] ?? '' );
                                    $t_native    = trim( $t['title_native'] ?? '' );
                                    $t_artists_raw = $t['artists'] ?? [];
                                    $t_artist_names = []; $t_artist_romaji_parts = [];
                                    foreach ( $t_artists_raw as $a ) {
                                        $dn = trim( $a['name_native'] ?? $a['name'] ?? '' );
                                        if ( $dn !== '' ) $t_artist_names[] = $dn;
                                        $rn = trim( $a['name'] ?? '' );
                                        if ( $rn !== '' ) $t_artist_romaji_parts[] = $rn;
                                    }
                                    $t_artist        = implode( '、', $t_artist_names );
                                    $t_artist_romaji = implode( ', ', $t_artist_romaji_parts );
                                    $t_audio_url = trim( $t['audio_url'] ?? '' );
                                    $t_video_url = trim( $t['video_url'] ?? '' );
                                    $t_episodes  = trim( $t['episodes'] ?? '' );
                                    $open_url    = $t_video_url ?: $t_audio_url;
                                    $badge_class = ( strpos( $t_type, 'OP' ) === 0 ) ? 'asd-music-type-badge--op' : 'asd-music-type-badge--ed';

                                    // ── 歌名 fallback：主標題永遠有值（原名優先，沒有就用羅馬字），
                                    //    避免原名為空時整個歌名消失、視覺上變成「歌手比歌名大」。
                                    $music_main = $t_native !== '' ? $t_native : $t_title;
                                    $music_sub  = ( $t_native !== '' && $t_title !== '' && $t_title !== $t_native ) ? $t_title : '';
                                ?>
                                    <div class="asd-music-card-v2">
                                        <span class="asd-music-type-badge <?php echo esc_attr( $badge_class ); ?>"><?php echo esc_html( $t_type ); ?></span>
                                        <div class="asd-music-body">
                                            <?php if ( $music_main !== '' ) : ?><span class="asd-music-title"><?php echo esc_html( $music_main ); ?></span><?php endif; ?>
                                            <?php if ( $music_sub !== '' ) : ?><span class="asd-music-native"><?php echo esc_html( $music_sub ); ?></span><?php endif; ?>
                                            <?php if ( $t_artist !== '' ) : ?>
                                                <span class="asd-music-artist">by <?php echo esc_html( $t_artist ); ?>
                                                    <?php if ( $t_artist_romaji !== '' && $t_artist_romaji !== $t_artist ) : ?>
                                                        <span class="asd-music-artist-romaji">(<?php echo esc_html( $t_artist_romaji ); ?>)</span>
                                                    <?php endif; ?>
                                                </span>
                                            <?php elseif ( $t_artist_romaji !== '' ) : ?>
                                                <span class="asd-music-artist">by <?php echo esc_html( $t_artist_romaji ); ?></span>
                                            <?php endif; ?>
                                            <?php if ( $t_episodes !== '' ) : ?>
                                                <span class="asd-music-episodes"><?php echo esc_html( $t_episodes ); ?></span>
                                            <?php endif; ?>
                                        </div>
                                        <?php if ( $t_audio_url || $t_video_url ) : ?>
                                            <div class="asd-music-player-wrap"
                                                 data-audio-src="<?php echo esc_url( $t_audio_url ); ?>"
                                                 data-video-src="<?php echo esc_url( $t_video_url ); ?>">
                                                <audio class="asd-music-audio" preload="none"></audio>
                                                <video class="asd-music-video" preload="none" playsinline style="display:none;width:0;height:0;opacity:0;pointer-events:none;"></video>
                                                <button class="asd-music-play-btn" type="button" aria-label="播放"></button>
                                                <div class="asd-music-progress-wrap"><div class="asd-music-progress-bar"></div></div>
                                                <span class="asd-music-time">0:00</span>
                                                <?php if ( $open_url ) : ?>
                                                    <a class="asd-music-open-link" href="<?php echo esc_url( $open_url ); ?>" target="_blank" rel="noopener noreferrer">看片</a>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endforeach; ?>
                    </section>
                <?php endif; ?>

                          <?php /* 串流平台 */ ?>
                <?php
                /* 先解析「其他配音版本」（台語 / 國語，支援每語言多平台）
                 * 需在最外層 if 之前算好，才能一起納入「是否有任何觀看管道」判斷。
                 */
                // [多平台] 解析格式同預告片：一行一筆，"平台名稱|網址"，逗號/分號/換行皆可分隔
                $parse_dub_urls = function ( $raw, $default_label ) {
                    $items = [];
                    if ( ! $raw ) return $items;
                    foreach ( preg_split( '/[,，、;；\r\n]+/u', (string) $raw ) as $entry ) {
                        $entry = trim( $entry );
                        if ( $entry === '' ) continue;

                        $label = '';
                        $url   = $entry;
                        if ( strpos( $entry, '|' ) !== false ) {
                            list( $label, $url ) = array_map( 'trim', explode( '|', $entry, 2 ) );
                        }
                        $url = trim( $url );
                        if ( $url === '' ) continue;

                        $items[] = [
                            'label'     => $label !== '' ? $label : $default_label,
                            'url'       => $url,
                            'has_label' => $label !== '', // 是否為使用者手動填的自訂名稱
                        ];
                    }
                    return $items;
                };

                $dub_icon_base = isset( $provider_icon_base ) ? $provider_icon_base : '';

                // [改進] 依網址向 Registry 查詢對應平台的「官方顯示名稱」與 icon
                $resolve_dub_platform = function ( $url ) use ( $has_streaming_registry, $provider_icon_map, $dub_icon_base ) {
                    if ( ! $has_streaming_registry || ! $url ) {
                        return [ 'label' => '', 'icon' => '' ];
                    }
                    $matched_key = Anime_Sync_Streaming_Registry::match_site( '', $url );
                    if ( ! $matched_key ) {
                        return [ 'label' => '', 'icon' => '' ];
                    }
                    $choices = Anime_Sync_Streaming_Registry::get_acf_choices();
                    return [
                        'label' => $choices[ $matched_key ] ?? '',
                        'icon'  => isset( $provider_icon_map[ $matched_key ] ) ? $dub_icon_base . $provider_icon_map[ $matched_key ] : '',
                    ];
                };

                // [改進] 優先用 Registry 依網址 domain 自動比對平台；比對不到才 fallback 用 label 關鍵字猜測
                $guess_dub_icon = function ( $label, $url ) use ( $dub_icon_base, $has_streaming_registry, $provider_icon_map ) {
                    if ( $has_streaming_registry && $url ) {
                        $matched_key = Anime_Sync_Streaming_Registry::match_site( $label, $url );
                        if ( $matched_key && isset( $provider_icon_map[ $matched_key ] ) ) {
                            return $dub_icon_base . $provider_icon_map[ $matched_key ];
                        }
                    }
                    $l = mb_strtolower( $label );
                    if ( strpos( $l, '巴哈' ) !== false || strpos( $l, '動畫瘋' ) !== false )   return $dub_icon_base . 'anigamer_icon.webp';
                    if ( strpos( $l, 'ofiii' ) !== false )                                       return $dub_icon_base . 'ofiii_icon.webp';
                    if ( strpos( $l, 'linetv' ) !== false || strpos( $l, 'line tv' ) !== false ) return $dub_icon_base . 'linetv_icon.webp';
                    if ( strpos( $l, '公視' ) !== false )                                        return $dub_icon_base . 'channels4.webp';
                    return '';
                };

                $dub_items_taigi    = in_array( 'taigi', $dub_arr, true )    ? $parse_dub_urls( $dub_url_taigi,    '台語配音' ) : [];
                $dub_items_mandarin = in_array( 'mandarin', $dub_arr, true ) ? $parse_dub_urls( $dub_url_mandarin, '國語配音' ) : [];
                $dub_items_all      = array_merge( $dub_items_taigi, $dub_items_mandarin );

                foreach ( $dub_items_all as &$dub_item_ref ) {
                    if ( empty( $dub_item_ref['has_label'] ) ) {
                        $resolved = $resolve_dub_platform( $dub_item_ref['url'] );
                        if ( $resolved['label'] !== '' ) {
                            $dub_item_ref['label'] = $resolved['label'];
                        }
                    }
                }
                unset( $dub_item_ref );

                /* 是否有「任何」觀看管道：台灣串流 / 海外 / 配音（用於決定整個 section 是否輸出） */
                $has_any_stream = ( ! empty( $tw_streaming_items ) || ! empty( $overseas_streams ) || ! empty( $dub_items_all ) );

                /* [方向一] 是否有「台灣本地」觀看管道：只看台灣串流 + 配音，不含海外
                 * 只要台灣沒有上架（即使有海外平台），就視為需要提供 Google 搜尋。
                 */
                $has_tw_stream = ( ! empty( $tw_streaming_items ) || ! empty( $dub_items_all ) );

                /* 台灣沒有上架管道，且後台「有手動填」Google 網址 → 才顯示搜尋按鈕 */
                $google_search_url = ( ! $has_tw_stream && $tw_no_stream_google ) ? $tw_no_stream_google : '';
                ?>

                <?php if ( $has_any_stream || $google_search_url ) : ?>
                    <section class="asd-section" id="asd-sec-stream">
                        <h2 class="asd-section-title">📺 串流平台</h2>

                        <?php /* 台灣無上架 → Google 搜尋按鈕（即使有海外平台也顯示） */ ?>
                        <?php if ( ! $has_tw_stream && $google_search_url ) : ?>
                            <div class="asd-stream-region asd-stream-region--google">
                                <div class="asd-stream-region-head">
                                    <span class="asd-stream-dot" style="background:#4285F4;"></span>
                                    <span>台灣暫無上架平台</span>
                                </div>
                                <p style="font-size:0.9em;color:var(--asd-text-muted,#888);margin:8px 0 12px;line-height:1.6;">
                                    本作在台灣目前尚無串流平台資訊，可透過 Google 搜尋是否有其他觀看管道。
                                </p>
                                <div class="asd-stream-list">
                                    <a href="<?php echo esc_url( $google_search_url ); ?>"
                                       target="_blank" rel="noopener noreferrer nofollow"
                                       class="asd-stream-btn"
                                       title="<?php echo esc_attr( $display_title ); ?> Google 搜尋">
                                        <span class="asd-stream-label">🔍 Google 搜尋哪裡看</span>
                                    </a>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if ( ! empty( $tw_streaming_items ) ) : ?>
                            <div class="asd-stream-region asd-stream-region--tw">
                                <div class="asd-stream-region-head">
                                    <span class="asd-stream-dot asd-stream-dot--tw"></span><span>台灣地區</span>
                                </div>
                                <div class="asd-stream-list">
                                    <?php foreach ( $tw_streaming_items as $si ) :
                                        $si_label     = $si['label'] ?? '';
                                        $si_url       = $si['url'] ?? '';
                                        $si_icon_url  = $si['icon_url'] ?? '';
                                        $si_icon_only = ! empty( $si['icon_only'] );
                                        if ( $si_label === '' ) continue;
                                        $btn_class = 'asd-stream-btn' . ( $si_icon_only ? ' asd-stream-btn--icon-only' : '' ) . ( $si_url ? '' : ' asd-stream-btn--no-link' );
                                    ?>
                                        <?php if ( $si_url ) : ?>
                                            <a href="<?php echo esc_url( $si_url ); ?>" target="_blank" rel="noopener noreferrer" class="<?php echo esc_attr( $btn_class ); ?>" title="<?php echo esc_attr( $si_label ); ?>">
                                                <?php if ( $si_icon_url ) : ?><img src="<?php echo esc_url( $si_icon_url ); ?>" alt="<?php echo esc_attr( $si_label ); ?>" class="asd-stream-icon"><?php endif; ?>
                                                <?php if ( ! $si_icon_only ) : ?><span class="asd-stream-label"><?php echo esc_html( $si_label ); ?></span><?php endif; ?>
                                            </a>
                                        <?php else : ?>
                                            <span class="<?php echo esc_attr( $btn_class ); ?>" title="<?php echo esc_attr( $si_label ); ?>">
                                                <?php if ( $si_icon_url ) : ?><img src="<?php echo esc_url( $si_icon_url ); ?>" alt="<?php echo esc_attr( $si_label ); ?>" class="asd-stream-icon"><?php endif; ?>
                                                <?php if ( ! $si_icon_only ) : ?><span class="asd-stream-label"><?php echo esc_html( $si_label ); ?></span><?php endif; ?>
                                            </span>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php /* 其他配音版本（台語 / 國語） */ ?>
                        <?php if ( ! empty( $dub_items_all ) ) : ?>
                        <div class="asd-stream-region asd-stream-region--dub" style="margin-top:16px;">
                            <div class="asd-stream-region-head">
                                <span class="asd-stream-dot asd-stream-dot--dub" style="background:#00A0E9;"></span>
                                <span>中文/台語/其他配音</span>
                            </div>
                            <div class="asd-stream-list">
                                <?php foreach ( $dub_items_all as $dub_item ) :
                                    $db_label = $dub_item['label'];
                                    $db_url   = $dub_item['url'];
                                    $db_icon  = $guess_dub_icon( $db_label, $db_url );
                                ?>
                                    <a href="<?php echo esc_url( $db_url ); ?>" target="_blank" rel="noopener noreferrer" class="asd-stream-btn asd-stream-btn--dub" title="<?php echo esc_attr( $db_label ); ?>">
                                        <?php if ( $db_icon ) : ?><img src="<?php echo esc_url( $db_icon ); ?>" alt="<?php echo esc_attr( $db_label ); ?>" class="asd-stream-icon"><?php endif; ?>
                                        <span class="asd-stream-label"><?php echo esc_html( $db_label ); ?></span>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endif; ?>


                        <?php /* 海外平台 */ ?>
                        <?php if ( ! empty( $overseas_streams ) ) :
                            // [14.5] 使用前面已 class_exists 防呆的 $provider_icon_map，避免 Registry 未載入時 fatal error。
                            $overseas_icon_map = is_array( $provider_icon_map ?? null ) ? $provider_icon_map : [];
                        ?>
                        <div class="asd-stream-region asd-stream-region--os" style="margin-top:16px;">
                            <div class="asd-stream-region-head">
                                <span class="asd-stream-dot asd-stream-dot--os" style="background:#888;"></span>
                                <span>海外平台</span>
                                <span style="font-size:12px;color:var(--asd-text-muted);margin-left:8px;">（台灣可能無法觀看）</span>
                            </div>
                            <div class="asd-stream-list">
                                <?php foreach ( $overseas_streams as $os ) :
                                    $os_site = trim( $os['site'] ?? '' );
                                    $os_url  = trim( $os['url']  ?? '' );
                                    if ( $os_site === '' || $os_url === '' ) continue;

                                    $os_key  = strtolower( $os_site );
                                    $os_icon = isset( $overseas_icon_map[ $os_key ] )
                                        ? $provider_icon_base . $overseas_icon_map[ $os_key ]
                                        : '';
                                ?>
                                    <a href="<?php echo esc_url( $os_url ); ?>"
                                       target="_blank" rel="noopener noreferrer"
                                       class="asd-stream-btn<?php echo $os_icon ? ' asd-stream-btn--icon-only' : ''; ?> asd-stream-btn--os"
                                       title="<?php echo esc_attr( $os_site ); ?>"
                                       data-fallback-label="<?php echo esc_attr( $os_site ); ?>">
                                        <?php if ( $os_icon ) : ?>
                                            <?php /* [14.1] 移除 onerror outerHTML 注入（XSS）。改用 CSS-only fallback：
                                                   img.onerror 時隱藏自己，由 ::after 或 JS 套用 data-fallback-label */ ?>
                                            <img src="<?php echo esc_url( $os_icon ); ?>"
                                                 alt="<?php echo esc_attr( $os_site ); ?>"
                                                 class="asd-stream-icon"
                                                 onerror="this.onerror=null;this.style.display='none';this.parentNode.classList.add('asd-stream-btn--icon-fail');">
                                            <span class="asd-stream-label asd-stream-label--fallback"><?php echo esc_html( $os_site ); ?></span>
                                        <?php else : ?>
                                            <span class="asd-stream-label"><?php echo esc_html( $os_site ); ?></span>
                                        <?php endif; ?>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endif; ?>

                        <p class="asd-stream-disclaimer" style="margin-top:16px;font-size:0.85em;color:var(--asd-text-muted,#888);line-height:1.6;">
                            ⚠️ 串流連結可能因平台授權異動而失效，建議以官方平台公告為準。
                        </p>
                    </section>
                <?php endif; ?>
                
                           <?php /* 線上看（YouTube 嵌入，分頁切換） */ ?>
                <?php if ( $has_online_watch ) : ?>
                    <section class="asd-section" id="asd-sec-online">
                        <h2 class="asd-section-title">▶ 線上看<?php echo count( $online_watch_items ) > 1 ? ' <span class="asd-pv-count">（' . esc_html( count( $online_watch_items ) ) . '）</span>' : ''; ?></h2>

                        <div class="asd-pv-box asd-ow-box" data-ow-count="<?php echo esc_attr( count( $online_watch_items ) ); ?>">

                            <?php if ( count( $online_watch_items ) > 1 ) : ?>
                                <div class="asd-pv-tabs asd-ow-tabs" role="tablist" aria-label="線上看切換">
                                    <?php foreach ( $online_watch_items as $i => $ow ) : ?>
                                        <button type="button"
                                                class="asd-pv-tab asd-ow-tab<?php echo $i === 0 ? ' is-active' : ''; ?>"
                                                role="tab"
                                                aria-selected="<?php echo $i === 0 ? 'true' : 'false'; ?>"
                                                aria-controls="asd-ow-panel-<?php echo (int) $i; ?>"
                                                data-ow-index="<?php echo (int) $i; ?>"
                                                data-ow-id="<?php echo esc_attr( $ow['id'] ); ?>">
                                            <span class="asd-pv-tab-icon">▶</span>
                                            <span class="asd-pv-tab-label"><?php echo esc_html( $ow['label'] ); ?></span>
                                        </button>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>

                            <div class="asd-pv-panels asd-ow-panels">
                                <?php foreach ( $online_watch_items as $i => $ow ) :
                                    /* [14.6] 依 type 決定嵌入來源：playlist 用 embed/videoseries?list=xxx，
                                       一般影片維持原本 embed/{video_id} */
                                    $ow_embed_src = ( ( $ow['type'] ?? 'video' ) === 'playlist' )
                                        ? 'https://www.youtube.com/embed/videoseries?list=' . rawurlencode( $ow['id'] )
                                        : 'https://www.youtube.com/embed/' . $ow['id'];
                                ?>
                                    <div class="asd-pv-panel asd-ow-panel<?php echo $i === 0 ? ' is-active' : ''; ?>"
                                         id="asd-ow-panel-<?php echo (int) $i; ?>"
                                         role="tabpanel"
                                         data-ow-index="<?php echo (int) $i; ?>"
                                         data-ow-id="<?php echo esc_attr( $ow['id'] ); ?>"
                                         data-ow-type="<?php echo esc_attr( $ow['type'] ?? 'video' ); ?>">
                                        <div class="asd-trailer-wrap">
                                            <iframe src="<?php echo esc_url( $ow_embed_src ); ?>"
                                                    title="<?php echo esc_attr( $display_title . ' ' . $ow['label'] ); ?>"
                                                    frameborder="0"
                                                    allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
                                                    allowfullscreen loading="lazy"></iframe>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>

                        </div>

                        <p class="asd-stream-disclaimer" style="margin-top:8px;font-size:0.85em;color:var(--asd-text-muted,#888);line-height:1.6;">
                            ⚠️ 影片由 YouTube 提供,若無法播放,可能需加入頻道會員,或影片已被版權方下架、限制嵌入。
                        </p>
                    </section>
                <?php endif; ?>


                <?php /* FAQ */ ?>
                <?php if ( ! empty( $faq_items ) ) : ?>
                    <section class="asd-section" id="asd-sec-faq">
                        <h2 class="asd-section-title">❓ 常見問題</h2>
                        <div class="asd-faq-list">
                            <?php foreach ( $faq_items as $f ) :
                                if ( empty( $f['q'] ) || empty( $f['a'] ) ) continue;
                            ?>
                                <div class="asd-faq-item">
                                    <div class="asd-faq-q">
                                        <span class="asd-faq-q-label">Q.</span>
                                        <span class="asd-faq-q-text"><?php echo esc_html( $f['q'] ); ?></span>
                                    </div>
                                    <div class="asd-faq-a">
                                        <span class="asd-faq-a-label">A.</span>
                                        <div class="asd-faq-a-text"><?php echo wp_kses_post( wpautop( $f['a'] ) ); ?></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </section>
                <?php endif; ?>

                <?php /* 外部連結 */ ?>
                <?php
                // 清洗 ID：只保留數字，避免值本身夾帶斜線或完整網址造成 URL 多斜線
                $clean_anilist = $anilist_id ? preg_replace( '/\D/', '', (string) $anilist_id ) : '';
                $clean_mal     = $mal_id     ? preg_replace( '/\D/', '', (string) $mal_id )     : '';
                $clean_bangumi = $bangumi_id ? preg_replace( '/\D/', '', (string) $bangumi_id ) : '';
                ?>
                <?php if ( $official_site || $twitter_url || $wikipedia_url || $tiktok_url || $clean_anilist || $clean_mal || $clean_bangumi ) : ?>
                    <section class="asd-section" id="asd-sec-links">
                        <h2 class="asd-section-title">🔗 外部連結</h2>
                        <div class="asd-ext-links-grid">
                            <?php if ( $official_site ) : ?><a href="<?php echo esc_url( $official_site ); ?>" target="_blank" rel="noopener noreferrer" class="asd-ext-link-card"><span class="asd-ext-site">🌐 官方網站</span><span class="asd-ext-arrow">→</span></a><?php endif; ?>
                            <?php if ( $twitter_url ) : ?><a href="<?php echo esc_url( $twitter_url ); ?>" target="_blank" rel="noopener noreferrer" class="asd-ext-link-card"><span class="asd-ext-site">𝕏 Twitter / X</span><span class="asd-ext-arrow">→</span></a><?php endif; ?>
                            <?php if ( $wikipedia_url ) : ?><a href="<?php echo esc_url( $wikipedia_url ); ?>" target="_blank" rel="noopener noreferrer" class="asd-ext-link-card"><span class="asd-ext-site">📖 Wikipedia</span><span class="asd-ext-arrow">→</span></a><?php endif; ?>
                            <?php if ( $tiktok_url ) : ?><a href="<?php echo esc_url( $tiktok_url ); ?>" target="_blank" rel="noopener noreferrer" class="asd-ext-link-card"><span class="asd-ext-site">🎵 TikTok</span><span class="asd-ext-arrow">→</span></a><?php endif; ?>
                            <?php if ( $clean_anilist ) : ?><a href="<?php echo esc_url( 'https://anilist.co/anime/' . $clean_anilist ); ?>" target="_blank" rel="noopener noreferrer" class="asd-ext-link-card asd-ext--al"><span class="asd-ext-site">🔵 AniList</span><span class="asd-ext-arrow">→</span></a><?php endif; ?>
                            <?php if ( $clean_mal ) : ?><a href="<?php echo esc_url( 'https://myanimelist.net/anime/' . $clean_mal ); ?>" target="_blank" rel="noopener noreferrer" class="asd-ext-link-card asd-ext--mal"><span class="asd-ext-site">🔵 MyAnimeList</span><span class="asd-ext-arrow">→</span></a><?php endif; ?>
                            <?php if ( $clean_bangumi ) : ?><a href="<?php echo esc_url( 'https://bgm.tv/subject/' . $clean_bangumi ); ?>" target="_blank" rel="noopener noreferrer" class="asd-ext-link-card asd-ext--bgm"><span class="asd-ext-site">🍡 Bangumi</span><span class="asd-ext-arrow">→</span></a><?php endif; ?>
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

                <div class="asd-side-section">
                    <div class="asd-side-section__head"><h3>🏷️ 作品標籤</h3></div>
                    <div class="asd-tags-wrap">
                        <?php if ( ! empty( $studio ) ) :
                            $studio_term = get_terms( [ 'taxonomy' => 'anime_studio_tax', 'name' => $studio, 'hide_empty' => false, 'number' => 1 ] );
                            $studio_url  = ( ! is_wp_error( $studio_term ) && ! empty( $studio_term ) ) ? get_term_link( $studio_term[0] ) : home_url( '/anime/' );
                        ?>
                            <a href="<?php echo esc_url( $studio_url ); ?>" class="asd-tag-item asd-tag-item--studio">🎬 <?php echo esc_html( $studio ); ?></a>
                        <?php endif; ?>
                        <?php foreach ( $season_child_terms as $st ) : ?>
                            <a href="<?php echo esc_url( get_term_link( $st ) ); ?>" class="asd-tag-item asd-tag-item--season"><?php echo esc_html( $st->name ); ?></a>
                        <?php endforeach; ?>
                        <?php foreach ( $genre_terms as $gt ) : ?>
                            <a href="<?php echo esc_url( get_term_link( $gt ) ); ?>" class="asd-tag-item"><?php echo esc_html( $gt->name ); ?></a>
                        <?php endforeach; ?>
                        <?php if ( empty( $studio ) && empty( $season_child_terms ) && empty( $genre_terms ) ) : ?>
                            <p class="asd-side-empty">暫無標籤資料</p>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="asd-side-section">
                    <div class="asd-side-section__head"><h3>📰 相關新聞</h3></div>
                    <div class="asd-side-news">
                        <?php if ( ! empty( $news_items ) ) : ?>
                            <?php foreach ( $news_items as $ni ) : ?>
                                <?php if ( ! empty( $ni['url'] ) ) : ?>
                                    <a href="<?php echo esc_url( $ni['url'] ); ?>" target="_blank" rel="noopener noreferrer" class="asd-news-card">
                                        <span class="asd-news-card__title"><?php echo esc_html( $ni['title'] ); ?></span>
                                        <span class="asd-news-arrow">→</span>
                                    </a>
                                <?php else : ?>
                                    <div class="asd-news-card"><span class="asd-news-card__title"><?php echo esc_html( $ni['title'] ); ?></span></div>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        <?php else : ?>
                            <p class="asd-side-empty">暫無相關新聞</p>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="asd-side-section">
                    <div class="asd-side-section__head"><h3>🔗 相關作品</h3></div>
                    <div class="asd-side-cards">
                        <?php if ( ! empty( $site_relations ) ) : ?>
                            <?php foreach ( $site_relations as $rel ) : ?>
                                <a href="<?php echo esc_url( $rel['url'] ); ?>" class="asd-mini-card">
                                    <div class="asd-mini-card__thumb">
                                        <?php if ( ! empty( $rel['cover_image'] ) ) : ?>
                                            <img src="<?php echo esc_url( $rel['cover_image'] ); ?>" alt="<?php echo esc_attr( $rel['title_zh'] ); ?>" loading="lazy">
                                        <?php else : ?>
                                            <div class="asd-mini-card__thumb-fb"><span><?php echo esc_html( mb_substr( $rel['title_zh'], 0, 2 ) ); ?></span></div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="asd-mini-card__body">
                                        <span class="asd-mini-card__title"><?php echo esc_html( $rel['title_zh'] ); ?></span>
                                        <span class="asd-mini-card__meta"><?php echo esc_html( $rel['relation_label'] ); ?><?php echo $rel['format'] ? ' · ' . esc_html( $rel['format'] ) : ''; ?></span>
                                    </div>
                                </a>
                            <?php endforeach; ?>
                        <?php else : ?>
                            <p class="asd-side-empty">暫無相關作品</p>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ( $affiliate_html ) : ?>
                    <div class="asd-side-section">
                        <div class="asd-side-section__head"><h3>🛒 購買連結</h3></div>
                        <div class="asd-affiliate-box"><?php echo wp_kses_post( $affiliate_html ); ?></div>
                    </div>
                <?php endif; ?>

                <div class="asd-side-section asd-sponsor-block">
                    <div class="asd-sponsor-title">支持微笑動漫</div>
                    <div class="asd-sponsor-desc">喜歡這部作品的資訊嗎？微笑動漫每天整合來自全球三大資料庫的動漫情報，你的咖啡讓我們繼續走下去 ☕</div>
                    <a href="/sponsor/" target="_blank" rel="noopener noreferrer" class="asd-sponsor-btn">贊助微笑動漫</a>
                    <div class="asd-sponsor-note">贊助費用於伺服器維護，感謝每一位支持者</div>
                </div>

                <div class="asd-ad-placeholder" aria-label="廣告版位" role="complementary">
                    <div class="asd-ad-inner"></div>
                </div>

            </aside><!-- /.asd-sidebar -->

        </div><!-- /.asd-container -->

    </div><!-- /.asd-tabs-wrap -->

</div><!-- /.asd-wrap -->

<?php endwhile; get_footer(); ?>