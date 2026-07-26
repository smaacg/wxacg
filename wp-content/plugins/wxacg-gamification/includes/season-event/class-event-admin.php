<?php
namespace WXACG\Gamification;

defined( 'ABSPATH' ) || exit;

/**
 * 限時挑戰後台 UI（原名「季賽事件後台 UI」，v1.1.0 改名）
 *
 * 功能：
 *   - 編輯頁 metabox：時間、條件、獎勵、進度概況
 *   - 列表頁自訂欄位：狀態、結束時間、完成人數
 *   - 列表頁列操作：重新結算、複製
 *
 * @version 1.1.0 (2026-05-28)
 *   - 介面文案從「活動」改為「挑戰」，與 class-event-cpt.php 同步
 *   - 不動 hook / meta key / function name，僅替換使用者可見字串
 */
class Event_Admin {

    private static $instance = null;

    public static function instance() {
        if ( self::$instance === null ) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        add_action( 'add_meta_boxes',                                       [ __CLASS__, 'add_metaboxes' ] );
        add_action( 'save_post_' . WXACG_EVENT_CPT,                         [ __CLASS__, 'save_meta' ], 10, 2 );
        add_filter( 'manage_' . WXACG_EVENT_CPT . '_posts_columns',         [ __CLASS__, 'columns' ] );
        add_action( 'manage_' . WXACG_EVENT_CPT . '_posts_custom_column',   [ __CLASS__, 'render_column' ], 10, 2 );
        add_filter( 'post_row_actions',                                     [ __CLASS__, 'row_actions' ], 10, 2 );
        add_action( 'admin_post_smacg_event_resettle',                      [ __CLASS__, 'handle_resettle' ] );
        add_action( 'admin_post_smacg_event_duplicate',                     [ __CLASS__, 'handle_duplicate' ] );
    }

    /* ==========================================================
     * Metabox
     * ========================================================== */
    public static function add_metaboxes() {
        add_meta_box( 'smacg_event_settings', '🎯 挑戰設定', [ __CLASS__, 'render_settings_box' ], WXACG_EVENT_CPT, 'normal', 'high' );
        add_meta_box( 'smacg_event_stats',    '📊 進度統計', [ __CLASS__, 'render_stats_box' ],    WXACG_EVENT_CPT, 'side',   'default' );
    }

