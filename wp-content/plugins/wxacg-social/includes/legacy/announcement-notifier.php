<?php
/**
 * SMACG Social — Announcement Notifier（精簡版）
 *
 * @package    weixiaoacg
 * @subpackage wxacg-social
 * @version    1.0.0
 * @since      1.3.0
 *
 * 功能：
 *   1. 公告（category=announcement）首次從非 publish 轉成 publish 時，
 *      自動透過 smacg_broadcast_system_notification() 廣播給全站使用者。
 *      → 全站使用者鈴鐺紅點亮起，面板顯示「📢 新公告：XXX」。
 *      → 點選後跳轉到 /announcement/{slug}/ 單篇。
 *   2. 提供後台「重新廣播」按鈕（編輯公告頁右側 publish box）。
 *      用途：公告改錯字後可重新通知全站。
 *
 * 設計理念：
 *   - 不另做橫幅、跑馬燈、dismiss UI；公告 = system 類型通知，全靠鈴鐺呈現。
 *   - 訪客看不到鈴鐺（header.php 邏輯），自然也看不到入口；
 *     但 /announcement/ 列表與單篇保持公開可訪問（SEO 友善）。
 *
 * 依賴：
 *   - smacg_broadcast_system_notification()  ← notifications-events.php
 *
 * 載入順序：必須在 notifications-events.php 之後，
 *   因此在 class-plugin.php 的 boot() 內排在第 11 步。
 *
 * Changelog:
 *   1.0.0 (2026-05-26) 初版（橫幅做法砍掉，改用鈴鐺整合）
 */

defined( 'ABSPATH' ) || exit;

/* ============================================================
   1. 發布公告時自動廣播 system notification
   ============================================================ */

/**
 * 監聽 post_status 轉變：
 *   非 publish → publish，且分類含 announcement → 廣播。
 *
 * 使用 _smacg_announcement_broadcasted post_meta 防止重複廣播。
 */
add_action( 'transition_post_status', 'smacg_announcement_on_publish', 10, 3 );

function smacg_announcement_on_publish( $new_status, $old_status, $post ) {
    if ( $new_status !== 'publish' || $old_status === 'publish' ) {
        return;
    }
    if ( ! $post instanceof WP_Post || $post->post_type !== 'post' ) {
        return;
    }
    if ( ! has_category( 'announcement', $post ) ) {
        return;
    }

    // 防重複：post_meta 標記
    if ( get_post_meta( $post->ID, '_smacg_announcement_broadcasted', true ) ) {
        return;
    }

    // 廣播函式必須存在（依賴 notifications-events.php）
    if ( ! function_exists( 'smacg_broadcast_system_notification' ) ) {
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( 'SMACG Announcement: smacg_broadcast_system_notification() not found.' );
        }
        return;
    }

    $title   = sprintf( '📢 新公告：%s', wp_strip_all_tags( $post->post_title ) );
    $excerpt = wp_trim_words( wp_strip_all_tags( $post->post_content ), 30, '…' );
    $url     = get_permalink( $post );

    $sent = smacg_broadcast_system_notification(
        $title,
        $excerpt,
        $url,
        null, // 全站使用者
        [
            'icon'       => 'fa-bullhorn',
            'force'      => false,   // 尊重使用者 system_site 偏好
            'batch_size' => 500,
            'sleep_ms'   => 50,
        ]
    );

    update_post_meta( $post->ID, '_smacg_announcement_broadcasted', 1 );
    update_post_meta( $post->ID, '_smacg_announcement_broadcasted_at', time() );
    update_post_meta( $post->ID, '_smacg_announcement_broadcasted_count', (int) $sent );

    if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
        error_log( sprintf(
            'SMACG Announcement broadcasted: post #%d "%s" → %d notifications.',
            $post->ID,
            $post->post_title,
            (int) $sent
        ) );
    }
}

/* ============================================================
   2. 重新廣播（後台手動觸發）
   ============================================================ */

/**
 * 編輯公告頁右側 publish box 顯示「重新廣播」區塊。
 */
add_action( 'post_submitbox_misc_actions', 'smacg_announcement_submitbox_button' );

function smacg_announcement_submitbox_button( $post ) {
    if ( ! $post instanceof WP_Post || $post->post_status !== 'publish' ) {
        return;
    }
    if ( ! has_category( 'announcement', $post ) ) {
        return;
    }
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    $broadcasted_at    = (int) get_post_meta( $post->ID, '_smacg_announcement_broadcasted_at', true );
    $broadcasted_count = (int) get_post_meta( $post->ID, '_smacg_announcement_broadcasted_count', true );
    $action_url        = admin_url( 'admin-post.php' );
    ?>
    <div class="misc-pub-section misc-pub-smacg-ann" style="border-top:1px solid #ddd;padding:10px 0;">
        <strong>📢 公告廣播</strong><br>
        <?php if ( $broadcasted_at ) : ?>
            <span style="color:#666;font-size:12px;">
                上次廣播：<?php echo esc_html( wp_date( 'Y-m-d H:i', $broadcasted_at ) ); ?><br>
                共寄出 <?php echo (int) $broadcasted_count; ?> 則站內通知。
            </span><br>
        <?php else : ?>
            <span style="color:#a00;font-size:12px;">尚未廣播。</span><br>
        <?php endif; ?>
        <form method="post" action="<?php echo esc_url( $action_url ); ?>" style="margin-top:6px;">
            <?php wp_nonce_field( 'smacg_ann_rebroadcast' ); ?>
            <input type="hidden" name="action" value="smacg_ann_rebroadcast">
            <input type="hidden" name="post_id" value="<?php echo (int) $post->ID; ?>">
            <button type="submit" class="button"
                    onclick="return confirm('確定要重新廣播這則公告給全站使用者嗎？\n\n所有人都會再收到一次站內通知。');">
                重新廣播
            </button>
        </form>
    </div>
    <?php
}

/**
 * 處理「重新廣播」表單送出：清掉 meta → 重跑 broadcast 邏輯。
 */
add_action( 'admin_post_smacg_ann_rebroadcast', 'smacg_announcement_rebroadcast' );

function smacg_announcement_rebroadcast() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'Permission denied.' );
    }
    check_admin_referer( 'smacg_ann_rebroadcast' );

    $post_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
    $post    = $post_id ? get_post( $post_id ) : null;

    if ( ! $post || ! has_category( 'announcement', $post ) ) {
        wp_die( '指定的文章不是公告。' );
    }

    // 清掉防重複 meta，然後手動觸發廣播
    delete_post_meta( $post_id, '_smacg_announcement_broadcasted' );
    smacg_announcement_on_publish( 'publish', 'draft', $post );

    $redirect = get_edit_post_link( $post_id, 'redirect' );
    $redirect = add_query_arg( 'smacg_ann_rebroadcasted', '1', $redirect );
    wp_safe_redirect( $redirect );
    exit;
}

/**
 * 後台 admin notice：重新廣播完成提示。
 */
add_action( 'admin_notices', function() {
    if ( empty( $_GET['smacg_ann_rebroadcasted'] ) ) return;
    echo '<div class="notice notice-success is-dismissible"><p>✓ 公告已重新廣播給全站使用者。</p></div>';
} );
