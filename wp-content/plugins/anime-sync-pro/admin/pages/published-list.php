<?php
/**
 * Published / Manage Anime List Page
 *
 * @package Anime_Sync_Pro
 * @version 1.3.1
 *
 * Changelog:
 *  - 1.3.1 (2026-07-20):
 *      • 修正 media query 語法：移除括號內外空格（( max-width )→(max-width)），
 *        原語法在部分環境下失效，導致桌面表格與手機卡片同時顯示。
 *      • 桌面/手機切換改用雙 class 選擇器 + !important 強制互斥。
 *      • 改用 min-width 正向宣告桌面樣式，避免版型重複。
 *  - 1.3.0 (2026-06-27):
 *      • 手機板響應式全面優化。
 *      • 篩選列改為可折疊 collapsible，手機板全寬垂直堆疊。
 *      • 表格在手機板（≤782px）切換為卡片式佈局。
 *      • 操作按鈕在卡片中全寬排列，方便觸控。
 *      • Toast 在手機板移至底部置中。
 *      • 分頁在手機板置中顯示。
 *  - 1.2.0 (2026-05-10):
 *      • 加入「重新同步」按鈕完整 JS handler。
 *      • 加入篩選器：狀態、搜尋框、每頁筆數。
 *      • 發布日期改用 wp_date() 站台時區。
 *      • 加入 current_user_can() 權限檢查。
 *      • 新增 toast 提示與按鈕 disabled 防重複點擊。
 *      • 顯示「上次同步」欄位。
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! current_user_can( 'edit_posts' ) ) {
    wp_die( esc_html__( '您沒有權限存取此頁面。', 'anime-sync-pro' ) );
}

/* ───────────────────────────────────────────────
   參數
─────────────────────────────────────────────── */
$paged      = isset( $_GET['paged'] )    ? max( 1, absint( $_GET['paged'] ) )                                        : 1;
$per_page   = isset( $_GET['per_page'] ) ? absint( $_GET['per_page'] )                                               : 20;
$per_page   = in_array( $per_page, [ 10, 20, 50, 100 ], true ) ? $per_page : 20;
$status_arg = isset( $_GET['status'] )   ? sanitize_text_field( wp_unslash( $_GET['status'] ) )                      : 'all';
$keyword    = isset( $_GET['s'] )        ? sanitize_text_field( wp_unslash( $_GET['s'] ) )                           : '';

$status_map_query = [
    'all'     => [ 'publish', 'pending', 'draft' ],
    'publish' => [ 'publish' ],
    'pending' => [ 'pending' ],
    'draft'   => [ 'draft' ],
];
$post_status = $status_map_query[ $status_arg ] ?? $status_map_query['all'];

$query_args = [
    'post_type'      => 'anime',
    'post_status'    => $post_status,
    'posts_per_page' => $per_page,
    'paged'          => $paged,
    'orderby'        => 'date',
    'order'          => 'DESC',
];
if ( $keyword !== '' ) {
    $query_args['s'] = $keyword;
}

$query       = new WP_Query( $query_args );
$has_filter  = $status_arg !== 'all' || $keyword !== '' || $per_page !== 20;

$status_label_map = [
    'publish' => [ '已發布', '#e7f6ed', '#207b45' ],
    'pending' => [ '待審核', '#fff4e5', '#b25e09' ],
    'draft'   => [ '草稿',   '#f0f0f1', '#646970' ],
];
?>