    public static function render_settings_box( $post ) {
        wp_nonce_field( 'smacg_event_save', 'smacg_event_nonce' );
        $m = [
            'starts_at'    => get_post_meta( $post->ID, '_smacg_event_starts_at',    true ),
            'ends_at'      => get_post_meta( $post->ID, '_smacg_event_ends_at',      true ),
            'action_type'  => get_post_meta( $post->ID, '_smacg_event_action_type',  true ),
            'target'       => (int) get_post_meta( $post->ID, '_smacg_event_target', true ),
            'reward_exp'   => (int) get_post_meta( $post->ID, '_smacg_event_reward_exp', true ),
            'reward_badge' => (int) get_post_meta( $post->ID, '_smacg_event_reward_badge', true ),
            'reward_title' => get_post_meta( $post->ID, '_smacg_event_reward_title', true ),
        ];

        $actions = [
            'comment'             => '發表留言',
            'follow'              => '追蹤用戶',
            'watchlist_add'       => '加入觀看列表',
            'watchlist_complete'  => '完成觀看',
            'rating'              => '評分',
            'exp_earned'          => '賺取 EXP',
        ];
        ?>
        <table class="form-table">
            <tr>
                <th><label>開始時間</label></th>
                <td><input type="datetime-local" name="smacg_event[starts_at]" value="<?php echo esc_attr( self::to_datetime_local( $m['starts_at'] ) ); ?>" class="regular-text"></td>
            </tr>
            <tr>
                <th><label>結束時間</label></th>
                <td><input type="datetime-local" name="smacg_event[ends_at]"   value="<?php echo esc_attr( self::to_datetime_local( $m['ends_at'] ) ); ?>"   class="regular-text"></td>
            </tr>
            <tr>
                <th><label>動作類型</label></th>
                <td>
                    <select name="smacg_event[action_type]">
                        <option value="">— 請選擇 —</option>
                        <?php foreach ( $actions as $k => $label ) : ?>
                            <option value="<?php echo esc_attr( $k ); ?>" <?php selected( $m['action_type'], $k ); ?>>
                                <?php echo esc_html( $label ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </td>
            </tr>
            <tr>
                <th><label>目標數值</label></th>
                <td><input type="number" min="1" name="smacg_event[target]" value="<?php echo (int) $m['target']; ?>" class="small-text"> 次/點</td>
            </tr>
            <tr><th colspan="2"><h3 style="margin:10px 0">🎁 完成獎勵</h3></th></tr>
            <tr>
                <th><label>EXP 獎勵</label></th>
                <td><input type="number" min="0" name="smacg_event[reward_exp]" value="<?php echo (int) $m['reward_exp']; ?>" class="small-text"></td>
            </tr>
            <tr>
                <th><label>徽章 ID</label></th>
                <td>
                    <input type="number" min="0" name="smacg_event[reward_badge]" value="<?php echo (int) $m['reward_badge']; ?>" class="small-text">
                    <p class="description">GamiPress 徽章的 post ID（留空＝不發徽章）</p>
                </td>
            </tr>
            <tr>
                <th><label>稱號</label></th>
                <td><input type="text" name="smacg_event[reward_title]" value="<?php echo esc_attr( $m['reward_title'] ); ?>" class="regular-text"></td>
            </tr>
        </table>
        <?php
    }

    public static function render_stats_box( $post ) {
        $completed = Event_Tracker::get_progress_count( $post->ID );
        $top       = Event_Tracker::get_leaderboard( $post->ID, 5 );
        $is_ended  = get_post_meta( $post->ID, '_smacg_event_ended_flag', true );
        ?>
        <p><strong>完成人數：</strong><?php echo (int) $completed; ?> 人</p>
        <p><strong>狀態：</strong><?php echo $is_ended ? '<span style="color:#888">已結束</span>' : '<span style="color:#0a0">進行中</span>'; ?></p>
        <?php if ( $top ) : ?>
            <p><strong>Top 5 進度：</strong></p>
            <ol style="padding-left:20px">
                <?php foreach ( $top as $r ) :
                    $u = get_userdata( (int) $r['user_id'] );
                    ?>
                    <li>
                        <?php echo $u ? esc_html( $u->display_name ) : '(已刪除)'; ?> —
                        <?php echo (int) $r['progress']; ?>
                    </li>
                <?php endforeach; ?>
            </ol>
        <?php endif; ?>
        <?php if ( $is_ended ) : ?>
            <?php
            $url = wp_nonce_url( admin_url( 'admin-post.php?action=smacg_event_resettle&event_id=' . $post->ID ), 'smacg_event_resettle' );
            ?>
            <p><a href="<?php echo esc_url( $url ); ?>" class="button button-secondary" onclick="return confirm('確定要重新結算此挑戰？')">🔄 重新結算</a></p>
        <?php endif; ?>
        <?php
    }

    private static function to_datetime_local( $mysql_dt ) {
        if ( ! $mysql_dt ) return '';
        return str_replace( ' ', 'T', substr( $mysql_dt, 0, 16 ) );
    }

    /* ==========================================================
     * Save meta
     * ========================================================== */
    public static function save_meta( $post_id, $post ) {
        if ( ! isset( $_POST['smacg_event_nonce'] ) ) return;
        if ( ! wp_verify_nonce( $_POST['smacg_event_nonce'], 'smacg_event_save' ) ) return;
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
        if ( ! current_user_can( 'edit_post', $post_id ) ) return;
        if ( $post->post_type !== WXACG_EVENT_CPT ) return;

        $in = $_POST['smacg_event'] ?? [];

        $starts = ! empty( $in['starts_at'] ) ? str_replace( 'T', ' ', sanitize_text_field( $in['starts_at'] ) ) . ':00' : '';
        $ends   = ! empty( $in['ends_at'] )   ? str_replace( 'T', ' ', sanitize_text_field( $in['ends_at'] ) )   . ':00' : '';

        update_post_meta( $post_id, '_smacg_event_starts_at',    $starts );
        update_post_meta( $post_id, '_smacg_event_ends_at',      $ends );
        update_post_meta( $post_id, '_smacg_event_action_type',  sanitize_key( $in['action_type'] ?? '' ) );
        update_post_meta( $post_id, '_smacg_event_target',       max( 1, (int) ( $in['target'] ?? 1 ) ) );
        update_post_meta( $post_id, '_smacg_event_reward_exp',   max( 0, (int) ( $in['reward_exp'] ?? 0 ) ) );
        update_post_meta( $post_id, '_smacg_event_reward_badge', max( 0, (int) ( $in['reward_badge'] ?? 0 ) ) );
        update_post_meta( $post_id, '_smacg_event_reward_title', sanitize_text_field( $in['reward_title'] ?? '' ) );
    }

    /* ==========================================================
     * 列表欄位
     * ========================================================== */
    public static function columns( $cols ) {
        $new = [];
        foreach ( $cols as $k => $v ) {
            $new[ $k ] = $v;
            if ( $k === 'title' ) {
                $new['smacg_status']    = '狀態';
                $new['smacg_period']    = '時間';
                $new['smacg_completed'] = '完成人數';
            }
        }
        return $new;
    }

    public static function render_column( $col, $post_id ) {
        switch ( $col ) {
            case 'smacg_status':
                $ended = get_post_meta( $post_id, '_smacg_event_ended_flag', true );
                if ( $ended ) {
                    echo '<span style="color:#888">已結束</span>';
                } elseif ( Event_CPT::is_active( $post_id ) ) {
                    echo '<span style="color:#0a0;font-weight:bold">● 進行中</span>';
                } else {
                    echo '<span style="color:#999">未開始 / 草稿</span>';
                }
                break;

            case 'smacg_period':
                $s = get_post_meta( $post_id, '_smacg_event_starts_at', true );
                $e = get_post_meta( $post_id, '_smacg_event_ends_at',   true );
                echo esc_html( substr( $s, 0, 16 ) ) . '<br>~ ' . esc_html( substr( $e, 0, 16 ) );
                break;

            case 'smacg_completed':
                echo (int) Event_Tracker::get_progress_count( $post_id ) . ' 人';
                break;
        }
    }

    /* ==========================================================
     * 列操作：複製、重結
     * ========================================================== */
    public static function row_actions( $actions, $post ) {
        if ( $post->post_type !== WXACG_EVENT_CPT ) return $actions;
        $dup = wp_nonce_url( admin_url( 'admin-post.php?action=smacg_event_duplicate&event_id=' . $post->ID ), 'smacg_event_duplicate' );
        $actions['smacg_duplicate'] = '<a href="' . esc_url( $dup ) . '">📋 複製</a>';
        return $actions;
    }

    public static function handle_resettle() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( '無權限', 403 );
        check_admin_referer( 'smacg_event_resettle' );

        $event_id = (int) ( $_GET['event_id'] ?? 0 );
        if ( $event_id ) {
            delete_post_meta( $event_id, '_smacg_event_ended_flag' );
            Event_Settle::finalize_event( $event_id );
        }
        wp_safe_redirect( wp_get_referer() ?: admin_url( 'edit.php?post_type=' . WXACG_EVENT_CPT ) );
        exit;
    }

    public static function handle_duplicate() {
        if ( ! current_user_can( 'edit_posts' ) ) wp_die( '無權限', 403 );
        check_admin_referer( 'smacg_event_duplicate' );

        $event_id = (int) ( $_GET['event_id'] ?? 0 );
        $src = get_post( $event_id );
        if ( ! $src || $src->post_type !== WXACG_EVENT_CPT ) wp_die( '找不到挑戰' );

        $new_id = wp_insert_post( [
            'post_type'    => WXACG_EVENT_CPT,
            'post_status'  => 'draft',
            'post_title'   => $src->post_title . '（複製）',
            'post_content' => $src->post_content,
            'post_excerpt' => $src->post_excerpt,
        ] );

        if ( $new_id && ! is_wp_error( $new_id ) ) {
            foreach ( [ 'starts_at','ends_at','action_type','target','reward_exp','reward_badge','reward_title' ] as $k ) {
                $v = get_post_meta( $event_id, '_smacg_event_' . $k, true );
                if ( $v !== '' ) update_post_meta( $new_id, '_smacg_event_' . $k, $v );
            }
            wp_safe_redirect( admin_url( 'post.php?post=' . $new_id . '&action=edit' ) );
            exit;
        }
        wp_die( '複製失敗' );
    }
}
