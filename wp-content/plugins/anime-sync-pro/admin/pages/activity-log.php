<?php
/**
 * Activity Log Page — 操作紀錄（誰匯入/發布/修改/刪除了幾篇動畫）
 *
 * @package Anime_Sync_Pro
 * @version 1.1.0
 *
 * 放置位置：admin/pages/activity-log.php
 * 對應選單：由 class-admin.php 的 render_activity_page() 呼叫 safe_include_page()
 *
 * 1.1.0 — 新增「匯入」動作獨立統計（imported），與「發布」（published）分開追蹤
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$logger = new Anime_Sync_Activity_Logger();

$action_filter = isset( $_GET['aal_action'] )  ? sanitize_key( wp_unslash( $_GET['aal_action'] ) )  : '';
$user_filter   = isset( $_GET['aal_user'] )    ? absint( $_GET['aal_user'] )                        : 0;
$limit         = isset( $_GET['aal_limit'] )   ? absint( $_GET['aal_limit'] )                       : 100;
$days          = isset( $_GET['aal_days'] )    ? absint( $_GET['aal_days'] )                        : 0;
$limit         = $limit ?: 100;

$summary = $logger->get_summary( $days );
$totals  = $logger->get_totals( $days );
$logs    = $logger->get_logs( $limit, $action_filter, $user_filter );
$users   = $logger->get_distinct_users();

$nonce = wp_create_nonce( 'anime_sync_admin_nonce' );

$action_config = [
    'imported'  => [ 'label' => '匯入', 'color' => '#5b21b6', 'bg' => '#f1e9fb', 'icon' => '📥' ],
    'published' => [ 'label' => '發布', 'color' => '#2271b1', 'bg' => '#e8f4fd', 'icon' => '📢' ],
    'updated'   => [ 'label' => '修改', 'color' => '#996800', 'bg' => '#fff8e1', 'icon' => '✏️' ],
    'trashed'   => [ 'label' => '移至垃圾桶', 'color' => '#8a4b00', 'bg' => '#fdf1e6', 'icon' => '🗑️' ],
    'deleted'   => [ 'label' => '永久刪除', 'color' => '#dc3232', 'bg' => '#fce8e8', 'icon' => '❌' ],
];

$has_filter = $action_filter || $user_filter || $limit !== 100 || $days !== 0;
?>

<div class="wrap aal-wrap">

    <h1 class="wp-heading-inline">📊 <?php esc_html_e( '操作紀錄', 'anime-sync-pro' ); ?></h1>
    <hr class="wp-header-end">

    <!-- ───────────────── 全站統計 ───────────────── -->
    <div class="aal-stats">
        <div class="aal-stat-item aal-stat-total">
            <span class="aal-stat-value"><?php echo esc_html( $totals['total'] ?? 0 ); ?></span>
            <span class="aal-stat-label">總計</span>
        </div>
        <div class="aal-stat-item aal-stat-imported">
            <span class="aal-stat-value"><?php echo esc_html( $totals['imported'] ?? 0 ); ?></span>
            <span class="aal-stat-label">匯入</span>
        </div>
        <div class="aal-stat-item aal-stat-published">
            <span class="aal-stat-value"><?php echo esc_html( $totals['published'] ?? 0 ); ?></span>
            <span class="aal-stat-label">發布</span>
        </div>
        <div class="aal-stat-item aal-stat-updated">
            <span class="aal-stat-value"><?php echo esc_html( $totals['updated'] ?? 0 ); ?></span>
            <span class="aal-stat-label">修改</span>
        </div>
        <div class="aal-stat-item aal-stat-removed">
            <span class="aal-stat-value"><?php echo esc_html( $totals['removed'] ?? 0 ); ?></span>
            <span class="aal-stat-label">刪除／垃圾桶</span>
        </div>
        <div class="aal-stat-period"><?php echo $days > 0 ? '最近 ' . esc_html( $days ) . ' 天' : '全部時間'; ?></div>
    </div>

    <!-- ───────────────── 每位作者統計表 ───────────────── -->
    <h2 class="aal-section-title">👤 各作者統計</h2>
    <?php if ( ! empty( $summary ) ) : ?>
        <table class="wp-list-table widefat fixed striped aal-summary-table">
            <thead>
                <tr>
                    <th>使用者</th>
                    <th class="aal-col-num">匯入</th>
                    <th class="aal-col-num">發布</th>
                    <th class="aal-col-num">修改</th>
                    <th class="aal-col-num">刪除／垃圾桶</th>
                    <th class="aal-col-num">總計</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $summary as $row ) : ?>
                    <tr>
                        <td>
                            <a href="?page=anime-sync-activity&aal_user=<?php echo esc_attr( $row['user_id'] ); ?>&aal_days=<?php echo esc_attr( $days ); ?>">
                                <?php echo esc_html( $row['user_name'] ?: '（未知使用者）' ); ?>
                            </a>
                        </td>
                        <td class="aal-col-num"><?php echo esc_html( $row['imported'] ?? 0 ); ?></td>
                        <td class="aal-col-num"><?php echo esc_html( $row['published'] ); ?></td>
                        <td class="aal-col-num"><?php echo esc_html( $row['updated'] ); ?></td>
                        <td class="aal-col-num"><?php echo esc_html( $row['removed'] ); ?></td>
                        <td class="aal-col-num"><strong><?php echo esc_html( $row['total'] ); ?></strong></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php else : ?>
        <p class="aal-empty-note">
            <?php echo $days > 0
                ? '最近 ' . esc_html( $days ) . ' 天內尚無操作紀錄。'
                : '目前尚無操作紀錄。'; ?>
        </p>
    <?php endif; ?>

    <!-- ───────────────── 篩選（可折疊） ───────────────── -->
    <div class="aal-filter-wrap">
        <button type="button" class="aal-filter-toggle <?php echo $has_filter ? 'is-active' : ''; ?>" id="aal-filter-toggle">
            <span class="dashicons dashicons-filter"></span>
            篩選明細與管理
            <?php if ( $has_filter ) : ?><span class="aal-filter-badge">已套用</span><?php endif; ?>
            <span class="aal-toggle-arrow"><?php echo $has_filter ? '▴' : '▾'; ?></span>
        </button>

        <div class="aal-filter-body <?php echo $has_filter ? 'is-open' : ''; ?>" id="aal-filter-body">

            <form method="get" class="aal-filter-section">
                <input type="hidden" name="page" value="anime-sync-activity">
                <div class="aal-filter-section-title">🔍 篩選明細</div>
                <div class="aal-filter-row">
                    <div class="aal-filter-field">
                        <label for="aal_action">動作</label>
                        <select name="aal_action" id="aal_action">
                            <option value="">所有動作</option>
                            <?php foreach ( $action_config as $key => $cfg ) : ?>
                                <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $action_filter, $key ); ?>>
                                    <?php echo esc_html( $cfg['icon'] . ' ' . $cfg['label'] ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="aal-filter-field">
                        <label for="aal_user">使用者</label>
                        <select name="aal_user" id="aal_user">
                            <option value="0">所有使用者</option>
                            <?php foreach ( $users as $u ) : ?>
                                <option value="<?php echo esc_attr( $u['user_id'] ); ?>" <?php selected( $user_filter, (int) $u['user_id'] ); ?>>
                                    <?php echo esc_html( $u['user_name'] ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="aal-filter-field">
                        <label for="aal_days">統計區間</label>
                        <select name="aal_days" id="aal_days">
                            <option value="7"   <?php selected( $days, 7   ); ?>>近 7 天</option>
                            <option value="30"  <?php selected( $days, 30  ); ?>>近 30 天</option>
                            <option value="90"  <?php selected( $days, 90  ); ?>>近 90 天</option>
                            <option value="365" <?php selected( $days, 365 ); ?>>近 1 年</option>
                            <option value="0"   <?php selected( $days, 0   ); ?>>全部時間（含補登資料）</option>
                        </select>
                    </div>
                    <div class="aal-filter-field">
                        <label for="aal_limit">顯示筆數</label>
                        <select name="aal_limit" id="aal_limit">
                            <option value="100" <?php selected( $limit, 100 ); ?>>最近 100 筆</option>
                            <option value="300" <?php selected( $limit, 300 ); ?>>最近 300 筆</option>
                            <option value="1000" <?php selected( $limit, 1000 ); ?>>最近 1000 筆</option>
                        </select>
                    </div>
                    <div class="aal-filter-field aal-filter-field--btn">
                        <label>&nbsp;</label>
                        <button type="submit" class="button button-primary">
                            <span class="dashicons dashicons-search"></span> 套用篩選
                        </button>
                    </div>
                    <?php if ( $has_filter ) : ?>
                    <div class="aal-filter-field aal-filter-field--btn">
                        <label>&nbsp;</label>
                        <a href="?page=anime-sync-activity" class="button">重設</a>
                    </div>
                    <?php endif; ?>
                </div>
            </form>

            <div class="aal-filter-divider"></div>

            <div class="aal-filter-section">
                <div class="aal-filter-section-title">🗑️ 清除舊紀錄</div>
                <div class="aal-filter-row">
                    <div class="aal-filter-field">
                        <label for="aal-clear-days">清除範圍</label>
                        <select id="aal-clear-days">
                            <option value="30">30 天前的紀錄</option>
                            <option value="90" selected>90 天前的紀錄</option>
                            <option value="180">180 天前的紀錄</option>
                            <option value="365">365 天前的紀錄</option>
                        </select>
                    </div>
                    <div class="aal-filter-field aal-filter-field--btn">
                        <label>&nbsp;</label>
                        <button type="button" id="aal-clear-old" class="button button-link-delete">
                            <span class="dashicons dashicons-trash"></span> 清除舊紀錄
                        </button>
                    </div>
                </div>
            </div>

        </div><!-- .aal-filter-body -->
    </div><!-- .aal-filter-wrap -->

    <!-- ───────────────── 明細列表 ───────────────── -->
    <h2 class="aal-section-title">📄 操作明細</h2>

    <?php if ( ! empty( $logs ) ) : ?>

        <table class="wp-list-table widefat fixed striped aal-log-table aal-desktop-only">
            <thead>
                <tr>
                    <th class="aal-col-action">動作</th>
                    <th>使用者</th>
                    <th>動畫</th>
                    <th class="aal-col-time">時間</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $logs as $log ) :
                    $act = $log['action'] ?? '';
                    $cfg = $action_config[ $act ] ?? [ 'label' => $act, 'color' => '#555', 'bg' => '#f5f5f5', 'icon' => '' ];
                ?>
                    <tr>
                        <td>
                            <span class="aal-action-badge" style="color:<?php echo esc_attr( $cfg['color'] ); ?>;background:<?php echo esc_attr( $cfg['bg'] ); ?>;">
                                <?php echo esc_html( $cfg['icon'] . ' ' . $cfg['label'] ); ?>
                            </span>
                        </td>
                        <td><?php echo esc_html( $log['user_name'] ?: '（未知使用者）' ); ?></td>
                        <td>
                            <?php if ( ! empty( $log['post_id'] ) && get_post( $log['post_id'] ) ) : ?>
                                <a href="<?php echo esc_url( get_edit_post_link( $log['post_id'] ) ); ?>">
                                    <?php echo esc_html( $log['post_title'] ); ?>
                                </a>
                            <?php else : ?>
                                <?php echo esc_html( $log['post_title'] ); ?>
                                <span class="aal-deleted-note">（文章已不存在）</span>
                            <?php endif; ?>
                        </td>
                        <td class="aal-log-time"><?php echo esc_html( $log['created_at'] ); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div class="aal-log-cards aal-mobile-only">
            <?php foreach ( $logs as $log ) :
                $act = $log['action'] ?? '';
                $cfg = $action_config[ $act ] ?? [ 'label' => $act, 'color' => '#555', 'bg' => '#f5f5f5', 'icon' => '' ];
            ?>
                <div class="aal-log-card">
                    <div class="aal-log-card-header">
                        <span class="aal-action-badge" style="color:<?php echo esc_attr( $cfg['color'] ); ?>;background:<?php echo esc_attr( $cfg['bg'] ); ?>;">
                            <?php echo esc_html( $cfg['icon'] . ' ' . $cfg['label'] ); ?>
                        </span>
                        <span class="aal-log-card-time"><?php echo esc_html( $log['created_at'] ); ?></span>
                    </div>
                    <div class="aal-log-card-body">
                        <div><strong><?php echo esc_html( $log['user_name'] ?: '（未知使用者）' ); ?></strong></div>
                        <div>
                            <?php if ( ! empty( $log['post_id'] ) && get_post( $log['post_id'] ) ) : ?>
                                <a href="<?php echo esc_url( get_edit_post_link( $log['post_id'] ) ); ?>"><?php echo esc_html( $log['post_title'] ); ?></a>
                            <?php else : ?>
                                <?php echo esc_html( $log['post_title'] ); ?>
                                <span class="aal-deleted-note">（文章已不存在）</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

    <?php else : ?>
        <div class="aal-empty-state">
            <span class="dashicons dashicons-yes-alt"></span>
            <p>目前沒有符合條件的操作紀錄</p>
        </div>
    <?php endif; ?>

</div><!-- .wrap -->

<div id="aal-toast" class="aal-toast" style="display:none;"></div>

<script>
jQuery( document ).ready( function ( $ ) {
    var nonce = '<?php echo esc_js( $nonce ); ?>';

    function showToast( message, isError ) {
        var $toast = $( '#aal-toast' );
        $toast.text( message ).css( 'background', isError ? '#dc3232' : '#46b450' ).fadeIn( 200 );
        setTimeout( function () { $toast.fadeOut( 300 ); }, 2800 );
    }

    $( '#aal-filter-toggle' ).on( 'click', function () {
        var $body  = $( '#aal-filter-body' );
        var $arrow = $( this ).find( '.aal-toggle-arrow' );
        var open   = $body.toggleClass( 'is-open' ).hasClass( 'is-open' );
        $arrow.text( open ? '▴' : '▾' );
        $( this ).toggleClass( 'is-active', open );
    } );

    $( '#aal-clear-old' ).on( 'click', function () {
        var $btn = $( this );
        var days = parseInt( $( '#aal-clear-days' ).val(), 10 ) || 90;
        var originalText = $btn.html();

        if ( ! confirm( '確定要清除 ' + days + ' 天前的操作紀錄嗎？（' + days + ' 天內的紀錄會保留）' ) ) return;

        $btn.prop( 'disabled', true ).html( '清除中…' );

        $.ajax( {
            url: ajaxurl,
            type: 'POST',
            dataType: 'json',
            data: { action: 'asp_clear_activity_log', nonce: nonce, days: days },
            success: function ( resp ) {
                if ( resp && resp.success ) {
                    showToast( ( resp.data && resp.data.message ) || '清除完成', false );
                    setTimeout( function () { location.reload(); }, 1000 );
                } else {
                    showToast( ( resp && resp.data ) || '清除失敗', true );
                    $btn.prop( 'disabled', false ).html( originalText );
                }
            },
            error: function ( xhr ) {
                showToast( '網路錯誤 (HTTP ' + xhr.status + ')', true );
                $btn.prop( 'disabled', false ).html( originalText );
            }
        } );
    } );
} );
</script>

<style>
.aal-wrap { max-width: 100%; }

.aal-stats {
    display: grid;
    grid-template-columns: repeat( 5, 1fr );
    gap: 10px;
    margin: 16px 0 28px;
    position: relative;
}
.aal-stat-period { position: absolute; top: -18px; right: 0; font-size: 11px; color: #888; }
.aal-stat-item {
    background: #fff; border: 1px solid #e0e0e0; border-radius: 6px;
    padding: 14px 12px; text-align: center; display: flex; flex-direction: column; gap: 4px;
}
.aal-stat-value { font-size: 28px; font-weight: 700; line-height: 1; color: #23282d; }
.aal-stat-label { font-size: 12px; color: #777; text-transform: uppercase; letter-spacing: .4px; }
.aal-stat-total     { border-top: 3px solid #555; }
.aal-stat-imported  { border-top: 3px solid #5b21b6; }
.aal-stat-imported  .aal-stat-value { color: #5b21b6; }
.aal-stat-published { border-top: 3px solid #2271b1; }
.aal-stat-published .aal-stat-value { color: #2271b1; }
.aal-stat-updated   { border-top: 3px solid #996800; }
.aal-stat-updated   .aal-stat-value { color: #996800; }
.aal-stat-removed   { border-top: 3px solid #dc3232; }
.aal-stat-removed   .aal-stat-value { color: #dc3232; }

.aal-section-title { font-size: 15px; margin: 24px 0 10px; }

.aal-summary-table, .aal-log-table { font-size: 13px; }
.aal-col-num  { width: 90px; text-align: right; }
.aal-col-action { width: 130px; }
.aal-col-time { width: 160px; font-size: 12px; color: #666; white-space: nowrap; }
.aal-empty-note { color: #777; font-style: italic; }

.aal-action-badge {
    display: inline-block; padding: 3px 8px; border-radius: 4px;
    font-size: 11px; font-weight: 700; white-space: nowrap;
}
.aal-deleted-note { color: #bbb; font-size: 11px; }

.aal-filter-wrap { margin: 16px 0; }
.aal-filter-toggle {
    display: flex; align-items: center; gap: 6px; width: 100%; text-align: left;
    background: #fff; border: 1px solid #ddd; border-radius: 4px 4px 0 0;
    padding: 9px 14px; font-size: 13px; font-weight: 600; color: #23282d; cursor: pointer;
    transition: background .15s, color .15s;
}
.aal-filter-toggle:hover, .aal-filter-toggle.is-active { background: #f0f7fc; color: #0073aa; border-color: #0073aa; }
.aal-filter-badge { background: #f0a500; color: #fff; border-radius: 8px; padding: 1px 7px; font-size: 11px; font-weight: 600; margin-left: 2px; }
.aal-toggle-arrow { margin-left: auto; font-size: 11px; }
.aal-filter-body { display: none; background: #fff; border: 1px solid #ddd; border-top: none; border-radius: 0 0 4px 4px; padding: 16px; }
.aal-filter-body.is-open { display: block; }
.aal-filter-section-title { font-size: 12px; font-weight: 700; color: #555; text-transform: uppercase; letter-spacing: .4px; margin-bottom: 10px; }
.aal-filter-divider { border: none; border-top: 1px solid #f0f0f0; margin: 14px 0; }
.aal-filter-row { display: flex; flex-wrap: wrap; gap: 10px; align-items: flex-end; }
.aal-filter-field { display: flex; flex-direction: column; gap: 4px; min-width: 150px; }
.aal-filter-field label { font-size: 12px; color: #555; font-weight: 600; }
.aal-filter-field select { width: 100%; }
.aal-filter-field--btn { min-width: auto; }
.aal-backfill-note { font-size: 12px; color: #777; margin: 0 0 10px; line-height: 1.6; }

/* ============================================================
   桌機 / 手機版型「完全隔離」
   預設（桌機）：只顯示表格，強制隱藏卡片
   ============================================================ */
