<?php
/**
 * Admin Page: Review Queue
 *
 * @package Anime_Sync_Pro
 * @version 3.2.1
 *
 * Changelog:
 *  - 3.2.1 (2026-06-27):
 *      • 修正 PHP 錯誤：移除 array destructuring [$label, $color] 語法（PHP 7.0 不支援）。
 *      • 改用 list() 確保 PHP 5.6+ / 7.x 相容。
 *      • 移除所有 const / let 改用 var（避免嚴格模式衝突）。
 *      • rewind_posts() 雙迴圈改為單迴圈 + 資料預先快取，避免重複查詢。
 *      • 手機板響應式全面優化（繼承自 3.2.0）。
 *  - 3.2.0 (2026-06-27):
 *      • 手機板響應式全面優化。
 *      • 篩選列改為可折疊 collapsible filter bar。
 *      • 表格在手機板切換為卡片式佈局。
 *  - 3.1.0 (2026-06-19):
 *      • 篩選列新增「格式」與「動畫狀態」下拉。
 *      • meta_query 對應加入 filter_format / filter_anime_status。
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! current_user_can( 'edit_posts' ) ) {
    wp_die( esc_html__( '您沒有權限存取此頁面。', 'anime-sync-pro' ) );
}

/* ───────────────────────────────────────────────
   Collect filter params
─────────────────────────────────────────────── */
$filter_status       = isset( $_GET['filter_status'] )       ? sanitize_text_field( wp_unslash( $_GET['filter_status'] ) )       : 'draft';
$filter_season       = isset( $_GET['filter_season'] )       ? sanitize_text_field( wp_unslash( $_GET['filter_season'] ) )       : '';
$filter_year         = isset( $_GET['filter_year'] )         ? (int) $_GET['filter_year']                                        : 0;
$filter_pending      = isset( $_GET['filter_pending'] )      ? (bool) $_GET['filter_pending']                                    : false;
$filter_format       = isset( $_GET['filter_format'] )       ? sanitize_text_field( wp_unslash( $_GET['filter_format'] ) )       : '';
$filter_anime_status = isset( $_GET['filter_anime_status'] ) ? sanitize_text_field( wp_unslash( $_GET['filter_anime_status'] ) ) : '';
$paged               = max( 1, isset( $_GET['paged'] ) ? (int) $_GET['paged'] : 1 );
$per_page            = 20;

/* ───────────────────────────────────────────────
   Build WP_Query args
─────────────────────────────────────────────── */
$query_args = array(
    'post_type'      => 'anime',
    'post_status'    => in_array( $filter_status, array( 'draft', 'publish', 'any' ), true ) ? $filter_status : 'draft',
    'posts_per_page' => $per_page,
    'paged'          => $paged,
    'orderby'        => 'date',
    'order'          => 'DESC',
    'meta_query'     => array(),
);

if ( $filter_season ) {
    $query_args['meta_query'][] = array(
        'key'     => 'anime_season',
        'value'   => strtoupper( $filter_season ),
        'compare' => '=',
    );
}
if ( $filter_year > 0 ) {
    $query_args['meta_query'][] = array(
        'key'     => 'anime_season_year',
        'value'   => $filter_year,
        'compare' => '=',
        'type'    => 'NUMERIC',
    );
}
if ( $filter_format ) {
    $query_args['meta_query'][] = array(
        'key'     => 'anime_format',
        'value'   => $filter_format,
        'compare' => '=',
    );
}
if ( $filter_anime_status ) {
    $query_args['meta_query'][] = array(
        'key'     => 'anime_status',
        'value'   => $filter_anime_status,
        'compare' => '=',
    );
}
if ( $filter_pending ) {
    $query_args['meta_query'][] = array(
        'key'     => '_bangumi_id_pending',
        'value'   => '1',
        'compare' => '=',
    );
}
if ( ! empty( $query_args['meta_query'] ) && count( $query_args['meta_query'] ) > 1 ) {
    $query_args['meta_query']['relation'] = 'AND';
}

$anime_query = new WP_Query( $query_args );
$total_posts = $anime_query->found_posts;
$total_pages = $anime_query->max_num_pages;

/* ───────────────────────────────────────────────
   Pending Bangumi count
─────────────────────────────────────────────── */
$pending_q = new WP_Query( array(
    'post_type'      => 'anime',
    'post_status'    => 'any',
    'posts_per_page' => -1,
    'fields'         => 'ids',
    'meta_query'     => array(
        array( 'key' => '_bangumi_id_pending', 'value' => '1', 'compare' => '=' )
    ),
) );
$pending_count = (int) $pending_q->found_posts;

$current_year = (int) date( 'Y' );
$years        = range( $current_year + 1, 2015 );
$seasons      = array( 'WINTER', 'SPRING', 'SUMMER', 'FALL' );
$formats      = array( 'TV', 'TV_SHORT', 'MOVIE', 'SPECIAL', 'OVA', 'ONA', 'MUSIC' );

$anime_statuses = array(
    'FINISHED'         => '完結',
    'RELEASING'        => '播出中',
    'NOT_YET_RELEASED' => '未播出',
    'CANCELLED'        => '取消',
    'HIATUS'           => '休刊',
);

$status_labels_map = array(
    'FINISHED'         => array( '完結',   '#46b450' ),
    'RELEASING'        => array( '播出中', '#0073aa' ),
    'NOT_YET_RELEASED' => array( '未播出', '#888888' ),
    'CANCELLED'        => array( '取消',   '#dc3232' ),
    'HIATUS'           => array( '休刊',   '#f0a500' ),
);

$gap_cached_at_raw = get_option( 'anime_sync_series_gaps_time', '' );
$gap_cached_at     = $gap_cached_at_raw ? strtotime( $gap_cached_at_raw ) : 0;

$has_active_filter = $filter_season || $filter_year || $filter_format
                     || $filter_anime_status || $filter_pending
                     || $filter_status !== 'draft';

/* ───────────────────────────────────────────────
   Pre-cache post data（單次迴圈，桌面 + 手機共用）
─────────────────────────────────────────────── */
$cached_posts = array();

