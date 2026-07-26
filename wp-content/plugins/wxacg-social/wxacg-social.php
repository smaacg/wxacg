<?php
/**
 * Plugin Name:       WXACG Social
 * Plugin URI:        https://github.com/wxacg/hostingerphp8.3wordpress7.0
 * Description:       社交功能：追蹤系統、通知中心、公開檔案。從 blocksy-child v2.14.0 拆分而來。
 * Version:           1.2.0
 * Requires at least: 6.4
 * Requires PHP:      8.0
 * Author:            微笑動漫
 * Author URI:        https://smile-acg.com
 * License:           GPL v2 or later
 * Text Domain:       wxacg-social
 *
 * Changelog:
 * - 1.2.0 (2026-05-26)
 *     * 版本常數升版至 1.2.0，觸發 public-profile.php 自動 flush rewrite
 *     * admin_notices 加 is-dismissible
 * - 1.0.0 (2026-05-15)
 *     * Phase 3-B 遷移：從 blocksy-child v2.14.0 拆分
 *
 * 軟依賴：
 *   - wxacg-members（提供 weixiaoacg_get_user_level_int / wxacg_get_member_center_url）
 *   - wxacg-gamification（提供 wxacg_get_user_level_info；負責 badge 通知）
 *   - Ultimate Member 外掛（public-profile 會用 um_user 取資料）
 *
 * 反向依賴：
 *   - wxacg_create_notification()           → 被 wxacg-gamification 呼叫
 *   - wxacg_get_public_profile_url()        → 被多處呼叫
 *   - wxacg_follow_user / wxacg_is_following → 被 public-profile-render 呼叫
 *
 * 資料表：
 *   - {prefix}wxacg_follows         追蹤關係
 *   - {prefix}wxacg_notifications   通知記錄
 *
 * Cron 排程：
 *   - wxacg_notifications_daily_purge  每日凌晨 3:00 清除 30 天前通知
 *   - smacg_notif_email_daily          每日 20:00 寄送 daily digest
 *   - smacg_notif_email_weekly         每週一 20:00 寄送 weekly digest
 */

defined( 'ABSPATH' ) || exit;

/* ============================================================
   常數
   ============================================================ */
if ( ! defined( 'WXACG_SOCIAL_VERSION' ) )  define( 'WXACG_SOCIAL_VERSION',  '1.2.0' );
if ( ! defined( 'WXACG_SOCIAL_FILE' ) )     define( 'WXACG_SOCIAL_FILE',     __FILE__ );
if ( ! defined( 'WXACG_SOCIAL_DIR' ) )      define( 'WXACG_SOCIAL_DIR',      plugin_dir_path( __FILE__ ) );
if ( ! defined( 'WXACG_SOCIAL_URL' ) )      define( 'WXACG_SOCIAL_URL',      plugin_dir_url( __FILE__ ) );
if ( ! defined( 'WXACG_SOCIAL_BASENAME' ) ) define( 'WXACG_SOCIAL_BASENAME', plugin_basename( __FILE__ ) );

/* ============================================================
   啟用 / 停用
   ============================================================ */
register_activation_hook( __FILE__, function() {
    require_once WXACG_SOCIAL_DIR . 'includes/class-activator.php';
    \WXACG\Social\Activator::run();
} );

register_deactivation_hook( __FILE__, function() {
    require_once WXACG_SOCIAL_DIR . 'includes/class-deactivator.php';
    \WXACG\Social\Deactivator::run();
} );

/* ============================================================
   Bootstrap（priority 12：在 wxacg-members=10 之後）
   ============================================================ */
add_action( 'plugins_loaded', function() {
    require_once WXACG_SOCIAL_DIR . 'includes/class-plugin.php';
    \WXACG\Social\Plugin::instance();
}, 12 );

/* ============================================================
   健康檢查
   ============================================================ */
add_action( 'admin_notices', function() {
    if ( ! current_user_can( 'manage_options' ) ) return;

    $missing = [];

    if ( ! defined( 'WXACG_FOLLOW_DAILY_LIMIT' ) ) {
        $missing[] = 'WXACG_FOLLOW_DAILY_LIMIT（請在主題 functions.php 定義）';
    }
    if ( ! defined( 'WXACG_FOLLOW_COOLDOWN' ) ) {
        $missing[] = 'WXACG_FOLLOW_COOLDOWN（請在主題 functions.php 定義）';
    }

    if ( empty( $missing ) ) return;

    echo '<div class="notice notice-error is-dismissible"><p><strong>SMACG Social：</strong>偵測到下列常數未定義，追蹤系統將無法正常運作：</p><ul style="list-style:disc;margin-left:24px">';
    foreach ( $missing as $m ) echo '<li>' . esc_html( $m ) . '</li>';
    echo '</ul></div>';
} );
