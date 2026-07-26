<?php
/**
 * Notifications System — Render（會員中心通知頁）
 *
 * @package weixiaoacg
 * @version 1.1.0 (2026-05-16)
 *
 * v1.1.0 變更：
 *   - Bug #5 修正：時間計算改用 get_gmt_from_date 避免時區錯位
 *
 * 提供：
 *   smacg_render_notifications_tab()  會員中心通知 tab 內容
 *   smacg_render_notification_item()  單則通知卡片（共用）
 */
defined( 'ABSPATH' ) || exit;

/**
 * 會員中心 通知 tab 內容
 */
function smacg_render_notifications_tab() {
	$uid = get_current_user_id();
	if ( ! $uid ) return;

	$initial = smacg_get_notifications( $uid, [ 'limit' => 20, 'offset' => 0 ] );
	$unread  = smacg_get_unread_count( $uid );
	?>
	<div class="smacg-notif-page" data-uid="<?php echo (int) $uid; ?>">

		<div class="smacg-notif-page-header">
			<h2>
				<i class="fa-solid fa-bell"></i>
				通知中心
				<span class="smacg-notif-page-count" data-unread-badge><?php echo (int) $unread; ?></span>
			</h2>

			<div class="smacg-notif-page-actions">
				<button type="button" class="smacg-notif-filter active" data-filter="all">全部</button>
				<button type="button" class="smacg-notif-filter" data-filter="unread">未讀</button>
				<button type="button" class="smacg-notif-mark-all" <?php echo $unread ? '' : 'disabled'; ?>>
					<i class="fa-solid fa-check-double"></i> 全部標為已讀
				</button>
			</div>
		</div>

		<div class="smacg-notif-page-list" data-list>
			<?php if ( empty( $initial ) ) : ?>
				<div class="smacg-notif-empty">
					<i class="fa-solid fa-bell-slash"></i>
					<p>還沒有任何通知</p>
				</div>
			<?php else : ?>
				<?php foreach ( $initial as $n ) : ?>
					<?php smacg_render_notification_item( $n ); ?>
				<?php endforeach; ?>
			<?php endif; ?>
		</div>

		<?php if ( count( $initial ) >= 20 ) : ?>
			<div class="smacg-notif-page-more">
				<button type="button" class="smacg-notif-loadmore" data-offset="20">
					<i class="fa-solid fa-arrow-down"></i> 載入更多
				</button>
			</div>
		<?php endif; ?>

	</div>
	<?php
}

/**
 * 單則通知卡片
 *
 * @param array $n  通知陣列（含 data 已 decode）
 */
function smacg_render_notification_item( $n ) {
	$data    = is_array( $n['data'] ) ? $n['data'] : [];
	$title   = $data['title']   ?? '';
	$excerpt = $data['excerpt'] ?? '';
	$url     = $data['url']     ?? '#';
	$icon    = $data['icon']    ?? 'fa-bell';

	// Actor 頭像
	$avatar = '';
	if ( ! empty( $n['actor_id'] ) ) {
		$aid = (int) get_user_meta( $n['actor_id'], 'smacg_avatar_id', true );
		if ( $aid && wp_attachment_is_image( $aid ) ) {
			$img = wp_get_attachment_image_src( $aid, 'thumbnail' );
			if ( $img ) $avatar = $img[0];
		}
		if ( ! $avatar ) {
			$avatar = get_avatar_url( $n['actor_id'], [ 'size' => 64 ] );
		}
	}

	// v1.1.0 修正：created_at 是本地時間字串，要先轉成 UTC timestamp
	$ts        = (int) strtotime( get_gmt_from_date( $n['created_at'] ) . ' UTC' );
	$time_diff = $ts ? human_time_diff( $ts, time() ) . '前' : '';

	$unread_cls = ( (int) $n['is_read'] === 0 ) ? ' is-unread' : '';
	?>
	<a class="smacg-notif-item<?php echo $unread_cls; ?>"
	   href="<?php echo esc_url( $url ); ?>"
	   data-id="<?php echo (int) $n['id']; ?>"
	   data-type="<?php echo esc_attr( $n['type'] ); ?>">

		<div class="smacg-notif-item-icon">
			<?php if ( $avatar ) : ?>
				<img src="<?php echo esc_url( $avatar ); ?>" alt="" loading="lazy">
				<span class="smacg-notif-item-badge"><i class="fa-solid <?php echo esc_attr( $icon ); ?>"></i></span>
			<?php else : ?>
				<span class="smacg-notif-item-badge smacg-notif-item-badge--lg">
					<i class="fa-solid <?php echo esc_attr( $icon ); ?>"></i>
				</span>
			<?php endif; ?>
		</div>

		<div class="smacg-notif-item-body">
			<p class="smacg-notif-item-title"><?php echo esc_html( $title ); ?></p>
			<?php if ( $excerpt ) : ?>
				<p class="smacg-notif-item-excerpt"><?php echo esc_html( $excerpt ); ?></p>
			<?php endif; ?>
			<p class="smacg-notif-item-time"><?php echo esc_html( $time_diff ); ?></p>
		</div>

		<button type="button" class="smacg-notif-item-delete" data-delete aria-label="刪除">
			<i class="fa-solid fa-xmark"></i>
		</button>
	</a>
	<?php
}
