<?php
/**
 * SMACG Social — Corrections 後台審核介面
 *
 * 把 wxacg_correction CPT 的後台列表改造成審核台：
 *   - 自訂欄位：類別 / 作品（連編輯頁）/ 問題說明 / 來源 / 回報人 / 時間 / 狀態
 *   - 每列操作：標記已修正 / 駁回（可填原因）
 *   - 「已修正」會觸發 do_action('wxacg_correction_resolved', $post_id, $reporter, $anime_id)
 *     由 corrections-notify.php 負責發通知 + 送 EXP
 *
 * @version 1.0.0
 */

namespace WXACG\Social;

defined( 'ABSPATH' ) || exit;

final class Corrections_Admin {

    private static ?Corrections_Admin $instance = null;

    public static function instance(): Corrections_Admin {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $pt = Corrections_CPT::POST_TYPE;

        // 自訂列表欄位
        add_filter( "manage_{$pt}_posts_columns",        [ __CLASS__, 'columns' ] );
        add_action( "manage_{$pt}_posts_custom_column",   [ __CLASS__, 'column_content' ], 10, 2 );

        // 狀態篩選下拉
        add_action( 'restrict_manage_posts', [ __CLASS__, 'status_filter' ] );
        add_filter( 'parse_query',           [ __CLASS__, 'apply_status_filter' ] );

        // 處理「已修正 / 駁回」動作
        add_action( 'admin_post_wxacg_corr_resolve', [ __CLASS__, 'handle_resolve' ] );
        add_action( 'admin_post_wxacg_corr_reject',  [ __CLASS__, 'handle_reject' ] );

        // 後台通知條（成功/失敗提示）
        add_action( 'admin_notices', [ __CLASS__, 'admin_notices' ] );

        // 移除通往空白編輯畫面的列操作
        add_filter( 'post_row_actions', [ __CLASS__, 'row_actions' ], 10, 2 );
    }

    /**
     * 處理回報所需的權限。
     *
     * 與站上其他編輯工具（短評控管、消息審核、操作紀錄）同一個 filter，
     * 預設 edit_others_posts——編輯有、作者以下沒有。
     *
     * 用 function_exists 包住，wxacg-social 不硬相依 anime-sync-pro；
     * 那個函式不存在時退回同名 filter 的預設值，兩邊仍然同步。
     */
    private static function manage_cap(): string {
        if ( function_exists( 'wxacg_editorial_tools_cap' ) ) {
            return wxacg_editorial_tools_cap();
        }

        return (string) apply_filters( 'wxacg_editorial_tools_cap', 'edit_others_posts' );
    }

    /**
     * 拿掉「編輯／快速編輯」列操作。
     *
     * 這個 CPT 只 supports title、也沒有註冊任何 meta box，編輯畫面除了
     * 標題什麼都沒有——回報的類別、說明、來源、狀態全都在列表頁的自訂
     * 欄位裡，處理按鈕也在列表頁。點進編輯畫面只會看到一片空白，是條死路。
     *
     * 保留「移至垃圾桶」，讓處理者能清掉灌水或重複的回報。
     */
    public static function row_actions( array $actions, \WP_Post $post ): array {
        if ( $post->post_type !== Corrections_CPT::POST_TYPE ) {
            return $actions;
        }

        unset( $actions['edit'], $actions['inline hide-if-no-js'] );

        return $actions;
    }

    /* -------------------------------------------------- *
     * 列表欄位定義
     * -------------------------------------------------- */
    public static function columns( array $cols ): array {
        return [
            'cb'          => $cols['cb'] ?? '<input type="checkbox" />',
            'corr_cat'    => '類別',
            'corr_target' => '作品',
            'corr_detail' => '問題說明',
            'corr_source' => '來源',
            'corr_user'   => '回報人',
            'corr_date'   => '回報時間',
            'corr_status' => '狀態',
            'corr_action' => '操作',
        ];
    }

