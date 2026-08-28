<?php
/**
 * 樣式 / 腳本載入：jQuery / FA / CSS / JS / 條件式載入 / 自訂模板 CSS
 *
 * @package weixiaoacg
 * @subpackage Enqueue
 * @version 2.15.0 (2026-07-24)
 *
 * v2.15.0 變更 — 熱門/最近搜尋（2026-07-24）：
 *   - 全站載入 search-hot.js + search-hot.css（隨 Header 一起，依賴 smacg-header）。
 *   - 搜尋框聚焦顯示「最近搜尋(localStorage)＋熱門搜尋(REST)」。
 *   - 後端 REST 由 /inc/search-hot.php 提供（weixiaoacg/v1/search-hot/*）。
 *
 * v2.14.0 變更 — 漫畫追蹤列支援（2026-07-19）：
 *   - 動畫追蹤列（track-bar / anime-status）的 CSS 與 JS，原本只在
 *     is_singular('anime') 載入。改為 is_singular(['anime','manga'])，
 *     讓漫畫單篇也能使用「想看 / 追漫中 / 已看完 / 棄坑 / 收藏 / 進度」追蹤列。
 *   - SmacgConfig.postId 用 get_the_ID()，自動帶漫畫 ID，無需額外處理。
 *   - anime-status.js 的 postId / totalEp 皆讀自 .smacg-track-bar 的 data-*，
 *     與 post type 無關，故 JS 不需修改。
 *   - 後端 Anime_Sync_User_Status_Manager 已同步放寬 post type 檢查。
 *
 * v2.13.0 變更 — 路徑比對精準化（2026-06-01）：
 *   - $is_account：原以 strpos(REQUEST_URI, '/account') 比對，任何含
 *     "/account" 字串的網址都會誤命中（如文章 slug）。改用 parse_url 取
 *     path 後精確比對 '/account' 或以 '/account/' 開頭。
 *   - wpForo $is_forum：同樣改用 path 比對，且改為「以 /community 或
 *     /forum 開頭」，避免誤命中。
 *
 * v2.12.0 變更 — 登入失敗修復（2026-06-01）：
 *   - 修正 smacg-header（header.js）的依賴：由 [] 改為 [ 'weixiaoacg-nav' ]。
 *     header.js 需讀取 weixiaoacg_ajax（ajax_url / nonce），而該變數
 *     localize 在 weixiaoacg-nav 上。原本未宣告依賴，導致載入順序不保證，
 *     header.js 取到空物件 → ajax_url / nonce 為 undefined → 登入/註冊/查重
 *     全部失敗或送錯網址。宣告依賴後即可確保 nav.js 先輸出。
 *
 * v2.11.0 變更 — 首頁整體樣式抽離（2026-05-24）：
 *   - 新增 home.css 的條件式載入（僅 is_front_page() 為 true 時）
 *   - 對應 front-page.php 移除內嵌 <style>，集中至 home.css
 *   - 涵蓋 hero / news-grid 卡片 / 熱門作品 / 會員 CTA + 手機 RWD 修正
 *   - 與 home-news-tabs.css 並存：home-news-tabs.css 專責 4-tab 區塊，
 *     home.css 專責其他首頁區塊與全局 RWD。
 *
 * v2.10.0 變更 — 首頁最新新聞 Tab 區塊（2026-05-23）：
 *   - 新增 home-news-tabs.css 的條件式載入（僅 is_front_page() 為 true 時）
 *   - 對應 front-page.php v2.4.0 的 4-tab 區塊（全部 / 新聞 / 評論 / 專題）
 *
 * v2.9.2 變更 — Public Profile 跑版修復(2026-05-17)：
 *   - smacg_is_public_profile_page() 單一條件改為三重防護。
 *   - 補載 member.css 作為 public-profile.css 的 --mc-* CSS 變數來源。
 *
 * v2.9.1 — Pilgrimage 頁面條件式 CSS
 * v2.9.0 — AI Tools 條件式 CSS/JS
 * v2.8.0 — News Filter AJAX
 * v2.2.0 — 頭像上傳 Cropper.js + LiteSpeed 排除
 * v2.1.0 — Year Review 條件式載入
 * v2.3.0 — 公開個人頁
 * v2.4.0 — 追蹤系統
 * v2.5.0 — 通知中心
 * v2.6.0/2.6.1 — Gamification / 職業 / 等級徽章
 * v2.7.0/2.7.1/2.7.2 — 排行榜 / Widget / Season Event
 */

defined( 'ABSPATH' ) || exit;

/* ============================================================
   jQuery 3.6.4
   ============================================================ */
add_action( 'wp_enqueue_scripts', function () {
    wp_deregister_script( 'jquery' );
    wp_deregister_script( 'jquery-core' );
    wp_deregister_script( 'jquery-migrate' );
    wp_register_script( 'jquery-core', '/wp-includes/js/jquery/jquery.min.js', [], '3.7.1', true );
    wp_register_script( 'jquery', false, [ 'jquery-core' ], null, false );
    wp_enqueue_script( 'jquery' );
}, 1 );

/* ============================================================
   Font Awesome（本機自載，避免被廣告攔截器誤擋）
   ============================================================ */