<div class="wrap asc-pub-wrap">

    <h1 class="wp-heading-inline">已發布動漫</h1>
    <a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=anime' ) ); ?>"
       class="page-title-action">新增動漫</a>
    <hr class="wp-header-end">

    <!-- ───────────────── Filter (Collapsible) ───────────────── -->
    <div class="asc-filter-wrap">

        <button type="button"
                class="asc-filter-toggle <?php echo $has_filter ? 'is-active' : ''; ?>"
                id="asc-pub-filter-toggle">
            <span class="dashicons dashicons-filter"></span>
            篩選條件
            <?php if ( $has_filter ) : ?>
                <span class="asc-filter-badge">已套用</span>
            <?php endif; ?>
            <span class="asc-toggle-arrow"><?php echo $has_filter ? '▴' : '▾'; ?></span>
        </button>

        <form method="get"
              class="asc-filter-form <?php echo $has_filter ? 'is-open' : ''; ?>"
              id="asc-pub-filter-form">
            <input type="hidden" name="page" value="anime-sync-published" />

            <div class="asc-filter-grid">

                <div class="asc-filter-field">
                    <label for="pub-status">狀態</label>
                    <select name="status" id="pub-status">
                        <option value="all"     <?php selected( $status_arg, 'all' );     ?>>全部狀態</option>
                        <option value="publish" <?php selected( $status_arg, 'publish' ); ?>>已發布</option>
                        <option value="draft"   <?php selected( $status_arg, 'draft' );   ?>>草稿</option>
                        <option value="pending" <?php selected( $status_arg, 'pending' ); ?>>待審核</option>
                    </select>
                </div>

                <div class="asc-filter-field">
                    <label for="pub-per-page">每頁筆數</label>
                    <select name="per_page" id="pub-per-page">
                        <option value="10"  <?php selected( $per_page, 10 );  ?>>每頁 10 筆</option>
                        <option value="20"  <?php selected( $per_page, 20 );  ?>>每頁 20 筆</option>
                        <option value="50"  <?php selected( $per_page, 50 );  ?>>每頁 50 筆</option>
                        <option value="100" <?php selected( $per_page, 100 ); ?>>每頁 100 筆</option>
                    </select>
                </div>

                <div class="asc-filter-field asc-filter-field--search">
                    <label for="pub-search">搜尋標題</label>
                    <input type="search" name="s" id="pub-search"
                           value="<?php echo esc_attr( $keyword ); ?>"
                           placeholder="輸入關鍵字…" />
                </div>

            </div><!-- .asc-filter-grid -->

            <div class="asc-filter-actions">
                <button type="submit" class="button button-primary">
                    <span class="dashicons dashicons-search"></span> 套用
                </button>
                <?php if ( $has_filter ) : ?>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=anime-sync-published' ) ); ?>"
                       class="button">重設</a>
                <?php endif; ?>
                <span class="asc-filter-count">
                    共 <strong><?php echo esc_html( number_format_i18n( $query->found_posts ) ); ?></strong> 筆
                </span>
                <a href="<?php echo esc_url( admin_url( 'edit.php?post_type=anime' ) ); ?>"
                   class="button asc-full-admin-btn">完整管理介面</a>
            </div>

        </form>
    </div><!-- .asc-filter-wrap -->

    <!-- ───────────────── Desktop Table ───────────────── -->
    <table class="wp-list-table widefat fixed striped asc-pub-table asc-desktop-only">
        <thead>
            <tr>
                <th class="asc-col-cover">封面</th>
                <th>標題</th>
                <th class="asc-col-anilist">AniList ID</th>
                <th class="asc-col-status">狀態</th>
                <th class="asc-col-date">發布日期</th>
                <th class="asc-col-sync">上次同步</th>
                <th class="asc-col-actions">操作</th>
            </tr>
        </thead>
        <tbody>
        <?php if ( $query->have_posts() ) : ?>
            <?php while ( $query->have_posts() ) : $query->the_post();
                $post_id        = get_the_ID();
                $anilist_id     = get_post_meta( $post_id, 'anime_anilist_id', true );
                $cover_url      = get_the_post_thumbnail_url( $post_id, 'thumbnail' )
                                  ?: get_post_meta( $post_id, 'anime_cover_image', true );
                $last_sync_raw  = get_post_meta( $post_id, 'anime_sync_time',  true )
                                  ?: get_post_meta( $post_id, 'anime_last_sync', true );
                $last_sync_str  = $last_sync_raw
                                  ? wp_date( 'Y-m-d H:i', strtotime( $last_sync_raw ) ) : '—';
                $current_status = get_post_status( $post_id );
                $sl             = $status_label_map[ $current_status ] ?? [ $current_status, '#f0f0f1', '#646970' ];
            ?>
            <tr data-post-id="<?php echo esc_attr( $post_id ); ?>">

                <td>
                    <?php if ( $cover_url ) : ?>
                        <img src="<?php echo esc_url( $cover_url ); ?>"
                             alt="" class="asc-cover-img" />
                    <?php else : ?>
                        <div class="asc-cover-placeholder">無封面</div>
                    <?php endif; ?>
                </td>

                <td>
                    <strong>
                        <a href="<?php echo esc_url( get_edit_post_link( $post_id ) ); ?>">
                            <?php the_title(); ?>
                        </a>
                    </strong>
                    <div class="asc-post-id">Post ID: <?php echo (int) $post_id; ?></div>
                </td>

                <td>
                    <?php if ( $anilist_id ) : ?>
                        <a href="https://anilist.co/anime/<?php echo esc_attr( $anilist_id ); ?>/"
                           target="_blank" rel="noopener">
                            <code><?php echo esc_html( $anilist_id ); ?></code>
                        </a>
                    <?php else : ?>
                        <span class="asc-na">—</span>
                    <?php endif; ?>
                </td>

                <td>
                    <span class="asc-status-badge"
                          style="background:<?php echo esc_attr( $sl[1] ); ?>;color:<?php echo esc_attr( $sl[2] ); ?>;">
                        <?php echo esc_html( $sl[0] ); ?>
                    </span>
                </td>

                <td class="asc-date-cell">
                    <?php echo esc_html( wp_date( 'Y-m-d H:i', strtotime( get_the_date( 'Y-m-d H:i:s' ) ) ) ); ?>
                </td>

                <td class="asc-sync-cell">
                    <?php echo esc_html( $last_sync_str ); ?>
                </td>

                <td>
                    <div class="asc-action-group">
                        <a href="<?php echo esc_url( get_edit_post_link( $post_id ) ); ?>"
                           class="button button-small">
                            <span class="dashicons dashicons-edit"></span>
                            <span class="asc-btn-label">編輯</span>
                        </a>
                        <a href="<?php echo esc_url( get_permalink( $post_id ) ); ?>"
                           class="button button-small"
                           target="_blank" rel="noopener">
                            <span class="dashicons dashicons-visibility"></span>
                            <span class="asc-btn-label">查看</span>
                        </a>
                        <?php if ( $anilist_id ) : ?>
                        <button type="button"
                                class="button button-small resync-anime"
                                data-post-id="<?php echo esc_attr( $post_id ); ?>"
                                data-anilist-id="<?php echo esc_attr( $anilist_id ); ?>">
                            <span class="dashicons dashicons-update"></span>
                            <span class="asc-btn-label">重新同步</span>
                        </button>
                        <?php endif; ?>
                    </div>
                </td>

            </tr>
            <?php endwhile; ?>
        <?php else : ?>
            <tr>
                <td colspan="7" class="asc-empty-cell">
                    <span class="dashicons dashicons-video-alt3"></span>
                    <p><?php echo $keyword !== '' || $status_arg !== 'all'
                        ? '沒有符合條件的動漫' : '尚未匯入任何動漫'; ?></p>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=anime-sync-import' ) ); ?>"
                       class="button button-primary button-large">立即匯入</a>
                </td>
            </tr>
        <?php endif; ?>
        </tbody>
    </table>

    <!-- ───────────────── Mobile Cards ───────────────── -->
    <div class="asc-pub-cards asc-mobile-only">
        <?php
        $query->rewind_posts();
        if ( $query->have_posts() ) :
            while ( $query->have_posts() ) : $query->the_post();
                $post_id        = get_the_ID();
                $anilist_id     = get_post_meta( $post_id, 'anime_anilist_id', true );
                $cover_url      = get_the_post_thumbnail_url( $post_id, 'thumbnail' )
                                  ?: get_post_meta( $post_id, 'anime_cover_image', true );
                $last_sync_raw  = get_post_meta( $post_id, 'anime_sync_time',  true )
                                  ?: get_post_meta( $post_id, 'anime_last_sync', true );
                $last_sync_str  = $last_sync_raw
                                  ? wp_date( 'Y-m-d H:i', strtotime( $last_sync_raw ) ) : '—';
                $current_status = get_post_status( $post_id );
                $sl             = $status_label_map[ $current_status ] ?? [ $current_status, '#f0f0f1', '#646970' ];
        ?>
            <div class="asc-pub-card" data-post-id="<?php echo esc_attr( $post_id ); ?>">

                <!-- Card Header -->
                <div class="asc-pub-card-header">
                    <?php if ( $cover_url ) : ?>
                        <img src="<?php echo esc_url( $cover_url ); ?>"
                             alt="" class="asc-pub-card-cover" />
                    <?php else : ?>
                        <div class="asc-pub-card-cover asc-cover-placeholder">無封面</div>
                    <?php endif; ?>

                    <div class="asc-pub-card-title-block">
                        <a href="<?php echo esc_url( get_edit_post_link( $post_id ) ); ?>"
                           class="asc-pub-card-title">
                            <?php the_title(); ?>
                        </a>
                        <div class="asc-post-id">Post ID: <?php echo (int) $post_id; ?></div>
                        <div class="asc-pub-card-badges">
                            <span class="asc-status-badge"
                                  style="background:<?php echo esc_attr( $sl[1] ); ?>;color:<?php echo esc_attr( $sl[2] ); ?>;">
                                <?php echo esc_html( $sl[0] ); ?>
                            </span>
                        </div>
                    </div>
                </div><!-- .asc-pub-card-header -->

                <!-- Card Meta -->
                <div class="asc-pub-card-meta">
                    <div class="asc-pub-meta-item">
                        <span class="asc-meta-label">AniList</span>
                        <?php if ( $anilist_id ) : ?>
                            <a href="https://anilist.co/anime/<?php echo esc_attr( $anilist_id ); ?>/"
                               target="_blank" rel="noopener">
                                <?php echo esc_html( $anilist_id ); ?>
                            </a>
                        <?php else : ?>
                            <span class="asc-na">—</span>
                        <?php endif; ?>
                    </div>
                    <div class="asc-pub-meta-item">
                        <span class="asc-meta-label">發布日期</span>
                        <span><?php echo esc_html( wp_date( 'Y-m-d H:i', strtotime( get_the_date( 'Y-m-d H:i:s' ) ) ) ); ?></span>
                    </div>
                    <div class="asc-pub-meta-item">
                        <span class="asc-meta-label">上次同步</span>
                        <span><?php echo esc_html( $last_sync_str ); ?></span>
                    </div>
                </div><!-- .asc-pub-card-meta -->

                <!-- Card Actions -->
                <div class="asc-pub-card-actions">
                    <a href="<?php echo esc_url( get_edit_post_link( $post_id ) ); ?>"
                       class="button button-small">✏️ 編輯</a>
                    <a href="<?php echo esc_url( get_permalink( $post_id ) ); ?>"
                       class="button button-small"
                       target="_blank" rel="noopener">👁️ 查看</a>
                    <?php if ( $anilist_id ) : ?>
                        <button type="button"
                                class="button button-small button-primary resync-anime"
                                data-post-id="<?php echo esc_attr( $post_id ); ?>"
                                data-anilist-id="<?php echo esc_attr( $anilist_id ); ?>">
                            🔄 重新同步
                        </button>
                    <?php endif; ?>
                </div><!-- .asc-pub-card-actions -->

            </div><!-- .asc-pub-card -->
        <?php endwhile;
        else : ?>
            <div class="asc-empty-state">
                <span class="dashicons dashicons-video-alt3"></span>
                <p><?php echo $keyword !== '' || $status_arg !== 'all'
                    ? '沒有符合條件的動漫' : '尚未匯入任何動漫'; ?></p>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=anime-sync-import' ) ); ?>"
                   class="button button-primary button-large">立即匯入</a>
            </div>
        <?php endif; ?>
    </div><!-- .asc-pub-cards -->

    <!-- ───────────────── Pagination ───────────────── -->
    <?php if ( $query->max_num_pages > 1 ) : ?>
    <div class="tablenav bottom asc-pagination-wrap">
        <div class="tablenav-pages">
            <?php
            $base_url = add_query_arg( [
                'page'     => 'anime-sync-published',
                'status'   => $status_arg,
                'per_page' => $per_page,
                's'        => $keyword,
            ], admin_url( 'admin.php' ) );

            echo paginate_links( [
                'base'      => $base_url . '%_%',
                'format'    => '&paged=%#%',
                'prev_text' => '&laquo;',
                'next_text' => '&raquo;',
                'total'     => $query->max_num_pages,
                'current'   => $paged,
            ] );
            ?>
        </div>
    </div>
    <?php endif; ?>