    public static function column_content( string $col, int $post_id ): void {
        switch ( $col ) {

            case 'corr_cat':
                $cat = get_post_meta( $post_id, '_wxacg_corr_category', true );
                echo esc_html( Corrections_CPT::category_label( $cat ) );
                break;

            case 'corr_target':
                $aid = (int) get_post_meta( $post_id, '_wxacg_corr_anime_id', true );
                if ( $aid ) {
                    $edit = admin_url( 'post.php?post=' . $aid . '&action=edit' );
                    printf(
                        '<a href="%s" target="_blank"><strong>%s</strong></a><br><span style="color:#888;font-size:11px">%s · ID %d ↗</span>',
                        esc_url( $edit ),
                        esc_html( get_the_title( $aid ) ),
                        esc_html( get_post_type( $aid ) ),
                        $aid
                    );
                } else {
                    echo '<span style="color:#c00">作品已不存在</span>';
                }
                break;

            case 'corr_detail':
                $detail = get_post_meta( $post_id, '_wxacg_corr_detail', true );
                echo '<div style="max-width:340px;white-space:pre-wrap;line-height:1.5">'
                   . esc_html( $detail ) . '</div>';
                break;

            case 'corr_source':
                $src = get_post_meta( $post_id, '_wxacg_corr_source', true );
                if ( $src ) {
                    printf( '<a href="%s" target="_blank" rel="noopener">查看 ↗</a>', esc_url( $src ) );
                } else {
                    echo '<span style="color:#bbb">—</span>';
                }
                break;

            case 'corr_user':
                $uid  = (int) get_post_meta( $post_id, '_wxacg_corr_reporter', true );
                $user = $uid ? get_userdata( $uid ) : null;
                echo $user ? esc_html( $user->display_name ) : '<span style="color:#bbb">—</span>';
                break;

            case 'corr_date':
                echo esc_html( get_the_date( 'Y-m-d H:i', $post_id ) );
                break;

            case 'corr_status':
                self::render_status( get_post_meta( $post_id, '_wxacg_corr_status', true ) );
                $note = get_post_meta( $post_id, '_wxacg_corr_admin_note', true );
                if ( $note ) {
                    echo '<br><span style="color:#888;font-size:11px">備註：' . esc_html( $note ) . '</span>';
                }
                break;

            case 'corr_action':
                self::render_actions( $post_id );
                break;
        }
    }

    private static function render_status( string $status ): void {
        $map = [
            'pending'  => [ '待處理', '#e6a700', 'rgba(230,167,0,.12)' ],
            'resolved' => [ '已修正', '#2e7d32', 'rgba(46,125,50,.12)' ],
            'rejected' => [ '已駁回', '#c62828', 'rgba(198,40,40,.12)' ],
        ];
        [ $label, $color, $bg ] = $map[ $status ] ?? [ $status ?: '未知', '#666', '#eee' ];
        printf(
            '<span style="display:inline-block;padding:2px 10px;border-radius:12px;color:%s;background:%s;font-size:12px;font-weight:600">%s</span>',
            esc_attr( $color ), esc_attr( $bg ), esc_html( $label )
        );
    }

    private static function render_actions( int $post_id ): void {
        $status = get_post_meta( $post_id, '_wxacg_corr_status', true );
        if ( $status !== 'pending' ) {
            echo '<span style="color:#bbb">已處理</span>';
            return;
        }

        // 標記已修正
        $resolve = wp_nonce_url(
            admin_url( 'admin-post.php?action=wxacg_corr_resolve&post_id=' . $post_id ),
            'wxacg_corr_resolve_' . $post_id
        );
        printf(
            '<a href="%s" class="button button-primary" style="margin-bottom:4px">✓ 標記已修正</a><br>',
            esc_url( $resolve )
        );

        /*
         * 駁回：與上面的「標記已修正」一樣用帶 nonce 的連結，不用表單。
         *
         * 原本這裡是一個 <form method="post" action="admin-post.php">，但它被
         * 渲染在列表表格的儲存格內，而整張列表本身已經包在 WordPress 的
         * <form method="get" id="posts-filter"> 裡。HTML 不允許表單巢狀，
         * 瀏覽器會直接丟棄內層的 <form> 標籤、只留下裡面的 input——於是按下
         * 駁回送出的是外層表單：GET 到 edit.php，帶著兩組 _wpnonce 與兩組
         * action，handle_reject() 根本不會被執行，畫面只顯示「連結已到期」。
         *
         * 改用連結後兩個動作機制一致，nonce 照樣保護。駁回原因用 prompt()
         * 取得後附加到 href；使用者按取消（回傳 null）就不送出。
         */
        $reject = wp_nonce_url(
            admin_url( 'admin-post.php?action=wxacg_corr_reject&post_id=' . $post_id ),
            'wxacg_corr_reject_' . $post_id
        );

        $onclick = "var r = window.prompt('駁回原因（選填，可留空）', '');"
                 . "if ( r === null ) { return false; }"
                 . "this.href = this.href + '&reason=' + encodeURIComponent( r );"
                 . "return true;";

        printf(
            '<a href="%s" class="button" style="margin-top:6px;font-size:11px" onclick="%s">✕ 駁回</a>',
            esc_url( $reject ),
            esc_attr( $onclick )
        );
    }