add_action( 'wp_enqueue_scripts', function () {
    if ( wp_style_is( 'weixiaoacg-fa6', 'registered' ) ) {
        return;
    }
    $fa_rel = '/assets/fontawesome/css/all.min.css';
    $fa_abs = weixiaoacg_THEME_DIR . $fa_rel;
    if ( ! file_exists( $fa_abs ) ) {
        // 檔案不存在時退回 CDN，避免圖示整個消失
        wp_enqueue_style( 'weixiaoacg-fa6', 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css', [], '6.5.0' );
        return;
    }
    wp_enqueue_style( 'weixiaoacg-fa6', weixiaoacg_THEME_URL . $fa_rel, [], filemtime( $fa_abs ) );
}, 5 );

/* ============================================================
   主要樣式載入
   ============================================================ */
add_action( 'wp_enqueue_scripts', function () {
    // ★ 2026-08-17：主題已改成獨立主題，不再是 Blocksy 子主題，不用再載入
    // Blocksy 自己的 style.css（原本 blocksy-parent 這個 handle）。
    wp_enqueue_style( 'weixiaoacg-child-style', get_stylesheet_uri(), [ 'weixiaoacg-fa6' ], weixiaoacg_VERSION );
    // wp_enqueue_style( 'weixiaoacg-fonts', 'https://fonts.googleapis.com/css2?family=Noto+Sans+TC:wght@300;400;500;700&family=Inter:wght@300;400;500;600;700;800&display=swap', [], null );

    foreach ( [
        'weixiaoacg-glass' => [ 'glass.css', [ 'weixiaoacg-child-style' ] ],
        'weixiaoacg-style' => [ 'style.css', [ 'weixiaoacg-glass' ] ],
    ] as $h => [ $f, $dep ] ) {
        $p = weixiaoacg_THEME_DIR . '/assets/css/' . $f;
        if ( file_exists( $p ) ) {
            wp_enqueue_style( $h, weixiaoacg_THEME_URL . '/assets/css/' . $f, $dep, filemtime( $p ) );
        }
    }

    $is_um_user = function_exists( 'um_is_core_page' ) && ( um_is_core_page( 'user' ) || get_query_var( 'um_user' ) );

    $cond = [];
    if ( is_front_page() ) {
        $cond['smacg-home-news-tabs'] = 'home-news-tabs.css'; // v2.10.0
        $cond['weixiaoacg-home']      = 'home.css';           // v2.11.0
    }
    if ( is_page( 'news' )   || is_page_template( 'page-news.php' ) )   $cond['weixiaoacg-news']       = 'news.css';
    if ( is_page( 'season' ) || is_page_template( 'page-season.php' ) ) $cond['weixiaoacg-season']     = 'season.css';
    if ( is_page_template( 'page-ranking.php' ) )                       $cond['weixiaoacg-ranking']    = 'ranking.css';
    if ( is_page_template( 'page-anime-list.php' ) )                    $cond['weixiaoacg-anime-list'] = 'anime-list.css';
    if ( is_page_template( 'page-music.php' ) )                         $cond['weixiaoacg-music']      = 'music.css';
    if ( is_page_template( 'page-pilgrimage.php' ) )                    $cond['weixiaoacg-pilgrimage'] = 'pilgrimage.css';
    if ( is_page_template( 'page-cosplay.php' ) )                       $cond['weixiaoacg-cosplay']    = 'cosplay.css';
    if ( is_search() )                                                  $cond['weixiaoacg-search']     = 'search.css';
    if ( is_404() )                                                     $cond['weixiaoacg-404']        = '404.css';
    if ( is_singular( 'achievements' ) )                                $cond['weixiaoacg-achievements'] = 'achievements.css';
    if ( is_page( [ 'about', 'contact', 'disclaimer', 'sources', 'privacy', 'terms', 'join' ] ) || is_page_template( 'page-join.php' ) ) {
        $cond['weixiaoacg-static'] = 'static.css';
    }
    if ( is_page_template( 'page-member.php' ) || $is_um_user ) {
        $cond['weixiaoacg-member'] = 'member.css';
    }

    // ★v2.13.0 修正：$is_account 用 parse_url 取 path 後精確比對，避免任何含 "/account" 字串的網址誤命中
    $req_path   = isset( $_SERVER['REQUEST_URI'] ) ? wp_parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH ) : '';
    $is_account = is_page( 1527 ) || is_page( 'account' )
        || ( function_exists( 'um_is_core_page' ) && um_is_core_page( 'account' ) )
        || ( $req_path && ( $req_path === '/account' || strpos( $req_path, '/account/' ) === 0 ) );
    if ( $is_account ) {
        $cond['weixiaoacg-account'] = 'account.css';
    }

    foreach ( $cond as $h => $f ) {
        $p = weixiaoacg_THEME_DIR . '/assets/css/' . $f;
        if ( file_exists( $p ) ) {
            wp_enqueue_style( $h, weixiaoacg_THEME_URL . '/assets/css/' . $f, [ 'weixiaoacg-style' ], filemtime( $p ) );
        }
    }

    // ★v2.14.0：追蹤列 CSS 放寬至 anime + manga
    if ( is_singular( [ 'anime', 'manga', 'novel' ] ) ) {
        if ( ! wp_style_is( 'weixiaoacg-anime', 'registered' ) ) {
            $anime_css = WP_PLUGIN_DIR . '/anime-sync-pro/public/assets/css/anime-single.css';
            if ( file_exists( $anime_css ) ) {
                wp_enqueue_style( 'weixiaoacg-anime', plugins_url( 'anime-sync-pro/public/assets/css/anime-single.css' ), [ 'weixiaoacg-style', 'weixiaoacg-fa6' ], filemtime( $anime_css ) );
            }
        }
        $tb = weixiaoacg_THEME_DIR . '/assets/css/track-bar.css';
        if ( file_exists( $tb ) ) {
            wp_enqueue_style( 'smacg-track-bar', weixiaoacg_THEME_URL . '/assets/css/track-bar.css', [ 'weixiaoacg-anime' ], filemtime( $tb ) );
        }
        $as = weixiaoacg_THEME_DIR . '/assets/css/anime-status.css';
        if ( file_exists( $as ) ) {
            wp_enqueue_style( 'smacg-anime-status', weixiaoacg_THEME_URL . '/assets/css/anime-status.css', [ 'smacg-track-bar' ], filemtime( $as ) );
        }

        // 評論（短評／長評）：v1 先只做動漫，漫畫還沒有片單/評分基礎設施可掛門檻
        if ( is_singular( 'anime' ) ) {
            $rv_css = weixiaoacg_THEME_DIR . '/assets/css/review.css';
            if ( file_exists( $rv_css ) ) {
                wp_enqueue_style( 'wxacg-review', weixiaoacg_THEME_URL . '/assets/css/review.css', [ 'smacg-anime-status' ], filemtime( $rv_css ) );
            }
        }
    }

    /*
     * 新聞（post）也用同一套評論系統。
     * 這裡不能沿用上面那段：那段的 review.css 相依 smacg-anime-status，
     * 而該樣式只在 anime/manga 載入，掛到新聞頁會因相依缺失而不輸出。
     */
    /*
     * 新聞、漫畫、輕小說、遊戲、真人版、角色頁、人物頁。
     * 後兩者是 rewrite 攔截的虛擬頁，用 query var 判斷。
     *
     * 這份清單必須與 Anime_Sync_Review_Manager::allowed_post_types() 同步：
     * 後端允許發表、前端卻沒載入 review.css，表單會變成沒有樣式的裸元素
     * （2026-08-28 小說頁實際發生過）。
     */
    if ( is_singular( [ 'post', 'manga', 'novel', 'game', 'liveaction' ] )
        || (int) get_query_var( 'asa_character_id' ) > 0
        || (int) get_query_var( 'asa_person_id' ) > 0 ) {
        $rv_css = weixiaoacg_THEME_DIR . '/assets/css/review.css';
        if ( file_exists( $rv_css ) ) {
            wp_enqueue_style( 'wxacg-review', weixiaoacg_THEME_URL . '/assets/css/review.css', [], filemtime( $rv_css ) );
        }
    }

    $p = weixiaoacg_THEME_DIR . '/assets/css/admin-sync.css';
    if ( file_exists( $p ) && filesize( $p ) > 0 ) {
        wp_enqueue_style( 'weixiaoacg-admin-sync', weixiaoacg_THEME_URL . '/assets/css/admin-sync.css', [ 'weixiaoacg-style' ], filemtime( $p ) );
    }
}, 10 );

