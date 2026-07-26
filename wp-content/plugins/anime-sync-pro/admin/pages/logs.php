<?php
/**
 * Error Logs Page
 *
 * @package Anime_Sync_Pro
 * @version 1.2.1
 *
 * Changelog:
 *  - 1.2.1 (2026-07-20):
 *      • 修正 media query 語法：移除括號內外空格（( max-width )→(max-width)），
 *        原語法在部分環境下失效，導致桌面表格與手機卡片同時顯示。
 *      • 桌面/手機切換改用雙 class 選擇器 + !important 強制互斥。
 *      • 改用 min-width 正向宣告桌面樣式，邏輯更清楚，避免版型重複。
 *  - 1.2.0 (2026-06-27):
 *      • 手機板響應式全面優化。
 *      • 統計區改為自適應 grid，手機板 2 欄排列。
 *      • 篩選列改為可折疊 collapsible，手機板全寬垂直堆疊。
 *      • 日誌表格在手機板（≤782px）切換為卡片式佈局。
 *      • Context Modal 在手機板改為全螢幕底部滑入式。
 *      • Toast 在手機板移至底部置中顯示。
 *  - 1.1.0 (2026-05-10):
 *      • 清除按鈕明確帶 days=30 參數，避免後端誤清全部紀錄。
 *      • 新增「清除天數」下拉，可選擇 7/30/60/90 天前。
 *      • 加上按鈕 disabled 與錯誤處理，避免重複點擊。
 *      • 新增成功時顯示 toast 提示。
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$logger = new Anime_Sync_Error_Logger();

$level = isset( $_GET['level'] ) ? sanitize_text_field( wp_unslash( $_GET['level'] ) ) : '';
$limit = isset( $_GET['limit'] ) ? absint( $_GET['limit'] ) : 100;

$logs  = $logger->get_recent_logs( $limit, $level ?: null );
$stats = $logger->get_statistics( 7 );

$nonce = wp_create_nonce( 'anime_sync_admin_nonce' );

/* 等級設定表 */
$level_config = [
    'info'     => [ 'label' => '資訊', 'color' => '#0073aa', 'bg' => '#e8f4fd', 'icon' => 'ℹ️' ],
    'warning'  => [ 'label' => '警告', 'color' => '#f0a500', 'bg' => '#fff8e1', 'icon' => '⚠️' ],
    'error'    => [ 'label' => '錯誤', 'color' => '#dc3232', 'bg' => '#fce8e8', 'icon' => '❌' ],
    'critical' => [ 'label' => '嚴重', 'color' => '#8b0000', 'bg' => '#f9e0e0', 'icon' => '🔥' ],
];

$has_filter = $level || $limit !== 100;
?>

