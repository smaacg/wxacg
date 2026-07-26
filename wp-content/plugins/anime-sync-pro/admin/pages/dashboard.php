<?php
/**
 * Dashboard Page
 *
 * @package Anime_Sync_Pro
 * @version 1.3.1
 *
 * Changelog:
 *  - 1.3.1 (2026-07-20):
 *      • 修正 media query 與 grid 函式語法：移除括號內外空格
 *        （( max-width )→(max-width)、repeat( 4, 1fr )→repeat(4, 1fr)），
 *        原語法在部分環境下失效，導致日誌表格與卡片同時顯示、統計卡片欄數異常。
 *      • 桌面/手機切換改用雙 class 選擇器 + !important 強制互斥。
 *      • 日誌區改用 min-width 正向宣告桌面樣式，避免版型重複。
 *  - 1.3.0 (2026-06-27):
 *      • 手機板響應式全面優化。
 *      • 統計卡片改為 2 欄 grid，超窄螢幕 1 欄。
 *      • 快速操作按鈕改為 2 欄 grid。
 *      • 系統資訊表格在手機板改為 block 堆疊佈局。
 *      • 日誌表格在手機板隱藏時間欄，訊息截斷顯示。
 *      • 繁簡轉換器表格在手機板改為 block 堆疊。
 *      • Cron 排程列表在手機板垂直堆疊。
 *      • Toast / 標題 / 整體 padding 在手機板最佳化。
 *  - 1.2.4 (2026-06-24):
 *      • [Fix-Time] 日誌時間欄位移除 wp_date() 時區轉換。
 *      • [Fix-Time] 上次升級時間同步修正。
 *      • [Fix-Version] 系統資訊新增 Cron Manager 版本對照列。
 *  - 1.2.3 (2026-06-18):
 *      • [Fix-M3] DB 版本 fallback option 名稱修正。
 *  - 1.2.2 (2026-06-18):
 *      • [Fix-M3] DB 版本不再永遠顯示「—」。
 *  - 1.1.0 (2026-05-10):
 *      • 版本常數判讀、時區、千分位、空狀態、系統資訊等優化。
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! current_user_can( 'manage_options' ) ) {
    wp_die( esc_html__( '您沒有權限存取此頁面。', 'anime-sync-pro' ) );
}

/* ───────────────────────────────────────────────
   統計資料
─────────────────────────────────────────────── */
$pending_count  = 0;
$approved_count = 0;

if ( class_exists( 'Anime_Sync_Review_Queue' ) ) {
    $review_queue   = new Anime_Sync_Review_Queue();
    $pending_count  = method_exists( $review_queue, 'get_count' ) ? (int) $review_queue->get_count( 'pending' )  : 0;
    $approved_count = method_exists( $review_queue, 'get_count' ) ? (int) $review_queue->get_count( 'approved' ) : 0;
}

$published_count = wp_count_posts( 'anime' );
$published_total = isset( $published_count->publish ) ? (int) $published_count->publish : 0;
$draft_total     = isset( $published_count->draft )   ? (int) $published_count->draft   : 0;

$log_stats = array(
    'total'    => 0,
    'info'     => 0,
    'warning'  => 0,
    'error'    => 0,
    'critical' => 0,
);
$recent_logs = array();
if ( class_exists( 'Anime_Sync_Error_Logger' ) ) {
    $logger      = new Anime_Sync_Error_Logger();
    $log_stats   = $logger->get_statistics( 7 );
    $recent_logs = $logger->get_recent_logs( 10 );
}

/* ───────────────────────────────────────────────
   版本字串
─────────────────────────────────────────────── */
if ( defined( 'ANIME_SYNC_PRO_VERSION' ) ) {
    $plugin_version = ANIME_SYNC_PRO_VERSION;
} elseif ( defined( 'ANIME_SYNC_VERSION' ) ) {
    $plugin_version = ANIME_SYNC_VERSION;
} else {
    $plugin_version = '1.0.0';
}

