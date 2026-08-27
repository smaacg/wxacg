<?php
/**
 * 會員自訂動漫排行 — 會員中心編輯器
 *
 * 排序用 ↑↓ 按鈕而不是拖曳：站上目前沒有任何拖曳排序的實作，要新寫；
 * 而 HTML5 drag & drop 在觸控裝置上支援很差，本站又有手機底部導覽、
 * 行動流量佔比不低。↑↓ 在所有裝置與輔助技術上都能用，也不必引入
 * 第三方函式庫。
 *
 * 可挑選的作品來源是會員自己的追番清單——站上有 108 人有清單、卻只有
 * 5 人有收藏，從清單挑是門檻最低的路徑，也不必額外做搜尋選片器。
 *
 * @package WXACG_Social
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * 會員中心「我的排行」面板。
 *
 * @param int   $user_id   會員 ID。
 * @param array $watchlist 已在 page-member.php 載入的追番清單，直接重用不重查。
 */
function wxacg_toplist_render_editor( int $user_id, array $watchlist = [] ): void {

    $lists = wxacg_toplist_get_all( $user_id );

    /*
     * 把可挑選的作品整理成前端要的最小形狀。
     * 只帶 id / 標題 / 封面——編輯器不需要年份、狀態等其他欄位。
     */
    $pool = [];
    foreach ( $watchlist as $w ) {
        // smacg_build_watchlist() 用的鍵是 post_id（見 member-stats.php），
        // 另外兩個是防禦性寫法，日後結構調整時不會整個空掉
        $pid = (int) ( $w['post_id'] ?? $w['id'] ?? $w['anime_id'] ?? 0 );
        if ( ! $pid ) {
            continue;
        }

        $cover = (string) get_the_post_thumbnail_url( $pid, 'weixiaoacg-cover' );
        if ( $cover === '' ) {
            $cover = (string) get_the_post_thumbnail_url( $pid, 'medium' );
        }

        $pool[] = [
            'id'    => $pid,
            'title' => get_the_title( $pid ),
            'cover' => $cover,
        ];
    }

    // 排行裡可能有已不在追番清單的作品（後來移出清單），補進候選池才不會
    // 在編輯器裡顯示成空白項目
    $in_pool = wp_list_pluck( $pool, 'id' );
    foreach ( $lists as $list ) {
        foreach ( $list['items'] as $pid ) {
            $pid = (int) $pid;
            if ( in_array( $pid, $in_pool, true ) ) {
                continue;
            }
            $item = wxacg_toplist_item_data( $pid );
            if ( $item ) {
                $pool[]    = [ 'id' => $pid, 'title' => $item['title'], 'cover' => $item['cover'] ];
                $in_pool[] = $pid;
            }
        }
    }

    $boot = [
        'lists'    => $lists,
        'pool'     => $pool,
        'max'      => WXACG_TOPLIST_MAX,
        'sizes'    => wxacg_toplist_sizes(),
        'titleMax' => WXACG_TOPLIST_TITLE_MAX,
        'ajax'     => admin_url( 'admin-ajax.php' ),
        'nonce'    => wp_create_nonce( WXACG_TOPLIST_NONCE ),
        'baseUrl'  => wxacg_toplist_permalink( $user_id, 0 ),
    ];
    ?>
    <div class="wxtl-editor" id="wxtl-editor"
         data-boot="<?php echo esc_attr( wp_json_encode( $boot ) ); ?>">

        <header class="wxtl-ed-head">
            <div>
                <h2 class="wxtl-ed-title">🏆 我的動漫排行</h2>
                <p class="wxtl-ed-sub">
                    排出自己的推薦榜，取個主題名稱，就能把連結貼到任何地方分享。
                    最多可建立 <?php echo (int) WXACG_TOPLIST_MAX; ?> 個排行。
                </p>
            </div>
            <button type="button" class="wxtl-btn wxtl-btn-primary" id="wxtl-new">＋ 新增排行</button>
        </header>

        <?php if ( empty( $pool ) ) : ?>
            <p class="wxtl-empty">
                你的追番清單還是空的。先去
                <a href="<?php echo esc_url( home_url( '/anime/' ) ); ?>">動畫資料庫</a>
                把想看或看過的作品加進清單，就能開始排行了。
            </p>
        <?php endif; ?>

        <!-- 清單分頁（由 JS 依 boot.lists 產生） -->
        <div class="wxtl-ed-tabs" id="wxtl-tabs"></div>

        <!-- 目前編輯中的排行（由 JS 產生） -->
        <div class="wxtl-ed-body" id="wxtl-body"></div>

    </div>
    <?php
}

/**
 * 會員中心載入排行編輯器的樣式與腳本。
 *
 * 依附在 smacg-member 之後，沿用它已經建立的載入時機判斷
 * （smacg_is_member_page）。
 */
add_action( 'wp_enqueue_scripts', function () {

    if ( ! function_exists( 'smacg_is_member_page' ) || ! smacg_is_member_page() ) {
        return;
    }

    $js  = get_stylesheet_directory() . '/assets/js/toplist-editor.js';
    $css = get_stylesheet_directory() . '/assets/css/toplist-editor.css';

    if ( file_exists( $js ) ) {
        wp_enqueue_script(
            'wxacg-toplist-editor',
            get_stylesheet_directory_uri() . '/assets/js/toplist-editor.js',
            [],
            filemtime( $js ),
            true
        );
    }

    if ( file_exists( $css ) ) {
        wp_enqueue_style(
            'wxacg-toplist-editor',
            get_stylesheet_directory_uri() . '/assets/css/toplist-editor.css',
            [],
            filemtime( $css )
        );
    }
}, 21 );
