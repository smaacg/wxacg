<?php
/**
 * Entity Routing (聲優/角色 個別頁路由)
 * Path: wp-content/plugins/anime-sync-pro/includes/class-entity-routing.php
 * Version: 1.2.0 (2026-08-13)
 *
 * 功能：攔截 /person/{bgm_id}/{name?} 與 /character/{bgm_id}/{name?}，
 *      載入對應模板。bgm_id 為真正識別碼,name 片段僅 SEO 裝飾(可省略)。
 *
 * Changelog:
 *   1.2.0 (2026-08-13)
 *     - [修正] 虛擬頁 <title> 未設定，導致角色/聲優頁標題顯示成主查詢
 *              （首頁最新文章）的標題。新增 filter_entity_title() 掛
 *              pre_get_document_title 與 rank_math/frontend/title，
 *              改為顯示「角色名 - 角色資料｜站名」。
 *
 * 設計對齊 class-series-index.php:
 *   - 靜態 class + __CLASS__ 註冊
 *   - rewrite 用 'top' 優先
 *   - template_include 先找子主題,再退回外掛內建
 *   - 不在檔尾 init(),由主外掛 plugins_loaded 統一呼叫
 *   - flush 靠主外掛版本號 +1 觸發(anime_sync_flush_rewrite flag)
 *
 * Changelog:
 *   1.1.1 (2026-07-28)
 *     - [修正] enqueue_assets() 的 entity.css 路徑改用
 *              plugin_dir_url( dirname( __FILE__ ) )，避免組出
 *              .../includes/../public/assets/entity.css 這種帶
 *              「../」的網址。實測該網址在正式站（LiteSpeed Cache
 *              環境）會回傳 404，導致 entity.css 完全沒有載入，
 *              person/character 頁面因此沒有樣式（無毛玻璃卡片、
 *              無深色主題）。改用 dirname(__FILE__) 先跳回外掛
 *              根目錄，再接上 public/assets/entity.css，產生的
 *              網址不含「../」，可正常存取。
 *   1.1.0 (2026-07-28)
 *     - [新增] enqueue_assets()：只在 person/character 頁掛載 entity.css，
 *              透過 is_entity_page() 判斷（用 query var，不依賴 is_page()
 *              等原生條件標籤，因這兩頁是 rewrite 攔截後直接載入模板，
 *              不是真正的 WP 文章）。
 *   1.0.0 (2026-07-28)
 *     - [新增] /person/ 與 /character/ rewrite + query var + template_include。
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class Anime_Sync_Entity_Routing {

    const QV_PERSON    = 'asa_person_id';
    const QV_CHARACTER = 'asa_character_id';

    const SLUG_PERSON    = 'person';
    const SLUG_CHARACTER = 'character';

    public static function init() {
        add_action( 'init',             [ __CLASS__, 'add_rewrite' ] );
        add_filter( 'query_vars',       [ __CLASS__, 'add_query_var' ] );
        add_filter( 'template_include', [ __CLASS__, 'load_template' ] );
        add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );

        // 虛擬頁沒有真正的 post，需自行設定 <title>，否則會退回主查詢
        // （最新文章）的標題。同時掛 Rank Math 的 title filter 才能覆蓋。
        add_filter( 'pre_get_document_title',   [ __CLASS__, 'filter_entity_title' ], 99 );
        add_filter( 'rank_math/frontend/title', [ __CLASS__, 'filter_entity_title' ], 99 );
    }

    /**
     * 註冊兩條 rewrite。
     * 樣式:/person/19619 或 /person/19619/千葉翔也 都命中,只抓數字 id。
     * 尾端 (?:/.*)? 吃掉任意 name 片段,不影響 id 擷取。
     */
    public static function add_rewrite() {
        add_rewrite_rule(
            '^' . self::SLUG_PERSON . '/(\d+)(?:/.*)?/?$',
            'index.php?' . self::QV_PERSON . '=$matches[1]',
            'top'
        );
        add_rewrite_rule(
            '^' . self::SLUG_CHARACTER . '/(\d+)(?:/.*)?/?$',
            'index.php?' . self::QV_CHARACTER . '=$matches[1]',
            'top'
        );
    }

    public static function add_query_var( $vars ) {
        $vars[] = self::QV_PERSON;
        $vars[] = self::QV_CHARACTER;
        return $vars;
    }

    /**
     * 命中 query var 時載入對應模板。
     * 找不到實體(get_person / get_character 回 null)時,交給 WP 走 404。
     */
    public static function load_template( $template ) {

        $person_id    = (int) get_query_var( self::QV_PERSON );
        $character_id = (int) get_query_var( self::QV_CHARACTER );

        if ( $person_id > 0 ) {
            return self::resolve_template( 'single-person.php', $template );
        }

        if ( $character_id > 0 ) {
            return self::resolve_template( 'single-character.php', $template );
        }

        return $template;
    }

    /**
     * 是否目前為 person 或 character 個別頁。
     * 用 query var 判斷,因為這兩頁是 rewrite 攔截後直接載入模板,
     * 不是真正的 WP 文章,不能用 is_page()/is_singular() 判斷。
     */
    public static function is_entity_page() {
        return (int) get_query_var( self::QV_PERSON ) > 0
            || (int) get_query_var( self::QV_CHARACTER ) > 0;
    }

    /**
     * 只在 person/character 頁掛載 entity.css,其餘頁面不受影響。
     *
     * ⚠ 1.1.1 修正:不可用 plugin_dir_url( __FILE__ ) . '../public/...'
     *   組路徑。__FILE__ 位在 includes/ 底下,這樣組出來的網址會帶
     *   「includes/../public/」,實測在正式站(LiteSpeed Cache 環境)
     *   會被解析成 404,導致 entity.css 完全沒有載入。
     *   改用 plugin_dir_url( dirname( __FILE__ ) ),先用 dirname()
     *   跳回外掛根目錄(anime-sync-pro/),再接上乾淨的
     *   public/assets/entity.css,不會出現「../」。
     */
    public static function enqueue_assets() {
        if ( ! self::is_entity_page() ) {
            return;
        }

        wp_enqueue_style(
            'anime-sync-entity',
            plugin_dir_url( dirname( __FILE__ ) ) . 'public/assets/css/entity.css',
            [],
            defined( 'ANIME_SYNC_PRO_VERSION' ) ? ANIME_SYNC_PRO_VERSION : '1.0.0'
        );
    }

    /**
     * 為 person / character 虛擬頁設定正確的文件標題。
     *
     * 這兩頁靠 rewrite 攔截載入模板、沒有對應的 WP post，
     * 若不覆蓋，Rank Math/WP 會用主查詢（首頁最新文章）的標題，
     * 造成角色頁 <title> 顯示成最新新聞稿標題。
     *
     * @param string $title 原標題。
     * @return string
     */
    public static function filter_entity_title( $title ) {
        if ( ! self::is_entity_page() || ! class_exists( 'Anime_Sync_Entity_Repository' ) ) {
            return $title;
        }

        $character_id = (int) get_query_var( self::QV_CHARACTER );
        $person_id    = (int) get_query_var( self::QV_PERSON );

        $repo = new Anime_Sync_Entity_Repository();
        $name = '';
        $kind = '';

        if ( $character_id > 0 ) {
            $entity = $repo->get_character( $character_id );

            if ( is_array( $entity ) && ! empty( $entity['name'] ) ) {
                $name = (string) $entity['name'];
                $kind = '角色';
            }
        } elseif ( $person_id > 0 ) {
            $entity = $repo->get_person( $person_id );

            if ( is_array( $entity ) && ! empty( $entity['name'] ) ) {
                $name = (string) $entity['name'];
                $kind = ( isset( $entity['type'] ) && 'cv' === $entity['type'] ) ? '配音員' : '人物';
            }
        }

        $name = trim( wp_strip_all_tags( $name ) );

        if ( '' === $name ) {
            return $title;
        }

        return sprintf( '%s - %s資料｜%s', $name, $kind, get_bloginfo( 'name' ) );
    }

    /**
     * 先找子主題,再退回外掛內建;都沒有就回原 template。
     */
    private static function resolve_template( $filename, $fallback ) {
        $child = get_stylesheet_directory() . '/' . $filename;
        if ( file_exists( $child ) ) {
            return $child;
        }
        $plugin = plugin_dir_path( __FILE__ ) . '../public/templates/' . $filename;
        if ( file_exists( $plugin ) ) {
            return $plugin;
        }
        return $fallback;
    }
}

// ⚠️ 初始化由主外掛 plugins_loaded 統一呼叫,勿在此檔尾 init()。