/* ───────────────────────────────────────────────
   DB 版本
─────────────────────────────────────────────── */
$db_version = get_option( 'anime_sync_db_version', '' );
if ( $db_version === '' || $db_version === '—' ) {
    $fallback = get_option( 'anime_sync_version', '' );
    if ( $fallback === '' ) {
        $fallback = $plugin_version;
    }
    $db_version          = $fallback;
    $db_version_is_guess = true;
} else {
    $db_version_is_guess = false;
}

/* ───────────────────────────────────────────────
   上次升級時間
─────────────────────────────────────────────── */
$last_upgrade     = get_option( 'anime_sync_last_upgrade_at', '' );
$last_upgrade_str = $last_upgrade ? $last_upgrade : '—';

/* ───────────────────────────────────────────────
   等級設定
─────────────────────────────────────────────── */
$level_config = array(
    'info'     => array( 'label' => '資訊', 'color' => '#0073aa', 'bg' => '#e8f4fd' ),
    'warning'  => array( 'label' => '警告', 'color' => '#f0a500', 'bg' => '#fff8e1' ),
    'error'    => array( 'label' => '錯誤', 'color' => '#dc3232', 'bg' => '#fce8e8' ),
    'critical' => array( 'label' => '嚴重', 'color' => '#8b0000', 'bg' => '#f9e0e0' ),
);
?>