    /* -------------------------------------------------- *
     * 狀態篩選下拉
     * -------------------------------------------------- */
    public static function status_filter(): void {
        global $typenow;
        if ( $typenow !== Corrections_CPT::POST_TYPE ) {
            return;
        }
        $cur = isset( $_GET['corr_status'] ) ? sanitize_key( $_GET['corr_status'] ) : '';
        $opts = [ '' => '全部狀態', 'pending' => '待處理', 'resolved' => '已修正', 'rejected' => '已駁回' ];
        echo '<select name="corr_status">';
        foreach ( $opts as $val => $label ) {
            printf(
                '<option value="%s"%s>%s</option>',
                esc_attr( $val ), selected( $cur, $val, false ), esc_html( $label )
            );
        }
        echo '</select>';
    }

    public static function apply_status_filter( $query ): void {
        global $pagenow, $typenow;
        if ( $pagenow !== 'edit.php' || $typenow !== Corrections_CPT::POST_TYPE || ! is_admin() ) {
            return;
        }
        if ( ! empty( $_GET['corr_status'] ) ) {
            $query->query_vars['meta_key']   = '_wxacg_corr_status';
            $query->query_vars['meta_value'] = sanitize_key( $_GET['corr_status'] );
        }
    }

    /* -------------------------------------------------- *
     * 處理：標記已修正
     * -------------------------------------------------- */
    public static function handle_resolve(): void {
        if ( ! current_user_can( self::manage_cap() ) ) {
            wp_die( '權限不足' );
        }
        $post_id = isset( $_GET['post_id'] ) ? absint( $_GET['post_id'] ) : 0;
        check_admin_referer( 'wxacg_corr_resolve_' . $post_id );

        if ( ! $post_id || get_post_type( $post_id ) !== Corrections_CPT::POST_TYPE ) {
            wp_die( '無效的回報' );
        }

        update_post_meta( $post_id, '_wxacg_corr_status', 'resolved' );
        update_post_meta( $post_id, '_wxacg_corr_resolved_at', current_time( 'mysql' ) );

        $reporter = (int) get_post_meta( $post_id, '_wxacg_corr_reporter', true );
        $anime_id = (int) get_post_meta( $post_id, '_wxacg_corr_anime_id', true );

        /**
         * 觸發通知 + 送 EXP（由 corrections-notify.php 接手）
         * @param int $post_id  回報記錄 ID
         * @param int $reporter 回報會員 ID
         * @param int $anime_id 被回報的作品 ID
         */
        do_action( 'wxacg_correction_resolved', $post_id, $reporter, $anime_id );

        self::redirect_back( 'resolved' );
    }

    /* -------------------------------------------------- *
     * 處理：駁回
     * -------------------------------------------------- */
    public static function handle_reject(): void {
        if ( ! current_user_can( self::manage_cap() ) ) {
            wp_die( '權限不足' );
        }
        // 改用連結送出後參數在 query string，與 handle_resolve() 一致
        $post_id = isset( $_GET['post_id'] ) ? absint( $_GET['post_id'] ) : 0;
        check_admin_referer( 'wxacg_corr_reject_' . $post_id );

        if ( ! $post_id || get_post_type( $post_id ) !== Corrections_CPT::POST_TYPE ) {
            wp_die( '無效的回報' );
        }

        $reason = isset( $_GET['reason'] ) ? sanitize_text_field( wp_unslash( $_GET['reason'] ) ) : '';

        update_post_meta( $post_id, '_wxacg_corr_status', 'rejected' );
        if ( $reason ) {
            update_post_meta( $post_id, '_wxacg_corr_admin_note', $reason );
        }

        // 駁回也發一個 hook，供未來擴充（目前 notify 可選擇要不要通知）
        $reporter = (int) get_post_meta( $post_id, '_wxacg_corr_reporter', true );
        do_action( 'wxacg_correction_rejected', $post_id, $reporter, $reason );

        self::redirect_back( 'rejected' );
    }

    private static function redirect_back( string $result ): void {
        $url = add_query_arg(
            [ 'post_type' => Corrections_CPT::POST_TYPE, 'corr_done' => $result ],
            admin_url( 'edit.php' )
        );
        wp_safe_redirect( $url );
        exit;
    }

    /* -------------------------------------------------- *
     * 後台提示條
     * -------------------------------------------------- */
    public static function admin_notices(): void {
        if ( empty( $_GET['corr_done'] ) ) {
            return;
        }
        $done = sanitize_key( $_GET['corr_done'] );
        $msg  = [
            'resolved' => [ 'notice-success', '已標記為「已修正」，並已通知回報會員 + 發放經驗值。' ],
            'rejected' => [ 'notice-warning', '已駁回這筆回報。' ],
        ];
        if ( isset( $msg[ $done ] ) ) {
            [ $class, $text ] = $msg[ $done ];
            printf( '<div class="notice %s is-dismissible"><p>%s</p></div>', esc_attr( $class ), esc_html( $text ) );
        }
    }
}
