<?php
/**
 * Security Handler
 * @package Anime_Sync_Pro
 */

// ✅ Bug H 修正：補上 ABSPATH 安全檢查
if ( ! defined( 'ABSPATH' ) ) exit;

class Anime_Sync_Security {

    public static function verify_ajax_nonce( $action = 'anime_sync_ajax' ) {
        if ( ! check_ajax_referer( $action, 'nonce', false ) ) {
            wp_send_json_error( [ 'message' => '安全驗證失敗' ], 403 );
            return false;
        }
        return true;
    }

    public static function check_capability( $capability = 'manage_options' ) {
        if ( ! current_user_can( $capability ) ) {
            wp_send_json_error( [ 'message' => '權限不足' ], 403 );
            return false;
        }
        return true;
    }

    // ✅ Bug G 修正：上限從 999,999 → 9,999,999
    public static function sanitize_anilist_id( $id ) {
        $id = absint( $id );
        if ( $id < 1 || $id > 9999999 ) return false;
        return $id;
    }

    public static function sanitize_anilist_ids( $ids ) {
        if ( is_string( $ids ) ) $ids = explode( ',', $ids );
        if ( ! is_array( $ids ) ) return [];
        $clean = [];
        foreach ( $ids as $id ) {
            $v = self::sanitize_anilist_id( $id );
            if ( $v !== false ) $clean[] = $v;
        }
        return array_unique( $clean );
    }

    public static function sanitize_season( $season ) {
        $s = strtoupper( trim( $season ) );
        return in_array( $s, [ 'WINTER', 'SPRING', 'SUMMER', 'FALL' ], true ) ? $s : false;
    }

   public static function sanitize_year( $year ): int|false {
    $year         = (int) $year;
    $current_year = (int) gmdate( 'Y' ); // Bug AS fix: use gmdate() instead of date()
    $min_year     = $current_year - 30;
    $max_year     = $current_year + 5;

    if ( $year >= $min_year && $year <= $max_year ) {
        return $year;
    }
    return false;
}

    public static function escape_output( $text, $context = 'html' ) {
        return match ( $context ) {
            'attr'     => esc_attr( $text ),
            'url'      => esc_url( $text ),
            'js'       => esc_js( $text ),
            'textarea' => esc_textarea( $text ),
            default    => wp_kses_post( $text ),
        };
    }

    public static function validate_json( $json ) {
        if ( empty( $json ) ) return false;
        $data = json_decode( $json, true );
        return json_last_error() === JSON_ERROR_NONE ? $data : false;
    }

    public static function rate_limit_check( $action, $limit = 10, $period = 60 ) {
        $uid = get_current_user_id();
        if ( ! $uid ) return false;
        $key  = 'anime_sync_rate_limit_' . $action . '_' . $uid;
        $reqs = get_transient( $key );
        if ( $reqs === false ) { set_transient( $key, 1, $period ); return true; }
        if ( $reqs >= $limit ) return false;
        set_transient( $key, $reqs + 1, $period );
        return true;
    }
}
