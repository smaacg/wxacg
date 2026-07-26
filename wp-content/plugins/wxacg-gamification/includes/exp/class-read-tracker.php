<?php
namespace WXACG\Gamification;

defined( 'ABSPATH' ) || exit;

/**
 * Read Tracker — 閱讀文章 EXP
 *
 * 流程：
 *   1. 文章單頁 (is_single('post')) enqueue read-tracker.js
 *   2. JS 計時 30 秒 → AJAX 送 post_id + nonce
 *   3. 後端驗證後觸發 smacg_post_read action
 *   4. Exp_Events::on_post_read() 接手發 EXP
 *
 * 防刷：
 *   - per-post 永久去重（Exp_Events 端的 dedupe_key）
 *   - daily cap 5 篇（Exp_Config）
 *   - 僅登入用戶
 *   - 僅 post 類型
 *
 * @since 2.7.0
 */
class Read_Tracker {

    private static $instance = null;

    public static function instance() {
        if ( self::$instance === null ) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_tracker' ], 25 );
        add_action( 'wp_ajax_smacg_post_read', [ $this, 'handle_ajax' ] );
        // 不註冊 nopriv：訪客不能拿 EXP
    }

    /**
     * Enqueue 前端 JS（僅單篇 post）
     */
    public function enqueue_tracker() {
        if ( ! is_user_logged_in() ) return;
        if ( ! is_singular( 'post' ) ) return;

        $js_rel = 'assets/js/read-tracker.js';
        $js_abs = WXACG_GAMIFY_DIR . $js_rel;
        if ( ! file_exists( $js_abs ) ) return;

        wp_enqueue_script(
            'smacg-read-tracker',
            WXACG_GAMIFY_URL . $js_rel,
            [],
            filemtime( $js_abs ),
            true
        );

        wp_localize_script( 'smacg-read-tracker', 'SmacgRead', [
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'smacg_post_read' ),
            'post_id'  => get_the_ID(),
            'delay'    => 30, // 秒
        ] );
    }

    /**
     * AJAX endpoint
     */
    public function handle_ajax() {
        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'msg' => 'not_logged_in' ], 401 );
        }
        check_ajax_referer( 'smacg_post_read', 'nonce' );

        $uid     = get_current_user_id();
        $post_id = (int) ( $_POST['post_id'] ?? 0 );

        if ( $post_id <= 0 || get_post_type( $post_id ) !== 'post' ) {
            wp_send_json_error( [ 'msg' => 'invalid_post' ], 400 );
        }
        if ( get_post_status( $post_id ) !== 'publish' ) {
            wp_send_json_error( [ 'msg' => 'not_published' ], 400 );
        }

        // 觸發 hook → Exp_Events::on_post_read() 接手
        do_action( 'smacg_post_read', $uid, $post_id );

        // 前端不需要知道實際 EXP 數（去重後可能是 0），給 ok 即可
        wp_send_json_success( [ 'msg' => 'ok' ] );
    }
}