</div><!-- .wrap -->

<?php wp_reset_postdata(); ?>

<!-- Toast -->
<div id="published-toast" class="asc-toast" style="display:none;"></div>

<!-- ───────────────── JS ───────────────── -->
<script>
jQuery( function ( $ ) {
    'use strict';

    /* ── Toast ── */
    function showToast( message, isError ) {
        var $toast = $( '#published-toast' );
        $toast.text( message )
              .css( 'background', isError ? '#dc3232' : '#46b450' )
              .fadeIn( 200 );
        setTimeout( function () { $toast.fadeOut( 300 ); }, 2500 );
    }

    /* ── Collapsible filter ── */
    $( '#asc-pub-filter-toggle' ).on( 'click', function () {
        var $form  = $( '#asc-pub-filter-form' );
        var $arrow = $( this ).find( '.asc-toggle-arrow' );
        var open   = $form.toggleClass( 'is-open' ).hasClass( 'is-open' );
        $arrow.text( open ? '▴' : '▾' );
        $( this ).toggleClass( 'is-active', open );
    } );

    /* ── Resync ── */
    $( document ).on( 'click', '.resync-anime', function () {
        var $btn      = $( this );
        var postId    = $btn.data( 'post-id' );
        var anilistId = $btn.data( 'anilist-id' );

        if ( ! anilistId ) {
            showToast( '此筆缺少 AniList ID，無法重新同步', true );
            return;
        }
        if ( ! confirm( '確定重新同步此動漫資料？\n（已鎖定欄位將被跳過。）' ) ) return;

        var cfg = window.animeSyncAdmin || {};
        if ( ! cfg.ajaxUrl || ! cfg.nonce ) {
            showToast( '系統錯誤：找不到 animeSyncAdmin 設定，請重新整理', true );
            return;
        }

        var originalHTML = $btn.html();
        $btn.prop( 'disabled', true ).html( '⏳ 同步中…' );

        $.ajax( {
            url      : cfg.ajaxUrl,
            type     : 'POST',
            dataType : 'json',
            timeout  : 180000,
            data     : {
                action     : 'anime_sync_import_single',
                nonce      : cfg.nonce,
                anilist_id : anilistId,
                post_id    : postId,
                force      : 1,
            },
            success : function ( resp ) {
                if ( resp && resp.success ) {
                    $btn.html( '✅ 已同步' ).css( 'color', '#46b450' );
                    showToast( '✓ 同步成功' );
                    setTimeout( function () {
                        $btn.prop( 'disabled', false ).html( originalHTML ).css( 'color', '' );
                    }, 2500 );
                } else {
                    var msg = ( resp && resp.data && ( resp.data.message || resp.data ) ) || '同步失敗';
                    showToast( msg, true );
                    $btn.prop( 'disabled', false ).html( originalHTML );
                }
            },
            error : function ( xhr ) {
                showToast( '網路錯誤 (HTTP ' + xhr.status + ')', true );
                $btn.prop( 'disabled', false ).html( originalHTML );
            },
        } );
    } );

} );
</script>