add_action( 'admin_enqueue_scripts', function () {
    $p = weixiaoacg_THEME_DIR . '/assets/css/admin-sync.css';
    if ( file_exists( $p ) && filesize( $p ) > 0 ) {
        wp_enqueue_style( 'weixiaoacg-admin', weixiaoacg_THEME_URL . '/assets/css/admin-sync.css', [], filemtime( $p ) );
    }
} );

/* LiteSpeed 排除 Font Awesome */
add_filter( 'litespeed_optm_css_minify', fn( $c ) => $c, 10 );
add_filter( 'litespeed_optimize_css_excludes', function ( $excludes ) {
    $excludes[] = 'cdnjs.cloudflare.com/ajax/libs/font-awesome';
    $excludes[] = 'fontawesome';
    return $excludes;
} );

/* ============================================================
   JS 載入（核心 + localize weixiaoacg_ajax）
   ============================================================ */
add_action( 'wp_enqueue_scripts', function () {
    foreach ( [
        'weixiaoacg-api'           => [ 'api.js',           [] ],
        'weixiaoacg-utils'         => [ 'utils.js',         [ 'weixiaoacg-api' ] ],
        'weixiaoacg-page-template' => [ 'page-template.js', [ 'weixiaoacg-utils' ] ],
        'weixiaoacg-nav'           => [ 'nav.js',           [ 'weixiaoacg-utils' ] ],
    ] as $h => [ $f, $dep ] ) {
        $p = weixiaoacg_THEME_DIR . '/assets/js/' . $f;
        if ( file_exists( $p ) ) {
            wp_enqueue_script( $h, weixiaoacg_THEME_URL . '/assets/js/' . $f, $dep, filemtime( $p ), true );
        }
    }

    if ( is_front_page() ) {
        $p = weixiaoacg_THEME_DIR . '/assets/js/main.js';
        if ( file_exists( $p ) ) {
            wp_enqueue_script( 'weixiaoacg-main', weixiaoacg_THEME_URL . '/assets/js/main.js', [ 'weixiaoacg-api' ], filemtime( $p ), true );
        }
    }

    // ★v2.14.0：追蹤列 JS + SmacgConfig 放寬至 anime + manga
    // 注意：anime-rating.js（評分）仍只在 anime 載入，漫畫暫不啟用評分
    if ( is_singular( [ 'anime', 'manga', 'novel' ] ) ) {

        /*
         * 評分腳本只給動畫；漫畫只載追蹤相關
         *
         * comment-rating.js 已於 2026-08-22 停止載入：
         * 它的用途是「在留言旁顯示作者評分」，靠 SmacgConfig.commentRatings
         * 對照 wpDiscuz 留言的 .wpd-comment 元素。留言區 2026-08-18 改為自建
         * 系統後那些元素不再存在，且自建系統已由後端直接回傳分數
         * （class-review-manager.php 的 'score' 欄位，review.js 渲染成
         *   .asd-review-score-tag），功能完整取代。
         * 檔案保留未刪，與模板註解一致：日後若切回 wpDiscuz 可直接恢復本行。
         */
        $status_js = [
            'weixiaoacg-anime-js'  => 'anime.js',
            'smacg-anime-status'   => 'anime-status.js',
        ];
        if ( is_singular( 'anime' ) ) {
            $status_js['smacg-anime-rating'] = 'anime-rating.js';
        }

        foreach ( $status_js as $h => $f ) {
            $p = weixiaoacg_THEME_DIR . '/assets/js/' . $f;
            if ( file_exists( $p ) ) {
                wp_enqueue_script( $h, weixiaoacg_THEME_URL . '/assets/js/' . $f, [ 'weixiaoacg-api' ], filemtime( $p ), true );
            }
        }

        /*
         * 評論：動漫與漫畫都掛。依賴 smacg-anime-status 確保
         * window.SmacgConfig 已經 localize 完成才會執行（該腳本在
         * anime 與 manga 都會載入，所以這裡不必自己補一份設定）。
         */
        $rv_js = weixiaoacg_THEME_DIR . '/assets/js/review.js';
        if ( file_exists( $rv_js ) ) {
            wp_enqueue_script( 'wxacg-review', weixiaoacg_THEME_URL . '/assets/js/review.js', [ 'smacg-anime-status' ], filemtime( $rv_js ), true );
        }

        /*
         * 原本這裡會查詢「這部作品所有評分者：user_nicename → 分數」，
         * localize 成 SmacgConfig.commentRatings 給 comment-rating.js 用。
         * 該腳本已停止載入（見上方說明），而 commentRatings 沒有其他使用者，
         * 這段 JOIN users 表的查詢等於每次瀏覽作品頁都白跑一次，一併移除。
         */
        wp_localize_script( 'smacg-anime-status', 'SmacgConfig', [
            'apiUrl'    => esc_url_raw( rest_url( 'weixiaoacg/v1/' ) ),
            'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
            'nonce'     => wp_create_nonce( 'wp_rest' ),
            'ajaxNonce' => wp_create_nonce( 'smacg_nonce' ),
            'loggedIn'  => is_user_logged_in(),
            'loginUrl'  => function_exists( 'um_get_core_page' ) ? um_get_core_page( 'login' ) : wp_login_url( get_permalink() ),
            'postId'    => get_the_ID(),
            'permalink' => get_permalink(),
            'title'     => get_the_title(),
            'userName'       => is_user_logged_in() ? wp_get_current_user()->display_name : '',
        ] );
    }

    /*
     * 新聞（post）的評論：與動漫共用 review.js。
     *
     * 動漫頁的 SmacgConfig 是 localize 在 smacg-anime-status 上，而該腳本
     * 只在 anime/manga 載入，所以新聞頁必須自己補一份，否則 review.js 取不到
     * apiUrl / nonce 會靜默失效。
     *
     * （原本這裡註明「不帶 commentRatings」，該欄位已於 2026-08-22 隨
     *   comment-rating.js 一併移除，兩邊的設定內容現在沒有這項差異。）
     */
    // 新聞、角色頁、人物頁：這幾種都沒有載入 smacg-anime-status，必須自己補設定
    if ( is_singular( 'post' )
        || (int) get_query_var( 'asa_character_id' ) > 0
        || (int) get_query_var( 'asa_person_id' ) > 0 ) {
        $rv_js = weixiaoacg_THEME_DIR . '/assets/js/review.js';
        if ( file_exists( $rv_js ) ) {
            wp_enqueue_script( 'wxacg-review', weixiaoacg_THEME_URL . '/assets/js/review.js', [ 'weixiaoacg-api' ], filemtime( $rv_js ), true );

            wp_localize_script( 'wxacg-review', 'SmacgConfig', [
                'apiUrl'    => esc_url_raw( rest_url( 'weixiaoacg/v1/' ) ),
                'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
                'nonce'     => wp_create_nonce( 'wp_rest' ),
                'ajaxNonce' => wp_create_nonce( 'smacg_nonce' ),
                'loggedIn'  => is_user_logged_in(),
                'loginUrl'  => function_exists( 'um_get_core_page' ) ? um_get_core_page( 'login' ) : wp_login_url( get_permalink() ),
                'postId'    => get_the_ID(),
                'permalink' => get_permalink(),
                'title'     => get_the_title(),
                'userName'  => is_user_logged_in() ? wp_get_current_user()->display_name : '',
            ] );
        }
    }

    if ( is_page_template( 'page-ranking.php' ) ) {
        $p = weixiaoacg_THEME_DIR . '/assets/js/ranking.js';
        if ( file_exists( $p ) ) {
            wp_enqueue_script( 'weixiaoacg-ranking', weixiaoacg_THEME_URL . '/assets/js/ranking.js', [ 'weixiaoacg-utils', 'weixiaoacg-api' ], filemtime( $p ), true );
        }
    }

    // 全站共用：weixiaoacg_ajax（ajax_url / nonce / 登入態等）
    // ⚠ header.js 依賴此變數，因此 smacg-header 必須以 weixiaoacg-nav 為依賴（見下方 Header 區塊）
    wp_localize_script( 'weixiaoacg-nav', 'weixiaoacg_ajax', [
        'ajax_url'     => admin_url( 'admin-ajax.php' ),
        'nonce'        => wp_create_nonce( 'weixiaoacg_nonce' ),
        'user_id'      => get_current_user_id(),
        'is_logged_in' => is_user_logged_in(),
        'user_level'   => weixiaoacg_get_user_level_int(),
        'site_url'     => home_url(),
        'login_url'    => wp_login_url( get_permalink() ),
        'register_url' => wp_registration_url(),
        'rest_url'     => rest_url( 'weixiaoacg/v1/' ),
        'rest_nonce'   => wp_create_nonce( 'wp_rest' ),
    ] );
}, 10 );

