<?php
/**
 * Series Index (系列總覽頁)
 * Path: wp-content/plugins/anime-sync-pro/includes/class-series-index.php
 * Version: 1.1.1 (2026-09-03)
 *
 * 功能：讓 /series/ 根目錄（taxonomy archive 的上層）能顯示一個
 *      「所有系列總覽頁」。WordPress 原生對 taxonomy 根目錄沒有模板，
 *      因此用自訂 rewrite rule + query var + template_include 攔截。
 *
 * Changelog:
 *   1.1.1 (2026-09-03)
 *     - [修正] 補上 description filter（1.1.0 只修了 title 與 canonical，
 *              description 仍與首頁一字不差）。meta / og / twitter 三個
 *              描述欄位各走各的取值路徑，都要掛。
 *   1.1.0 (2026-09-03)
 *     - [修正] 補上 canonical / og:url filter。虛擬頁被 WordPress 判定為首頁，
 *              Rank Math 把 canonical 覆寫成首頁網址，本頁因此無法進索引。
 *     - [修正] 補上 <title>。原本沿用首頁標題（與首頁一字不差），
 *              本身就是重複標題問題；改為與頁面 H1「全部系列總覽」一致。
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

        /* 虛擬頁沒有主查詢，標題與 canonical 都會沿用首頁的，必須自己覆寫 */
        add_filter( 'pre_get_document_title',       [ __CLASS__, 'filter_title' ],     99 );
        add_filter( 'rank_math/frontend/title',     [ __CLASS__, 'filter_title' ],     99 );
        add_filter( 'rank_math/frontend/canonical', [ __CLASS__, 'filter_canonical' ], 99 );
        add_filter( 'rank_math/opengraph/url',      [ __CLASS__, 'filter_canonical' ], 99 );

        /* 三個描述欄位各走各的路徑，og/twitter 不會繼承 meta description */
        add_filter( 'rank_math/frontend/description',           [ __CLASS__, 'filter_description' ], 99 );
        add_filter( 'rank_math/opengraph/facebook/description', [ __CLASS__, 'filter_description' ], 99 );
        add_filter( 'rank_math/opengraph/twitter/description',  [ __CLASS__, 'filter_description' ], 99 );
    }

    /** 目前請求是不是 /series/ 總覽頁 */
    public static function is_series_index() {
        return (int) get_query_var( self::QUERY_VAR ) === 1;
    }

    /**
     * 本頁原本的 <title> 與首頁完全相同（「微笑動漫 | 動漫新聞、新番情報與動漫評論」），
     * 那是重複標題。改成與頁面 H1「全部系列總覽」一致。
     */
    public static function filter_title( $title ) {

        if ( ! self::is_series_index() ) {
            return $title;
        }

        return '全部系列總覽 - ' . get_bloginfo( 'name' );
    }

    /**
     * canonical / og:url 指向本頁自己。
     *
     * 原因與寫法同 class-streaming-routing.php::filter_canonical()：
     * WordPress 把虛擬頁判定為首頁，Rank Math 於是把 canonical 覆寫成首頁網址，
     * 等同宣告本頁是首頁的複本。優先度 99、參數不宣告型別、取不到就退回原值，
     * 均比照 class-subview-routing.php 的既有實作。
     */
    public static function filter_canonical( $canonical ) {

        if ( ! self::is_series_index() ) {
            return $canonical;
        }

        $url = home_url( '/' . self::SLUG . '/' );

        return is_string( $url ) && '' !== $url ? $url : $canonical;
    }

    /**
     * meta description / og:description / twitter:description。
     *
     * 原因同 filter_canonical()：虛擬頁被判定為首頁，Rank Math 套用首頁描述，
     * 本頁描述因此與首頁一字不差。og 與 twitter 不會從 meta description
     * 繼承（各自走 class-opengraph.php 的 get_description()），三個都要掛。
     */
    public static function filter_description( $description ) {

        if ( ! self::is_series_index() ) {
            return $description;
        }

        return '微笑動漫全部動畫系列總覽，依系列彙整續作、前傳、外傳與衍生作品，'
            . '快速掌握一個系列的完整作品與觀看順序。';
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