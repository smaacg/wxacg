<?php
/**
 * Entity Routing (聲優/角色 個別頁路由)
 * Path: wp-content/plugins/anime-sync-pro/includes/class-entity-routing.php
 * Version: 1.0.0 (2026-07-28)
 *
 * 功能：攔截 /person/{bgm_id}/{name?} 與 /character/{bgm_id}/{name?}，
 *      載入對應模板。bgm_id 為真正識別碼,name 片段僅 SEO 裝飾(可省略)。
 *
 * 設計對齊 class-series-index.php:
 *   - 靜態 class + __CLASS__ 註冊
 *   - rewrite 用 'top' 優先
 *   - template_include 先找子主題,再退回外掛內建
 *   - 不在檔尾 init(),由主外掛 plugins_loaded 統一呼叫
 *   - flush 靠主外掛版本號 +1 觸發(anime_sync_flush_rewrite flag)
 *
 * Changelog:
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