if ( $anime_query->have_posts() ) {
    while ( $anime_query->have_posts() ) {
        $anime_query->the_post();
        $pid = get_the_ID();

        $anilist_id  = get_post_meta( $pid, 'anime_anilist_id',    true );
        $bangumi_id  = get_post_meta( $pid, 'anime_bangumi_id',    true );
        $format      = get_post_meta( $pid, 'anime_format',        true );
        $season      = get_post_meta( $pid, 'anime_season',        true );
        $season_year = get_post_meta( $pid, 'anime_season_year',   true );
        $status      = get_post_meta( $pid, 'anime_status',        true );
        $last_sync   = get_post_meta( $pid, 'anime_sync_time',     true )
                       ?: get_post_meta( $pid, 'anime_last_sync',  true );
        $is_pending  = get_post_meta( $pid, '_bangumi_id_pending', true );
        $title_cn    = get_post_meta( $pid, 'anime_title_chinese', true );

        $cover_image = '';
        if ( has_post_thumbnail( $pid ) ) {
            $cover_image = get_the_post_thumbnail_url( $pid, 'medium' );
        }
        if ( empty( $cover_image ) ) {
            $cover_image = get_post_meta( $pid, 'anime_cover_image', true );
        }

        $cached_posts[] = array(
            'post_id'      => $pid,
            'title'        => get_the_title(),
            'title_cn'     => $title_cn,
            'edit_url'     => get_edit_post_link( $pid ),
            'view_url'     => get_permalink( $pid ),
            'post_status'  => get_post_status( $pid ),
            'anilist_id'   => $anilist_id,
            'bangumi_id'   => $bangumi_id,
            'format'       => $format,
            'season'       => $season,
            'season_year'  => $season_year,
            'status'       => $status,
            'last_sync'    => $last_sync,
            'is_pending'   => $is_pending,
            'cover_image'  => $cover_image,
            'season_label' => ( $season && $season_year ) ? esc_html( $season . ' ' . $season_year ) : '—',
            'sync_label'   => $last_sync
                              ? esc_html( wp_date( 'Y-m-d H:i', strtotime( $last_sync ) ) )
                              : '從未',
        );
    }
    wp_reset_postdata();
}
?>