<div class="wrap asc-dash-wrap">

    <div class="asc-dash-header">
        <h1>Anime Sync Pro 儀表板
            <span class="asc-dash-version">v<?php echo esc_html( $plugin_version ); ?></span>
        </h1>
    </div>

    <!-- ───────────────── Stats Grid ───────────────── -->
    <div class="asc-stats-grid">

        <div class="asc-stat-card asc-stat-card--pending">
            <div class="asc-stat-icon">
                <span class="dashicons dashicons-clock"></span>
            </div>
            <div class="asc-stat-content">
                <div class="asc-stat-label">待審核</div>
                <div class="asc-stat-number"><?php echo esc_html( number_format_i18n( $pending_count ) ); ?></div>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=anime-sync-queue' ) ); ?>"
                   class="asc-stat-link">查看佇列 →</a>
            </div>
        </div>

        <div class="asc-stat-card asc-stat-card--approved">
            <div class="asc-stat-icon">
                <span class="dashicons dashicons-yes-alt"></span>
            </div>
            <div class="asc-stat-content">
                <div class="asc-stat-label">已通過</div>
                <div class="asc-stat-number"><?php echo esc_html( number_format_i18n( $approved_count ) ); ?></div>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=anime-sync-queue&filter_status=draft' ) ); ?>"
                   class="asc-stat-link">查看已通過 →</a>
            </div>
        </div>

        <div class="asc-stat-card asc-stat-card--published">
            <div class="asc-stat-icon">
                <span class="dashicons dashicons-megaphone"></span>
            </div>
            <div class="asc-stat-content">
                <div class="asc-stat-label">已發布</div>
                <div class="asc-stat-number"><?php echo esc_html( number_format_i18n( $published_total ) ); ?></div>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=anime-sync-published' ) ); ?>"
                   class="asc-stat-link">查看動漫 →</a>
            </div>
        </div>

        <div class="asc-stat-card asc-stat-card--error">
            <div class="asc-stat-icon">
                <span class="dashicons dashicons-warning"></span>
            </div>
            <div class="asc-stat-content">
                <div class="asc-stat-label">錯誤 (7天)</div>
                <div class="asc-stat-number">
                    <?php echo esc_html( number_format_i18n( (int) $log_stats['error'] + (int) $log_stats['critical'] ) ); ?>
                </div>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=anime-sync-logs' ) ); ?>"
                   class="asc-stat-link">查看日誌 →</a>
            </div>
        </div>

    </div>

    <!-- ───────────────── 空狀態 ───────────────── -->
    <?php if ( $published_total === 0 && $draft_total === 0 ) : ?>
        <div class="notice notice-info inline asc-empty-notice">
            <p>
                <strong>👋 還沒匯入任何動漫</strong>，建議從匯入工具開始：
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=anime-sync-import' ) ); ?>"
                   class="button button-primary asc-empty-btn">立即匯入</a>
            </p>
        </div>
    <?php endif; ?>

    <!-- ───────────────── 快速操作 ───────────────── -->
    <div class="asc-section">
        <h2 class="asc-section-title">⚡ 快速操作</h2>
        <div class="asc-quick-grid">
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=anime-sync-import' ) ); ?>"
               class="asc-quick-btn">
                <span class="dashicons dashicons-download"></span>
                <span>匯入動漫</span>
            </a>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=anime-sync-queue' ) ); ?>"
               class="asc-quick-btn">
                <span class="dashicons dashicons-list-view"></span>
                <span>審核佇列</span>
            </a>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=anime-sync-settings' ) ); ?>"
               class="asc-quick-btn">
                <span class="dashicons dashicons-admin-settings"></span>
                <span>設定</span>
            </a>
            <a href="<?php echo esc_url( admin_url( 'edit.php?post_type=anime' ) ); ?>"
               class="asc-quick-btn">
                <span class="dashicons dashicons-edit"></span>
                <span>管理動漫</span>
            </a>
        </div>
    </div>

    <!-- ───────────────── 繁簡轉換器 ───────────────── -->
    <div class="asc-section">
        <h2 class="asc-section-title">🔤 繁簡轉換器狀態</h2>
        <div class="asc-section-body">
            <?php if ( class_exists( 'Anime_Sync_CN_Converter' ) ) : ?>
                <?php
                $cn_converter = new Anime_Sync_CN_Converter();
                $stats        = method_exists( $cn_converter, 'get_stats' ) ? $cn_converter->get_stats() : array();
                $test_cn      = '动画制作、脚本、监督、角色设计';
                $test_tw      = $cn_converter->convert( $test_cn );
                $is_working   = ( $test_cn !== $test_tw );
                ?>
                <table class="wp-list-table widefat fixed asc-info-table">
                    <tbody>
                        <tr>
                            <th>字典路徑</th>
                            <td><code class="asc-code-wrap"><?php echo esc_html( isset( $stats['dict_path'] ) ? $stats['dict_path'] : '—' ); ?></code></td>
                        </tr>
                        <tr>
                            <th>檔案狀態</th>
                            <td>
                                <?php if ( ! empty( $stats['dict_file_size'] ) && $stats['dict_file_size'] > 0 ) : ?>
                                    <span class="asc-status-ok">✓ 存在 (<?php echo esc_html( size_format( $stats['dict_file_size'] ) ); ?>)</span>
                                <?php else : ?>
                                    <span class="asc-status-err">✗ 不存在或為空</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <th>詞條總數</th>
                            <td><?php echo esc_html( number_format_i18n( (int) ( isset( $stats['dict_entry_count'] ) ? $stats['dict_entry_count'] : 0 ) ) ); ?> 條</td>
                        </tr>
                        <tr>
                            <th>轉換模式</th>
                            <td>
                                <span class="asc-mode-badge">
                                    <?php echo esc_html( isset( $stats['mode'] ) ? $stats['mode'] : 'dict' ); ?>
                                </span>
                            </td>
                        </tr>
                        <tr>
                            <th>轉換測試</th>
                            <td>
                                <div class="asc-convert-test">
                                    <div class="asc-convert-row">
                                        <span class="asc-convert-label">原文</span>
                                        <code><?php echo esc_html( $test_cn ); ?></code>
                                    </div>
                                    <div class="asc-convert-row">
                                        <span class="asc-convert-label">結果</span>
                                        <strong><?php echo esc_html( $test_tw ); ?></strong>
                                    </div>
                                    <div class="asc-convert-row">
                                        <span class="asc-convert-label">狀態</span>
                                        <?php if ( $is_working ) : ?>
                                            <span class="asc-status-ok">✓ 運作正常</span>
                                        <?php else : ?>
                                            <span class="asc-status-err">✗ 轉換無效（請檢查字典內容）</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            <?php else : ?>
                <p class="asc-status-err">⚠️ 找不到 <code>Anime_Sync_CN_Converter</code> 類別。</p>
            <?php endif; ?>
        </div>
    </div>

    <!-- ───────────────── 最近日誌 ───────────────── -->
    <div class="asc-section">
        <h2 class="asc-section-title">📋 最近日誌 (7天)</h2>
        <div class="asc-log-summary">
            <span class="asc-log-pill asc-log-pill--info">ℹ️ <?php echo esc_html( $log_stats['info'] ?? 0 ); ?></span>
            <span class="asc-log-pill asc-log-pill--warning">⚠️ <?php echo esc_html( $log_stats['warning'] ?? 0 ); ?></span>
            <span class="asc-log-pill asc-log-pill--error">❌ <?php echo esc_html( $log_stats['error'] ?? 0 ); ?></span>
            <span class="asc-log-pill asc-log-pill--critical">🔥 <?php echo esc_html( $log_stats['critical'] ?? 0 ); ?></span>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=anime-sync-logs' ) ); ?>"
               class="button button-small asc-log-more">查看全部</a>
        </div>

        <!-- 桌面表格 -->
        <table class="wp-list-table widefat fixed striped asc-log-table asc-desktop-only">
            <thead>
                <tr>
                    <th class="asc-col-level">等級</th>
                    <th>訊息</th>
                    <th class="asc-col-time">時間</th>
                </tr>
            </thead>
            <tbody>
                <?php if ( ! empty( $recent_logs ) ) : ?>
                    <?php foreach ( $recent_logs as $log ) :
                        $lv  = isset( $log['level'] ) ? $log['level'] : 'info';
                        $cfg = isset( $level_config[ $lv ] ) ? $level_config[ $lv ] : array( 'label' => strtoupper( $lv ), 'color' => '#555', 'bg' => '#f5f5f5' );
                    ?>
                        <tr>
                            <td>
                                <span class="asc-level-badge"
                                      style="color:<?php echo esc_attr( $cfg['color'] ); ?>;background:<?php echo esc_attr( $cfg['bg'] ); ?>;">
                                    <?php echo esc_html( $cfg['label'] ); ?>
                                </span>
                            </td>
                            <td class="asc-log-message"><?php echo esc_html( isset( $log['message'] ) ? $log['message'] : '' ); ?></td>
                            <td class="asc-log-time"><?php echo esc_html( isset( $log['created_at'] ) ? $log['created_at'] : '—' ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else : ?>
                    <tr>
                        <td colspan="3" class="asc-empty-cell">尚無日誌記錄</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>

        <!-- 手機卡片 -->
        <div class="asc-log-cards asc-mobile-only">
            <?php if ( ! empty( $recent_logs ) ) : ?>
                <?php foreach ( $recent_logs as $log ) :
                    $lv  = isset( $log['level'] ) ? $log['level'] : 'info';
                    $cfg = isset( $level_config[ $lv ] ) ? $level_config[ $lv ] : array( 'label' => strtoupper( $lv ), 'color' => '#555', 'bg' => '#f5f5f5' );
                ?>
                    <div class="asc-log-card asc-log-card--<?php echo esc_attr( $lv ); ?>">
                        <div class="asc-log-card-header">
                            <span class="asc-level-badge"
                                  style="color:<?php echo esc_attr( $cfg['color'] ); ?>;background:<?php echo esc_attr( $cfg['bg'] ); ?>;">
                                <?php echo esc_html( $cfg['label'] ); ?>
                            </span>
                            <span class="asc-log-card-time">
                                <?php echo esc_html( isset( $log['created_at'] ) ? $log['created_at'] : '—' ); ?>
                            </span>
                        </div>
                        <div class="asc-log-card-msg">
                            <?php echo esc_html( isset( $log['message'] ) ? $log['message'] : '' ); ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else : ?>
                <p class="asc-empty-cell">尚無日誌記錄</p>
            <?php endif; ?>
        </div>
    </div>

    <!-- ───────────────── 系統資訊 ───────────────── -->
    <div class="asc-section">
        <h2 class="asc-section-title">⚙️ 系統資訊</h2>
        <div class="asc-section-body">
            <table class="wp-list-table widefat fixed asc-info-table">
                <tbody>
                    <tr>
                        <th>插件版本</th>
                        <td><strong><?php echo esc_html( $plugin_version ); ?></strong></td>
                    </tr>
                    <tr>
                        <th>DB 版本</th>
                        <td>
                            <?php echo esc_html( $db_version ); ?>
                            <?php if ( ! empty( $db_version_is_guess ) ) : ?>
                                <span class="asc-hint">（推測）</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th>上次升級時間</th>
                        <td><?php echo esc_html( $last_upgrade_str ); ?></td>
                    </tr>
                    <tr>
                        <th>WordPress 版本</th>
                        <td><?php echo esc_html( get_bloginfo( 'version' ) ); ?></td>
                    </tr>
                    <tr>
                        <th>PHP 版本</th>
                        <td>
                            <?php echo esc_html( PHP_VERSION ); ?>
                            <?php if ( version_compare( PHP_VERSION, '8.0', '<' ) ) : ?>
                                <span class="asc-status-warn">⚠️ 建議升級至 PHP 8.0+</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th>記憶體使用</th>
                        <td>
                            <?php
                            if ( class_exists( 'Anime_Sync_Performance' ) && method_exists( 'Anime_Sync_Performance', 'get_memory_usage' ) ) {
                                $memory = Anime_Sync_Performance::get_memory_usage();
                                echo esc_html( ( isset( $memory['current'] ) ? $memory['current'] : '—' ) . ' / ' . ( isset( $memory['limit'] ) ? $memory['limit'] : '—' ) );
                            } else {
                                echo esc_html( size_format( memory_get_usage( true ) ) . ' / ' . ini_get( 'memory_limit' ) );
                            }
                            ?>
                        </td>
                    </tr>
                    <tr>
                        <th>動漫總數</th>
                        <td>
                            <?php echo esc_html( number_format_i18n( $published_total ) ); ?> 已發布
                            <span class="asc-separator">/</span>
                            <?php echo esc_html( number_format_i18n( $draft_total ) ); ?> 草稿
                        </td>
                    </tr>
                    <tr>
                        <th>Cron 排程</th>
                        <td>
                            <?php
                            $cron_hooks = array(
                                'anime_sync_daily_score_update'       => '每日動態更新',
                                'anime_sync_themes_episodes_update'   => '主題曲＋集數同步',
                                'anime_sync_recalc_user_status_stats' => '用戶統計重算',
                            );
                            foreach ( $cron_hooks as $hook => $label ) :
                                $ts  = wp_next_scheduled( $hook );
                                $now = time();
                                if ( $ts ) {
                                    $diff    = $ts - $now;
                                    $is_late = $diff < -60;
                                    if ( $is_late ) {
                                        $status = '<span class="asc-status-err">⚠️ 已逾期 ' . esc_html( abs( $diff ) ) . ' 秒</span>';
                                    } else {
                                        $status = '<span class="asc-status-ok">✓ 還有 ' . esc_html( max( 0, $diff ) ) . ' 秒</span>';
                                    }
                                    $time_display = esc_html( get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $ts ), 'Y-m-d H:i:s' ) );
                                } else {
                                    $status       = '<span class="asc-status-err">❌ 未排程</span>';
                                    $time_display = '—';
                                }
                            ?>
                                <div class="asc-cron-row">
                                    <span class="asc-cron-label"><?php echo esc_html( $label ); ?></span>
                                    <span class="asc-cron-time"><?php echo $time_display; ?></span>
                                    <span class="asc-cron-status"><?php echo $status; ?></span>
                                </div>
                            <?php endforeach; ?>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

