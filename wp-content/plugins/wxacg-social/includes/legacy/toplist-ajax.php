<?php
/**
 * 會員自訂動漫排行 — AJAX 端點
 *
 * 只有兩個動作：儲存（新增／更新共用）與刪除。清單內容一律整份覆寫，
 * 不做逐項增刪的 API——排序本來就是整份重排，逐項操作反而要處理更多
 * 併發與順序衝突。
 *
 * 所有驗證都在資料層（toplist-data.php）做，這裡只負責身分、nonce
 * 與參數形狀。
 *
 * @package WXACG_Social
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * 共用的請求前置檢查。
 *
 * @return int 通過檢查的會員 ID；未通過時直接送出 JSON 錯誤並結束。
 */
function wxacg_toplist_ajax_guard(): int {
    check_ajax_referer( WXACG_TOPLIST_NONCE, 'nonce' );

    $uid = get_current_user_id();
    if ( ! $uid ) {
        wp_send_json_error( [ 'msg' => '請先登入' ], 401 );
    }

    return $uid;
}

/**
 * 儲存排行清單（新增或更新）。
 *
 * items 以逗號分隔的字串傳入而不是陣列——PHP 對 items[] 這種形式有
 * max_input_vars 上限（預設 1000），雖然本功能最多 20 筆碰不到，但用
 * 字串傳可以完全避開這類環境差異。
 */
add_action( 'wp_ajax_wxacg_toplist_save', function () {

    $uid = wxacg_toplist_ajax_guard();

    $raw_items = isset( $_POST['items'] ) ? sanitize_text_field( wp_unslash( $_POST['items'] ) ) : '';
    $items     = $raw_items !== '' ? array_map( 'intval', explode( ',', $raw_items ) ) : [];

    $result = wxacg_toplist_save( $uid, [
        'id'     => isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0,
        'title'  => isset( $_POST['title'] ) ? wp_unslash( $_POST['title'] ) : '',
        'size'   => isset( $_POST['size'] ) ? absint( $_POST['size'] ) : 10,
        'items'  => $items,
        'public' => ! empty( $_POST['public'] ) && $_POST['public'] !== 'false',
    ] );

    if ( is_wp_error( $result ) ) {
        wp_send_json_error( [ 'msg' => $result->get_error_message() ], 400 );
    }

    $saved = wxacg_toplist_get( $uid, (int) $result );

    wp_send_json_success( [
        'id'    => (int) $result,
        'list'  => $saved,
        'url'   => wxacg_toplist_permalink( $uid, (int) $result ),
        // 回傳清洗後的實際筆數，讓前端能提示「有幾筆被剔除」
        'kept'  => $saved ? count( $saved['items'] ) : 0,
        'sent'  => count( $items ),
    ] );
} );

/**
 * 刪除排行清單。
 */
add_action( 'wp_ajax_wxacg_toplist_delete', function () {

    $uid     = wxacg_toplist_ajax_guard();
    $list_id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;

    if ( ! $list_id ) {
        wp_send_json_error( [ 'msg' => '缺少清單 ID' ], 400 );
    }

    if ( ! wxacg_toplist_delete( $uid, $list_id ) ) {
        wp_send_json_error( [ 'msg' => '找不到該清單' ], 404 );
    }

    wp_send_json_success( [ 'id' => $list_id ] );
} );