<div class="wrap asc-logs-wrap">

    <h1 class="wp-heading-inline">📋 <?php esc_html_e( '錯誤日誌', 'anime-sync-pro' ); ?></h1>
    <hr class="wp-header-end">

    <!-- ───────────────── Stats ───────────────── -->
    <div class="asc-log-stats">
        <div class="asc-stat-item asc-stat-total">
            <span class="asc-stat-value"><?php echo esc_html( $stats['total'] ); ?></span>
            <span class="asc-stat-label">總計</span>
        </div>
        <div class="asc-stat-item asc-stat-info">
            <span class="asc-stat-value"><?php echo esc_html( $stats['info'] ?? 0 ); ?></span>
            <span class="asc-stat-label">資訊</span>
        </div>
        <div class="asc-stat-item asc-stat-warning">
            <span class="asc-stat-value"><?php echo esc_html( $stats['warning'] ?? 0 ); ?></span>
            <span class="asc-stat-label">警告</span>
        </div>
        <div class="asc-stat-item asc-stat-error">
            <span class="asc-stat-value"><?php echo esc_html( $stats['error'] ?? 0 ); ?></span>
            <span class="asc-stat-label">錯誤</span>
        </div>
        <div class="asc-stat-item asc-stat-critical">
            <span class="asc-stat-value"><?php echo esc_html( $stats['critical'] ?? 0 ); ?></span>
            <span class="asc-stat-label">嚴重</span>
        </div>
        <div class="asc-stat-period">最近 7 天</div>
    </div>

    <!-- ───────────────── Filter (Collapsible) ───────────────── -->
    <div class="asc-filter-wrap">
        <button type="button" class="asc-filter-toggle <?php echo $has_filter ? 'is-active' : ''; ?>"
                id="asc-log-filter-toggle">
            <span class="dashicons dashicons-filter"></span>
            <?php esc_html_e( '篩選與管理', 'anime-sync-pro' ); ?>
            <?php if ( $has_filter ) : ?>
                <span class="asc-filter-badge">已套用</span>
            <?php endif; ?>
            <span class="asc-toggle-arrow"><?php echo $has_filter ? '▴' : '▾'; ?></span>
        </button>

        <div class="asc-filter-body <?php echo $has_filter ? 'is-open' : ''; ?>" id="asc-log-filter-body">

            <!-- 篩選區 -->
            <div class="asc-filter-section">
                <div class="asc-filter-section-title">🔍 篩選日誌</div>
                <div class="asc-filter-row">
                    <div class="asc-filter-field">
                        <label for="log-level-filter">等級</label>
                        <select id="log-level-filter">
                            <option value="">所有等級</option>
                            <option value="info"     <?php selected( $level, 'info' );     ?>>ℹ️ 資訊</option>
                            <option value="warning"  <?php selected( $level, 'warning' );  ?>>⚠️ 警告</option>
                            <option value="error"    <?php selected( $level, 'error' );    ?>>❌ 錯誤</option>
                            <option value="critical" <?php selected( $level, 'critical' ); ?>>🔥 嚴重</option>
                        </select>
                    </div>
                    <div class="asc-filter-field">
                        <label for="log-limit">顯示筆數</label>
                        <select id="log-limit">
                            <option value="100"  <?php selected( $limit, 100  ); ?>>最近 100 筆</option>
                            <option value="500"  <?php selected( $limit, 500  ); ?>>最近 500 筆</option>
                            <option value="1000" <?php selected( $limit, 1000 ); ?>>最近 1000 筆</option>
                        </select>
                    </div>
                    <div class="asc-filter-field asc-filter-field--btn">
                        <label>&nbsp;</label>
                        <button type="button" id="filter-logs" class="button button-primary">
                            <span class="dashicons dashicons-search"></span> 套用篩選
                        </button>
                    </div>
                    <?php if ( $has_filter ) : ?>
                    <div class="asc-filter-field asc-filter-field--btn">
                        <label>&nbsp;</label>
                        <a href="?page=anime-sync-logs" class="button">重設</a>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="asc-filter-divider"></div>

            <!-- 清除區 -->
            <div class="asc-filter-section">
                <div class="asc-filter-section-title">🗑️ 清除舊日誌</div>
                <div class="asc-filter-row">
                    <div class="asc-filter-field">
                        <label for="clear-days">清除範圍</label>
                        <select id="clear-days">
                            <option value="7">7 天前的日誌</option>
                            <option value="30" selected>30 天前的日誌</option>
                            <option value="60">60 天前的日誌</option>
                            <option value="90">90 天前的日誌</option>
                        </select>
                    </div>
                    <div class="asc-filter-field asc-filter-field--btn">
                        <label>&nbsp;</label>
                        <button type="button" id="clear-old-logs" class="button button-link-delete">
                            <span class="dashicons dashicons-trash"></span> 清除舊日誌
                        </button>
                    </div>
                </div>
            </div>

        </div><!-- .asc-filter-body -->
    </div><!-- .asc-filter-wrap -->

    <!-- 筆數提示 -->
    <div class="asc-log-count-bar">
        <span>
            共顯示 <strong><?php echo esc_html( count( $logs ) ); ?></strong> 筆
            <?php if ( $level && isset( $level_config[ $level ] ) ) : ?>
                ・篩選等級：<strong><?php echo esc_html( $level_config[ $level ]['label'] ); ?></strong>
            <?php endif; ?>
        </span>
    </div>

    <?php if ( ! empty( $logs ) ) : ?>

        <!-- ───────────────── Desktop Table ───────────────── -->
        <table class="wp-list-table widefat fixed striped asc-log-table asc-desktop-only">
            <thead>
                <tr>
                    <th class="asc-col-level">等級</th>
                    <th>訊息</th>
                    <th class="asc-col-time">時間</th>
                    <th class="asc-col-detail">詳情</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $logs as $log ) :
                    $lv     = $log['level'] ?? 'info';
                    $cfg    = $level_config[ $lv ] ?? [ 'label' => strtoupper( $lv ), 'color' => '#555', 'bg' => '#f5f5f5', 'icon' => '' ];
                ?>
                    <tr class="asc-log-row asc-log-row--<?php echo esc_attr( $lv ); ?>">
                        <td>
                            <span class="asc-level-badge"
                                  style="color:<?php echo esc_attr( $cfg['color'] ); ?>;background:<?php echo esc_attr( $cfg['bg'] ); ?>;">
                                <?php echo esc_html( $cfg['icon'] . ' ' . $cfg['label'] ); ?>
                            </span>
                        </td>
                        <td class="asc-log-message"><?php echo esc_html( $log['message'] ); ?></td>
                        <td class="asc-log-time"><?php echo esc_html( $log['created_at'] ); ?></td>
                        <td>
                            <?php if ( ! empty( $log['context'] ) ) : ?>
                                <button type="button" class="button button-small show-context"
                                        data-context='<?php echo esc_attr( wp_json_encode( $log['context'] ) ); ?>'>
                                    查看
                                </button>
                            <?php else : ?>
                                <span class="asc-no-context">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <!-- ───────────────── Mobile Cards ───────────────── -->
        <div class="asc-log-cards asc-mobile-only">
            <?php foreach ( $logs as $log ) :
                $lv  = $log['level'] ?? 'info';
                $cfg = $level_config[ $lv ] ?? [ 'label' => strtoupper( $lv ), 'color' => '#555', 'bg' => '#f5f5f5', 'icon' => '' ];
            ?>
                <div class="asc-log-card asc-log-card--<?php echo esc_attr( $lv ); ?>">
                    <div class="asc-log-card-header">
                        <span class="asc-level-badge"
                              style="color:<?php echo esc_attr( $cfg['color'] ); ?>;background:<?php echo esc_attr( $cfg['bg'] ); ?>;">
                            <?php echo esc_html( $cfg['icon'] . ' ' . $cfg['label'] ); ?>
                        </span>
                        <span class="asc-log-card-time"><?php echo esc_html( $log['created_at'] ); ?></span>
                    </div>
                    <div class="asc-log-card-message"><?php echo esc_html( $log['message'] ); ?></div>
                    <?php if ( ! empty( $log['context'] ) ) : ?>
                        <div class="asc-log-card-footer">
                            <button type="button" class="button button-small show-context"
                                    data-context='<?php echo esc_attr( wp_json_encode( $log['context'] ) ); ?>'>
                                📋 查看詳情
                            </button>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>

    <?php else : ?>

        <div class="asc-empty-state">
            <span class="dashicons dashicons-yes-alt"></span>
            <p>目前沒有日誌記錄</p>
            <?php if ( $has_filter ) : ?>
                <a href="?page=anime-sync-logs" class="button">清除篩選條件</a>
            <?php endif; ?>
        </div>

    <?php endif; ?>

