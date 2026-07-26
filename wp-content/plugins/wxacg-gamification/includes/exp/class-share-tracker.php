<?php
namespace WXACG\Gamification;

defined( 'ABSPATH' ) || exit;

/**
 * Share Tracker — 分享文章 EXP
 *
 * 流程：
 *   1. blocksy-child/single.php 內既有 .share-btn / .share-copy（無侵入）
 *   2. JS 監聽點擊 → AJAX 送 post_id
 *   3. 後端觸發 smacg_post_shared action
 *
 * 防刷：
 *   - per-post 永久去重
 *   - daily cap 3 篇
 *   - 僅登入用戶
 *
 * @since 2.7.0
 */
class Share_Tracker {

    private static $instance = null;

    public static function instance() {
        if ( self::$instance === null ) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_tracker' ], 25 );
        add_action( 'wp_ajax_smacg_post_shared', [ $this, 'handle_ajax' ] );
    }

    public function enqueue_tracker() {
        if ( ! is_user_logged_in() ) return;
        if ( ! is_singular( 'post' ) ) return;

        $js_rel = 'assets/js/share-tracker.js';
        $js_abs = WXACG_GAMIFY_DIR . $js_rel;
        if ( ! file_exists( $js_abs ) ) return;

        wp_enqueue_script(
            'smacg-share-tracker',
            WXACG_GAMIFY_URL . $js_rel,
            [],
            filemtime( $js_abs ),
            true
        );

        wp_localize_script( 'smacg-share-tracker', 'SmacgShare', [
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'smacg_post_shared' ),
            'post_id'  => get_the_ID(),
        ] );
    }

    public function handle_ajax() {
        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'msg' => 'not_logged_in' ], 401 );
        }
        check_ajax_referer( 'smacg_post_shared', 'nonce' );

        $uid     = get_current_user_id();
        $post_id = (int) ( $_POST['post_id'] ?? 0 );

        if ( $post_id <= 0 || get_post_type( $post_id ) !== 'post' ) {
            wp_send_json_error( [ 'msg' => 'invalid_post' ], 400 );
        }

        do_action( 'smacg_post_shared', $uid, $post_id );

        wp_send_json_success( [ 'msg' => 'ok' ] );
    }
}