/* ============================================================
   自訂模板專用 CSS（後台外的條件式 CSS）
   ============================================================ */
add_action( 'wp_enqueue_scripts', function () {
    $base_url = get_stylesheet_directory_uri() . '/assets/css/';
    $base_dir = get_stylesheet_directory() . '/assets/css/';
    $ver      = fn( $f ) => file_exists( $base_dir . $f ) ? filemtime( $base_dir . $f ) : '1.0';

    if ( is_category() || is_tax( 'channel' ) ) {
        wp_enqueue_style( 'smacg-news', $base_url . 'news.css', [], $ver( 'news.css' ) );
    }

    if ( is_singular( 'post' ) ) {
        wp_enqueue_style( 'smacg-news', $base_url . 'news.css', [], $ver( 'news.css' ) );
        wp_enqueue_style( 'smacg-single', $base_url . 'single.css', [ 'smacg-news' ], $ver( 'single.css' ) );
    }

    if ( is_page_template( 'page-columns.php' ) ) {
        wp_enqueue_style( 'smacg-news', $base_url . 'news.css', [], $ver( 'news.css' ) );
        wp_enqueue_style( 'smacg-columns', $base_url . 'columns.css', [ 'smacg-news' ], $ver( 'columns.css' ) );
    }
}, 20 );