</div><!-- .wrap -->

<!-- ───────────────── Context Modal ───────────────── -->
<div id="context-modal" class="asc-modal" style="display:none;" role="dialog" aria-modal="true" aria-label="日誌詳情">
    <div class="asc-modal-overlay"></div>
    <div class="asc-modal-content">
        <div class="asc-modal-header">
            <h3>📋 日誌詳情</h3>
            <button type="button" class="asc-modal-close" aria-label="關閉">&times;</button>
        </div>
        <div class="asc-modal-body">
            <pre id="context-data"></pre>
        </div>
        <div class="asc-modal-footer">
            <button type="button" class="button asc-modal-close">關閉</button>
        </div>
    </div>
</div>

<!-- Toast -->
<div id="logs-toast" class="asc-toast" style="display:none;"></div>

<!-- ───────────────── JS ───────────────── -->
<script>
jQuery( document ).ready( function ( $ ) {

    var nonce = '<?php echo esc_js( $nonce ); ?>';

    /* ── Toast ── */
    function showToast( message, isError ) {
        var $toast = $( '#logs-toast' );
        $toast.text( message )
              .css( 'background', isError ? '#dc3232' : '#46b450' )
              .fadeIn( 200 );
        setTimeout( function () { $toast.fadeOut( 300 ); }, 2800 );
    }

    /* ── Collapsible filter ── */
    $( '#asc-log-filter-toggle' ).on( 'click', function () {
        var $body  = $( '#asc-log-filter-body' );
        var $arrow = $( this ).find( '.asc-toggle-arrow' );
        var open   = $body.toggleClass( 'is-open' ).hasClass( 'is-open' );
        $arrow.text( open ? '▴' : '▾' );
        $( this ).toggleClass( 'is-active', open );
    } );

    /* ── Filter ── */
    $( '#filter-logs' ).on( 'click', function () {
        var level = $( '#log-level-filter' ).val();
        var limit = $( '#log-limit' ).val();
        var url   = '?page=anime-sync-logs';
        if ( level ) url += '&level=' + encodeURIComponent( level );
        if ( limit ) url += '&limit=' + encodeURIComponent( limit );
        window.location.href = url;
    } );

    /* ── Clear old logs ── */
    $( '#clear-old-logs' ).on( 'click', function () {
        var $btn         = $( this );
        var days         = parseInt( $( '#clear-days' ).val(), 10 ) || 30;
        var originalText = $btn.html();

        if ( ! confirm( '確定要清除 ' + days + ' 天前的日誌嗎？\n（' + days + ' 天內的紀錄會保留）' ) ) {
            return;
        }

        $btn.prop( 'disabled', true ).html( '清除中…' );

        $.ajax( {
            url      : ajaxurl,
            type     : 'POST',
            dataType : 'json',
            data     : { action: 'anime_clear_old_logs', nonce: nonce, days: days },
            success  : function ( resp ) {
                if ( resp && resp.success ) {
                    var msg = ( resp.data && resp.data.message )
                              || ( '已清除 ' + ( resp.data && resp.data.count ) + ' 筆舊日誌' );
                    showToast( msg, false );
                    setTimeout( function () { location.reload(); }, 1000 );
                } else {
                    showToast( ( resp && resp.data ) || '清除失敗', true );
                    $btn.prop( 'disabled', false ).html( originalText );
                }
            },
            error    : function ( xhr ) {
                showToast( '網路錯誤 (HTTP ' + xhr.status + ')', true );
                $btn.prop( 'disabled', false ).html( originalText );
            }
        } );
    } );

    /* ── Show context modal ── */
    $( document ).on( 'click', '.show-context', function () {
        var context = $( this ).data( 'context' );
        try {
            $( '#context-data' ).text( JSON.stringify( context, null, 2 ) );
        } catch ( e ) {
            $( '#context-data' ).text( String( context ) );
        }
        $( '#context-modal' ).fadeIn( 200 );
        $( 'body' ).addClass( 'asc-modal-open' );
    } );

    /* ── Close modal ── */
    $( document ).on( 'click', '.asc-modal-overlay, .asc-modal-close', function () {
        $( '#context-modal' ).fadeOut( 200 );
        $( 'body' ).removeClass( 'asc-modal-open' );
    } );

    /* ── ESC to close ── */
    $( document ).on( 'keydown', function ( e ) {
        if ( e.key === 'Escape' ) {
            $( '#context-modal' ).fadeOut( 200 );
            $( 'body' ).removeClass( 'asc-modal-open' );
        }
    } );

} );
</script>