<div class="wrap asc-rq-wrap">

    <h1 class="wp-heading-inline">
        <?php esc_html_e( '審核佇列', 'anime-sync-pro' ); ?>
    </h1>

    <?php if ( $pending_count > 0 ) : ?>
        <span class="asc-badge asc-badge--red">
            <?php echo esc_html( $pending_count . ' 筆 Bangumi ID 待處理' ); ?>
        </span>
    <?php endif; ?>

    <hr class="wp-header-end">

    <!-- ───────────────── Filter (Collapsible) ───────────────── -->
    <div class="asc-filter-wrap">
        <button type="button"
                class="asc-filter-toggle <?php echo $has_active_filter ? 'is-active' : ''; ?>"
                id="asc-filter-toggle">
            <span class="dashicons dashicons-filter"></span>
            <?php esc_html_e( '篩選條件', 'anime-sync-pro' ); ?>
            <?php if ( $has_active_filter ) : ?>
                <span class="asc-badge asc-badge--orange asc-filter-active-badge">已套用</span>
            <?php endif; ?>
            <span class="asc-toggle-arrow"><?php echo $has_active_filter ? '▴' : '▾'; ?></span>
        </button>

        <form method="get"
              class="asc-filter-form <?php echo $has_active_filter ? 'is-open' : ''; ?>"
              id="asc-filter-form">
            <input type="hidden" name="page" value="anime-sync-queue" />

            <div class="asc-filter-grid">

                <div class="asc-filter-item">
                    <label><?php esc_html_e( '文章狀態', 'anime-sync-pro' ); ?></label>
                    <select name="filter_status">
                        <option value="draft"   <?php selected( $filter_status, 'draft' );   ?>><?php esc_html_e( '草稿',   'anime-sync-pro' ); ?></option>
                        <option value="publish" <?php selected( $filter_status, 'publish' ); ?>><?php esc_html_e( '已發佈', 'anime-sync-pro' ); ?></option>
                        <option value="any"     <?php selected( $filter_status, 'any' );     ?>><?php esc_html_e( '全部',   'anime-sync-pro' ); ?></option>
                    </select>
                </div>

                <div class="asc-filter-item">
                    <label><?php esc_html_e( '格式', 'anime-sync-pro' ); ?></label>
                    <select name="filter_format">
                        <option value=""><?php esc_html_e( '全部格式', 'anime-sync-pro' ); ?></option>
                        <?php foreach ( $formats as $f ) : ?>
                            <option value="<?php echo esc_attr( $f ); ?>" <?php selected( $filter_format, $f ); ?>>
                                <?php echo esc_html( $f ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="asc-filter-item">
                    <label><?php esc_html_e( '季節', 'anime-sync-pro' ); ?></label>
                    <select name="filter_season">
                        <option value=""><?php esc_html_e( '全季節', 'anime-sync-pro' ); ?></option>
                        <?php foreach ( $seasons as $s ) : ?>
                            <option value="<?php echo esc_attr( $s ); ?>" <?php selected( $filter_season, $s ); ?>>
                                <?php echo esc_html( $s ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="asc-filter-item">
                    <label><?php esc_html_e( '年份', 'anime-sync-pro' ); ?></label>
                    <select name="filter_year">
                        <option value="0"><?php esc_html_e( '全年份', 'anime-sync-pro' ); ?></option>
                        <?php foreach ( $years as $y ) : ?>
                            <option value="<?php echo esc_attr( $y ); ?>" <?php selected( $filter_year, $y ); ?>>
                                <?php echo esc_html( $y ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="asc-filter-item">
                    <label><?php esc_html_e( '動畫狀態', 'anime-sync-pro' ); ?></label>
                    <select name="filter_anime_status">
                        <option value=""><?php esc_html_e( '全部狀態', 'anime-sync-pro' ); ?></option>
                        <?php foreach ( $anime_statuses as $key => $label ) : ?>
                            <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $filter_anime_status, $key ); ?>>
                                <?php echo esc_html( $label ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="asc-filter-item asc-filter-item--checkbox">
                    <label>
                        <input type="checkbox" name="filter_pending" value="1"
                               <?php checked( $filter_pending ); ?> />
                        <?php esc_html_e( '僅顯示 Bangumi 待處理', 'anime-sync-pro' ); ?>
                    </label>
                </div>

            </div>

            <div class="asc-filter-actions">
                <button type="submit" class="button button-primary">
                    <span class="dashicons dashicons-search"></span>
                    <?php esc_html_e( '篩選', 'anime-sync-pro' ); ?>
                </button>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=anime-sync-queue' ) ); ?>"
                   class="button"><?php esc_html_e( '重設', 'anime-sync-pro' ); ?></a>
                <span class="asc-filter-count">
                    <?php echo esc_html( '共 ' . number_format_i18n( $total_posts ) . ' 筆' ); ?>
                </span>
            </div>

        </form>
    </div>

    <!-- ───────────────── Gap Scan ───────────────── -->
    <div class="asc-gap-scan-bar">
        <div class="asc-gap-scan-title">
            <strong>📡 <?php esc_html_e( '系列缺漏掃描', 'anime-sync-pro' ); ?></strong>
            <?php if ( $gap_cached_at > 0 ) : ?>
                <span class="asc-gap-cache-info">
                    <?php echo esc_html( '快取：' . wp_date( 'Y-m-d H:i', $gap_cached_at ) . '（6h 有效）' ); ?>
                </span>
            <?php endif; ?>
        </div>
        <div class="asc-gap-scan-actions">
            <button type="button" id="btn-scan-gaps" class="button button-primary">
                <?php esc_html_e( '開始掃描', 'anime-sync-pro' ); ?>
            </button>
            <button type="button" id="btn-scan-gaps-force" class="button">
                <?php esc_html_e( '強制重新掃描', 'anime-sync-pro' ); ?>
            </button>
            <span id="gap-scan-status" class="asc-gap-scan-status"></span>
        </div>
    </div>

    <div id="gap-scan-result" style="display:none;margin-bottom:20px;"></div>

    <!-- ───────────────── Bulk Actions ───────────────── -->
    <div class="asc-bulk-bar">
        <select id="bulk-action-select">
            <option value=""><?php esc_html_e( '批次動作', 'anime-sync-pro' ); ?></option>
            <option value="publish"><?php esc_html_e( '批次發佈', 'anime-sync-pro' ); ?></option>
            <option value="draft"><?php esc_html_e( '批次轉為草稿', 'anime-sync-pro' ); ?></option>
            <option value="delete"><?php esc_html_e( '批次刪除', 'anime-sync-pro' ); ?></option>
            <option value="refetch"><?php esc_html_e( '批次重新抓取資料', 'anime-sync-pro' ); ?></option>
        </select>
        <button type="button" id="btn-bulk-apply" class="button">
            <?php esc_html_e( '套用', 'anime-sync-pro' ); ?>
        </button>
        <span id="bulk-action-result" class="asc-bulk-result"></span>
    </div>

    <?php if ( ! empty( $cached_posts ) ) : ?>

    <!-- ───────────────── Desktop Table ───────────────── -->
    <table class="wp-list-table widefat fixed striped asc-queue-table asc-desktop-only">
        <thead>
            <tr>
                <td class="manage-column column-cb check-column">
                    <input type="checkbox" id="queue-select-all" />
                </td>
                <th style="width:70px;"><?php esc_html_e( '封面', 'anime-sync-pro' ); ?></th>
                <th><?php esc_html_e( '標題', 'anime-sync-pro' ); ?></th>
                <th style="width:80px;"><?php esc_html_e( '格式', 'anime-sync-pro' ); ?></th>
                <th style="width:100px;"><?php esc_html_e( '季度', 'anime-sync-pro' ); ?></th>
                <th style="width:70px;"><?php esc_html_e( '狀態', 'anime-sync-pro' ); ?></th>
                <th style="width:85px;">AniList</th>
                <th style="width:100px;">Bangumi</th>
                <th style="width:110px;"><?php esc_html_e( '上次同步', 'anime-sync-pro' ); ?></th>
                <th style="width:165px;"><?php esc_html_e( '操作', 'anime-sync-pro' ); ?></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ( $cached_posts as $p ) :
            $sl = isset( $status_labels_map[ $p['status'] ] ) ? $status_labels_map[ $p['status'] ] : array( '', '#888' );
        ?>
            <tr data-post-id="<?php echo esc_attr( $p['post_id'] ); ?>"
                class="<?php echo $p['is_pending'] ? 'bangumi-pending' : ''; ?>">

                <th scope="row" class="check-column">
                    <input type="checkbox" class="queue-item-checkbox"
                           value="<?php echo esc_attr( $p['post_id'] ); ?>" />
                </th>

                <td>
                    <?php if ( $p['cover_image'] ) : ?>
                        <img src="<?php echo esc_url( $p['cover_image'] ); ?>"
                             alt="" class="asc-cover-img" />
                    <?php else : ?>
                        <div class="asc-cover-placeholder">N/A</div>
                    <?php endif; ?>
                </td>

                <td>
                    <strong>
                        <a href="<?php echo esc_url( $p['edit_url'] ); ?>">
                            <?php echo esc_html( $p['title_cn'] ? $p['title_cn'] : $p['title'] ); ?>
                        </a>
                    </strong>
                    <br>
                    <span class="asc-subtitle"><?php echo esc_html( $p['title'] ); ?></span>
                    <?php if ( $p['is_pending'] ) : ?>
                        <br>
                        <span class="asc-badge asc-badge--orange">Bangumi 待處理</span>
                    <?php endif; ?>
                </td>

                <td><?php echo esc_html( $p['format'] ? $p['format'] : '—' ); ?></td>
                <td><?php echo $p['season_label']; ?></td>

                <td>
                    <?php if ( $p['status'] && isset( $status_labels_map[ $p['status'] ] ) ) :
                        $sl = $status_labels_map[ $p['status'] ];
                    ?>
                        <span style="color:<?php echo esc_attr( $sl[1] ); ?>;font-weight:600;">
                            <?php echo esc_html( $sl[0] ); ?>
                        </span>
                    <?php else : ?>
                        —
                    <?php endif; ?>
                </td>

                <td>
                    <?php if ( $p['anilist_id'] ) : ?>
                        <a href="https://anilist.co/anime/<?php echo esc_attr( $p['anilist_id'] ); ?>/"
                           target="_blank" rel="noopener">
                            <?php echo esc_html( $p['anilist_id'] ); ?>
                        </a>
                    <?php else : ?>—<?php endif; ?>
                </td>

                <td>
                    <?php if ( $p['bangumi_id'] && ! $p['is_pending'] ) : ?>
                        <a href="https://bgm.tv/subject/<?php echo esc_attr( $p['bangumi_id'] ); ?>"
                           target="_blank" rel="noopener">
                            <?php echo esc_html( $p['bangumi_id'] ); ?>
                        </a>
                    <?php elseif ( $p['is_pending'] ) : ?>
                        <div class="bangumi-id-edit">
                            <input type="number" class="bangumi-id-input small-text"
                                   placeholder="ID" min="1"
                                   value="<?php echo esc_attr( $p['bangumi_id'] ); ?>"
                                   data-post-id="<?php echo esc_attr( $p['post_id'] ); ?>"
                                   style="width:70px;" />
                            <button type="button" class="button button-small btn-save-bangumi-id"
                                    data-post-id="<?php echo esc_attr( $p['post_id'] ); ?>">
                                <?php esc_html_e( '儲存', 'anime-sync-pro' ); ?>
                            </button>
                        </div>
                    <?php else : ?>—<?php endif; ?>
                </td>

                <td class="asc-sync-time"><?php echo $p['sync_label']; ?></td>

                <td>
                    <div class="asc-action-group">
                        <?php if ( $p['post_status'] === 'draft' ) : ?>
                            <button type="button"
                                    class="button button-small btn-publish-one asc-action-btn"
                                    data-post-id="<?php echo esc_attr( $p['post_id'] ); ?>"
                                    title="發佈">
                                <span class="dashicons dashicons-upload"></span>
                                <span class="asc-btn-label">發佈</span>
                            </button>
                        <?php else : ?>
                            <button type="button"
                                    class="button button-small btn-unpublish-one asc-action-btn"
                                    data-post-id="<?php echo esc_attr( $p['post_id'] ); ?>"
                                    title="轉草稿">
                                <span class="dashicons dashicons-download"></span>
                                <span class="asc-btn-label">轉草稿</span>
                            </button>
                        <?php endif; ?>

                        <button type="button"
                                class="button button-small btn-refetch-one asc-action-btn"
                                data-post-id="<?php echo esc_attr( $p['post_id'] ); ?>"
                                data-anilist-id="<?php echo esc_attr( $p['anilist_id'] ); ?>"
                                title="重抓">
                            <span class="dashicons dashicons-update"></span>
                            <span class="asc-btn-label">重抓</span>
                        </button>

                        <a href="<?php echo esc_url( $p['edit_url'] ); ?>"
                           class="button button-small asc-action-btn" title="編輯">
                            <span class="dashicons dashicons-edit"></span>
                            <span class="asc-btn-label">編輯</span>
                        </a>

                        <?php if ( $p['post_status'] === 'publish' ) : ?>
                            <a href="<?php echo esc_url( $p['view_url'] ); ?>"
                               class="button button-small asc-action-btn"
                               target="_blank" rel="noopener" title="檢視">
                                <span class="dashicons dashicons-visibility"></span>
                                <span class="asc-btn-label">檢視</span>
                            </a>
                        <?php endif; ?>

                        <button type="button"
                                class="button button-small button-link-delete btn-delete-one asc-action-btn"
                                data-post-id="<?php echo esc_attr( $p['post_id'] ); ?>"
                                title="刪除">
                            <span class="dashicons dashicons-trash"></span>
                            <span class="asc-btn-label">刪除</span>
                        </button>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <!-- ───────────────── Mobile Cards ───────────────── -->
    <div class="asc-mobile-cards asc-mobile-only">

        <div class="asc-mobile-select-all">
            <label>
                <input type="checkbox" id="queue-select-all-mobile" />
                <?php esc_html_e( '全選', 'anime-sync-pro' ); ?>
            </label>
        </div>

        <?php foreach ( $cached_posts as $p ) : ?>
            <div class="asc-card <?php echo $p['is_pending'] ? 'asc-card--pending' : ''; ?>"
                 data-post-id="<?php echo esc_attr( $p['post_id'] ); ?>">

                <div class="asc-card-header">
                    <input type="checkbox" class="queue-item-checkbox asc-card-checkbox"
                           value="<?php echo esc_attr( $p['post_id'] ); ?>" />

                    <?php if ( $p['cover_image'] ) : ?>
                        <img src="<?php echo esc_url( $p['cover_image'] ); ?>"
                             alt="" class="asc-card-cover" />
                    <?php else : ?>
                        <div class="asc-card-cover asc-cover-placeholder">N/A</div>
                    <?php endif; ?>

                    <div class="asc-card-title-block">
                        <a href="<?php echo esc_url( $p['edit_url'] ); ?>" class="asc-card-title">
                            <?php echo esc_html( $p['title_cn'] ? $p['title_cn'] : $p['title'] ); ?>
                        </a>
                        <div class="asc-card-romaji"><?php echo esc_html( $p['title'] ); ?></div>
                        <div class="asc-card-badges">
                            <?php if ( $p['format'] ) : ?>
                                <span class="asc-tag"><?php echo esc_html( $p['format'] ); ?></span>
                            <?php endif; ?>
                            <?php if ( $p['status'] && isset( $status_labels_map[ $p['status'] ] ) ) :
                                $sl = $status_labels_map[ $p['status'] ];
                            ?>
                                <span class="asc-tag"
                                      style="color:<?php echo esc_attr( $sl[1] ); ?>;border-color:<?php echo esc_attr( $sl[1] ); ?>;">
                                    <?php echo esc_html( $sl[0] ); ?>
                                </span>
                            <?php endif; ?>
                            <?php if ( $p['post_status'] === 'publish' ) : ?>
                                <span class="asc-tag asc-tag--green">已發佈</span>
                            <?php else : ?>
                                <span class="asc-tag asc-tag--gray">草稿</span>
                            <?php endif; ?>
                            <?php if ( $p['is_pending'] ) : ?>
                                <span class="asc-tag asc-tag--orange">Bangumi 待處理</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="asc-card-meta">
                    <div class="asc-card-meta-item">
                        <span class="asc-meta-label">季度</span>
                        <span><?php echo $p['season_label']; ?></span>
                    </div>
                    <div class="asc-card-meta-item">
                        <span class="asc-meta-label">AniList</span>
                        <?php if ( $p['anilist_id'] ) : ?>
                            <a href="https://anilist.co/anime/<?php echo esc_attr( $p['anilist_id'] ); ?>/"
                               target="_blank" rel="noopener">
                                <?php echo esc_html( $p['anilist_id'] ); ?>
                            </a>
                        <?php else : ?>
                            <span>—</span>
                        <?php endif; ?>
                    </div>
                    <div class="asc-card-meta-item">
                        <span class="asc-meta-label">Bangumi</span>
                        <?php if ( $p['bangumi_id'] && ! $p['is_pending'] ) : ?>
                            <a href="https://bgm.tv/subject/<?php echo esc_attr( $p['bangumi_id'] ); ?>"
                               target="_blank" rel="noopener">
                                <?php echo esc_html( $p['bangumi_id'] ); ?>
                            </a>
                        <?php elseif ( $p['is_pending'] ) : ?>
                            <div class="bangumi-id-edit" style="display:flex;gap:4px;flex-wrap:wrap;">
                                <input type="number" class="bangumi-id-input small-text"
                                       placeholder="ID" min="1"
                                       value="<?php echo esc_attr( $p['bangumi_id'] ); ?>"
                                       data-post-id="<?php echo esc_attr( $p['post_id'] ); ?>"
                                       style="width:80px;" />
                                <button type="button" class="button button-small btn-save-bangumi-id"
                                        data-post-id="<?php echo esc_attr( $p['post_id'] ); ?>">
                                    儲存
                                </button>
                            </div>
                        <?php else : ?>
                            <span>—</span>
                        <?php endif; ?>
                    </div>
                    <div class="asc-card-meta-item">
                        <span class="asc-meta-label">上次同步</span>
                        <span><?php echo $p['sync_label']; ?></span>
                    </div>
                </div>

                <div class="asc-card-actions">
                    <?php if ( $p['post_status'] === 'draft' ) : ?>
                        <button type="button"
                                class="button button-primary button-small btn-publish-one"
                                data-post-id="<?php echo esc_attr( $p['post_id'] ); ?>">
                            📤 發佈
                        </button>
                    <?php else : ?>
                        <button type="button"
                                class="button button-small btn-unpublish-one"
                                data-post-id="<?php echo esc_attr( $p['post_id'] ); ?>">
                            📥 轉草稿
                        </button>
                    <?php endif; ?>

                    <button type="button"
                            class="button button-small btn-refetch-one"
                            data-post-id="<?php echo esc_attr( $p['post_id'] ); ?>"
                            data-anilist-id="<?php echo esc_attr( $p['anilist_id'] ); ?>">
                        🔄 重抓
                    </button>

                    <a href="<?php echo esc_url( $p['edit_url'] ); ?>"
                       class="button button-small">✏️ 編輯</a>

                    <?php if ( $p['post_status'] === 'publish' ) : ?>
                        <a href="<?php echo esc_url( $p['view_url'] ); ?>"
                           class="button button-small"
                           target="_blank" rel="noopener">👁️ 檢視</a>
                    <?php endif; ?>

                    <button type="button"
                            class="button button-small button-link-delete btn-delete-one"
                            data-post-id="<?php echo esc_attr( $p['post_id'] ); ?>">
                        🗑️ 刪除
                    </button>
                </div>

            </div>
        <?php endforeach; ?>
    </div>

    <!-- ───────────────── Pagination ───────────────── -->
    <?php if ( $total_pages > 1 ) : ?>
        <div class="tablenav bottom asc-pagination-wrap">
            <div class="tablenav-pages">
                <?php
                $base_url = add_query_arg( array(
                    'page'                => 'anime-sync-queue',
                    'filter_status'       => $filter_status,
                    'filter_format'       => $filter_format,
                    'filter_season'       => $filter_season,
                    'filter_year'         => $filter_year,
                    'filter_anime_status' => $filter_anime_status,
                    'filter_pending'      => $filter_pending ? '1' : '',
                ), admin_url( 'admin.php' ) );

                echo paginate_links( array(
                    'base'      => $base_url . '%_%',
                    'format'    => '&paged=%#%',
                    'current'   => $paged,
                    'total'     => $total_pages,
                    'prev_text' => '&laquo;',
                    'next_text' => '&raquo;',
                ) );
                ?>
            </div>
        </div>
    <?php endif; ?>

    <?php else : ?>
        <div class="asc-empty-state">
            <span class="dashicons dashicons-format-video"></span>
            <p><?php esc_html_e( '目前沒有符合條件的動漫。', 'anime-sync-pro' ); ?></p>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=anime-sync-import' ) ); ?>"
               class="button button-primary">
                <?php esc_html_e( '前往匯入工具', 'anime-sync-pro' ); ?>
            </a>
        </div>
    <?php endif; ?>

</div>

<!-- Toast -->
<div id="rq-toast" class="asc-toast" style="display:none;"></div>

<!-- ───────────────── JS ───────────────── -->
<script>
( function ( $ ) {
    'use strict';

    function showToast( message, isError ) {
        var $toast = $( '#rq-toast' );
        $toast.text( message )
              .css( 'background', isError ? '#dc3232' : '#46b450' )
              .fadeIn( 200 );
        setTimeout( function () { $toast.fadeOut( 300 ); }, 2500 );
    }

    var relationColors = {
        'PREQUEL'    : { bg: '#e8f4fd', color: '#0073aa' },
        'SEQUEL'     : { bg: '#e8f4fd', color: '#0073aa' },
        'PARENT'     : { bg: '#edfaef', color: '#46b450' },
        'SIDE_STORY' : { bg: '#fff8e1', color: '#f0a500' },
        'SPIN_OFF'   : { bg: '#fce4ec', color: '#dc3232' },
    };

    /* ── Collapsible filter ── */
    $( '#asc-filter-toggle' ).on( 'click', function () {
        var $form  = $( '#asc-filter-form' );
        var $arrow = $( this ).find( '.asc-toggle-arrow' );
        var open   = $form.toggleClass( 'is-open' ).hasClass( 'is-open' );
        $arrow.text( open ? '▴' : '▾' );
        $( this ).toggleClass( 'is-active', open );
    } );

    /* ── Select-all desktop ── */
    $( '#queue-select-all' ).on( 'change', function () {
        $( '.queue-item-checkbox' ).prop( 'checked', this.checked );
        $( '#queue-select-all-mobile' ).prop( 'checked', this.checked );
    } );

    /* ── Select-all mobile ── */
    $( '#queue-select-all-mobile' ).on( 'change', function () {
        $( '.queue-item-checkbox' ).prop( 'checked', this.checked );
        $( '#queue-select-all' ).prop( 'checked', this.checked );
    } );

    function getCheckedIds() {
        return $( '.queue-item-checkbox:checked' ).map( function () {
            return parseInt( $( this ).val(), 10 );
        } ).get();
    }

    /* ── Gap Scan ── */
    $( '#btn-scan-gaps, #btn-scan-gaps-force' ).on( 'click', function () {
        var $clicked = $( this );
        var ids      = getCheckedIds();
        var force    = $clicked.is( '#btn-scan-gaps-force' );
        var $status  = $( '#gap-scan-status' );
        var $result  = $( '#gap-scan-result' );

        if ( ids.length === 0 ) {
            alert( '<?php echo esc_js( '請先在審核列表勾選要掃描的動漫作品' ); ?>' );
            return;
        }

        $clicked.prop( 'disabled', true );
        $status.text( '掃描中…' );
        $result.hide().empty();

        $.post( ajaxurl, {
            action       : 'anime_sync_scan_series_gaps',
            nonce        : animeSyncAdmin.nonce,
            force        : force ? 1 : 0,
            selected_ids : ids.join( ',' ),
        }, function ( resp ) {
            $clicked.prop( 'disabled', false );
            if ( resp.success ) {
                var gaps = resp.data.gaps || [];
                var html = '<div class="asc-gap-result-wrap">';
                html += '<h3>📡 系列缺漏掃描結果（已勾選 ' + ids.length + ' 部）</h3>';

                if ( gaps.length === 0 ) {
                    html += '<p class="asc-gap-ok">✓ 所有系列關聯都已齊全。</p>';
                } else {
                    html += '<p class="asc-gap-error">找到 ' + gaps.length + ' 個缺漏：</p>';
                    html += '<table class="wp-list-table widefat fixed striped asc-gap-table">';
                    html += '<thead><tr><th>來源</th><th>類型</th><th>AniList ID</th><th>標題</th><th>操作</th></tr></thead><tbody>';
                    $.each( gaps, function ( i, gap ) {
                        var relInfo  = relationColors[ gap.relation_type ] || { bg: '#f1f1f1', color: '#333' };
                        var missing  = gap.missing_anilist_id || '';
                        var title    = gap.missing_title || '（無標題）';
                        var relTypeCN = gap.relation_type_cn || gap.relation_type || '';
                        html += '<tr>';
                        html += '<td>' + $( '<span>' ).text( gap.source_title || '' ).html() + '</td>';
                        html += '<td><span class="asc-rel-badge" style="background:' + relInfo.bg + ';color:' + relInfo.color + ';">' + $( '<span>' ).text( relTypeCN ).html() + '</span></td>';
                        html += '<td><a href="https://anilist.co/anime/' + missing + '" target="_blank">' + missing + '</a></td>';
                        html += '<td>' + $( '<span>' ).text( title ).html() + '</td>';
                        html += '<td>';
                        html += '<a href="' + ( gap.source_url || '#' ) + '" target="_blank" class="button button-small">編輯來源</a> ';
                        html += '<button type="button" class="button button-small button-primary btn-gap-import" data-anilist-id="' + missing + '" data-title="' + $( '<span>' ).text( title ).html() + '">立即匯入</button>';
                        html += '</td></tr>';
                    } );
                    html += '</tbody></table>';
                }
                html += '</div>';
                $result.html( html ).show();
                $status.text( '完成' );
            } else {
                $status.text( '失敗' );
                showToast( resp.data || '掃描失敗', true );
            }
        } ).fail( function () {
            $clicked.prop( 'disabled', false );
            $status.text( '網路錯誤' );
        } );
    } );

    /* ── Gap Import ── */
    $( document ).on( 'click', '.btn-gap-import', function () {
        var $btn      = $( this );
        var anilistId = $btn.data( 'anilist-id' );
        var title     = $btn.data( 'title' );
        if ( ! confirm( '確定要匯入「' + title + '」（AniList ID: ' + anilistId + '）？' ) ) return;
        $btn.prop( 'disabled', true ).text( '匯入中…' );
        $.post( ajaxurl, {
            action     : 'anime_sync_import_single',
            nonce      : animeSyncAdmin.nonce,
            anilist_id : anilistId,
        }, function ( resp ) {
            if ( resp.success ) {
                $btn.closest( 'tr' ).fadeOut( 400, function () { $( this ).remove(); } );
                showToast( '✓ 已匯入：' + title );
            } else {
                $btn.prop( 'disabled', false ).text( '立即匯入' );
                showToast( ( resp.data && ( resp.data.message || resp.data ) ) || '匯入失敗', true );
            }
        } ).fail( function () {
            $btn.prop( 'disabled', false ).text( '立即匯入' );
            showToast( '網路錯誤', true );
        } );
    } );

    /* ── Bulk ── */
    $( '#btn-bulk-apply' ).on( 'click', function () {
        var action  = $( '#bulk-action-select' ).val();
        var ids     = getCheckedIds();
        var $result = $( '#bulk-action-result' );
        if ( ! action ) { $result.text( '請選擇動作' ); return; }
        if ( ids.length === 0 ) { $result.text( '請勾選至少一筆' ); return; }
        if ( action === 'delete' && ! confirm( '確定要刪除選取的動漫？此操作無法復原。' ) ) return;
        $result.text( '處理中…' );
        $.post( ajaxurl, {
            action  : 'anime_sync_bulk_action',
            nonce   : animeSyncAdmin.nonce,
            bulk    : action,
            post_ids: ids,
        }, function ( resp ) {
            if ( resp.success ) {
                $result.text( resp.data.message || '完成' );
                setTimeout( function () { location.reload(); }, 1200 );
            } else {
                $result.text( resp.data || '發生錯誤' );
            }
        } );
    } );

    /* ── Publish single ── */
    $( document ).on( 'click', '.btn-publish-one', function () {
        var $btn   = $( this );
        var postId = $btn.data( 'post-id' );
        $btn.prop( 'disabled', true ).text( '…' );
        $.post( ajaxurl, {
            action  : 'anime_sync_bulk_action',
            nonce   : animeSyncAdmin.nonce,
            bulk    : 'publish',
            post_ids: [ postId ],
        }, function ( resp ) {
            if ( resp.success ) {
                $( '[data-post-id="' + postId + '"]' ).fadeOut( 400, function () { $( this ).remove(); } );
                showToast( '✓ 已發佈' );
            } else {
                $btn.prop( 'disabled', false ).text( '發佈' );
                showToast( resp.data || '失敗', true );
            }
        } );
    } );

    /* ── Unpublish single ── */
    $( document ).on( 'click', '.btn-unpublish-one', function () {
        var $btn   = $( this );
        var postId = $btn.data( 'post-id' );
        $btn.prop( 'disabled', true ).text( '…' );
        $.post( ajaxurl, {
            action  : 'anime_sync_bulk_action',
            nonce   : animeSyncAdmin.nonce,
            bulk    : 'draft',
            post_ids: [ postId ],
        }, function ( resp ) {
            if ( resp.success ) { location.reload(); }
            else {
                $btn.prop( 'disabled', false ).text( '轉草稿' );
                showToast( resp.data || '失敗', true );
            }
        } );
    } );

    /* ── Delete single ── */
    $( document ).on( 'click', '.btn-delete-one', function () {
        if ( ! confirm( '確定刪除這筆動漫？' ) ) return;
        var $btn   = $( this );
        var postId = $btn.data( 'post-id' );
        $btn.prop( 'disabled', true );
        $.post( ajaxurl, {
            action  : 'anime_sync_bulk_action',
            nonce   : animeSyncAdmin.nonce,
            bulk    : 'delete',
            post_ids: [ postId ],
        }, function ( resp ) {
            if ( resp.success ) {
                $( '[data-post-id="' + postId + '"]' ).fadeOut( 400, function () { $( this ).remove(); } );
                showToast( '✓ 已刪除' );
            } else {
                $btn.prop( 'disabled', false );
                showToast( resp.data || '刪除失敗', true );
            }
        } );
    } );

    /* ── Refetch single ── */
    $( document ).on( 'click', '.btn-refetch-one', function () {
        var $btn      = $( this );
        var postId    = $btn.data( 'post-id' );
        var anilistId = $btn.data( 'anilist-id' );
        if ( ! anilistId ) { showToast( '此筆缺少 AniList ID', true ); return; }
        $btn.prop( 'disabled', true ).text( '…' );
        $.post( ajaxurl, {
            action     : 'anime_sync_import_single',
            nonce      : animeSyncAdmin.nonce,
            anilist_id : anilistId,
            post_id    : postId,
            force      : 1,
        }, function ( resp ) {
            $btn.prop( 'disabled', false ).text( '重抓' );
            if ( resp.success ) {
                showToast( '✓ 重抓完成' );
            } else {
                showToast( ( resp.data && ( resp.data.message || resp.data ) ) || '重抓失敗', true );
            }
        } ).fail( function () {
            $btn.prop( 'disabled', false ).text( '重抓' );
            showToast( '網路錯誤', true );
        } );
    } );

    /* ── Save Bangumi ID ── */
    $( document ).on( 'click', '.btn-save-bangumi-id', function () {
        var $btn   = $( this );
        var postId = $btn.data( 'post-id' );
        var bgmId  = $btn.closest( '.bangumi-id-edit' ).find( '.bangumi-id-input' ).val();
        if ( ! bgmId || bgmId < 1 ) {
            alert( '請輸入有效的 Bangumi ID' );
            return;
        }
        $btn.prop( 'disabled', true ).text( '…' );
        $.post( ajaxurl, {
            action     : 'anime_sync_save_bangumi_id',
            nonce      : animeSyncAdmin.nonce,
            post_id    : postId,
            bangumi_id : bgmId,
        }, function ( resp ) {
            if ( resp.success ) {
                $btn.closest( 'td, .asc-card-meta-item' ).find( '.bangumi-id-edit' ).replaceWith(
                    $( '<a>' ).attr( { href: 'https://bgm.tv/subject/' + bgmId, target: '_blank', rel: 'noopener' } ).text( bgmId )
                );
                $( '[data-post-id="' + postId + '"]' ).removeClass( 'bangumi-pending asc-card--pending' );
                showToast( '✓ 已儲存' );
            } else {
                $btn.prop( 'disabled', false ).text( '儲存' );
                showToast( resp.data || '儲存失敗', true );
            }
        } );
    } );

} )( jQuery );
</script>

<!-- ───────────────── CSS ───────────────── -->
<style>
/* 共用 */
.asc-badge { display:inline-block;border-radius:10px;padding:2px 8px;font-size:11px;font-weight:600;vertical-align:middle;line-height:1.6; }
.asc-badge--red    { background:#dc3232;color:#fff; }
.asc-badge--orange { background:#f0a500;color:#fff; }
.asc-tag { display:inline-block;border:1px solid currentColor;border-radius:3px;padding:1px 5px;font-size:11px;line-height:1.5;vertical-align:middle; }
.asc-tag--green  { color:#46b450;border-color:#46b450; }
.asc-tag--gray   { color:#888;border-color:#bbb; }
.asc-tag--orange { color:#f0a500;border-color:#f0a500; }
.asc-subtitle  { color:#777;font-size:12px; }
.asc-sync-time { font-size:12px; }
.asc-cover-img         { width:48px;height:68px;object-fit:cover;border-radius:2px;display:block; }
.asc-cover-placeholder { width:48px;height:68px;background:#eee;border-radius:2px;display:flex;align-items:center;justify-content:center;color:#999;font-size:10px; }

/* Toast */
.asc-toast { position:fixed;top:50px;right:20px;z-index:99999;padding:12px 20px;border-radius:4px;box-shadow:0 2px 8px rgba(0,0,0,.2);font-size:14px;color:#fff;max-width:calc(100vw - 40px); }

/* Filter */
.asc-filter-wrap { margin:16px 0; }
.asc-filter-toggle { display:flex;align-items:center;gap:6px;width:100%;text-align:left;background:#fff;border:1px solid #ddd;border-radius:4px 4px 0 0;padding:9px 14px;font-size:13px;font-weight:600;color:#23282d;cursor:pointer;box-sizing:border-box; }
.asc-filter-toggle:hover,.asc-filter-toggle.is-active { background:#f0f7fc;color:#0073aa;border-color:#0073aa; }
.asc-filter-active-badge { margin-left:2px;font-size:11px;padding:1px 6px; }
.asc-toggle-arrow { margin-left:auto;font-size:11px; }
.asc-filter-form { display:none;background:#fff;border:1px solid #ddd;border-top:none;border-radius:0 0 4px 4px;padding:16px;box-sizing:border-box; }
.asc-filter-form.is-open { display:block; }
.asc-filter-grid { display:flex;flex-wrap:wrap;gap:12px;margin-bottom:12px; }
.asc-filter-item { display:flex;flex-direction:column;gap:4px;min-width:130px; }
.asc-filter-item label { font-size:12px;font-weight:600;color:#555; }
.asc-filter-item select { min-width:130px; }
.asc-filter-item--checkbox { flex-direction:row;align-items:center;gap:6px;min-width:auto;align-self:flex-end;padding-bottom:2px; }
.asc-filter-item--checkbox label { font-size:13px;font-weight:normal;color:#23282d;display:flex;align-items:center;gap:6px;cursor:pointer; }
.asc-filter-actions { display:flex;align-items:center;gap:8px;flex-wrap:wrap; }
.asc-filter-count { color:#777;font-size:13px; }

/* Gap Scan */
.asc-gap-scan-bar { margin:0 0 16px;padding:12px 16px;background:#fff;border:1px solid #ddd;border-radius:4px;display:flex;align-items:flex-start;flex-wrap:wrap;gap:10px; }
.asc-gap-scan-title { display:flex;flex-direction:column;gap:3px;flex:1 1 200px; }
.asc-gap-cache-info { color:#777;font-size:12px; }
.asc-gap-scan-actions { display:flex;align-items:center;flex-wrap:wrap;gap:6px; }
.asc-gap-scan-status { color:#777;font-size:13px; }
.asc-gap-result-wrap { padding:14px 16px;background:#fff;border:1px solid #ddd;border-radius:4px; }
.asc-gap-result-wrap h3 { margin:0 0 12px; }
.asc-gap-ok    { color:#46b450;margin:0; }
.asc-gap-error { color:#dc3232;margin:0 0 12px; }
.asc-rel-badge { padding:2px 6px;border-radius:3px;font-size:11px;font-weight:600; }

/* Bulk */
.asc-bulk-bar { display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:12px; }
.asc-bulk-result { color:#555;font-size:13px; }

/* Table */
.asc-queue-table .bangumi-pending { background:#fffbea !important; }
.asc-queue-table th,.asc-queue-table td { vertical-align:middle; }
.asc-action-group { display:flex;flex-wrap:wrap;gap:3px; }
.asc-action-btn { display:inline-flex;align-items:center;gap:3px;padding:3px 6px !important;font-size:12px !important; }
.asc-action-btn .dashicons { font-size:14px;width:14px;height:14px;flex-shrink:0; }

/* Disabled */
.btn-publish-one[disabled],.btn-unpublish-one[disabled],.btn-refetch-one[disabled],
.btn-delete-one[disabled],.btn-save-bangumi-id[disabled],.btn-gap-import[disabled] { opacity:.6;cursor:not-allowed; }

/* Mobile cards (hidden desktop) */
.asc-desktop-only { display:table; }
.asc-mobile-only  { display:none; }
.asc-mobile-select-all { padding:8px 12px;background:#f1f1f1;border-radius:4px;margin-bottom:8px;font-size:13px; }
.asc-card { background:#fff;border:1px solid #e0e0e0;border-radius:6px;margin-bottom:10px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.06); }
.asc-card--pending { border-left:4px solid #f0a500;background:#fffbea; }
.asc-card-header { display:flex;align-items:flex-start;gap:10px;padding:12px 12px 0; }
.asc-card-checkbox { margin-top:4px;flex-shrink:0; }
.asc-card-cover { width:52px;height:74px;object-fit:cover;border-radius:3px;flex-shrink:0;display:block; }
.asc-card-cover.asc-cover-placeholder { width:52px;height:74px; }
.asc-card-title-block { flex:1;min-width:0; }
.asc-card-title { display:block;font-size:14px;font-weight:600;color:#23282d;line-height:1.4;margin-bottom:3px;word-break:break-all;text-decoration:none; }
.asc-card-title:hover { color:#0073aa; }
.asc-card-romaji { font-size:11px;color:#888;margin-bottom:5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis; }
.asc-card-badges { display:flex;flex-wrap:wrap;gap:4px; }
.asc-card-meta { display:grid;grid-template-columns:1fr 1fr;gap:6px 12px;padding:10px 12px;border-top:1px solid #f0f0f0;margin-top:10px;font-size:12px; }
.asc-card-meta-item { display:flex;flex-direction:column;gap:2px; }
.asc-meta-label { color:#888;font-size:11px;text-transform:uppercase;letter-spacing:.3px; }
.asc-card-actions { display:flex;flex-wrap:wrap;gap:6px;padding:10px 12px 12px;border-top:1px solid #f0f0f0; }
.asc-card-actions .button { font-size:12px !important; }

/* Pagination */
.asc-pagination-wrap { margin-top:12px; }
.asc-pagination-wrap .tablenav-pages { text-align:center; }

/* Empty */
.asc-empty-state { text-align:center;padding:60px 20px;color:#777;border:2px dashed #ddd;border-radius:4px;margin-top:20px; }
.asc-empty-state .dashicons { font-size:48px;height:48px;width:48px;color:#ddd; }
.asc-empty-state p { font-size:16px;margin-top:12px; }

/* ── Responsive ≤782px ── */
@media screen and (max-width:782px) {
    .asc-filter-grid { gap:10px; }
    .asc-filter-item { min-width:calc(50% - 6px); }
    .asc-filter-item select { width:100%;min-width:unset; }
    .asc-filter-item--checkbox { min-width:100%; }
    .asc-filter-actions { gap:6px; }
    .asc-filter-actions .button { flex:1;text-align:center; }
    .asc-filter-count { width:100%; }
    .asc-gap-scan-bar    { flex-direction:column;align-items:stretch; }
    .asc-gap-scan-actions { justify-content:flex-start; }
    .asc-gap-scan-actions .button { flex:1;text-align:center; }
    .asc-bulk-bar { flex-direction:column;align-items:stretch; }
    .asc-bulk-bar select { width:100%; }
    #btn-bulk-apply { width:100%; }
    .asc-desktop-only { display:none !important; }
    .asc-mobile-only  { display:block; }
    .asc-pagination-wrap .page-numbers { padding:6px 10px;font-size:14px;min-width:36px;display:inline-block;text-align:center; }
    .asc-toast { left:16px;right:16px;top:auto;bottom:20px;text-align:center; }
}

@media screen and (max-width:480px) {
    .asc-filter-item { min-width:100%; }
    .asc-card-meta   { grid-template-columns:1fr; }
    .asc-card-actions .button { flex:1 1 calc(50% - 4px);text-align:center; }
}
</style>
