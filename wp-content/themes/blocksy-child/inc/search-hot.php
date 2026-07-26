<?php
/**
 * 微笑動漫 — 熱門搜尋
 * 路徑：/blocksy-child/inc/search-hot.php
 * 版本：1.0.0 (2026-07-24)
 *
 * REST：
 *   POST weixiaoacg/v1/search-hot/hit   body: { q: "關鍵字" }  → 該字 +1
 *   GET  weixiaoacg/v1/search-hot/top   ?limit=8              → 回傳熱門前 N
 *
 * 儲存：wp_options 'wxacg_hot_search' = { "關鍵字": 次數, ... }
 */
defined( 'ABSPATH' ) || exit;

const WXACG_HOT_OPTION   = 'wxacg_hot_search';
const WXACG_HOT_MAX_KEYS = 300;   // 最多保留幾個關鍵字，避免無限膨脹

add_action( 'rest_api_init', function () {

    register_rest_route( 'weixiaoacg/v1', '/search-hot/hit', [
        'methods'             => 'POST',
        'permission_callback' => '__return_true',
        'callback'            => 'wxacg_hot_search_hit',
        'args'                => [
            'q' => [ 'required' => true, 'type' => 'string' ],
        ],
    ] );

    register_rest_route( 'weixiaoacg/v1', '/search-hot/top', [
        'methods'             => 'GET',
        'permission_callback' => '__return_true',
        'callback'            => 'wxacg_hot_search_top',
        'args'                => [
            'limit' => [ 'default' => 8, 'sanitize_callback' => 'absint' ],
        ],
    ] );
} );

/**
 * 關鍵字正規化：去頭尾空白、限制長度、過濾空字串。
 */
function wxacg_hot_search_norm( $q ) {
    $q = trim( wp_strip_all_tags( (string) $q ) );
    $q = preg_replace( '/\s+/u', ' ', $q );
    if ( $q === '' ) return '';
    // 限制長度（以字元計，避免有人塞超長字串）
    if ( function_exists( 'mb_substr' ) ) {
        $q = mb_substr( $q, 0, 40 );
    } else {
        $q = substr( $q, 0, 40 );
    }
    return $q;
}

function wxacg_hot_search_hit( WP_REST_Request $req ) {
    $q = wxacg_hot_search_norm( $req->get_param( 'q' ) );
    if ( $q === '' ) {
        return new WP_REST_Response( [ 'success' => false, 'message' => 'empty' ], 200 );
    }

    $data = get_option( WXACG_HOT_OPTION, [] );
    if ( ! is_array( $data ) ) $data = [];

    $data[ $q ] = isset( $data[ $q ] ) ? (int) $data[ $q ] + 1 : 1;

    // 超過上限時，砍掉次數最少的
    if ( count( $data ) > WXACG_HOT_MAX_KEYS ) {
        arsort( $data );
        $data = array_slice( $data, 0, WXACG_HOT_MAX_KEYS, true );
    }

    // 不 autoload，避免每頁都載入
    update_option( WXACG_HOT_OPTION, $data, false );

    return new WP_REST_Response( [ 'success' => true, 'count' => $data[ $q ] ], 200 );
}

function wxacg_hot_search_top( WP_REST_Request $req ) {
    $limit = max( 1, min( 20, (int) $req->get_param( 'limit' ) ) );

    $data = get_option( WXACG_HOT_OPTION, [] );
    if ( ! is_array( $data ) ) $data = [];

    arsort( $data );
    $top = array_slice( $data, 0, $limit, true );

    $out = [];
    foreach ( $top as $kw => $n ) {
        $out[] = [ 'q' => $kw, 'count' => (int) $n ];
    }

    return new WP_REST_Response( [ 'success' => true, 'data' => $out ], 200 );
}