</div><!-- .wrap -->

<!-- ───────────────── CSS ───────────────── -->
<style>

/* ═══════════════════════════════════════════
   基礎 Layout
═══════════════════════════════════════════ */
.asc-dash-wrap { max-width: 100%; }

.asc-dash-header { margin-bottom: 16px; }
.asc-dash-header h1 { margin: 0; display: flex; align-items: center; flex-wrap: wrap; gap: 8px; }
.asc-dash-version { font-size: 13px; color: #888; font-weight: normal; }

/* ═══════════════════════════════════════════
   Section 通用
═══════════════════════════════════════════ */
.asc-section {
    background: #fff;
    border: 1px solid #e0e0e0;
    border-radius: 6px;
    margin-top: 20px;
    overflow: hidden;
}
.asc-section-title {
    margin: 0;
    padding: 12px 16px;
    font-size: 14px;
    font-weight: 600;
    border-bottom: 1px solid #f0f0f0;
    background: #fafafa;
}
.asc-section-body { padding: 16px; }

/* ═══════════════════════════════════════════
   Stats Grid
═══════════════════════════════════════════ */
.asc-stats-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 14px;
    margin-top: 16px;
}
.asc-stat-card {
    background: #fff;
    border: 1px solid #e0e0e0;
    border-radius: 6px;
    padding: 16px;
    display: flex;
    align-items: flex-start;
    gap: 12px;
    border-top: 3px solid #ddd;
}
.asc-stat-card--pending   { border-top-color: #f0a500; }
.asc-stat-card--approved  { border-top-color: #46b450; }
.asc-stat-card--published { border-top-color: #0073aa; }
.asc-stat-card--error     { border-top-color: #dc3232; }

.asc-stat-icon { flex-shrink: 0; }
.asc-stat-icon .dashicons {
    font-size: 28px; width: 28px; height: 28px;
    color: #bbb;
}
.asc-stat-card--pending   .dashicons { color: #f0a500; }
.asc-stat-card--approved  .dashicons { color: #46b450; }
.asc-stat-card--published .dashicons { color: #0073aa; }
.asc-stat-card--error     .dashicons { color: #dc3232; }

.asc-stat-content { flex: 1; min-width: 0; }
.asc-stat-label  { font-size: 12px; color: #777; text-transform: uppercase; letter-spacing: .4px; margin-bottom: 4px; }
.asc-stat-number { font-size: 28px; font-weight: 700; line-height: 1; color: #23282d; margin-bottom: 6px; }
.asc-stat-link   { font-size: 12px; color: #0073aa; text-decoration: none; }
.asc-stat-link:hover { text-decoration: underline; }

/* ═══════════════════════════════════════════
   Empty Notice
═══════════════════════════════════════════ */
.asc-empty-notice { margin-top: 16px !important; padding: 14px 16px !important; }
.asc-empty-notice p { margin: 0; display: flex; align-items: center; flex-wrap: wrap; gap: 8px; }
.asc-empty-btn { margin: 0 !important; }

/* ═══════════════════════════════════════════
   Quick Actions
═══════════════════════════════════════════ */
.asc-quick-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 10px;
    padding: 16px;
}
.asc-quick-btn {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 6px;
    padding: 16px 10px;
    background: #f9f9f9;
    border: 1px solid #e0e0e0;
    border-radius: 6px;
    color: #23282d;
    text-decoration: none;
    font-size: 13px;
    font-weight: 500;
    transition: background .15s, border-color .15s;
    text-align: center;
}
.asc-quick-btn:hover {
    background: #f0f7fc;
    border-color: #0073aa;
    color: #0073aa;
}
.asc-quick-btn .dashicons {
    font-size: 22px; width: 22px; height: 22px;
    color: #0073aa;
}

/* ═══════════════════════════════════════════
   Info Table
═══════════════════════════════════════════ */
.asc-info-table { font-size: 13px; }
.asc-info-table th {
    width: 160px;
    font-weight: 600;
    background: #fafafa;
    vertical-align: top;
    padding: 10px 12px;
}
.asc-info-table td {
    padding: 10px 12px;
    vertical-align: top;
    word-break: break-word;
}
.asc-code-wrap { font-size: 11px; word-break: break-all; }
.asc-hint      { color: #999; font-size: 12px; margin-left: 6px; }
.asc-separator { color: #bbb; margin: 0 4px; }

/* ═══════════════════════════════════════════
   Status helpers
═══════════════════════════════════════════ */
.asc-status-ok   { color: #46b450; font-weight: 600; }
.asc-status-err  { color: #dc3232; font-weight: 600; }
.asc-status-warn { color: #f0a500; font-weight: 600; margin-left: 8px; }

.asc-mode-badge {
    display: inline-block;
    padding: 2px 8px;
    background: #e7f6ed;
    color: #207b45;
    border-radius: 3px;
    font-size: 12px;
}

/* ═══════════════════════════════════════════
   Converter test
═══════════════════════════════════════════ */
.asc-convert-test   { display: flex; flex-direction: column; gap: 4px; }
.asc-convert-row    { display: flex; align-items: baseline; gap: 8px; flex-wrap: wrap; }
.asc-convert-label  { font-size: 11px; color: #888; min-width: 34px; flex-shrink: 0; }

/* ═══════════════════════════════════════════
   Cron rows
═══════════════════════════════════════════ */
.asc-cron-row {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 6px;
    padding: 5px 0;
    border-bottom: 1px solid #f5f5f5;
    font-size: 13px;
}
.asc-cron-row:last-child { border-bottom: none; }
.asc-cron-label  { font-weight: 600; min-width: 140px; }
.asc-cron-time   { color: #555; flex: 1; white-space: nowrap; }
.asc-cron-status { white-space: nowrap; }

/* ═══════════════════════════════════════════
   Log summary pills
═══════════════════════════════════════════ */
.asc-log-summary {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 8px;
    padding: 10px 16px;
    border-bottom: 1px solid #f0f0f0;
    background: #fafafa;
}
.asc-log-pill {
    display: inline-block;
    padding: 3px 10px;
    border-radius: 12px;
    font-size: 12px;
    font-weight: 600;
}
.asc-log-pill--info     { background: #e8f4fd; color: #0073aa; }
.asc-log-pill--warning  { background: #fff8e1; color: #f0a500; }
.asc-log-pill--error    { background: #fce8e8; color: #dc3232; }
.asc-log-pill--critical { background: #f9e0e0; color: #8b0000; }
.asc-log-more { margin-left: auto !important; }

/* ═══════════════════════════════════════════
   Log table
═══════════════════════════════════════════ */
.asc-log-table { font-size: 13px; }
.asc-col-level { width: 80px; }
.asc-col-time  { width: 150px; white-space: nowrap; }
.asc-log-message { word-break: break-all; line-height: 1.5; }
.asc-log-time    { font-size: 12px; color: #666; }

.asc-level-badge {
    display: inline-block;
    padding: 2px 7px;
    border-radius: 3px;
    font-size: 11px;
    font-weight: 700;
}

.asc-empty-cell { text-align: center; color: #999; padding: 20px !important; }

/* ═══════════════════════════════════════════
   桌面 / 手機 顯示互斥（關鍵修正）
   預設（手機優先）：顯示卡片、隱藏表格
═══════════════════════════════════════════ */
.asc-log-table.asc-desktop-only { display: none !important; }
.asc-log-cards.asc-mobile-only  { display: flex  !important; }

/* Log cards */
.asc-log-cards { padding: 10px 12px; flex-direction: column; gap: 8px; }
.asc-log-card {
    border-radius: 5px;
    padding: 10px 12px;
    border-left: 4px solid #ddd;
    background: #fafafa;
    font-size: 13px;
}
.asc-log-card--info     { border-left-color: #0073aa; }
.asc-log-card--warning  { border-left-color: #f0a500; }
.asc-log-card--error    { border-left-color: #dc3232; }
.asc-log-card--critical { border-left-color: #8b0000; background: #fff5f5; }

.asc-log-card-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 6px;
    gap: 8px;
    flex-wrap: wrap;
}
.asc-log-card-time { font-size: 11px; color: #888; margin-left: auto; white-space: nowrap; }
.asc-log-card-msg  { font-size: 12px; line-height: 1.5; color: #333; word-break: break-all; }

/* ═══════════════════════════════════════════
   寬螢幕 ≥ 783px：日誌顯示表格、隱藏卡片
   （語法已修正，括號內不留空格）
═══════════════════════════════════════════ */
@media screen and (min-width: 783px) {

    .asc-log-table.asc-desktop-only { display: table !important; }
    .asc-log-cards.asc-mobile-only  { display: none  !important; }
}

/* ═══════════════════════════════════════════
   手機 ≤ 782px：其餘版面調整
═══════════════════════════════════════════ */
@media screen and (max-width: 782px) {

    /* Stats → 2 欄 */
    .asc-stats-grid {
        grid-template-columns: repeat(2, 1fr);
        gap: 10px;
        margin-top: 12px;
    }
    .asc-stat-card  { padding: 12px; gap: 10px; }
    .asc-stat-number { font-size: 22px; }
    .asc-stat-icon .dashicons { font-size: 22px; width: 22px; height: 22px; }

    /* Quick actions → 2 欄 */
    .asc-quick-grid { grid-template-columns: repeat(2, 1fr); gap: 8px; padding: 12px; }
    .asc-quick-btn  { padding: 14px 8px; font-size: 12px; }

    /* Empty notice */
    .asc-empty-notice p { flex-direction: column; align-items: flex-start; }
    .asc-empty-btn { width: 100%; text-align: center; }

    /* Info table → block */
    .asc-info-table,
    .asc-info-table tbody,
    .asc-info-table tr,
    .asc-info-table th,
    .asc-info-table td { display: block; width: 100% !important; box-sizing: border-box; }
    .asc-info-table tr { border-bottom: 1px solid #f0f0f0; }
    .asc-info-table tr:last-child { border-bottom: none; }
    .asc-info-table th {
        padding: 8px 12px 2px;
        background: #f9f9f9;
        border-bottom: none;
        font-size: 11px;
        text-transform: uppercase;
        letter-spacing: .3px;
        color: #777;
    }
    .asc-info-table td { padding: 4px 12px 10px; }

    /* Cron rows → 垂直堆疊 */
    .asc-cron-row    { flex-direction: column; align-items: flex-start; gap: 2px; }
    .asc-cron-label  { min-width: unset; }
    .asc-cron-time   { flex: none; }

    /* Log summary */
    .asc-log-more { margin-left: 0 !important; width: 100%; text-align: center; }

    /* Section title */
    .asc-section-title { font-size: 13px; padding: 10px 14px; }
}

/* ═══════════════════════════════════════════
   超窄 ≤ 480px
═══════════════════════════════════════════ */
@media screen and (max-width: 480px) {
    .asc-stats-grid    { grid-template-columns: 1fr 1fr; gap: 8px; }
    .asc-stat-number   { font-size: 20px; }
    .asc-quick-grid    { grid-template-columns: 1fr 1fr; }
}
</style>
