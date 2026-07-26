<?php
/**
 * Series Index (系列總覽頁)
 * Path: wp-content/plugins/anime-sync-pro/includes/class-series-index.php
 * Version: 1.0.1 (2026-07-20)
 *
 * 功能：讓 /series/ 根目錄（taxonomy archive 的上層）能顯示一個
 *      「所有系列總覽頁」。WordPress 原生對 taxonomy 根目錄沒有模板，
 *      因此用自訂 rewrite rule + query var + template_include 攔截。
 *
 * Changelog:
 *   1.0.1 (2026-07-20)
 *     - [修正] 移除檔尾自動 init()，改由主外掛 anime-sync-pro.php 的
 *              plugins_loaded 統一初始化，避免 hook 重複註冊。
 *   1.0.0 (2026-07-20)
 *     - [新增] /series/ rewrite rule + template_include。
 *
 * 注意：改版後主外掛版本號會 +1，init(priority 99) 會自動 flush rewrite，
 *      通常不需手動執行 wp rewrite flush。
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class Anime_Sync_Series_Index {

    const QUERY_VAR = 'asa_series_index';
    const SLUG      = 'series';   // 與 anime_series_tax 的 rewrite slug 一致

    public static function init() {
        add_action( 'init',             [ __CLASS__, 'add_rewrite' ] );
        add_filter( 'query_vars',       [ __CLASS__, 'add_query_var' ] );
        add_filter( 'template_include', [ __CLASS__, 'load_template' ] );
    }

    /** 註冊 /series/ 的 rewrite rule */
    public static function add_rewrite() {
        add_rewrite_rule(
            '^' . self::SLUG . '/?$',
            'index.php?' . self::QUERY_VAR . '=1',
            'top'
        );
    }

    public static function add_query_var( $vars ) {
        $vars[] = self::QUERY_VAR;
        return $vars;
    }

    /** 命中 query var 時載入總覽模板 */
    public static function load_template( $template ) {
        if ( (int) get_query_var( self::QUERY_VAR ) === 1 ) {

            // 優先讀子主題，其次外掛內建
            $child = get_stylesheet_directory() . '/series-index.php';
            if ( file_exists( $child ) ) {
                return $child;
            }
            $plugin = plugin_dir_path( __FILE__ ) . '../public/templates/series-index.php';
            if ( file_exists( $plugin ) ) {
                return $plugin;
            }
        }
        return $template;
    }
}

// ⚠️ v1.0.1：已移除檔尾的 Anime_Sync_Series_Index::init();
// 初始化改由主外掛 plugins_loaded 統一呼叫，避免重複註冊 hook。