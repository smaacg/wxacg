<?php
/**
 * Plugin Name: WXACG Gamification
 * Plugin URI:  https://github.com/wxacg/hostingerphp8.3wordpress7.0
 * Description: weixiaoacg 站點的等級、經驗值、徽章、排行榜、季賽事件、TFT 排位賽季系統。
 * Version: 2.8.0
 * Author:      wxacg
 * Text Domain: wxacg-gamification
 *
 * v2.8.0 (2026-05-26)
 *   - 補上 WXACG_MAX_LEVEL 常數定義（=200），修正 Level_System::calc_level_from_exp()
 *     與 get_level_table() 因常數未定義導致的 PHP 8 警告
 *   - Career_Jobs 擴充至 9 職業 × 5 階稱號
 *     新增第 9 職業：craft（製造／藍領／工程）
 *     新增五轉里程碑：Lv.170 「傳說稱號」
 *
 * v2.6.5 (2026-05-21)
 *   - Refactor：EXP 改為純累計型，廢除 GamiPress balance（B）概念
 *     Gamipress_Bridge::get_user_exp() 改讀 _gamipress_exp_points_awarded
 *     Gamipress_Bridge::deduct_exp() 停用（永遠 return false）
 *   - 徽章 / 賽季積分（C）/ Exp_Events 加分流程皆不受影響
 *
 * v2.6.1 (2026-05-17)
 *   - WXACG_RANKING_TYPES 新增 'rank_last_season'（上季牌位排行）
 */
defined( 'ABSPATH' ) || exit;

define( 'WXACG_GAMIFY_VERSION', '2.8.0' );
define( 'WXACG_GAMIFY_FILE',    __FILE__ );
define( 'WXACG_GAMIFY_DIR',     plugin_dir_path( __FILE__ ) );
define( 'WXACG_GAMIFY_URL',     plugin_dir_url( __FILE__ ) );
define( 'WXACG_GAMIFY_BASENAME', plugin_basename( __FILE__ ) );

/* ─────────────────────────────────────────────────────────
 * 等級系統常數（v2.8.0 補上）
 * WXACG_MAX_LEVEL：等級上限，對應 Level_System sqrt 公式
 * ───────────────────────────────────────────────────────── */
if ( ! defined( 'WXACG_MAX_LEVEL' ) )   define( 'WXACG_MAX_LEVEL', 200 );

if ( ! defined( 'WXACG_EXP_SLUG' ) )   define( 'WXACG_EXP_SLUG',   'exps' );
if ( ! defined( 'WXACG_BADGE_SLUG' ) ) define( 'WXACG_BADGE_SLUG', 'achievements' );

if ( ! defined( 'WXACG_EVENT_CPT' ) )        define( 'WXACG_EVENT_CPT',        'wxacg_season_event' );
if ( ! defined( 'WXACG_EVENT_DB_VERSION' ) ) define( 'WXACG_EVENT_DB_VERSION', '1.0.0' );

if ( ! defined( 'WXACG_RANKING_DB_VERSION' ) ) define( 'WXACG_RANKING_DB_VERSION', '1.0.0' );
if ( ! defined( 'WXACG_RANKING_TOP_N' ) )      define( 'WXACG_RANKING_TOP_N',      100 );
if ( ! defined( 'WXACG_RANKING_PAGE_SIZE' ) )  define( 'WXACG_RANKING_PAGE_SIZE',  20 );
if ( ! defined( 'WXACG_RANKING_TYPES' ) )      define( 'WXACG_RANKING_TYPES',      [ 'exp_total', 'exp_monthly', 'followers', 'achievements', 'rank_season', 'rank_last_season' ] );
if ( ! defined( 'WXACG_RANKING_META_KEY' ) )   define( 'WXACG_RANKING_META_KEY',   'wxacg_appear_in_ranking' );

if ( ! defined( 'WXACG_RANK_SEASON_DB_VERSION' ) ) define( 'WXACG_RANK_SEASON_DB_VERSION', '1.0.0' );

add_filter( 'cron_schedules', function ( $schedules ) {
    if ( ! isset( $schedules['wxacg_10min'] ) ) {
        $schedules['wxacg_10min'] = [ 'interval' => 600, 'display' => __( 'Every 10 Minutes', 'wxacg-gamification' ) ];
    }
    return $schedules;
} );

register_activation_hook( __FILE__, function () {
    require_once WXACG_GAMIFY_DIR . 'includes/class-activator.php';
    require_once WXACG_GAMIFY_DIR . 'includes/ranking/class-rank-tier.php';
    \WXACG\Gamification\Activator::run();
} );

register_deactivation_hook( __FILE__, function () {
    require_once WXACG_GAMIFY_DIR . 'includes/class-deactivator.php';
    \WXACG\Gamification\Deactivator::run();
} );

require_once WXACG_GAMIFY_DIR . 'includes/class-plugin.php';
add_action( 'plugins_loaded', [ '\WXACG\Gamification\Plugin', 'instance' ], 5 );

add_action( 'admin_notices', function () {
    if ( ! current_user_can( 'activate_plugins' ) ) return;
    $msgs = [];
    if ( ! function_exists( 'gamipress_get_user_points' ) ) {
        $msgs[] = '偵測不到 <strong>GamiPress</strong> 外掛，EXP / 徽章功能將降級為 user_meta fallback。';
    }
    if ( defined( 'weixiaoacg_VERSION' ) && version_compare( weixiaoacg_VERSION, '2.12.0', '<' ) ) {
        $msgs[] = sprintf( '主題 <strong>weixiaoacg</strong> 版本為 %s，需升級到 ≥ 2.12.0。', esc_html( weixiaoacg_VERSION ) );
    }
    if ( empty( $msgs ) ) return;
    echo '<div class="notice notice-warning"><p><strong>SMACG Gamification：</strong></p><ul style="margin-left:20px;list-style:disc;">';
    foreach ( $msgs as $m ) echo '<li>' . wp_kses_post( $m ) . '</li>';
    echo '</ul></div>';
} );
