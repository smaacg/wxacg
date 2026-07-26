<?php
/**
 * Plugin Name:       WXACG Members
 * Plugin URI:        https://github.com/wxacg/hostingerphp8.3wordpress7.0
 * Description:       會員中心核心：使用者資料、統計、渲染、AJAX、Ultimate Member 整合。從 blocksy-child v2.7.3 拆分而來。
 * Version:           1.0.0
 * Requires at least: 6.4
 * Requires PHP:      8.0
 * Author:            微笑動漫
 * Author URI:        https://smile-acg.com
 * License:           GPL v2 or later
 * Text Domain:       wxacg-members
 *
 * Phase 3 遷移（2026-05-15）：
 *   來源：blocksy-child v2.7.3
 *     - inc/member-functions.php  → includes/legacy/member-functions.php
 *     - inc/member-stats.php      → includes/legacy/member-stats.php
 *     - inc/member-render.php     → includes/legacy/member-render.php
 *     - inc/member-ajax.php       → includes/legacy/member-ajax.php
 *     - inc/um-integration.php    → includes/legacy/um-integration.php
 *
 *   遷移策略：薄包裝（Thin Wrapper）
 *     - 既有 weixiaoacg_*() / smacg_*() 函式名稱、簽名、行為完全保留
 *     - Plugin 類只負責載入時機與健康檢查
 *     - 既有前端 JS / 頁面模板 / 其他模組 0 改動
 *
 *   軟依賴：
 *     - wxacg-gamification（提供 wxacg_get_user_level_info / wxacg_award_exp）
 *     - wxacg-social（提供 wxacg_get_public_profile_url / 追蹤計數）
 *     - Ultimate Member 外掛（um-integration 模組會檢查存在性後才掛 hook）
 *     - GamiPress 外掛（透過 wxacg-gamification 間接依賴）
 */

defined( 'ABSPATH' ) || exit;

/* ============================================================
   常數
   ============================================================ */
if ( ! defined( 'WXACG_MEMBERS_VERSION' ) )  define( 'WXACG_MEMBERS_VERSION',  '1.0.0' );
if ( ! defined( 'WXACG_MEMBERS_FILE' ) )     define( 'WXACG_MEMBERS_FILE',     __FILE__ );
if ( ! defined( 'WXACG_MEMBERS_DIR' ) )      define( 'WXACG_MEMBERS_DIR',      plugin_dir_path( __FILE__ ) );
if ( ! defined( 'WXACG_MEMBERS_URL' ) )      define( 'WXACG_MEMBERS_URL',      plugin_dir_url( __FILE__ ) );
if ( ! defined( 'WXACG_MEMBERS_BASENAME' ) ) define( 'WXACG_MEMBERS_BASENAME', plugin_basename( __FILE__ ) );

/* ============================================================
   啟用 / 停用
   ============================================================ */
register_activation_hook( __FILE__, function() {
    require_once WXACG_MEMBERS_DIR . 'includes/class-activator.php';
    \WXACG\Members\Activator::run();
} );

register_deactivation_hook( __FILE__, function() {
    require_once WXACG_MEMBERS_DIR . 'includes/class-deactivator.php';
    \WXACG\Members\Deactivator::run();
} );

/* ============================================================
   Bootstrap（priority 10：在 wxacg-api=5、wxacg-gamification=8 之後）
   ============================================================ */
add_action( 'plugins_loaded', function() {
    require_once WXACG_MEMBERS_DIR . 'includes/class-plugin.php';
    \WXACG\Members\Plugin::instance();
}, 10 );

/* ============================================================
   健康檢查：缺少建議外掛時顯示 admin notice（非阻擋）
   ============================================================ */
add_action( 'admin_notices', function() {
    if ( ! current_user_can( 'manage_options' ) ) return;

    $missing = [];

    // 軟依賴：wxacg-gamification 提供等級 / EXP API
    if ( ! function_exists( 'wxacg_get_user_level_info' ) && ! function_exists( 'wxacg_award_exp' ) ) {
        $missing[] = 'SMACG Gamification（等級與 EXP 系統）';
    }

    if ( empty( $missing ) ) return;

    echo '<div class="notice notice-warning"><p><strong>SMACG Members：</strong>偵測到下列建議搭配的外掛尚未啟用，部分功能將以降級模式運作：</p><ul style="list-style:disc;margin-left:24px">';
    foreach ( $missing as $m ) {
        echo '<li>' . esc_html( $m ) . '</li>';
    }
    echo '</ul></div>';
} );