<!-- ───────────────── Styles ───────────────── -->
<style>

/* ═══════════════════════════════════════════
   共用
═══════════════════════════════════════════ */
.asc-cover-img {
    width: 50px; height: 70px;
    object-fit: cover;
    border-radius: 4px;
    border: 1px solid #ddd;
    display: block;
}
.asc-cover-placeholder {
    width: 50px; height: 70px;
    background: #eee;
    border-radius: 4px;
    border: 1px solid #ddd;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #ccc;
    font-size: 10px;
}
.asc-status-badge {
    display: inline-block;
    padding: 3px 8px;
    border-radius: 3px;
    font-weight: 500;
    font-size: 12px;
    white-space: nowrap;
}
.asc-post-id  { color: #999; font-size: 12px; margin-top: 3px; }
.asc-date-cell,
.asc-sync-cell { font-size: 12px; color: #666; white-space: nowrap; }
.asc-na       { color: #ccc; }

/* ═══════════════════════════════════════════
   Toast
═══════════════════════════════════════════ */
.asc-toast {
    position: fixed;
    top: 50px; right: 20px;
    z-index: 99999;
    padding: 12px 20px;
    border-radius: 4px;
    box-shadow: 0 2px 8px rgba(0,0,0,.2);
    font-size: 14px;
    color: #fff;
    max-width: calc(100vw - 40px);
}

/* ═══════════════════════════════════════════
   Collapsible Filter
═══════════════════════════════════════════ */
.asc-filter-wrap { margin: 16px 0; }

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
    box-sizing: border-box;
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
}
.asc-toggle-arrow { margin-left: auto; font-size: 11px; }

.asc-filter-form {
    display: none;
    background: #fff;
    border: 1px solid #ddd;
    border-top: none;
    border-radius: 0 0 4px 4px;
    padding: 16px;
    box-sizing: border-box;
}
.asc-filter-form.is-open { display: block; }

.asc-filter-grid {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    margin-bottom: 12px;
}
.asc-filter-field {
    display: flex;
    flex-direction: column;
    gap: 4px;
    min-width: 140px;
}
.asc-filter-field--search { flex: 1; min-width: 180px; }
.asc-filter-field--search input { width: 100%; }
.asc-filter-field label  { font-size: 12px; color: #555; font-weight: 600; }
.asc-filter-field select { width: 100%; }

.asc-filter-actions {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}
.asc-filter-actions .button {
    display: inline-flex;
    align-items: center;
    gap: 4px;
}
.asc-filter-actions .dashicons { font-size: 14px; width: 14px; height: 14px; }
.asc-filter-count { color: #666; font-size: 13px; }
.asc-full-admin-btn { margin-left: auto; }

/* ═══════════════════════════════════════════
   Desktop Table
═══════════════════════════════════════════ */
.asc-pub-table th { font-weight: 600 !important; background: #f8f9fa; }
.asc-pub-table td { vertical-align: middle !important; }
.asc-col-cover   { width: 70px; }
.asc-col-anilist { width: 100px; }
.asc-col-status  { width: 90px; }
.asc-col-date    { width: 130px; }
.asc-col-sync    { width: 130px; }
.asc-col-actions { width: 210px; }

.asc-action-group {
    display: flex;
    flex-wrap: wrap;
    gap: 4px;
    align-items: center;
}
.asc-action-group .button {
    display: inline-flex;
    align-items: center;
    gap: 3px;
    font-size: 12px !important;
    padding: 3px 7px !important;
}
.asc-action-group .dashicons {
    font-size: 13px; width: 13px; height: 13px;
}
.resync-anime       { color: #2271b1 !important; }
.resync-anime:hover { background: #f0f6fb !important; border-color: #2271b1 !important; }
.resync-anime[disabled] { opacity: .6; cursor: not-allowed; }

/* ═══════════════════════════════════════════
   Empty Cell
═══════════════════════════════════════════ */
.asc-empty-cell {
    text-align: center;
    padding: 60px 20px !important;
    background: #fff;
}
.asc-empty-cell .dashicons {
    font-size: 48px; width: 48px; height: 48px;
    color: #ddd; display: block; margin: 0 auto 14px;
}
.asc-empty-cell p { font-size: 16px; color: #666; margin: 0 0 16px; }

/* ═══════════════════════════════════════════
   桌面 / 手機 顯示互斥（關鍵修正）
   預設（手機優先）：顯示卡片、隱藏表格
═══════════════════════════════════════════ */
.asc-pub-table.asc-desktop-only { display: none !important; }
.asc-pub-cards.asc-mobile-only  { display: flex  !important; }

.asc-pub-cards { flex-direction: column; gap: 10px; }

.asc-pub-card {
    background: #fff;
    border: 1px solid #e0e0e0;
    border-radius: 6px;
    overflow: hidden;
    box-shadow: 0 1px 3px rgba(0,0,0,.06);
}

/* Card Header */
.asc-pub-card-header {
    display: flex;
    gap: 12px;
    padding: 12px 12px 0;
    align-items: flex-start;
}
.asc-pub-card-cover {
    width: 54px; height: 76px;
    object-fit: cover;
    border-radius: 4px;
    border: 1px solid #ddd;
    flex-shrink: 0;
    display: block;
}
.asc-pub-card-cover.asc-cover-placeholder {
    width: 54px; height: 76px;
}
.asc-pub-card-title-block { flex: 1; min-width: 0; }
.asc-pub-card-title {
    display: block;
    font-size: 14px;
    font-weight: 600;
    color: #23282d;
    line-height: 1.4;
    margin-bottom: 4px;
    word-break: break-all;
    text-decoration: none;
}
.asc-pub-card-title:hover { color: #0073aa; }
.asc-pub-card-badges { margin-top: 6px; }

/* Card Meta */
.asc-pub-card-meta {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 6px 14px;
    padding: 10px 12px;
    border-top: 1px solid #f0f0f0;
    margin-top: 10px;
    font-size: 12px;
}
.asc-pub-meta-item {
    display: flex;
    flex-direction: column;
    gap: 2px;
}
.asc-meta-label {
    font-size: 11px;
    color: #888;
    text-transform: uppercase;
    letter-spacing: .3px;
}

/* Card Actions */
.asc-pub-card-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    padding: 10px 12px 12px;
    border-top: 1px solid #f0f0f0;
}
.asc-pub-card-actions .button {
    flex: 1;
    text-align: center;
    font-size: 12px !important;
    justify-content: center;
}

/* ═══════════════════════════════════════════
   Pagination
═══════════════════════════════════════════ */
.asc-pagination-wrap              { margin-top: 14px; }
.asc-pagination-wrap .tablenav-pages { text-align: center; }

/* ═══════════════════════════════════════════
   Empty State (mobile)
═══════════════════════════════════════════ */
.asc-empty-state {
    text-align: center;
    padding: 50px 20px;
    color: #777;
    border: 2px dashed #ddd;
    border-radius: 6px;
}
.asc-empty-state .dashicons {
    font-size: 48px; width: 48px; height: 48px;
    color: #ddd; display: block; margin: 0 auto 12px;
}
.asc-empty-state p { font-size: 15px; margin: 0 0 14px; }

/* ═══════════════════════════════════════════
   寬螢幕 ≥ 783px：顯示表格、隱藏卡片
   （語法已修正，括號內不留空格）
═══════════════════════════════════════════ */
@media screen and (min-width: 783px) {

    .asc-pub-table.asc-desktop-only { display: table !important; }
    .asc-pub-cards.asc-mobile-only  { display: none  !important; }
}

/* ═══════════════════════════════════════════
   手機 ≤ 782px：Filter/Toast/Pagination 調整
═══════════════════════════════════════════ */
@media screen and (max-width: 782px) {

    /* Filter → 全寬堆疊 */
    .asc-filter-grid        { gap: 10px; }
    .asc-filter-field       { min-width: 100%; }
    .asc-filter-field select,
    .asc-filter-field input { width: 100%; }
    .asc-filter-actions     { gap: 6px; }
    .asc-filter-actions .button { flex: 1; justify-content: center; }
    .asc-full-admin-btn     { flex: 0 0 100%; margin-left: 0; order: 10; }
    .asc-filter-count       { width: 100%; }

    /* Toast → 底部 */
    .asc-toast {
        top: auto;
        bottom: 20px;
        left: 16px;
        right: 16px;
        text-align: center;
    }

    /* Pagination 置中 */
    .asc-pagination-wrap .tablenav-pages { text-align: center; }
    .asc-pagination-wrap .page-numbers {
        padding: 6px 10px;
        font-size: 14px;
        min-width: 36px;
        display: inline-block;
        text-align: center;
    }
}

/* ═══════════════════════════════════════════
   超窄 ≤ 480px
═══════════════════════════════════════════ */
@media screen and (max-width: 480px) {
    .asc-pub-card-meta { grid-template-columns: 1fr; }
    .asc-pub-card-actions .button { flex: 1 1 calc(50% - 4px); }
}
</style>
