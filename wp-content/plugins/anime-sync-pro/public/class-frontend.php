<?php
/**
 * Frontend Handler
 * @package Anime_Sync_Pro
 *
 * ACG – enqueue_assets() 加入 is_search + post_type=anime 條件
 *       load_single_template() 加入 is_search + post_type=anime 條件
 *       讓搜尋結果頁套用 archive-anime.php 模板並正確載入 CSS/JS
 *       新增 filter_anime_search():搜尋時同時查詢
 *       anime_title_romaji、anime_title_english meta 欄位
 *       僅在 post_type=anime 搜尋時生效,不影響其他搜尋
 * ACG v2 – anime-single.css 擴展至 archive / taxonomy / search 頁
 *          確保 --asd-* CSS 變數在所有 anime 頁面皆可用
 * ACG v3 – 新增 anime_series_tax 系列分類法支援
 *          enqueue_assets() 加入 is_tax('anime_series_tax') 條件
 *          load_single_template() 加入系列頁路由 → archive-series.php
 *          新增 pre_get_posts hook → sort_series_archive()
 *          sort_series_archive() 使用 $query->is_tax() 避免全域污染(Bug #6 修正)
 * ACN – filter_anime_search() 改用 posts_join + posts_where + posts_distinct
 *       取代原本 preg_replace 改 SQL 的脆弱寫法,
 *       避免與其他外掛的 posts_search hook 互相干擾造成搜尋壞掉
 * ACO – 移除不存在的 style.css 載入,修正 404 錯誤
 * ACP v1.2.1 – 多媒體系列聚合
 *       enqueue_assets() 偵測 anime_series_tax 時,額外載入 archive-series.css
 *       sort_series_archive() post_type 擴充為 ANIME_SYNC_PRO_CPTS(anime/manga/novel/game/music)
 *       讓 /series/{slug}/ 可同時顯示多種媒體形式的作品
 *       依賴：anime-sync-pro.php v1.2.0+ 已將 anime_series_tax object_type 擴充
 * ACQ v1.3.2 – 漫畫單篇支援
 *       enqueue_assets() 加入 is_singular('manga') 條件（載入 public.css + anime-single.css）
 *       load_single_template() 加入 manga 單篇路由 → single-manga.php（主題可覆蓋）
 * ACR v1.3.3 – 漫畫列表頁支援（2026-07-17）
 *       enqueue_assets() 加入 is_post_type_archive('manga') / manga genre 頁條件
 *       load_single_template() 加入 archive-manga.php 路由（主題可覆蓋）
 *       讓 /manga/ 與 /genre/{slug}/（漫畫）套用專屬模板並正確載入 CSS/JS
 * ACS v1.3.4 – [Fix] 漫畫列表頁跑版（2026-07-17）
 *       archive-manga.php 使用的 .aaa-* class 從未被任何 CSS 檔案定義，
 *       ACR v1.3.3 只加了「載入判斷」跟「模板路由」，漏了實際 enqueue
 *       archive-manga.css，導致 /manga/ 頁面裸奔跑版。
 *       enqueue_assets() 補上 $is_manga_archive || $is_manga_search 時
 *       載入 public/assets/css/archive-manga.css（比照 archive-series.css
 *       的條件式載入寫法，檔案不存在時自動跳過，不會 fatal）。
 * ACT v1.3.5 – 漫畫單篇專屬 CSS（2026-07-17）
 *       single-manga.php v1.2.0 新增「各地區出版」卡片與「單行本一覽」表格，
 *       樣式抽成獨立 public/assets/css/single-manga.css。
 *       enqueue_assets() 於 is_singular('manga') 時載入該檔，
 *       依賴 anime-single.css（取得 --asd-* 變數），檔案不存在時自動跳過。
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Anime_Sync_Frontend {

    public function __construct() {
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ] );
        add_filter( 'template_include',   [ $this, 'load_single_template' ] );
        add_action( 'wp_head',            [ $this, 'output_seo_meta' ] );
        add_filter( 'the_title',          [ $this, 'filter_title' ], 10, 2 );
        add_filter( 'body_class',         [ $this, 'add_body_classes' ] );
        add_shortcode( 'anime_score',     [ $this, 'shortcode_score' ] );
        add_shortcode( 'anime_streaming', [ $this, 'shortcode_streaming' ] );
        add_shortcode( 'anime_themes',    [ $this, 'shortcode_themes' ] );
        add_action( 'rest_api_init',      [ $this, 'register_rest_routes' ] );
        add_filter( 'query_vars',         [ $this, 'allow_upcoming_query_var' ] );
        add_action( 'pre_get_posts',      [ $this, 'filter_upcoming_query' ] );
        add_action( 'pre_get_posts',      [ $this, 'sort_series_archive' ] );

        add_filter( 'posts_search',   [ $this, 'filter_anime_search' ],          10, 2 );
        add_filter( 'posts_join',     [ $this, 'filter_anime_search_join' ],      10, 2 );
        add_filter( 'posts_where',    [ $this, 'filter_anime_search_where' ],     10, 2 );
        add_filter( 'posts_distinct', [ $this, 'filter_anime_search_distinct' ],  10, 2 );

        // 角色/聲優頁「✏️ 修正資料」快速編輯（僅管理員可見/可用）。
        add_action( 'wp_ajax_asp_entity_save_edit',     [ $this, 'ajax_entity_save_edit' ] );
        add_action( 'wp_ajax_asp_entity_deepl_suggest', [ $this, 'ajax_entity_deepl_suggest' ] );
    }

    /**
     * 儲存角色/聲優的人工修正。直接寫回 wp_anime_characters / wp_anime_persons，
     * 這兩張表不是 wp_posts，沒有 ACF/一般文章編輯畫面，只能走這支 AJAX。
     * 寫入後，Anime_Sync_Entity_Migrator 的 upsert 邏輯（name/image 已有值不覆蓋，
     * 其餘欄位本來就是如此）會保護這裡填的內容不被下次同步蓋掉。
     */
    public function ajax_entity_save_edit(): void {
        check_ajax_referer( 'asp_entity_edit', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( '權限不足' );
        }

        $entity_type = isset( $_POST['entity_type'] ) ? sanitize_key( wp_unslash( $_POST['entity_type'] ) ) : '';
        $bgm_id      = isset( $_POST['bgm_id'] ) ? (int) $_POST['bgm_id'] : 0;

        if ( ! in_array( $entity_type, [ 'character', 'person' ], true ) || $bgm_id <= 0 ) {
            wp_send_json_error( '參數錯誤' );
        }

        global $wpdb;
        $table = $entity_type === 'character' ? $wpdb->prefix . 'anime_characters' : $wpdb->prefix . 'anime_persons';

        $fields = [
            'name'      => isset( $_POST['name'] )      ? sanitize_text_field( wp_unslash( $_POST['name'] ) )      : null,
            'summary'   => isset( $_POST['summary'] )   ? sanitize_textarea_field( wp_unslash( $_POST['summary'] ) ) : null,
            'gender'    => isset( $_POST['gender'] )    ? sanitize_text_field( wp_unslash( $_POST['gender'] ) )    : null,
            'birthday'  => isset( $_POST['birthday'] )  ? sanitize_text_field( wp_unslash( $_POST['birthday'] ) )  : null,
            'bloodtype' => isset( $_POST['bloodtype'] ) ? sanitize_text_field( wp_unslash( $_POST['bloodtype'] ) ) : null,
            'height'    => isset( $_POST['height'] )    ? sanitize_text_field( wp_unslash( $_POST['height'] ) )    : null,
        ];

        // wp_anime_persons 沒有 weight 欄位，只有角色表才有，避免 $wpdb->update() 打到不存在的欄位。
        if ( $entity_type === 'character' && isset( $_POST['weight'] ) ) {
            $fields['weight'] = sanitize_text_field( wp_unslash( $_POST['weight'] ) );
        }

        // 只更新真的有送過來的欄位，未出現在表單裡的欄位維持原樣。
        $set = array_filter( $fields, static function ( $v ) {
            return $v !== null;
        } );

        if ( empty( $set ) ) {
            wp_send_json_error( '沒有可更新的欄位' );
        }

        $updated = $wpdb->update( $table, $set, [ 'bgm_id' => $bgm_id ] );

        if ( $updated === false ) {
            wp_send_json_error( '資料庫寫入失敗' );
        }

        wp_send_json_success( [ 'message' => '已儲存' ] );
    }

    /**
     * 「🌐 DeepL 翻譯建議」按鈕：只回傳翻譯結果給前端填入表單當草稿，
     * 不會直接寫入資料庫，還是要走上面 ajax_entity_save_edit 才會真的存檔。
     */
    public function ajax_entity_deepl_suggest(): void {
        check_ajax_referer( 'asp_entity_edit', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( '權限不足' );
        }

        $text = isset( $_POST['text'] ) ? sanitize_textarea_field( wp_unslash( $_POST['text'] ) ) : '';
        $text = trim( $text );

        if ( $text === '' ) {
            wp_send_json_error( '沒有內容可翻譯' );
        }

        if ( ! class_exists( 'Anime_Sync_Entity_Migrator' ) ) {
            wp_send_json_error( '翻譯模組尚未載入' );
        }

        $migrator   = new Anime_Sync_Entity_Migrator();
        $translated = $migrator->translate_text_public( $text );

        if ( $translated === '' ) {
            wp_send_json_error( '翻譯失敗（可能未設定 DeepL 金鑰、額度不足，或內容已經是中文）' );
        }

        wp_send_json_success( [ 'translated' => $translated ] );
    }

    // =========================================================
    // 資源載入
    // =========================================================

    public function enqueue_assets(): void {

        $is_anime_single  = is_singular( 'anime' );
        /*
         * novel 併入 manga 判斷。
         *
         * 輕小說沿用漫畫的模板（見 load_single_template），但 CSS 這裡原本
         * 只認 manga，導致小說單篇整頁沒有樣式——內容都在，版面卻是裸的。
         * 模板與樣式的條件必須同步，改一邊沒改另一邊就會出現這種情況。
         */
        $is_manga_single  = is_singular( [ 'manga', 'novel' ] ); // ACQ v1.3.2
        $is_anime_archive = is_post_type_archive( 'anime' );
        $is_anime_tax     = is_tax( 'genre' )
                         || is_tax( 'anime_season_tax' )
                         || is_tax( 'anime_format_tax' )
                         || is_tax( 'anime_source_tax' )
                         || is_tax( 'anime_series_tax' )
                         || is_tax( 'anime_studio_tax' );
        $is_anime_search  = is_search() && get_query_var( 'post_type' ) === 'anime';

        // ACR v1.3.3：漫畫列表頁判斷（post type archive 或漫畫的 genre 分類頁）
        $is_manga_archive = is_post_type_archive( 'manga' )
                         || ( is_tax( 'genre' ) && get_post_type() === 'manga' );
        $is_manga_search  = is_search() && get_query_var( 'post_type' ) === 'manga';

        // ACP v1.2.1：系列頁獨立判斷（用於決定是否載入 archive-series.css）
        $is_series_page = is_tax( 'anime_series_tax' );

        $is_upcoming = isset( $_SERVER['REQUEST_URI'] ) && strpos( $_SERVER['REQUEST_URI'], '/upcoming-anime' ) !== false;

        if (
            ! $is_anime_single
            && ! $is_manga_single
            && ! $is_manga_archive   // ACR v1.3.3
            && ! $is_manga_search    // ACR v1.3.3
            && ! $is_anime_archive
            && ! $is_anime_tax
            && ! $is_anime_search
            && ! $is_upcoming
        ) {
            return;
        }

        $public_css_path       = ANIME_SYNC_PRO_DIR . 'public/assets/css/public.css';
        $single_css_path       = ANIME_SYNC_PRO_DIR . 'public/assets/css/anime-single.css';
        $series_css_path       = ANIME_SYNC_PRO_DIR . 'public/assets/css/archive-series.css';
        $manga_css_path        = ANIME_SYNC_PRO_DIR . 'public/assets/css/archive-manga.css';  // ACS v1.3.4
        $single_manga_css_path = ANIME_SYNC_PRO_DIR . 'public/assets/css/single-manga.css';   // ACT v1.3.5
        $frontend_js_path      = ANIME_SYNC_PRO_DIR . 'public/assets/js/frontend.js';

        wp_enqueue_style(
            'anime-sync-public',
            ANIME_SYNC_PRO_URL . 'public/assets/css/public.css',
            [],
            file_exists( $public_css_path ) ? (string) filemtime( $public_css_path ) : ANIME_SYNC_PRO_VERSION
        );

        wp_enqueue_style(
            'anime-sync-single',
            ANIME_SYNC_PRO_URL . 'public/assets/css/anime-single.css',
            [ 'anime-sync-public' ],
            file_exists( $single_css_path ) ? (string) filemtime( $single_css_path ) : ANIME_SYNC_PRO_VERSION
        );

        // ----------------------------------------------------------
        // ACT v1.3.5：漫畫單篇專屬 CSS（各地區出版卡片 + 單行本一覽表格）
        // 只在 is_singular('manga') 載入；依賴 anime-single.css 以取得 --asd-* 變數。
        // 檔案不存在時自動跳過（向下相容，不會 fatal）。
        // ----------------------------------------------------------
        if ( $is_manga_single && file_exists( $single_manga_css_path ) ) {
            wp_enqueue_style(
                'anime-sync-single-manga',
                ANIME_SYNC_PRO_URL . 'public/assets/css/single-manga.css',
                [ 'anime-sync-single' ],
                (string) filemtime( $single_manga_css_path )
            );
        }

        // ----------------------------------------------------------
        // ACP v1.2.1：系列頁專屬 CSS（只在 /series/{slug}/ 載入）
        // 檔案不存在時自動跳過（向下相容，不會 fatal）
        // ----------------------------------------------------------
        if ( $is_series_page && file_exists( $series_css_path ) ) {
            wp_enqueue_style(
                'anime-sync-archive-series',
                ANIME_SYNC_PRO_URL . 'public/assets/css/archive-series.css',
                [ 'anime-sync-public' ],
                (string) filemtime( $series_css_path )
            );
        }

        // ----------------------------------------------------------
        // ACS v1.3.4：漫畫列表頁專屬 CSS（/manga/、漫畫 genre 頁、漫畫搜尋頁）
        // archive-manga.php 用的 .aaa-* class 都定義在這支檔案裡；
        // ACR v1.3.3 當初只加了模板路由，漏了這段 enqueue，
        // 導致 /manga/ 頁面完全沒有對應樣式而跑版。
        // 檔案不存在時自動跳過（向下相容，不會 fatal）。
        // ----------------------------------------------------------
        if ( ( $is_manga_archive || $is_manga_search ) && file_exists( $manga_css_path ) ) {
            wp_enqueue_style(
                'anime-sync-archive-manga',
                ANIME_SYNC_PRO_URL . 'public/assets/css/archive-manga.css',
                [ 'anime-sync-public' ],
                (string) filemtime( $manga_css_path )
            );
        }

        wp_enqueue_script(
            'anime-sync-frontend',
            ANIME_SYNC_PRO_URL . 'public/assets/js/frontend.js',
            [],
            file_exists( $frontend_js_path ) ? (string) filemtime( $frontend_js_path ) : ANIME_SYNC_PRO_VERSION,
            true
        );

        wp_script_add_data( 'anime-sync-frontend', 'defer', true );

        // ----------------------------------------------------------
        // [v1.7.1] /upcoming-anime/ 即時篩選：只在這個視圖載入，獨立小檔案
        // 不掛 frontend.js（無依賴關係，各自獨立初始化）。
        // 檔案不存在時自動跳過（向下相容，不會 fatal）。
        // ----------------------------------------------------------
        $upcoming_filters_js_path = ANIME_SYNC_PRO_DIR . 'public/assets/js/upcoming-filters.js';
        if ( $is_upcoming && file_exists( $upcoming_filters_js_path ) ) {
            wp_enqueue_script(
                'anime-sync-upcoming-filters',
                ANIME_SYNC_PRO_URL . 'public/assets/js/upcoming-filters.js',
                [],
                (string) filemtime( $upcoming_filters_js_path ),
                true
            );
            wp_script_add_data( 'anime-sync-upcoming-filters', 'defer', true );
        }

        wp_localize_script( 'anime-sync-frontend', 'animeSyncData', [
            // 向後相容:restUrl 仍保留給既有 anime-sync/v1 前台 API 使用
            'restUrl'       => esc_url_raw( rest_url( 'anime-sync/v1/' ) ),
            'animeRestUrl'  => esc_url_raw( rest_url( 'anime-sync/v1/' ) ),
            // 評分系統實際 REST namespace
            'ratingRestUrl' => esc_url_raw( rest_url( 'weixiaoacg/v1/' ) ),
            'nonce'         => wp_create_nonce( 'wp_rest' ),
            'debug'         => defined( 'WP_DEBUG' ) && WP_DEBUG,
            // 角色/聲優頁「✏️ 修正資料」用（僅管理員看得到對應 UI，一般訪客不受影響）
            'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
            'entityEditNonce' => wp_create_nonce( 'asp_entity_edit' ),
        ] );
    }

    // =========================================================
    // 模板覆蓋
    // =========================================================

    public function load_single_template( string $template ): string {

        if ( is_singular( 'anime' ) ) {
            $plugin = ANIME_SYNC_PRO_DIR . 'public/templates/single-anime.php';
            if ( file_exists( $plugin ) ) {
                return $plugin;
            }
        }

        // ---- ACQ v1.3.2：漫畫單篇模板（主題可覆蓋）----
        if ( is_singular( 'manga' ) ) {
            $theme = locate_template( 'single-manga.php' );
            if ( $theme ) {
                return $theme;
            }
            $plugin = ANIME_SYNC_PRO_DIR . 'public/templates/single-manga.php';
            if ( file_exists( $plugin ) ) {
                return $plugin;
            }
        }

        /*
         * 輕小說沿用漫畫模板。
         *
         * novel 與 manga 的欄位結構相同（同一份 trait-manga-field-groups.php
         * 提供作者、出版社、台灣代理、卷數、購買連結、關聯動畫），差別只在
         * 資料來源的 format 是 NOVEL 還是 MANGA。共用模板避免維護兩套幾乎
         * 一樣的版面；日後若輕小說要做出區隔，在主題放一支 single-novel.php
         * 就會優先被 locate_template() 取用，不必改這裡。
         */
        if ( is_singular( 'novel' ) ) {
            $theme = locate_template( [ 'single-novel.php', 'single-manga.php' ] );
            if ( $theme ) {
                return $theme;
            }
            $plugin = ANIME_SYNC_PRO_DIR . 'public/templates/single-manga.php';
            if ( file_exists( $plugin ) ) {
                return $plugin;
            }
        }

        if ( is_post_type_archive( 'novel' ) ) {
            $theme = locate_template( [ 'archive-novel.php', 'archive-manga.php' ] );
            if ( $theme ) {
                return $theme;
            }
            $plugin = ANIME_SYNC_PRO_DIR . 'public/templates/archive-manga.php';
            if ( file_exists( $plugin ) ) {
                return $plugin;
            }
        }

        if ( is_tax( 'anime_series_tax' ) ) {
            $theme = locate_template( 'archive-series.php' );
            if ( $theme ) return $theme;

            $plugin = ANIME_SYNC_PRO_DIR . 'public/templates/archive-series.php';
            if ( file_exists( $plugin ) ) return $plugin;
        }

        // ---- ACR v1.3.3：漫畫列表頁模板（主題可覆蓋）----
        // 條件：post type archive（/manga/）或漫畫的 genre 分類頁、或漫畫的搜尋結果頁
        $is_manga_archive_route = is_post_type_archive( 'manga' )
                                || ( is_tax( 'genre' ) && get_post_type() === 'manga' );
        $is_manga_search_route  = is_search() && get_query_var( 'post_type' ) === 'manga';

        if ( $is_manga_archive_route || $is_manga_search_route ) {
            $theme = locate_template( 'archive-manga.php' );
            if ( $theme ) {
                return $theme;
            }
            $plugin = ANIME_SYNC_PRO_DIR . 'public/templates/archive-manga.php';
            if ( file_exists( $plugin ) ) {
                return $plugin;
            }
        }

        $is_anime_search = is_search() && get_query_var( 'post_type' ) === 'anime';

        // [v3.4] /upcoming-anime/ 用 REQUEST_URI 判斷，確保 query var 問題不影響
        $_req_uri = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '';
        if ( strpos( $_req_uri, '/upcoming-anime' ) !== false ) {
            $plugin = ANIME_SYNC_PRO_DIR . 'public/templates/archive-anime.php';
            if ( file_exists( $plugin ) ) return $plugin;
        }

        if (
            is_post_type_archive( 'anime' )
            || is_tax( 'genre' )
            || is_tax( 'anime_season_tax' )
            || is_tax( 'anime_format_tax' )
            || is_tax( 'anime_source_tax' )
            || is_tax( 'anime_series_tax' )
            || is_tax( 'anime_studio_tax' )
            || $is_anime_search
        ) {
            $theme = locate_template( 'archive-anime.php' );
            if ( $theme ) return $theme;

            $plugin = ANIME_SYNC_PRO_DIR . 'public/templates/archive-anime.php';
            if ( file_exists( $plugin ) ) return $plugin;
        }

        return $template;
    }

    // =========================================================
    // ACG v3 / ACP v1.2.1：系列頁排序
    //
    // ACP v1.2.1 變更:
    //   - post_type 從 'anime' 擴充為 ANIME_SYNC_PRO_CPTS 全部 5 種 CPT
    //   - 讓 /series/{slug}/ 同時撈出 anime + manga + novel + game + music
    //
    // ★ [v1.5.7] 移除 meta_key + orderby=meta_value_num 的 SQL 排序
    //
    //   舊寫法設定 meta_key='anime_season_year' 搭配 orderby=meta_value_num，
    //   WordPress 會因此加上 INNER JOIN postmeta —— 也就是「沒有這個 meta 列」
    //   的文章會被整個排除在查詢外，不是排到後面而已。實測《鬼滅之刃》系列
    //   10 部只撈得到 7 部，消失的是：
    //     · 劇場版無限城篇 第二、三章（未播出、檔期未定 → 無 season_year）
    //     · 鬼滅之刃 漫畫（漫畫本來就不會有 anime_season_year）
    //
    //   註：舊註解說「由 archive-series.php 端做二次處理」，但那邊做的是
    //   year「顯示值」的 fallback（season_year → start_date → post_date），
    //   無法救回在 SQL 階段就被 JOIN 濾掉的資料；同檔 v1.3.0 那段「漫畫
    //   fallback 補撈」也是為了補救本問題的症狀，根因其實在這裡。
    //
    //   改由 archive-series.php 在 PHP 層排序：那裡的 year 已套用完整
    //   fallback，比原始 meta 更準確，且不會排除任何資料。
    // =========================================================

    public function sort_series_archive( \WP_Query $query ): void {
        if ( is_admin() ) return;
        if ( ! $query->is_main_query() ) return;
        if ( ! $query->is_tax( 'anime_series_tax' ) ) return;

        // ACP v1.2.1：擴充 post_type 為全部支援的 CPT
        $cpts = defined( 'ANIME_SYNC_PRO_CPTS' )
            ? explode( ',', ANIME_SYNC_PRO_CPTS )
            : [ 'anime' ];

        $query->set( 'post_type',      $cpts );
        $query->set( 'posts_per_page', -1 );
    }

    // =========================================================
    // 搜尋 meta 欄位擴展
    // =========================================================

    private function is_anime_search_query( \WP_Query $query ): bool {
        if ( is_admin() ) return false;
        if ( ! $query->is_main_query() ) return false;
        if ( ! $query->is_search() ) return false;
        if ( $query->get( 'post_type' ) !== 'anime' ) return false;
        if ( empty( $query->get( 's' ) ) ) return false;
        return true;
    }

    public function filter_anime_search( string $search, \WP_Query $query ): string {
        return $search;
    }

    public function filter_anime_search_join( string $join, \WP_Query $query ): string {
        if ( ! $this->is_anime_search_query( $query ) ) return $join;

        global $wpdb;

        if ( strpos( $join, 'anime_meta_search' ) !== false ) return $join;

        $join .= " LEFT JOIN {$wpdb->postmeta} AS anime_meta_search
                   ON ( {$wpdb->posts}.ID = anime_meta_search.post_id
                        AND anime_meta_search.meta_key IN (
                            'anime_title_romaji',
                            'anime_title_english'
                        ) ) ";

        return $join;
    }

    public function filter_anime_search_where( string $where, \WP_Query $query ): string {
        if ( ! $this->is_anime_search_query( $query ) ) return $where;

        global $wpdb;

        $term = $query->get( 's' );
        if ( empty( $term ) ) return $where;

        $like = '%' . $wpdb->esc_like( $term ) . '%';

        $meta_condition = $wpdb->prepare(
            " OR ( anime_meta_search.meta_value LIKE %s ) ",
            $like
        );

        $posts_table = preg_quote( $wpdb->posts, '/' );

        $new_where = preg_replace(
            '/(\(\s*\(\s*' . $posts_table . '\.post_title\s+LIKE\s+[\'"]%.*?%[\'"].*?\)\s*\))/s',
            '$1' . $meta_condition,
            $where,
            1
        );

        if ( $new_where !== null && $new_where !== $where ) {
            return $new_where;
        }

        $where .= ' OR ( anime_meta_search.meta_value LIKE ' . $wpdb->prepare( '%s', $like ) . ' ) ';

        return $where;
    }

    public function filter_anime_search_distinct( string $distinct, \WP_Query $query ): string {
        if ( ! $this->is_anime_search_query( $query ) ) return $distinct;
        return 'DISTINCT';
    }

    // =========================================================
    // SEO Meta
    // =========================================================

    public function output_seo_meta(): void {
        if ( ! is_singular( 'anime' ) ) return;
        if ( defined( 'WPSEO_VERSION' ) || defined( 'RANK_MATH_VERSION' ) || class_exists( 'All_in_One_SEO_Pack' ) ) return;

        global $post;
        if ( ! $post instanceof \WP_Post ) return;

        $pid   = $post->ID;
        $title = get_post_meta( $pid, 'anime_title_chinese', true ) ?: get_the_title( $pid );
        $desc  = mb_substr( wp_strip_all_tags(
            get_post_meta( $pid, 'anime_synopsis_chinese', true )
            ?: get_post_meta( $pid, 'anime_synopsis', true )
            ?: ''
        ), 0, 160 );
        $cover = get_post_meta( $pid, 'anime_cover_image', true );
        $url   = get_permalink( $pid );

        echo '<meta property="og:type" content="video.tv_show">' . "\n";
        echo '<meta property="og:title" content="' . esc_attr( $title ) . '">' . "\n";
        echo '<meta property="og:description" content="' . esc_attr( $desc ) . '">' . "\n";
        echo '<meta property="og:url" content="' . esc_url( $url ) . '">' . "\n";
        echo '<meta property="og:site_name" content="' . esc_attr( get_bloginfo( 'name' ) ) . '">' . "\n";
        if ( $cover ) echo '<meta property="og:image" content="' . esc_url( $cover ) . '">' . "\n";
        echo '<meta name="twitter:card" content="summary_large_image">' . "\n";
        echo '<meta name="twitter:title" content="' . esc_attr( $title ) . '">' . "\n";
        echo '<meta name="twitter:description" content="' . esc_attr( $desc ) . '">' . "\n";
        if ( $cover ) echo '<meta name="twitter:image" content="' . esc_url( $cover ) . '">' . "\n";
        echo '<link rel="canonical" href="' . esc_url( $url ) . '">' . "\n";
    }

    // =========================================================
    // 標題過濾
    // =========================================================

    public function filter_title( string $title, int $post_id = 0 ): string {
        if ( ! $post_id || get_post_type( $post_id ) !== 'anime' ) return $title;
        return get_post_meta( $post_id, 'anime_title_chinese', true ) ?: $title;
    }

    // =========================================================
    // Body Classes
    // =========================================================

    public function add_body_classes( array $classes ): array {
        if ( is_singular( 'anime' ) ) {
            global $post;
            if ( $post instanceof \WP_Post ) {
                $format    = get_post_meta( $post->ID, 'anime_format', true );
                $status    = get_post_meta( $post->ID, 'anime_status', true );
                $classes[] = 'anime-single';
                if ( $format ) $classes[] = 'anime-format-' . sanitize_html_class( strtolower( $format ) );
                if ( $status ) $classes[] = 'anime-status-' . sanitize_html_class( strtolower( $status ) );
            }
        }

        // ACQ v1.3.2：漫畫單篇 body class
        if ( is_singular( 'manga' ) ) {
            global $post;
            if ( $post instanceof \WP_Post ) {
                $status    = get_post_meta( $post->ID, 'anime_status', true );
                $classes[] = 'manga-single';
                $classes[] = 'anime-single'; // 共用 anime-single.css 樣式
                if ( $status ) $classes[] = 'manga-status-' . sanitize_html_class( strtolower( $status ) );
            }
        }

        if ( is_post_type_archive( 'anime' ) ) {
            $classes[] = 'anime-archive';
        }

        // ACR v1.3.3：漫畫列表頁 body class，方便主題客製
        if ( is_post_type_archive( 'manga' ) || ( is_tax( 'genre' ) && get_post_type() === 'manga' ) ) {
            $classes[] = 'manga-archive';
        }

        // ACP v1.2.1：系列頁加上 body class，方便主題客製
        if ( is_tax( 'anime_series_tax' ) ) {
            $classes[] = 'anime-series-archive';
        }

        return $classes;
    }

    // =========================================================
    // Shortcodes
    // =========================================================

    public function shortcode_score( array $atts ): string {
        $atts = shortcode_atts( [ 'post_id' => get_the_ID() ], $atts );
        $pid  = (int) $atts['post_id'];
        if ( ! $pid ) return '';

        $anilist = get_post_meta( $pid, 'anime_score_anilist', true );
        $bangumi = get_post_meta( $pid, 'anime_score_bangumi', true );
        $mal     = get_post_meta( $pid, 'anime_score_mal', true );

        ob_start(); ?>
        <div class="anime-scores">
            <?php if ( $anilist ) : ?>
                <span class="score score-anilist">
                    <span class="score-label">AniList</span>
                    <span class="score-value"><?php echo esc_html( number_format( (float) $anilist, 1 ) ); ?></span>
                </span>
            <?php endif; ?>
            <?php if ( $bangumi ) : ?>
                <span class="score score-bangumi">
                    <span class="score-label">Bangumi</span>
                    <span class="score-value"><?php echo esc_html( number_format( (float) $bangumi, 1 ) ); ?></span>
                </span>
            <?php endif; ?>
            <?php if ( $mal ) : ?>
                <span class="score score-mal">
                    <span class="score-label">MAL</span>
                    <span class="score-value"><?php echo esc_html( number_format( (float) $mal, 1 ) ); ?></span>
                </span>
            <?php endif; ?>
        </div>
        <?php return ob_get_clean();
    }

    public function shortcode_streaming( array $atts ): string {
        $atts      = shortcode_atts( [ 'post_id' => get_the_ID() ], $atts );
        $pid       = (int) $atts['post_id'];
        $raw       = get_post_meta( $pid, 'anime_streaming', true );
        if ( ! $raw ) return '';

        $platforms = is_array( $raw ) ? $raw : json_decode( $raw, true );
        if ( empty( $platforms ) ) return '';

        ob_start(); ?>
        <div class="anime-streaming">
            <h4><?php esc_html_e( '串流平台', 'anime-sync-pro' ); ?></h4>
            <ul class="streaming-list">
                <?php foreach ( $platforms as $item ) :
                    $name = $item['platform'] ?? $item['site'] ?? '';
                    $url  = $item['url'] ?? '';
                    if ( ! $name ) continue;
                ?>
                    <li>
                        <?php if ( $url ) : ?>
                            <a href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener">
                                <?php echo esc_html( $name ); ?>
                            </a>
                        <?php else : ?>
                            <?php echo esc_html( $name ); ?>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php return ob_get_clean();
    }

    public function shortcode_themes( array $atts ): string {
        $atts   = shortcode_atts( [ 'post_id' => get_the_ID() ], $atts );
        $pid    = (int) $atts['post_id'];
        $raw    = get_post_meta( $pid, 'anime_themes', true );
        if ( ! $raw ) return '';

        $themes = is_array( $raw ) ? $raw : json_decode( $raw, true );
        if ( empty( $themes ) ) return '';

        $ops = array_filter( $themes, fn( $t ) => strtoupper( $t['type'] ?? '' ) === 'OP' );
        $eds = array_filter( $themes, fn( $t ) => strtoupper( $t['type'] ?? '' ) === 'ED' );

        ob_start(); ?>
        <div class="anime-themes">
            <?php if ( $ops ) : ?>
                <div class="themes-op">
                    <h4><?php esc_html_e( '片頭曲 (OP)', 'anime-sync-pro' ); ?></h4>
                    <?php foreach ( $ops as $t ) $this->render_theme_item( $t ); ?>
                </div>
            <?php endif; ?>
            <?php if ( $eds ) : ?>
                <div class="themes-ed">
                    <h4><?php esc_html_e( '片尾曲 (ED)', 'anime-sync-pro' ); ?></h4>
                    <?php foreach ( $eds as $t ) $this->render_theme_item( $t ); ?>
                </div>
            <?php endif; ?>
        </div>
        <?php return ob_get_clean();
    }

    private function render_theme_item( array $theme ): void {
        $title      = $theme['song_title'] ?? $theme['title'] ?? __( '未知曲目', 'anime-sync-pro' );
        $artists    = $theme['artists'] ?? $theme['by'] ?? [];
        $artist_str = is_array( $artists )
            ? implode( '、', array_filter(
                array_map( fn( $a ) => is_array( $a ) ? ( $a['name'] ?? '' ) : (string) $a, $artists )
            ) )
            : (string) $artists;
        $video    = $theme['video_url'] ?? $theme['video'] ?? '';
        $sequence = $theme['sequence'] ?? '';
        $type     = strtoupper( $theme['type'] ?? '' );
        ?>
        <div class="theme-item">
            <div class="theme-info">
                <?php if ( $sequence ) : ?>
                    <span class="theme-seq"><?php echo esc_html( $type . $sequence ); ?></span>
                <?php endif; ?>
                <span class="theme-title"><?php echo esc_html( $title ); ?></span>
                <?php if ( $artist_str ) : ?>
                    <span class="theme-artist"><?php echo esc_html( $artist_str ); ?></span>
                <?php endif; ?>
            </div>
            <?php if ( $video ) : ?>
                <a href="<?php echo esc_url( $video ); ?>" target="_blank" rel="noopener" class="theme-video-link">
                    ▶ <?php esc_html_e( '觀看', 'anime-sync-pro' ); ?>
                </a>
            <?php endif; ?>
        </div>
        <?php
    }

    // =========================================================
    // REST API
    // =========================================================

    public function register_rest_routes(): void {
        register_rest_route( 'anime-sync/v1', '/anime/(?P<id>\d+)', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [ $this, 'rest_get_anime' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'id' => [
                    'validate_callback' => fn( $v ) => is_numeric( $v ),
                    'sanitize_callback' => 'absint',
                ],
            ],
        ] );

        register_rest_route( 'anime-sync/v1', '/season', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [ $this, 'rest_get_season' ],
            'permission_callback' => '__return_true',
        ] );
    }

    public function rest_get_anime( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
        $post = get_post( $request->get_param( 'id' ) );

        if ( ! $post || $post->post_type !== 'anime' || $post->post_status !== 'publish' ) {
            return new \WP_Error( 'not_found', '找不到該動畫', [ 'status' => 404 ] );
        }

        return new \WP_REST_Response( $this->build_rest_response( $post ), 200 );
    }

    public function rest_get_season( \WP_REST_Request $request ): \WP_REST_Response {
        $year   = $request->get_param( 'year' );
        $season = strtoupper( $request->get_param( 'season' ) ?? '' );
        $args   = [
            'post_type'      => 'anime',
            'post_status'    => 'publish',
            'posts_per_page' => 100,
            'meta_query'     => [],
        ];

        if ( $year ) {
            $args['meta_query'][] = [
                'key'   => 'anime_season_year',
                'value' => $year,
                'type'  => 'NUMERIC',
            ];
        }

        if ( $season ) {
            $args['meta_query'][] = [
                'key'   => 'anime_season',
                'value' => $season,
            ];
        }

        if ( count( $args['meta_query'] ) > 1 ) {
            $args['meta_query']['relation'] = 'AND';
        }

        $q     = new \WP_Query( $args );
        $items = array_map( [ $this, 'build_rest_response' ], $q->posts );

        return new \WP_REST_Response( [
            'total' => $q->found_posts,
            'items' => $items,
        ], 200 );
    }

    private function build_rest_response( \WP_Post $post ): array {
        $id   = $post->ID;
        $meta = [];

        foreach ( [
            'anime_anilist_id', 'anime_mal_id', 'anime_bangumi_id',
            'anime_title_chinese', 'anime_title_native', 'anime_title_romaji',
            'anime_format', 'anime_status', 'anime_episodes', 'anime_duration',
            'anime_start_date', 'anime_end_date', 'anime_season', 'anime_season_year',
            'anime_score_anilist', 'anime_score_bangumi', 'anime_score_mal',
            'anime_synopsis_chinese', 'anime_cover_image', 'anime_banner_image',
            'anime_trailer_url', 'anime_staff_json', 'anime_cast_json',
            'anime_relations_json', 'anime_last_sync',
        ] as $key ) {
            $v = get_post_meta( $id, $key, true );
            if ( $v !== '' && $v !== false ) {
                if ( is_string( $v ) && str_starts_with( trim( $v ), '[' ) ) {
                    $d = json_decode( $v, true );
                    $v = ( json_last_error() === JSON_ERROR_NONE ) ? $d : $v;
                }
                $meta[ $key ] = $v;
            }
        }

        $genres = get_the_terms( $id, 'genre' );

        return [
            'id'     => $id,
            'slug'   => $post->post_name,
            'url'    => get_permalink( $id ),
            'title'  => $meta['anime_title_chinese'] ?? get_the_title( $id ),
            'meta'   => $meta,
            'genres' => ( $genres && ! is_wp_error( $genres ) ) ? wp_list_pluck( $genres, 'name' ) : [],
        ];
    }

    // =========================================================
    // /anime/upcoming/ 支援（v3.4 新增）
    // =========================================================

    public function allow_upcoming_query_var( array $vars ): array {
        $vars[] = 'anime_upcoming';
        return $vars;
    }

    public function filter_upcoming_query( \WP_Query $query ): void {
        if ( is_admin() ) return;
        if ( ! $query->is_main_query() ) return;
        if ( ! get_query_var( 'anime_upcoming' ) ) return;

        $query->set( 'post_type',      'anime' );
        $query->set( 'post_status',    'publish' );
        /*
         * [v1.7.1] 改成不分頁，跟 anime-sync-pro.php 3.3 節同步改動。
         *
         * 這個 hook 跟 anime-sync-pro.php 的 pre_get_posts（priority 1）
         * 是同一件事的重複實作——兩邊都設定同一批 query 參數，這裡沒有
         * 明確 priority，預設 10，跑在後面，原本用 posts_per_page=24
         * 把前面那份的 -1 蓋掉，即時篩選會篩不到還沒載入的頁面。
         * 兩邊沒有指定 priority 的原因不明，暫不合併成一份（避免這次
         * 改動範圍擴大到跟這個 bug 無關的重構），先保持同步不衝突。
         */
        $query->set( 'posts_per_page', -1 );
        $query->set( 'meta_key',       'anime_popularity' );
        $query->set( 'orderby',        'meta_value_num' );
        $query->set( 'order',          'DESC' );
        $query->set( 'meta_query', [
            [
                'key'     => 'anime_status',
                'value'   => 'NOT_YET_RELEASED',
                'compare' => '=',
            ],
        ]);
    }

}
