<?php
/**
 * 會員自訂動漫排行 — 瀏覽次數
 *
 * 為什麼走 AJAX 而不是在模板裡直接 +1：
 *   公開個人頁對訪客是被 LiteSpeed 快取的（實測 X-LiteSpeed-Cache-Control:
 *   public,max-age=604800）。伺服器端計數只會在快取未命中時執行，命中的
 *   訪問完全不會進到 PHP——數字會嚴重低估，而且越熱門的排行低估越多。
 *
 * 為什麼不帶 nonce：
 *   同一份快取 HTML 會發給所有訪客，裡面的 nonce 是產生快取那一刻的值，
 *   對其他人／過一段時間後都是無效的。硬要驗只會讓計數整片失敗。
 *   改用「清單必須存在且公開」+「同一 IP 在冷卻期內只算一次」把關；
 *   這是純計數、不改任何使用者資料，風險與寫入型端點不同。
 *
 * 計數存在獨立的 user_meta（每個清單一個鍵），不塞回 wxacg_toplists 陣列：
 *   後者是讀取整包、修改、整包寫回，瀏覽計數的併發寫入會互相覆蓋，
 *   連帶可能把使用者剛存的排行內容一起蓋掉。
 *
 * @package WXACG_Social
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** 同一訪客對同一份排行的計數冷卻時間（秒） */
if ( ! defined( 'WXACG_TOPLIST_VIEW_COOLDOWN' ) ) {
    define( 'WXACG_TOPLIST_VIEW_COOLDOWN', 6 * HOUR_IN_SECONDS );
}

/**
 * 瀏覽次數的 user_meta 鍵名。
 *
 * @param int $list_id 清單 ID。
 */
function wxacg_toplist_views_key( int $list_id ): string {
    return '_wxacg_toplist_views_' . $list_id;
}

/**
 * 取得某份排行的瀏覽次數。
 *
 * @param int $user_id 會員 ID。
 * @param int $list_id 清單 ID。
 */
function wxacg_toplist_get_views( int $user_id, int $list_id ): int {
    return (int) get_user_meta( $user_id, wxacg_toplist_views_key( $list_id ), true );
}

/**
 * 訪客識別字串——用來做冷卻判斷。
 *
 * 只取 IP 與 User-Agent 的雜湊，不存原始值，也不寫進資料庫（只放 transient）。
 * 這不是精準的不重複訪客統計，目的僅是擋掉重整頁面就 +1 的灌水。
 */
function wxacg_toplist_viewer_hash( int $list_id ): string {
    $ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';
    $ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';

    return 'wxtlv_' . $list_id . '_' . md5( $ip . '|' . $ua );
}

/**
 * 累加一次瀏覽。
 *
 * @param int $user_id 清單擁有者。
 * @param int $list_id 清單 ID。
 * @return int|null 累加後的次數；未計入時回傳 null。
 */
function wxacg_toplist_add_view( int $user_id, int $list_id ): ?int {

    // 擁有者看自己的排行不計入——否則編輯時反覆預覽就會把數字灌大
    if ( get_current_user_id() === $user_id ) {
        return null;
    }

    $key = wxacg_toplist_viewer_hash( $list_id );
    if ( get_transient( $key ) ) {
        return null;
    }
    set_transient( $key, 1, WXACG_TOPLIST_VIEW_COOLDOWN );

    $meta = wxacg_toplist_views_key( $list_id );
    $now  = (int) get_user_meta( $user_id, $meta, true ) + 1;
    update_user_meta( $user_id, $meta, $now );

    return $now;
}

/* ============================================================
   AJAX：記錄一次瀏覽（登入與未登入都要）
   ============================================================ */
function wxacg_toplist_handle_view(): void {

    $user_id = isset( $_POST['user'] ) ? absint( $_POST['user'] ) : 0;
    $list_id = isset( $_POST['list'] ) ? absint( $_POST['list'] ) : 0;

    if ( ! $user_id || ! $list_id ) {
        wp_send_json_error( [], 400 );
    }

    // 必須是真實存在且對外公開的清單，否則不計
    $list = wxacg_toplist_get( $user_id, $list_id );
    if ( ! $list || empty( $list['public'] ) ) {
        wp_send_json_error( [], 404 );
    }

    $count = wxacg_toplist_add_view( $user_id, $list_id );

    wp_send_json_success( [
        'counted' => $count !== null,
        'views'   => $count ?? wxacg_toplist_get_views( $user_id, $list_id ),
    ] );
}
add_action( 'wp_ajax_wxacg_toplist_view',        'wxacg_toplist_handle_view' );
add_action( 'wp_ajax_nopriv_wxacg_toplist_view', 'wxacg_toplist_handle_view' );

/* ============================================================
   刪除排行時一併清掉計數，不留孤兒 user_meta
   ============================================================ */
add_action( 'wxacg_toplist_deleted', function ( int $user_id, int $list_id ): void {
    delete_user_meta( $user_id, wxacg_toplist_views_key( $list_id ) );
}, 10, 2 );