/* ============================================================
   News Filter AJAX（v2.8.0 - 2026-05-16）
   ============================================================ */
add_action( 'wp_enqueue_scripts', function () {
    $is_target = is_category( [ 'news', 'review', 'feature', 'announcement' ] ) || is_tax( 'channel' );
    if ( ! $is_target ) {
        return;
    }

    $js_path = weixiaoacg_THEME_DIR . '/assets/js/news-filter.js';
    if ( ! file_exists( $js_path ) ) {
        return;
    }

    wp_enqueue_script( 'smacg-news-filter', weixiaoacg_THEME_URL . '/assets/js/news-filter.js', [], filemtime( $js_path ), true );
    wp_localize_script( 'smacg-news-filter', 'smacgNewsFilter', [
        'ajaxUrl' => admin_url( 'admin-ajax.php' ),
        'nonce'   => wp_create_nonce( 'smacg_news_filter' ),
    ] );
}, 21 );

/* ============================================================
   Year Review 頁面（Batch C #14 - 2026-05-13）
   ============================================================ */
add_action( 'wp_enqueue_scripts', function () {
    if ( ! is_page_template( 'page-year-review.php' ) ) {
        return;
    }

    $css_path = weixiaoacg_THEME_DIR . '/assets/css/year-review.css';
    if ( file_exists( $css_path ) ) {
        wp_enqueue_style( 'smacg-year-review', weixiaoacg_THEME_URL . '/assets/css/year-review.css', [ 'weixiaoacg-fa6' ], filemtime( $css_path ) );
    }

    $js_path = weixiaoacg_THEME_DIR . '/assets/js/year-review.js';
    if ( file_exists( $js_path ) ) {
        wp_enqueue_script( 'smacg-year-review', weixiaoacg_THEME_URL . '/assets/js/year-review.js', [], filemtime( $js_path ), true );

        $year = isset( $_GET['year'] ) ? max( 2020, min( (int) date( 'Y' ), (int) $_GET['year'] ) ) : (int) date( 'Y' );
        wp_localize_script( 'smacg-year-review', 'smacgYearReview', [
            'ajax'    => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'smacg_year_review' ),
            'userId'  => get_current_user_id(),
            'year'    => $year,
            'siteUrl' => home_url(),
        ] );
    }
}, 15 );

/* ============================================================
   Cropper.js（v2.2.0 - 2026-05-13）
   ============================================================ */
add_action( 'wp_enqueue_scripts', function () {
    if ( ! function_exists( 'smacg_is_member_page' ) || ! smacg_is_member_page() ) {
        return;
    }

    wp_enqueue_style( 'cropperjs', 'https://cdn.jsdelivr.net/npm/cropperjs@1.6.1/dist/cropper.min.css', [], '1.6.1' );
    wp_enqueue_script( 'cropperjs', 'https://cdn.jsdelivr.net/npm/cropperjs@1.6.1/dist/cropper.min.js', [], '1.6.1', true );
}, 25 );

add_filter( 'litespeed_optimize_js_excludes', function ( $excludes ) {
    $excludes[] = 'cdn.jsdelivr.net/npm/cropperjs';
    $excludes[] = 'cropper.min.js';
    return $excludes;
} );
add_filter( 'litespeed_optimize_css_excludes', function ( $excludes ) {
    $excludes[] = 'cdn.jsdelivr.net/npm/cropperjs';
    $excludes[] = 'cropper.min.css';
    return $excludes;
} );

/* ============================================================
   Public Profile 公開個人頁（v2.9.2 - 2026-05-17）
   ============================================================ */
