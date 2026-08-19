<?php
/**
 * 檔案名稱: includes/class-events-admin.php
 * 作品變更事件 — 後台審核清單
 *
 * 機器偵測到的是「封面 URL 從 A 變成 B」，那不是可以發布的文案。
 * 這頁的工作是讓人看一眼、補一句中文說明、然後決定發布或退回。
 *
 * 視覺圖事件一定並排顯示新舊圖——AniList 有時只是重新編碼、圖其實沒變，
 * 不並排根本無從判斷是不是誤報。
 *
 * 自行註冊選單（比照 includes/ai-editorial-tool.php 的作法），
 * 不動 admin/class-admin.php。
 *
 * @version 1.0.0
 * @package Anime_Sync_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Anime_Sync_Events_Admin {

	const PAGE_SLUG = 'anime-sync-events';
	const NONCE     = 'anime_sync_events_review';

	/** 一頁顯示幾筆待審事件。 */
	const PER_PAGE = 30;

	public function __construct() {
		add_action( 'admin_menu', [ $this, 'register_menu' ], 20 );
	}

	/**
	 * 掛在既有的「動漫同步」選單底下，標題帶待審數紅點。
	 */
	public function register_menu(): void {
		$pending = class_exists( 'Anime_Sync_Anime_Events' )
			? Anime_Sync_Anime_Events::count_pending()
			: 0;

		$label = '📰 消息審核';

		if ( $pending > 0 ) {
			$label .= sprintf(
				' <span class="update-plugins count-%d"><span class="update-count">%d</span></span>',
				$pending,
				$pending
			);
		}

		add_submenu_page(
			'anime-sync-pro',
			'消息審核',
			$label,
			'manage_options',
			self::PAGE_SLUG,
			[ $this, 'render' ]
		);
	}

	// =========================================================================
	// 表單處理
	// =========================================================================

	/**
	 * 處理發布／退回。
	 *
	 * @return string 給使用者看的結果訊息（空字串代表這次沒有動作）。
	 */
	private function handle_post(): string {
		if ( empty( $_POST['anime_sync_event_action'] ) ) {
			return '';
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return '權限不足。';
		}

		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), self::NONCE ) ) {
			return '安全驗證失敗，請重新整理後再試。';
		}

		$action   = sanitize_key( wp_unslash( $_POST['anime_sync_event_action'] ) );
		$event_id = isset( $_POST['event_id'] ) ? absint( $_POST['event_id'] ) : 0;

		if ( ! $event_id ) {
			return '缺少事件 ID。';
		}

		if ( 'reject' === $action ) {
			return Anime_Sync_Anime_Events::reject( $event_id )
				? '已退回這筆事件。'
				: '退回失敗。';
		}

		if ( 'publish' === $action ) {
			// $_POST 已經被 WP 加過斜線，取用時要 unslash。
			$summary = isset( $_POST['summary'] )
				? sanitize_text_field( wp_unslash( $_POST['summary'] ) )
				: '';

			if ( '' === trim( $summary ) ) {
				return '請先填寫消息說明再發布——空白的說明在前台沒有意義。';
			}

			$event_date = isset( $_POST['event_date'] )
				? sanitize_text_field( wp_unslash( $_POST['event_date'] ) )
				: '';

			if ( ! Anime_Sync_Anime_Events::publish( $event_id, $summary, $event_date ) ) {
				return '發布失敗。';
			}

			return '已發布，並已通知追番這部作品的會員。';
		}

		return '';
	}

	/**
	 * 處理「手動新增消息」。
	 *
	 * @return string 結果訊息（空字串代表這次沒有動作）。
	 */
	private function handle_manual(): string {
		if ( empty( $_POST['anime_sync_manual_add'] ) ) {
			return '';
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return '權限不足。';
		}

		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), self::NONCE ) ) {
			return '安全驗證失敗，請重新整理後再試。';
		}

		$anime_id   = isset( $_POST['manual_anime_id'] ) ? absint( $_POST['manual_anime_id'] ) : 0;
		$summary    = isset( $_POST['manual_summary'] ) ? sanitize_text_field( wp_unslash( $_POST['manual_summary'] ) ) : '';
		$event_date = isset( $_POST['manual_date'] ) ? sanitize_text_field( wp_unslash( $_POST['manual_date'] ) ) : '';
		$event_type = isset( $_POST['manual_type'] ) ? sanitize_key( wp_unslash( $_POST['manual_type'] ) ) : '';

		if ( ! $anime_id || '' === trim( $summary ) ) {
			return '請填寫作品與消息內容。';
		}

		if ( 'anime' !== get_post_type( $anime_id ) ) {
			return '找不到這部作品（ID ' . $anime_id . '），請確認是動畫文章的 ID。';
		}

		$result = Anime_Sync_Anime_Events::add_manual( $anime_id, $summary, $event_date, $event_type );

		if ( -1 === $result ) {
			return '新增失敗，請確認日期格式（YYYY-MM-DD）與類型是否正確。';
		}

		if ( 0 === $result ) {
			return '這則消息已經存在，未重複新增。';
		}

		return '已新增消息，並已通知追番這部作品的會員。';
	}

	// =========================================================================
	// 畫面
	// =========================================================================

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( '權限不足。' );
		}

		$message = $this->handle_post();

		if ( '' === $message ) {
			$message = $this->handle_manual();
		}

		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : 'pending';

		if ( ! in_array( $status, [ 'pending', 'published', 'rejected' ], true ) ) {
			$status = 'pending';
		}

		global $wpdb;

		$events = (array) $wpdb->get_results( $wpdb->prepare(
			'SELECT * FROM ' . Anime_Sync_Anime_Events::table() . '
			  WHERE status = %s
			  ORDER BY event_date DESC, id DESC
			  LIMIT %d',
			$status,
			self::PER_PAGE
		) );

		echo '<div class="wrap">';
		echo '<h1>消息審核</h1>';

		echo '<p class="description">上游（AniList）偵測到的資料異動。補上中文說明並發布之後，'
			. '才會出現在作品頁的「消息更新」區塊、全站最近更新頁，並通知追番的會員。</p>';

		if ( '' !== $message ) {
			printf(
				'<div class="notice notice-info is-dismissible"><p>%s</p></div>',
				esc_html( $message )
			);
		}

		$this->render_manual_form();

		$this->render_tabs( $status );

		if ( empty( $events ) ) {
			echo '<p style="padding:24px 0;color:#666;">目前沒有這個狀態的事件。</p>';
			echo '</div>';
			return;
		}

		foreach ( $events as $event ) {
			$this->render_event_card( $event, $status );
		}

		echo '</div>';
	}

	/**
	 * 手動新增消息。
	 *
	 * 偵測只看得到 AniList 欄位的異動，但作品消息有一大半不在任何 API 裡
	 * ——「宣布第二季製作決定」「監督確定」這類只在官方推特或新聞稿。
	 * 沒有這個入口，消息更新就只是一份「封面換了／日期改了」的機械紀錄。
	 */
	private function render_manual_form(): void {
		echo '<details style="background:#fff;border:1px solid #ccd0d4;padding:12px 20px;margin:16px 0;max-width:1000px;">';
		echo '<summary style="cursor:pointer;font-weight:600;">➕ 手動新增消息</summary>';

		echo '<p class="description" style="margin:10px 0;">'
			. '偵測抓不到的消息寫在這裡——例如「宣布第二季製作決定」「公開主視覺」「監督確定」。'
			. '人工填寫的內容不需審核，直接發布並通知追番會員。</p>';

		echo '<form method="post" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin:0 0 8px;">';

		wp_nonce_field( self::NONCE );

		echo '<input type="number" name="manual_anime_id" placeholder="作品 ID" required
		             style="width:110px;" min="1">';

		printf(
			'<input type="date" name="manual_date" value="%s" required>',
			esc_attr( current_time( 'Y-m-d' ) )
		);

		echo '<select name="manual_type">';

		foreach ( Anime_Sync_Anime_Events::types() as $key => $label ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $key ),
				'schedule' === $key ? ' selected' : '',
				esc_html( $label )
			);
		}

		echo '</select>';

		echo '<input type="text" name="manual_summary" required maxlength="255"
		             placeholder="例：第一季全 24 話播出後，宣布第二季製作決定"
		             style="flex:1;min-width:300px;">';

		echo '<button type="submit" name="anime_sync_manual_add" value="1" class="button button-primary">新增</button>';

		echo '</form>';

		echo '<p class="description" style="margin:0;font-size:11px;color:#888;">'
			. '作品 ID 可在動畫文章的編輯網址列 <code>post=數字</code> 找到。</p>';

		echo '</details>';
	}

	private function render_tabs( string $current ): void {
		global $wpdb;

		$counts = [];

		foreach ( (array) $wpdb->get_results(
			'SELECT status, COUNT(*) AS c FROM ' . Anime_Sync_Anime_Events::table() . ' GROUP BY status'
		) as $row ) {
			$counts[ $row->status ] = (int) $row->c;
		}

		$tabs = [
			'pending'   => '待審',
			'published' => '已發布',
			'rejected'  => '已退回',
		];

		echo '<h2 class="nav-tab-wrapper">';

		foreach ( $tabs as $key => $label ) {
			printf(
				'<a href="%s" class="nav-tab%s">%s (%d)</a>',
				esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&status=' . $key ) ),
				$key === $current ? ' nav-tab-active' : '',
				esc_html( $label ),
				isset( $counts[ $key ] ) ? $counts[ $key ] : 0
			);
		}

		echo '</h2>';
	}

	/**
	 * 單筆事件的卡片。
	 */
	private function render_event_card( object $event, string $status ): void {
		$payload = ! empty( $event->payload ) ? json_decode( $event->payload, true ) : [];
		$payload = is_array( $payload ) ? $payload : [];

		$title = get_the_title( $event->anime_id );

		echo '<div style="background:#fff;border:1px solid #ccd0d4;border-left:4px solid #2271b1;'
			. 'padding:16px 20px;margin:16px 0;max-width:1000px;">';

		printf(
			'<h2 style="margin:0 0 4px;font-size:16px;">
				<a href="%s" target="_blank" rel="noopener">%s</a>
				<span style="background:#f0f0f1;color:#50575e;font-size:12px;font-weight:400;
					padding:2px 8px;border-radius:3px;margin-left:8px;">%s</span>
			</h2>',
			esc_url( (string) get_permalink( $event->anime_id ) ),
			esc_html( '' !== $title ? $title : '（找不到作品 #' . $event->anime_id . '）' ),
			esc_html( Anime_Sync_Anime_Events::type_label( $event->event_type ) )
		);

		printf(
			'<p style="margin:0 0 12px;color:#666;font-size:12px;">偵測日期 %s ・ 來源 %s ・ 事件 #%d</p>',
			esc_html( $event->event_date ),
			esc_html( '' !== $event->source ? $event->source : '—' ),
			(int) $event->id
		);

		if ( 'visual' === $event->event_type ) {
			$this->render_visual_diff( $event, $payload );
		} else {
			$this->render_value_diff( $payload );
		}

		if ( 'pending' === $status ) {
			$this->render_actions( $event );
		} elseif ( '' !== $event->summary ) {
			printf(
				'<p style="margin:12px 0 0;"><strong>說明：</strong>%s</p>',
				esc_html( $event->summary )
			);
		}

		echo '</div>';
	}

	/**
	 * 視覺圖：新舊並排。
	 *
	 * 舊圖用 payload 裡的原始外部 URL——那是上游換掉之前的位址，
	 * 通常還存活一段時間；抓不到時瀏覽器顯示破圖，本身也是一種訊息。
	 */
	private function render_visual_diff( object $event, array $payload ): void {
		$new_url = $event->attachment_id
			? (string) wp_get_attachment_image_url( (int) $event->attachment_id, 'medium' )
			: (string) ( $payload['new'] ?? '' );

		$old_url = (string) ( $payload['old'] ?? '' );

		echo '<div style="display:flex;gap:20px;align-items:flex-start;flex-wrap:wrap;">';

		foreach ( [ [ '舊圖', $old_url ], [ '新圖', $new_url ] ] as $pair ) {
			list( $label, $url ) = $pair;

			echo '<div style="text-align:center;">';
			printf( '<p style="margin:0 0 6px;font-weight:600;font-size:12px;">%s</p>', esc_html( $label ) );

			if ( '' !== $url ) {
				printf(
					'<img src="%s" alt="%s" style="max-width:180px;height:auto;border:1px solid #ddd;">',
					esc_url( $url ),
					esc_attr( $label )
				);
			} else {
				echo '<p style="color:#999;font-size:12px;">（無）</p>';
			}

			echo '</div>';
		}

		echo '</div>';

		echo '<p style="margin:10px 0 0;color:#888;font-size:11px;">'
			. '兩張圖看起來一樣的話多半是上游重新編碼，按「退回」即可。</p>';
	}

	private function render_value_diff( array $payload ): void {
		printf(
			'<p style="margin:0;font-size:14px;">
				<span style="color:#999;text-decoration:line-through;">%s</span>
				<span style="margin:0 8px;">→</span>
				<strong style="color:#1d2327;">%s</strong>
			</p>',
			esc_html( '' !== (string) ( $payload['old'] ?? '' ) ? (string) $payload['old'] : '（空）' ),
			esc_html( (string) ( $payload['new'] ?? '' ) )
		);
	}

	/**
	 * 說明輸入與發布／退回按鈕。
	 */
	private function render_actions( object $event ): void {
		echo '<form method="post" style="margin:14px 0 0;display:flex;gap:8px;align-items:center;flex-wrap:wrap;">';

		wp_nonce_field( self::NONCE );

		printf( '<input type="hidden" name="event_id" value="%d">', (int) $event->id );

		/*
		 * 日期可修正：掃描寫入的是「偵測日」，但官方公告常常早幾天。
		 * 前台顯示的應該是公告日，這裡讓審核時一併改掉。
		 */
		printf(
			'<input type="date" name="event_date" value="%s" title="消息日期（預設為偵測日，可改成實際公告日）">',
			esc_attr( $event->event_date )
		);

		printf(
			'<input type="text" name="summary" value="%s" placeholder="%s"
			        style="flex:1;min-width:280px;" maxlength="255">',
			esc_attr( $event->summary ),
			esc_attr( $this->placeholder_for( $event->event_type ) )
		);

		echo '<button type="submit" name="anime_sync_event_action" value="publish" class="button button-primary">發布</button>';
		echo '<button type="submit" name="anime_sync_event_action" value="reject" class="button">退回</button>';

		echo '</form>';
	}

	/**
	 * 依事件類型給出範例寫法，減少每次都要想怎麼下筆。
	 */
	private function placeholder_for( string $type ): string {
		$samples = [
			'visual'    => '例：公開超前導視覺圖',
			'schedule'  => '例：宣布 2027 年 10 月播出',
			'status'    => '例：正式開播',
			'episodes'  => '例：全 24 話確定',
			'staff'     => '例：公開監督與製作公司',
			'cast'      => '例：宣布主要角色配音',
			'streaming' => '例：於串流平台上架',
		];

		return $samples[ $type ] ?? '填寫要顯示在前台的中文說明';
	}
}