.aal-desktop-only { display: table !important; }
.aal-log-cards.aal-mobile-only { display: none !important; }

.aal-log-card { background: #fff; border: 1px solid #e0e0e0; border-radius: 6px; padding: 12px; box-shadow: 0 1px 3px rgba(0,0,0,.05); }
.aal-log-card-header { display: flex; align-items: center; justify-content: space-between; gap: 8px; margin-bottom: 8px; }
.aal-log-card-time { font-size: 11px; color: #888; white-space: nowrap; }
.aal-log-card-body { font-size: 13px; line-height: 1.6; }

.aal-empty-state { text-align: center; padding: 60px 20px; color: #777; border: 2px dashed #ddd; border-radius: 6px; margin-top: 16px; }
.aal-empty-state .dashicons { font-size: 48px; width: 48px; height: 48px; color: #46b450; display: block; margin: 0 auto 12px; }

.aal-toast {
    position: fixed; top: 50px; right: 20px; z-index: 100001;
    padding: 12px 20px; border-radius: 6px; box-shadow: 0 3px 10px rgba(0,0,0,.2);
    font-size: 14px; color: #fff; max-width: calc( 100vw - 40px );
}

/* ============================================================
   手機版（≤782px）：只顯示卡片，強制隱藏表格
   ============================================================ */
@media screen and ( max-width: 782px ) {
    .aal-stats { grid-template-columns: repeat( 2, 1fr ); margin-top: 24px; }
    .aal-stat-total { grid-column: 1 / -1; }
    .aal-stat-period { top: -20px; }
    .aal-filter-field, .aal-filter-field--btn { min-width: 100%; }
    .aal-filter-field--btn .button { width: 100%; justify-content: center; }

    .aal-desktop-only { display: none !important; }
    .aal-log-cards.aal-mobile-only { display: flex !important; flex-direction: column; gap: 8px; }
}
</style>