add_action( 'wp_enqueue_scripts', function () {
    $is_pp = false;

    // 1) 標準判斷：外掛 helper 函式
    if ( function_exists( 'smacg_is_public_profile_page' ) && smacg_is_public_profile_page() ) {
        $is_pp = true;
    }
    // 2) Query var 判斷
    if ( ! $is_pp && get_query_var( 'smacg_pp_user' ) ) {
        $is_pp = true;
    }
    // 3) URL pattern 判斷（最後保險）
    if ( ! $is_pp && isset( $_SERVER['REQUEST_URI'] ) ) {
        $uri = wp_parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );
        if ( $uri && preg_match( '#^/u/[^/]+/?#', $uri ) ) {
            $is_pp = true;
        }
    }

    if ( ! $is_pp ) {
        return;
    }

    $base_dir = weixiaoacg_THEME_DIR;
    $base_url = weixiaoacg_THEME_URL;

    // 先載 member.css（提供 --mc-* CSS 變數）
    if ( ! wp_style_is( 'weixiaoacg-member', 'enqueued' ) ) {
        $member_css = $base_dir . '/assets/css/member.css';
        if ( file_exists( $member_css ) ) {
            wp_enqueue_style( 'weixiaoacg-member', $base_url . '/assets/css/member.css', [ 'weixiaoacg-fa6' ], filemtime( $member_css ) );
        }
    }

    // public-profile.css（依賴 member.css）
    $css_path = $base_dir . '/assets/css/public-profile.css';
    if ( file_exists( $css_path ) ) {
        wp_enqueue_style( 'smacg-public-profile', $base_url . '/assets/css/public-profile.css', [ 'weixiaoacg-fa6', 'weixiaoacg-member' ], filemtime( $css_path ) );

        wp_add_inline_style( 'smacg-public-profile', '
            .pp-toast {
                position: fixed;
                bottom: 30px;
                left: 50%;
                transform: translateX(-50%) translateY(20px);
                padding: 10px 20px;
                font-size: 14px;
                font-weight: 600;
                color: #fff;
                border-radius: 999px;
                box-shadow: 0 8px 24px rgba(0,0,0,.4);
                opacity: 0;
                pointer-events: none;
                transition: opacity .2s, transform .2s;
                z-index: 99999;
            }
            .pp-toast--show { opacity: 1; transform: translateX(-50%) translateY(0); }
            .pp-toast--ok   { background: linear-gradient(135deg, #34d399, #10b981); }
            .pp-toast--err  { background: linear-gradient(135deg, #f87171, #ef4444); }
        ' );
    }

    // public-profile.js
    $js_path = $base_dir . '/assets/js/public-profile.js';
    if ( file_exists( $js_path ) ) {
        wp_enqueue_script( 'smacg-public-profile', $base_url . '/assets/js/public-profile.js', [], filemtime( $js_path ), true );
    }
}, 20 );

/* ============================================================
   Follow System 追蹤按鈕（v2.4.0 - 2026-05-13）
   ============================================================ */
add_action( 'wp_enqueue_scripts', function () {
    $base_dir = weixiaoacg_THEME_DIR;
    $base_url = weixiaoacg_THEME_URL;

    $css_path = $base_dir . '/assets/css/follow.css';
    if ( file_exists( $css_path ) ) {
        wp_enqueue_style( 'smacg-follow', $base_url . '/assets/css/follow.css', [ 'weixiaoacg-fa6' ], filemtime( $css_path ) );
    }

    $js_path = $base_dir . '/assets/js/follow.js';
    if ( file_exists( $js_path ) ) {
        wp_enqueue_script( 'smacg-follow', $base_url . '/assets/js/follow.js', [], filemtime( $js_path ), true );
        wp_localize_script( 'smacg-follow', 'smacgFollow', [
            'ajax'     => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'wxacg_follow_nonce' ),
            'loggedIn' => is_user_logged_in(),
            'loginUrl' => function_exists( 'um_get_core_page' ) ? um_get_core_page( 'login' ) : wp_login_url(),
        ] );
    }
}, 22 );

/* ============================================================
   Notifications 通知中心（v2.5.0 - 2026-05-13）
   ============================================================ */
add_action( 'wp_enqueue_scripts', function () {
    if ( ! is_user_logged_in() ) {
        return;
    }

    $base_dir = weixiaoacg_THEME_DIR;
    $base_url = weixiaoacg_THEME_URL;

    $css_path = $base_dir . '/assets/css/notifications.css';
    if ( file_exists( $css_path ) ) {
        wp_enqueue_style( 'smacg-notifications', $base_url . '/assets/css/notifications.css', [ 'weixiaoacg-fa6' ], filemtime( $css_path ) );
    }

    $js_path = $base_dir . '/assets/js/notifications.js';
    if ( file_exists( $js_path ) ) {
        wp_enqueue_script( 'smacg-notifications', $base_url . '/assets/js/notifications.js', [], filemtime( $js_path ), true );
        wp_localize_script( 'smacg-notifications', 'smacgNotif', [
            'ajax'         => admin_url( 'admin-ajax.php' ),
            'nonce'        => wp_create_nonce( 'smacg_notif_nonce' ),
            'loggedIn'     => true,
            'mcUrl'        => home_url( '/mc/' ),
            'pollInterval' => 60,
            'sounds'       => [
                '01' => $base_url . '/assets/sounds/notification01.mp3',
                '02' => $base_url . '/assets/sounds/notification02.mp3',
                '03' => $base_url . '/assets/sounds/notification03.mp3',
            ],
        ] );
    }
}, 23 );

/* ============================================================
   Gamification 等級／徽章系統（v2.6.0 - 2026-05-14）
   ============================================================ */
add_action( 'wp_enqueue_scripts', function () {
    $css_path = weixiaoacg_THEME_DIR . '/assets/css/gamification.css';
    if ( file_exists( $css_path ) ) {
        wp_enqueue_style( 'wxacg-gamification', weixiaoacg_THEME_URL . '/assets/css/gamification.css', [ 'weixiaoacg-fa6' ], filemtime( $css_path ) );
    }
}, 24 );

/* ============================================================
   Level Badge 等級徽章（v2.6.1 - 2026-05-14）
   ============================================================ */
add_action( 'wp_enqueue_scripts', function () {
    $css_path = weixiaoacg_THEME_DIR . '/assets/css/level-badge.css';
    if ( file_exists( $css_path ) ) {
        wp_enqueue_style( 'smacg-level-badge', weixiaoacg_THEME_URL . '/assets/css/level-badge.css', [ 'wxacg-gamification' ], filemtime( $css_path ) );
    }
}, 25 );

/* ============================================================
   Career Selection 職業選擇（v2.6.1 - 2026-05-14）
   ============================================================ */
add_action( 'wp_enqueue_scripts', function () {
    if ( ! is_user_logged_in() || ! is_page_template( 'page-member.php' ) ) {
        return;
    }

    $tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : '';
    if ( $tab !== 'career' ) {
        return;
    }

    $js_path = weixiaoacg_THEME_DIR . '/assets/js/career.js';
    if ( ! file_exists( $js_path ) ) {
        return;
    }

    wp_enqueue_script( 'smacg-career', weixiaoacg_THEME_URL . '/assets/js/career.js', [], filemtime( $js_path ), true );

    $uid      = get_current_user_id();
    $lvl_info = function_exists( 'wxacg_get_user_level_info' ) ? wxacg_get_user_level_info( $uid ) : [ 'level' => 0 ];
    $current  = function_exists( 'smacg_get_user_career_job' ) ? smacg_get_user_career_job( $uid ) : '';

    wp_localize_script( 'smacg-career', 'smacgCareer', [
        'ajax'       => admin_url( 'admin-ajax.php' ),
        'nonce'      => wp_create_nonce( 'smacg_career_nonce' ),
        'loggedIn'   => true,
        'userLevel'  => (int) ( $lvl_info['level'] ?? 0 ),
        'currentJob' => $current,
        'loginUrl'   => function_exists( 'um_get_core_page' ) ? um_get_core_page( 'login' ) : wp_login_url(),
    ] );
}, 26 );

/* ============================================================
   Leaderboard 會員排行榜（v2.7.0 - 2026-05-14）
   ============================================================ */
add_action( 'wp_enqueue_scripts', function () {
    if ( ! is_page_template( 'page-ranking-users.php' ) ) {
        return;
    }

    $css_path = weixiaoacg_THEME_DIR . '/assets/css/leaderboard.css';
    if ( file_exists( $css_path ) ) {
        wp_enqueue_style( 'smacg-leaderboard', weixiaoacg_THEME_URL . '/assets/css/leaderboard.css', [ 'weixiaoacg-fa6', 'smacg-level-badge' ], filemtime( $css_path ) );
    }

    $js_path = weixiaoacg_THEME_DIR . '/assets/js/leaderboard.js';
    if ( ! file_exists( $js_path ) ) {
        return;
    }

    wp_enqueue_script( 'smacg-leaderboard', weixiaoacg_THEME_URL . '/assets/js/leaderboard.js', [], filemtime( $js_path ), true );

    /*
     * 分頁白名單必須與 page-ranking-users.php 的 $valid_tabs 一致。
     *
     * 這份清單原本只有前三項，後來新增的 exp_monthly／login_streak／
     * achievements 沒同步進來，導致直接開 ?tab=login_streak 時：
     * PHP 把簽到榜標成選中，JS 卻因為 defaultTab 被打回 exp_total 而
     * 載入等級排行——分頁高亮與實際資料對不上。站內點擊分頁不受影響
     * （leaderboard.js 的點擊處理自己會設定 state.type），只有直接
     * 輸入網址／書籤／分享連結會踩到。
     */
    $default_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'exp_total';
    if ( ! in_array( $default_tab, [ 'exp_total', 'rank_season', 'rank_last_season', 'exp_monthly', 'login_streak', 'achievements' ], true ) ) {
        $default_tab = 'exp_total';
    }

    $privacy = function_exists( 'smacg_ranking_privacy_localize_data' ) ? smacg_ranking_privacy_localize_data() : null;

    wp_localize_script( 'smacg-leaderboard', 'smacgRanku', [
        'ajax'       => admin_url( 'admin-ajax.php' ),
        'defaultTab' => $default_tab,
        'currentUid' => get_current_user_id(),
        'loggedIn'   => is_user_logged_in(),
        'privacy'    => $privacy,
    ] );
}, 27 );

/* ============================================================
   Leaderboard Widget / Shortcode 樣式（v2.7.1 - 2026-05-14）
   ============================================================ */
add_action( 'wp_enqueue_scripts', function () {
    $css_path = weixiaoacg_THEME_DIR . '/assets/css/leaderboard-widget.css';
    if ( file_exists( $css_path ) ) {
        wp_enqueue_style( 'smacg-leaderboard-widget', weixiaoacg_THEME_URL . '/assets/css/leaderboard-widget.css', [ 'weixiaoacg-fa6' ], filemtime( $css_path ) );
    }
}, 28 );

/* ============================================================
   Season Event single 模板（v2.7.2 - 2026-05-14）
   ============================================================ */
add_action( 'wp_enqueue_scripts', function () {
    if ( ! is_singular( defined( 'WXACG_EVENT_CPT' ) ? WXACG_EVENT_CPT : 'wxacg_season_event' ) ) {
        return;
    }

    $css_path = weixiaoacg_THEME_DIR . '/assets/css/season-event.css';
    if ( file_exists( $css_path ) ) {
        wp_enqueue_style( 'smacg-season-event', weixiaoacg_THEME_URL . '/assets/css/season-event.css', [ 'weixiaoacg-fa6' ], filemtime( $css_path ) );
    }
}, 29 );

/* ============================================================
   AI Tools 頁面（v2.9.0 - 2026-05-16）
   ============================================================ */
add_action( 'wp_enqueue_scripts', function () {
    if ( ! is_page_template( 'page-ai-tools.php' ) ) {
        return;
    }

    $css_path = weixiaoacg_THEME_DIR . '/assets/css/ai-tools.css';
    if ( file_exists( $css_path ) ) {
        wp_enqueue_style( 'smacg-ai-tools', weixiaoacg_THEME_URL . '/assets/css/ai-tools.css', [ 'weixiaoacg-style', 'weixiaoacg-fa6' ], filemtime( $css_path ) );
    }

    $js_path = weixiaoacg_THEME_DIR . '/assets/js/ai-tools.js';
    if ( file_exists( $js_path ) ) {
        wp_enqueue_script( 'smacg-ai-tools', weixiaoacg_THEME_URL . '/assets/js/ai-tools.js', [], filemtime( $js_path ), true );
    }
}, 22 );

/* ============================================================
   Header v2.0.0 (2026-05-28) — header.css / header.js
   ------------------------------------------------------------
   - Google Fonts 已在 weixiaoacg-fonts 載入，不重複註冊
   - header.css：密碼眼睛切換、verify-sent 面板樣式
   - header.js：搜尋、頭像下拉、Login Modal、AJAX 登入/註冊/查重
   - ⚠ v2.12.0：header.js 依賴 weixiaoacg-nav，確保 weixiaoacg_ajax
     （ajax_url / nonce）先就緒，否則登入/註冊/查重會失敗。
   ============================================================ */
add_action( 'wp_enqueue_scripts', function () {

    // Header CSS
    $css_rel = '/assets/css/header.css';
    $css_abs = weixiaoacg_THEME_DIR . $css_rel;
    if ( file_exists( $css_abs ) ) {
        wp_enqueue_style( 'smacg-header', weixiaoacg_THEME_URL . $css_rel, [ 'weixiaoacg-style' ], filemtime( $css_abs ) );
    }

    /*
     * Header 裝飾橫幅（header-banner.css / .js）
     *
     * 只在真的有設定橫幅時才載入 —— 沒設定就完全不送這兩個檔案，
     * header 每一頁都會執行，不該為一個未啟用的功能付出常態成本。
     * 網址來源見 functions.php 的 wxacg_header_banner_url()。
     */
    if ( function_exists( 'wxacg_header_banner_url' ) && wxacg_header_banner_url() !== '' ) {

        $hb_css_rel = '/assets/css/header-banner.css';
        $hb_css_abs = weixiaoacg_THEME_DIR . $hb_css_rel;
        if ( file_exists( $hb_css_abs ) ) {
            wp_enqueue_style(
                'smacg-header-banner',
                weixiaoacg_THEME_URL . $hb_css_rel,
                [ 'weixiaoacg-style' ],
                filemtime( $hb_css_abs )
            );
        }

        $hb_js_rel = '/assets/js/header-banner.js';
        $hb_js_abs = weixiaoacg_THEME_DIR . $hb_js_rel;
        if ( file_exists( $hb_js_abs ) ) {
            wp_enqueue_script(
                'smacg-header-banner',
                weixiaoacg_THEME_URL . $hb_js_rel,
                [],
                filemtime( $hb_js_abs ),
                true
            );
        }
    }

    // Header JS（依賴 weixiaoacg-nav：weixiaoacg_ajax 由其 localize）
    $js_rel = '/assets/js/header.js';
    $js_abs = weixiaoacg_THEME_DIR . $js_rel;
    if ( file_exists( $js_abs ) ) {
        wp_enqueue_script(
            'smacg-header',
            weixiaoacg_THEME_URL . $js_rel,
            [ 'weixiaoacg-nav' ], // ← v2.12.0 關鍵修正：原為 []，造成 weixiaoacg_ajax 未就緒、登入失敗
            filemtime( $js_abs ),
            true
        );
        wp_localize_script( 'smacg-header', 'weixiaoacg_header', [
            'search_url' => home_url( '/?s=' ),
        ] );
    }

    /* ────────────────────────────────────────
       ★v2.15.0：最近/熱門搜尋（search-hot）
       全站載入；CSS 依賴 smacg-header，JS 依賴 smacg-header，
       確保搜尋框（#header-search-*）相關樣式與變數皆已就緒。
       後端 REST 由 /inc/search-hot.php 提供。
       ──────────────────────────────────────── */
    // search-hot CSS
    $sh_css_rel = '/assets/css/search-hot.css';
    $sh_css_abs = weixiaoacg_THEME_DIR . $sh_css_rel;
    if ( file_exists( $sh_css_abs ) ) {
        wp_enqueue_style(
            'smacg-search-hot',
            weixiaoacg_THEME_URL . $sh_css_rel,
            wp_style_is( 'smacg-header', 'registered' ) ? [ 'smacg-header' ] : [ 'weixiaoacg-style' ],
            filemtime( $sh_css_abs )
        );
    }

    // search-hot JS
    $sh_js_rel = '/assets/js/search-hot.js';
    $sh_js_abs = weixiaoacg_THEME_DIR . $sh_js_rel;
    if ( file_exists( $sh_js_abs ) ) {
        wp_enqueue_script(
            'smacg-search-hot',
            weixiaoacg_THEME_URL . $sh_js_rel,
            wp_script_is( 'smacg-header', 'registered' ) ? [ 'smacg-header' ] : [],
            filemtime( $sh_js_abs ),
            true
        );
    }
}, 25 );

/* ============================================================
   wpForo 論壇樣式覆蓋（玻璃擬態主題對齊）
   ============================================================ */
add_action( 'wp_enqueue_scripts', function () {
    // ★v2.13.0 修正：改用 path 比對，且改為「以 /community 或 /forum 開頭」，避免誤命中
    $uri      = isset( $_SERVER['REQUEST_URI'] ) ? wp_parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH ) : '';
    $is_forum = ( $uri && ( strpos( $uri, '/community' ) === 0 || strpos( $uri, '/forum' ) === 0 ) );
    if ( ! $is_forum && function_exists( 'is_wpforo_page' ) ) {
        $is_forum = is_wpforo_page();
    }
    if ( ! $is_forum ) {
        return;
    }

    $css_path = weixiaoacg_THEME_DIR . '/assets/css/wpforo-override.css';
    if ( ! file_exists( $css_path ) ) {
        return;
    }

    $deps = [];
    if ( wp_style_is( 'weixiaoacg-style', 'registered' ) ) {
        $deps[] = 'weixiaoacg-style';
    }

    wp_enqueue_style( 'smacg-wpforo-override', weixiaoacg_THEME_URL . '/assets/css/wpforo-override.css', $deps, filemtime( $css_path ) );
}, 30 );
