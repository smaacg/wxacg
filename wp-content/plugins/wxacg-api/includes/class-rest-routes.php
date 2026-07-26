<?php
/**
 * REST API 路由
 *
 * 提供以下端點（namespace 與原 blocksy-child/inc/api-rest.php 完全相同，前端不需任何改動）：
 *   GET  /wp-json/weixiaoacg/v1/user/favorites
 *   GET  /wp-json/weixiaoacg/v1/anime-url?ids=1,2,3
 *
 * 註：/wp-json/smacg/v1/user-level 已交由 wxacg-gamification 外掛
 *     （includes/rest/class-rest-api.php）註冊，不在本外掛範圍。
 *
 * 註：原 /weixiaoacg/v1/ranking 端點已於本版移除
 *     （meta key 錯誤、前端未使用，站內排行改由 anime-sync-pro 的
 *     class-rating-manager.php 之 /ranking/site 提供）。
 *
 * @package WxacgApi
 */

defined( 'ABSPATH' ) || exit;

class Wxacg_Api_Rest_Routes {

    public function register_hooks(): void {
        add_action( 'rest_api_init', [ $this, 'register_routes' ] );
    }

    public function register_routes(): void {
        $this->route_user_favorites();
        $this->route_anime_url();
    }

    /* ──────────────────────────────────────────────
     * /weixiaoacg/v1/user/favorites
     * ────────────────────────────────────────────── */
    private function route_user_favorites(): void {
        register_rest_route( 'weixiaoacg/v1', '/user/favorites', [
            'methods'             => 'GET',
            'permission_callback' => 'is_user_logged_in',
            'callback'            => [ $this, 'callback_user_favorites' ],
        ] );
    }

    public function callback_user_favorites(): WP_REST_Response {
        $uid       = get_current_user_id();
        $favorites = (array) ( get_user_meta( $uid, 'weixiaoacg_favorites', true ) ?: [] );

        $items = array_values( array_filter( array_map( function ( $pid ) {
            $pid = (int) $pid;
            if ( ! $pid ) return null;
            return [
                'id'       => $pid,
                'title_zh' => $this->acf( 'weixiaoacg_title_zh', $pid, get_the_title( $pid ) ),
                'cover'    => get_the_post_thumbnail_url( $pid, 'weixiaoacg-thumb' ),
                'url'      => get_permalink( $pid ),
            ];
        }, $favorites ) ) );

        return new WP_REST_Response( [ 'favorites' => $items ], 200 );
    }

    /* ──────────────────────────────────────────────
     * /weixiaoacg/v1/anime-url?ids=1,2,3
     * ────────────────────────────────────────────── */
    private function route_anime_url(): void {
        register_rest_route( 'weixiaoacg/v1', '/anime-url', [
            'methods'             => 'GET',
            'permission_callback' => '__return_true',
            'args' => [
                'ids' => [
                    'required'          => true,
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
            'callback' => [ $this, 'callback_anime_url' ],
        ] );
    }

    public function callback_anime_url( WP_REST_Request $req ) {
        $ids = array_filter( array_map( 'intval', explode( ',', $req->get_param( 'ids' ) ) ) );
        if ( empty( $ids ) ) {
            return new WP_Error( 'no_ids', 'ids 參數必填', [ 'status' => 400 ] );
        }

        $posts = get_posts( [
            'post_type'      => 'anime',
            'post_status'    => 'publish',
            'posts_per_page' => count( $ids ),
            'no_found_rows'  => true,
            'meta_query'     => [ [
                'key'     => 'anime_anilist_id',
                'value'   => $ids,
                'compare' => 'IN',
                'type'    => 'NUMERIC',
            ] ],
        ] );

        $map = [];
        foreach ( $posts as $p ) {
            $al = (int) get_post_meta( $p->ID, 'anime_anilist_id', true );
            if ( $al ) {
                $map[ $al ] = [
                    'url'  => get_permalink( $p->ID ),
                    'slug' => $p->post_name,
                ];
            }
        }

        return rest_ensure_response( $map );
    }

    /* ──────────────────────────────────────────────
     * Helpers
     * ────────────────────────────────────────────── */

    /**
     * 安全讀取 ACF 欄位（沿用原 weixiaoacg_acf 行為）
     */
    private function acf( string $key, int $post_id = 0, $default = '' ) {
        if ( function_exists( 'get_field' ) ) {
            $v = get_field( $key, $post_id );
            return ( $v === null || $v === false || $v === '' ) ? $default : $v;
        }
        $v = get_post_meta( $post_id, $key, true );
        return ( $v === '' ) ? $default : $v;
    }
}