<!-- ───────────────── Styles ───────────────── -->
<style>

/* ═══════════════════════════════════════════
   Layout
═══════════════════════════════════════════ */
.asc-logs-wrap { max-width: 100%; }

/* ═══════════════════════════════════════════
   Stats Grid
═══════════════════════════════════════════ */
.asc-log-stats {
    display: grid;
    grid-template-columns: repeat(5, 1fr);
    gap: 10px;
    margin: 16px 0;
    position: relative;
}
.asc-stat-period {
    position: absolute;
    top: -18px; right: 0;
    font-size: 11px;
    color: #888;
}
.asc-stat-item {
    background: #fff;
    border: 1px solid #e0e0e0;
    border-radius: 6px;
    padding: 14px 12px;
    text-align: center;
    display: flex;
    flex-direction: column;
    gap: 4px;
}
.asc-stat-value {
    font-size: 28px;
    font-weight: 700;
    line-height: 1;
    color: #23282d;
}
.asc-stat-label {
    font-size: 12px;
    color: #777;
    text-transform: uppercase;
    letter-spacing: .4px;
}
.asc-stat-info     .asc-stat-value { color: #0073aa; }
.asc-stat-warning  .asc-stat-value { color: #f0a500; }
.asc-stat-error    .asc-stat-value { color: #dc3232; }
.asc-stat-critical .asc-stat-value { color: #8b0000; }
.asc-stat-info     { border-top: 3px solid #0073aa; }
.asc-stat-warning  { border-top: 3px solid #f0a500; }
.asc-stat-error    { border-top: 3px solid #dc3232; }
.asc-stat-critical { border-top: 3px solid #8b0000; }
.asc-stat-total    { border-top: 3px solid #555; }

/* ═══════════════════════════════════════════
   Collapsible Filter
═══════════════════════════════════════════ */
.asc-filter-wrap { margin: 0 0 16px; }

.asc-filter-toggle {
    display: flex;
    align-items: center;
    gap: 6px;
    width: 100%;
    text-align: left;
    background: #fff;
    border: 1px solid #ddd;
    border-radius: 4px 4px 0 0;
    padding: 9px 14px;
    font-size: 13px;
    font-weight: 600;
    color: #23282d;
    cursor: pointer;
    transition: background .15s, color .15s;
}
.asc-filter-toggle:hover,
.asc-filter-toggle.is-active {
    background: #f0f7fc;
    color: #0073aa;
    border-color: #0073aa;
}
.asc-filter-badge {
    background: #f0a500;
    color: #fff;
    border-radius: 8px;
    padding: 1px 7px;
    font-size: 11px;
    font-weight: 600;
    margin-left: 2px;
}
.asc-toggle-arrow { margin-left: auto; font-size: 11px; }

.asc-filter-body {
    display: none;
    background: #fff;
    border: 1px solid #ddd;
    border-top: none;
    border-radius: 0 0 4px 4px;
    padding: 16px;
}
.asc-filter-body.is-open { display: block; }

.asc-filter-section { margin-bottom: 4px; }
.asc-filter-section-title {
    font-size: 12px;
    font-weight: 700;
    color: #555;
    text-transform: uppercase;
    letter-spacing: .4px;
    margin-bottom: 10px;
}
.asc-filter-divider {
    border: none;
    border-top: 1px solid #f0f0f0;
    margin: 14px 0;
}
.asc-filter-row {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    align-items: flex-end;
}
.asc-filter-field {
    display: flex;
    flex-direction: column;
    gap: 4px;
    min-width: 140px;
}
.asc-filter-field label {
    font-size: 12px;
    color: #555;
    font-weight: 600;
}
.asc-filter-field select { width: 100%; }
.asc-filter-field--btn { min-width: auto; }
.asc-filter-field--btn .button {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    white-space: nowrap;
}
.asc-filter-field--btn .dashicons {
    font-size: 14px; width: 14px; height: 14px;
}

/* ═══════════════════════════════════════════
   Log Count Bar
═══════════════════════════════════════════ */
.asc-log-count-bar {
    font-size: 13px;
    color: #555;
    margin-bottom: 8px;
    padding: 6px 2px;
}

/* ═══════════════════════════════════════════
   Level Badge
═══════════════════════════════════════════ */
.asc-level-badge {
    display: inline-block;
    padding: 3px 8px;
    border-radius: 4px;
    font-size: 11px;
    font-weight: 700;
    letter-spacing: .3px;
    white-space: nowrap;
}

/* ═══════════════════════════════════════════
   Desktop Table
═══════════════════════════════════════════ */
.asc-log-table { font-size: 13px; }
.asc-col-level  { width: 90px; }
.asc-col-time   { width: 160px; }
.asc-col-detail { width: 72px; }
.asc-log-message { word-break: break-all; line-height: 1.5; }
.asc-log-time    { font-size: 12px; color: #666; white-space: nowrap; }
.asc-no-context  { color: #bbb; }

/* 列左側色條 */
.asc-log-row--warning  td:first-child { border-left: 3px solid #f0a500; }
.asc-log-row--error    td:first-child { border-left: 3px solid #dc3232; }
.asc-log-row--critical td:first-child { border-left: 3px solid #8b0000; }
.asc-log-row--critical { background: #fff5f5 !important; }

/* ═══════════════════════════════════════════
   桌面 / 手機 顯示互斥（關鍵修正）
   預設（手機優先）：顯示卡片、隱藏表格
═══════════════════════════════════════════ */
.asc-log-table.asc-desktop-only { display: none !important; }
.asc-log-cards.asc-mobile-only  { display: flex  !important; }

.asc-log-cards { flex-direction: column; gap: 8px; }

.asc-log-card {
    background: #fff;
    border: 1px solid #e0e0e0;
    border-radius: 6px;
    padding: 12px;
    box-shadow: 0 1px 3px rgba(0,0,0,.05);
    border-left: 4px solid #ddd;
}
.asc-log-card--info     { border-left-color: #0073aa; }
.asc-log-card--warning  { border-left-color: #f0a500; }
.asc-log-card--error    { border-left-color: #dc3232; }
.asc-log-card--critical { border-left-color: #8b0000; background: #fff5f5; }

.asc-log-card-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    margin-bottom: 8px;
    flex-wrap: wrap;
}
.asc-log-card-time {
    font-size: 11px;
    color: #888;
    white-space: nowrap;
    margin-left: auto;
}
.asc-log-card-message {
    font-size: 13px;
    line-height: 1.55;
    color: #23282d;
    word-break: break-all;
}
.asc-log-card-footer {
    margin-top: 10px;
    padding-top: 8px;
    border-top: 1px solid #f0f0f0;
}

/* ═══════════════════════════════════════════
   Context Modal
═══════════════════════════════════════════ */
.asc-modal {
    position: fixed;
    inset: 0;
    z-index: 100000;
    display: flex;
    align-items: center;
    justify-content: center;
}
.asc-modal-overlay {
    position: absolute;
    inset: 0;
    background: rgba(0,0,0,.55);
}
.asc-modal-content {
    position: relative;
    background: #fff;
    border-radius: 8px;
    width: min(720px, 92vw);
    max-height: 80vh;
    display: flex;
    flex-direction: column;
    box-shadow: 0 8px 32px rgba(0,0,0,.2);
    overflow: hidden;
}
.asc-modal-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 14px 18px;
    border-bottom: 1px solid #e0e0e0;
    background: #f9f9f9;
    flex-shrink: 0;
}
.asc-modal-header h3 { margin: 0; font-size: 15px; }
.asc-modal-close {
    background: none;
    border: none;
    font-size: 22px;
    line-height: 1;
    cursor: pointer;
    color: #555;
    padding: 4px 8px;
    border-radius: 4px;
    transition: background .15s;
}
.asc-modal-close:hover { background: #eee; color: #000; }
.asc-modal-body {
    padding: 16px 18px;
    overflow-y: auto;
    flex: 1;
}
.asc-modal-body pre {
    margin: 0;
    font-size: 12px;
    line-height: 1.6;
    white-space: pre-wrap;
    word-break: break-all;
    background: #f6f8fa;
    border: 1px solid #e0e0e0;
    border-radius: 4px;
    padding: 12px;
}
.asc-modal-footer {
    padding: 10px 18px;
    border-top: 1px solid #e0e0e0;
    text-align: right;
    flex-shrink: 0;
}
body.asc-modal-open { overflow: hidden; }

/* ═══════════════════════════════════════════
   Empty State
═══════════════════════════════════════════ */
.asc-empty-state {
    text-align: center;
    padding: 60px 20px;
    color: #777;
    border: 2px dashed #ddd;
    border-radius: 6px;
    margin-top: 16px;
}
.asc-empty-state .dashicons {
    font-size: 48px; width: 48px; height: 48px;
    color: #46b450; display: block; margin: 0 auto 12px;
}
.asc-empty-state p { font-size: 15px; margin: 0 0 14px; }

/* ═══════════════════════════════════════════
   Toast
═══════════════════════════════════════════ */
.asc-toast {
    position: fixed;
    top: 50px; right: 20px;
    z-index: 100001;
    padding: 12px 20px;
    border-radius: 6px;
    box-shadow: 0 3px 10px rgba(0,0,0,.2);
    font-size: 14px;
    color: #fff;
    max-width: calc(100vw - 40px);
}

/* ═══════════════════════════════════════════
   寬螢幕 ≥ 783px：顯示表格、隱藏卡片
   （語法已修正，括號內不留空格）
═══════════════════════════════════════════ */
@media screen and (min-width: 783px) {

    .asc-log-table.asc-desktop-only { display: table !important; }
    .asc-log-cards.asc-mobile-only  { display: none  !important; }
}

/* ═══════════════════════════════════════════
   手機 ≤ 782px：Stats/Filter/Modal/Toast 調整
═══════════════════════════════════════════ */
@media screen and (max-width: 782px) {

    /* Stats：2 欄 */
    .asc-log-stats {
        grid-template-columns: repeat(2, 1fr);
        margin-top: 24px;
    }
    .asc-stat-total {
        grid-column: 1 / -1;  /* 總計獨佔一行 */
    }
    .asc-stat-period { top: -20px; }
    .asc-stat-value  { font-size: 22px; }

    /* Filter：全寬堆疊 */
    .asc-filter-field      { min-width: 100%; }
    .asc-filter-field--btn { min-width: 100%; }
    .asc-filter-field--btn .button { width: 100%; justify-content: center; }

    /* Modal → 底部滑入全寬 */
    .asc-modal         { align-items: flex-end; }
    .asc-modal-content {
        width: 100%;
        border-radius: 12px 12px 0 0;
        max-height: 88vh;
    }

    /* Toast → 底部置中 */
    .asc-toast {
        top: auto;
        bottom: 20px;
        left: 16px;
        right: 16px;
        text-align: center;
    }
}

/* ═══════════════════════════════════════════
   超窄 ≤ 480px
═══════════════════════════════════════════ */
@media screen and (max-width: 480px) {
    .asc-log-stats { grid-template-columns: 1fr 1fr; }
    .asc-stat-item { padding: 10px 8px; }
    .asc-stat-value { font-size: 20px; }
    .asc-log-card  { padding: 10px; }
    .asc-log-card-message { font-size: 12px; }
}
</style>
