<?php
/**
 * Plugin Name:       WXACG API
 * Plugin URI:        https://github.com/wxacg/hostingerphp8.3wordpress7.0
 * Description:       微笑動漫 — REST API、內容 slug 翻譯、外部連結處理。從 blocksy-child 子主題抽離。
 * Version:           1.0.0
 * Requires at least: 6.4
 * Requires PHP:      8.0
 * Author:            微笑動漫
 * Text Domain:       wxacg-api
 * Domain Path:       /languages
 *
 * @package WxacgApi
 *
 * Phase 1 搬遷說明：
 *   本外掛抽離自 blocksy-child v2.7.3 的以下檔案：
 *     - inc/api-rest.php          → includes/class-rest-routes.php
 *     - inc/content-slug.php      → includes/class-content-slug.php
 *     - inc/external-links.php    → includes/class-external-links.php
 *
 *   REST namespace 維持：
 *     - weixiaoacg/v1/ranking
 *     - weixiaoacg/v1/user/favorites
 *     - weixiaoacg/v1/anime-url
 *
 *   註：/smacg/v1/user-level 由 wxacg-gamification 外掛提供，不在本外掛範圍。
 *
 *   常數 WEIXIAOACG_GEMINI_API_KEY、WEIXIAOACG_ID_CATS、WEIXIAOACG_LLM_CATS
 *   仍由 blocksy-child/functions.php 或 wp-config.php 定義，本外掛不重複定義。
 */

defined( 'ABSPATH' ) || exit;

/* ============================================================
   常數定義
   ============================================================ */
define( 'WXACG_API_VERSION',  '1.0.0' );
define( 'WXACG_API_FILE',     __FILE__ );
define( 'WXACG_API_DIR',      plugin_dir_path( __FILE__ ) );
define( 'WXACG_API_URL',      plugin_dir_url( __FILE__ ) );
define( 'WXACG_API_BASENAME', plugin_basename( __FILE__ ) );

/* ============================================================
   Autoloader（簡易 PSR-4 風格 / Wxacg_Api_* → class-*.php）
   ============================================================ */
spl_autoload_register( function ( $class ) {
    if ( strpos( $class, 'Wxacg_Api_' ) !== 0 ) {
        return;
    }
    $file = 'class-' . strtolower(
        str_replace( [ 'Wxacg_Api_', '_' ], [ '', '-' ], $class )
    ) . '.php';
    $path = WXACG_API_DIR . 'includes/' . $file;
    if ( file_exists( $path ) ) {
        require_once $path;
    }
} );

/* ============================================================
   Bootstrap
   ------------------------------------------------------------
   priority 5：早於 blocksy-child（after_setup_theme 預設 10），
              讓 REST 路由和 the_content filter 提前註冊。
   ============================================================ */
add_action( 'plugins_loaded', function () {
    if ( class_exists( 'Wxacg_Api_Plugin' ) ) {
        Wxacg_Api_Plugin::instance()->init();
    }
}, 5 );

/* ============================================================
   啟用 / 停用 Hook
   ============================================================ */
register_activation_hook( __FILE__, function () {
    // 預留：未來如需建表或寫入預設 option，可放這裡
    update_option( 'wxacg_api_activated_at', current_time( 'mysql' ) );
    flush_rewrite_rules();
} );

register_deactivation_hook( __FILE__, function () {
    flush_rewrite_rules();
} );
