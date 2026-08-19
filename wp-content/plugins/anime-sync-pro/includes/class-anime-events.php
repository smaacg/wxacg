<?php
/**
 * 檔案名稱: includes/class-anime-events.php
 * 作品變更事件 — 上游資料異動的統一記錄
 *
 * ★ 這是共用的寫入目標，不是某一支掃描的私有產出。
 *   任何偵測到「上游資料變了」的地方都應該呼叫 record()，包括：
 *     - Anime_Sync_Upstream_Diff_Scan   AniList 每日全站比對（封面／播出日期／集數／狀態）
 *     - Anime_Sync_Upcoming_BGM_Scan    Bangumi 側的 staff／cast 增補
 *     - Anime_Sync_Cron_Manager         detect_upstream_cast_growth()
 *
 *   刻意不讓每支偵測器自己存自己的記錄——那會變成多份來源，
 *   前台要合併顯示，新增偵測器時容易漏接。
 *
 * ★ 為什麼事件預設是 pending
 *   機器偵測到的是「封面 URL 從 A 變成 B」，那不是可以發布的文案。
 *   要人工補上 summary（例：「公開超前導視覺圖」）並發布之後，
 *   才會出現在前台與通知裡。偵測可以有誤報，審核閘門擋住它。
 *
 * ★ 去重
 *   dedupe_key 在資料表上是 UNIQUE。同一個變更重複偵測會被資料庫擋掉，
 *   不依賴呼叫端記得先查。record() 遇到重複回傳 0，不是錯誤。
 *
 * @version 1.0.0
 * @package Anime_Sync_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Anime_Sync_Anime_Events {

	/**
	 * 事件類型與顯示名稱。
	 *
	 * 新增類型只要在這裡加一筆，後台清單的篩選與前台的標籤都會跟著出現。
	 *
	 * @var array<string,string>
	 */
	private static array $TYPES = [
		'visual'    => '視覺圖',
		'trailer'   => '宣傳影片',
		'schedule'  => '播出時間',
		'status'    => '播出狀態',
		'episodes'  => '集數',
		'staff'     => '製作陣容',
		'cast'      => '聲優',
		'streaming' => '串流平台',
	];

	/**
	 * 發布事件時，event_date 距今超過這個天數就不發通知。
	 *
	 * 用途是防洗版：日後補審一批舊事件時，不會把追番會員的鈴鐺灌爆。
	 */
	private const NOTIFY_MAX_AGE_DAYS = 14;

	/**
	 * ★ 偵測後直接自動發布、不進待審清單的類型 ★
	 *
	 * 判準是「文案能不能從新舊值機械推導出來」：
	 *
	 *   schedule / status / episodes
	 *       「2027 → 2027-10」只可能是「宣布 2027 年 10 月播出」，
	 *       沒有寫錯的空間，也沒有誤報的空間——日期就是變了。
	 *
	 *   visual（不在此列）
	 *       AniList 有時只是重新編碼、圖其實沒變，需要人並排看一眼才知道
	 *       是不是誤報。這是唯一真的需要判斷的類型。
	 *
	 * 想把某一類改回人工審核，從這個陣列拿掉即可。
	 * 自動發布的事件一樣會通知追番會員，事後也能在「已發布」分頁改文字或退回。
	 *
	 * @var array<string>
	 */
	private static array $AUTO_PUBLISH_TYPES = [ 'schedule', 'status', 'episodes', 'trailer' ];

	/**
	 * 這個類型是否自動發布。
	 */
	public static function is_auto_publish( string $type ): bool {
		return in_array( $type, self::$AUTO_PUBLISH_TYPES, true );
	}

	/**
	 * 由新舊值推導中文文案。
	 *
	 * 推導不出來時回傳空字串——呼叫端應據此改走人工審核，
	 * 而不是發布一則空白說明的消息。
	 *
	 * @param string $type 事件類型。
	 * @param mixed  $old  舊值。
	 * @param mixed  $new  新值。
	 */
	public static function auto_summary( string $type, $old, $new ): string {
		switch ( $type ) {
			case 'schedule':
				return self::summary_for_schedule( (string) $old, (string) $new );

			case 'status':
				return self::summary_for_status( (string) $new );

			case 'trailer':
				/*
				 * AniList 的 trailer 是單一欄位，換了就代表官方換上新影片。
				 * 不像封面會因為重新編碼而變網址——不同的 YouTube ID
				 * 必定是不同支影片，誤報空間極小。
				 */
				if ( '' === (string) $new ) {
					return '';
				}

				return '' === (string) $old ? '公開宣傳影片' : '公開新宣傳影片';

			case 'episodes':
				$o = (int) $old;
				$n = (int) $new;

				if ( $n <= 0 ) {
					return '';
				}

				return $o > 0
					? sprintf( '集數由 %d 話更新為 %d 話', $o, $n )
					: sprintf( '全 %d 話確定', $n );
		}

		return '';
	}

	/**
	 * 播出日期的文案。
	 *
	 * format_start_date() 產出三種精度：YYYY / YYYY-MM / YYYY-MM-DD。
	 * 由粗到細是「檔期逐步確定」，同精度改變則是「檔期異動」，兩者語意不同。
	 */
	private static function summary_for_schedule( string $old, string $new ): string {
		$parts = explode( '-', $new );
		$y     = isset( $parts[0] ) ? (int) $parts[0] : 0;

		if ( ! $y ) {
			return '';
		}

		$old_len = strlen( $old );
		$new_len = strlen( $new );

		// 精度變細＝檔期逐步確定。
		$refining = $new_len > $old_len;

		if ( 4 === $new_len ) {
			return $refining ? sprintf( '宣布 %d 年播出', $y ) : sprintf( '播出時間變更為 %d 年', $y );
		}

		$m = isset( $parts[1] ) ? (int) $parts[1] : 0;

		if ( 7 === $new_len ) {
			return $refining
				? sprintf( '宣布 %d 年 %d 月播出', $y, $m )
				: sprintf( '播出時間變更為 %d 年 %d 月', $y, $m );
		}

		$d = isset( $parts[2] ) ? (int) $parts[2] : 0;

		return $refining
			? sprintf( '播出日期確定為 %d 年 %d 月 %d 日', $y, $m, $d )
			: sprintf( '播出日期變更為 %d 年 %d 月 %d 日', $y, $m, $d );
	}

	/**
	 * 播出狀態的文案。
	 *
	 * 只看新值——「變成放送中」就是開播，不需要知道之前是什麼。
	 */
	private static function summary_for_status( string $new ): string {
		$map = [
			'RELEASING'        => '正式開播',
			'FINISHED'         => '播出完結',
			'CANCELLED'        => '製作中止',
			'HIATUS'           => '暫停播出',
			'NOT_YET_RELEASED' => '狀態調整為未播出',
		];

		return $map[ $new ] ?? '';
	}

	/**
	 * 資料表名稱。
	 */
	public static function table(): string {
		global $wpdb;

		return $wpdb->prefix . 'wxacg_anime_events';
	}

	/**
	 * 事件類型清單。
	 *
	 * @return array<string,string>
	 */
	public static function types(): array {
		return self::$TYPES;
	}

	/**
	 * 類型顯示名稱。找不到時回傳原始 key，方便發現漏登錄的類型。
	 */
	public static function type_label( string $type ): string {
		return self::$TYPES[ $type ] ?? $type;
	}

	// =========================================================================
	// 寫入
	// =========================================================================

	/**
	 * 記錄一筆變更事件（狀態為 pending，不會出現在前台）。
	 *
	 * @param array $args {
	 *     @type int    $anime_id      作品的 WP post ID。必填。
	 *     @type string $event_type    self::$TYPES 的 key。必填。
	 *     @type string $fingerprint   新值的指紋，用來去重。必填——
	 *                                 同一個 anime_id + event_type + fingerprint 只會有一筆。
	 *     @type string $event_date    事件日期 Y-m-d，預設今天。
	 *     @type array  $payload       新舊值等佐證資料，會存成 JSON。
	 *     @type string $source        anilist / bangumi / manual。
	 *     @type int    $attachment_id 視覺圖事件的圖片附件 ID。
	 *     @type string $summary       人工說明，偵測寫入時通常留空。
	 * }
	 * @return int 三種結果，呼叫端必須分辨——
	 *             >0  新事件的 ID
	 *              0  這筆已經存在（重複偵測，正常情況）
	 *             -1  參數錯誤或寫入失敗
	 *
	 *             「重複」與「失敗」不可混為一談：偵測端會依據回傳值決定
	 *             要不要推進比對基準，把失敗當成重複會讓那個變更永久遺失。
	 */
	public static function record( array $args ): int {
		global $wpdb;

		$anime_id    = isset( $args['anime_id'] ) ? absint( $args['anime_id'] ) : 0;
		$event_type  = isset( $args['event_type'] ) ? sanitize_key( $args['event_type'] ) : '';
		$fingerprint = isset( $args['fingerprint'] ) ? (string) $args['fingerprint'] : '';

		if ( ! $anime_id || ! $event_type || '' === $fingerprint ) {
			return -1;
		}

		// 未登錄的類型視為錯誤——寧可不寫，也不要在前台冒出無名標籤。
		if ( ! isset( self::$TYPES[ $event_type ] ) ) {
			return -1;
		}

		$dedupe_key = md5( $anime_id . '|' . $event_type . '|' . $fingerprint );

		$payload = isset( $args['payload'] ) && is_array( $args['payload'] )
			? wp_json_encode( $args['payload'], JSON_UNESCAPED_UNICODE )
			: null;

		/*
		 * UNIQUE(dedupe_key) 會擋掉重複。這裡刻意用 INSERT 直接撞，
		 * 而不是先 SELECT 再 INSERT——後者在兩支排程同時跑時仍會重複寫入。
		 */
		$ok = $wpdb->insert(
			self::table(),
			[
				'anime_id'      => $anime_id,
				'event_type'    => $event_type,
				'event_date'    => ! empty( $args['event_date'] ) ? (string) $args['event_date'] : current_time( 'Y-m-d' ),
				'status'        => 'pending',
				'summary'       => isset( $args['summary'] ) ? mb_substr( (string) $args['summary'], 0, 255 ) : '',
				'payload'       => $payload,
				'source'        => isset( $args['source'] ) ? sanitize_key( $args['source'] ) : '',
				'attachment_id' => ! empty( $args['attachment_id'] ) ? absint( $args['attachment_id'] ) : null,
				'dedupe_key'    => $dedupe_key,
				'created_at'    => current_time( 'mysql' ),
			],
			[ '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' ]
		);

		if ( false === $ok ) {
			/*
			 * 寫入失敗有兩種：UNIQUE 衝突（重複偵測，正常）與其他 DB 錯誤。
			 * 必須分辨——把後者當成前者，呼叫端會推進比對基準，
			 * 那個變更就再也偵測不到了。
			 */
			$exists = (int) $wpdb->get_var( $wpdb->prepare(
				'SELECT id FROM ' . self::table() . ' WHERE dedupe_key = %s',
				$dedupe_key
			) );

			if ( $exists ) {
				return 0;
			}

			if ( class_exists( 'Anime_Sync_Error_Logger' ) ) {
				Anime_Sync_Error_Logger::error(
					'事件寫入失敗：' . $wpdb->last_error,
					[
						'anime_id'   => $anime_id,
						'event_type' => $event_type,
						'dedupe_key' => $dedupe_key,
					]
				);
			}

			return -1;
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * 發布事件：寫入 summary、轉為 published，並通知追番會員。
	 *
	 * @param int    $event_id   事件 ID。
	 * @param string $summary    顯示在前台的中文說明。
	 * @param string $event_date 修正後的事件日期 Y-m-d，留空表示沿用原值。
	 *                           掃描寫入的是「偵測日」，但官方公告可能早幾天，
	 *                           前台要顯示的是公告日，因此開放審核時修正。
	 * @return bool 是否成功。
	 */
	public static function publish( int $event_id, string $summary, string $event_date = '' ): bool {
		global $wpdb;

		$event = self::get( $event_id );

		if ( ! $event ) {
			return false;
		}

		$data    = [
			'status'  => 'published',
			'summary' => mb_substr( $summary, 0, 255 ),
		];
		$formats = [ '%s', '%s' ];

		if ( '' !== $event_date && self::is_valid_date( $event_date ) ) {
			$data['event_date'] = $event_date;
			$formats[]          = '%s';
		}

		$ok = $wpdb->update(
			self::table(),
			$data,
			[ 'id' => $event_id ],
			$formats,
			[ '%d' ]
		);

		if ( false === $ok ) {
			return false;
		}

		self::promote_visual( $event_id );
		self::notify_followers( $event_id );

		return true;
	}

	/**
	 * 把已發布的視覺圖設為作品主圖。
	 *
	 * ★ 為什麼要換主圖
	 *   舊圖不會消失——它留在媒體庫，也留在頁首的視覺圖切換器裡。
	 *   換的只是「顯示指標」。不換的話，搜尋下拉、列表卡片、社群分享的
	 *   OG 圖會永遠停在匯入當天那張，即使官方早就公開了新視覺圖。
	 *
	 *   搜尋縮圖取的是 get_the_post_thumbnail_url()，fallback 才是
	 *   anime_cover_image，所以兩個都要寫。
	 *
	 * ★ 為什麼放在 publish() 而不是偵測時
	 *   偵測會有誤報（上游只是重新編碼）。發布是人看過圖之後的明確決定，
	 *   在這個時間點換主圖才安全。
	 *
	 * 使用者在 ACF 勾選鎖定 anime_cover_image 的作品完全不動。
	 */
	private static function promote_visual( int $event_id ): void {
		$event = self::get( $event_id );

		if ( ! $event || 'visual' !== $event->event_type || empty( $event->attachment_id ) ) {
			return;
		}

		$anime_id      = (int) $event->anime_id;
		$attachment_id = (int) $event->attachment_id;

		// 尊重 ACF 的欄位鎖定（站上目前有 11 篇鎖了封面）。
		$locked = get_post_meta( $anime_id, 'anime_locked_fields', true );

		if ( is_array( $locked ) && in_array( 'anime_cover_image', $locked, true ) ) {
			return;
		}

		if ( ! wp_attachment_is_image( $attachment_id ) ) {
			return;
		}

		$prev_thumb_id = (int) get_post_thumbnail_id( $anime_id );

		if ( $prev_thumb_id === $attachment_id ) {
			return;
		}

		/*
		 * 把換掉之前的封面記在 payload 裡，reject() 才有辦法還原。
		 * 沒有這一步，「發布後才發現是誤報」就會卡住：封面已經換掉，
		 * 舊圖的附件 ID 無從得知。
		 */
		self::remember_previous_cover( $event_id, $prev_thumb_id, (string) get_post_meta( $anime_id, 'anime_cover_image', true ) );

		set_post_thumbnail( $anime_id, $attachment_id );

		$local_url = wp_get_attachment_url( $attachment_id );

		if ( $local_url ) {
			update_post_meta( $anime_id, 'anime_cover_image', esc_url_raw( $local_url ) );
		}
	}

	/**
	 * 在事件的 payload 追加換圖前的封面資訊。
	 */
	private static function remember_previous_cover( int $event_id, int $prev_thumb_id, string $prev_cover_url ): void {
		global $wpdb;

		$event = self::get( $event_id );

		if ( ! $event ) {
			return;
		}

		$payload = ! empty( $event->payload ) ? json_decode( $event->payload, true ) : [];
		$payload = is_array( $payload ) ? $payload : [];

		$payload['prev_thumbnail_id'] = $prev_thumb_id;
		$payload['prev_cover_url']    = $prev_cover_url;

		$wpdb->update(
			self::table(),
			[ 'payload' => wp_json_encode( $payload, JSON_UNESCAPED_UNICODE ) ],
			[ 'id' => $event_id ],
			[ '%s' ],
			[ '%d' ]
		);
	}

	/**
	 * 退回時把主圖還原成發布前那張。
	 *
	 * 只在「目前的特色圖片正是這筆事件的圖」時才動——使用者若在發布之後
	 * 又自己換過封面，那是更新的決定，不該被退回動作蓋掉。
	 */
	private static function restore_previous_cover( object $event ): void {
		$attachment_id = (int) $event->attachment_id;
		$anime_id      = (int) $event->anime_id;

		if ( ! $attachment_id || (int) get_post_thumbnail_id( $anime_id ) !== $attachment_id ) {
			return;
		}

		$payload = ! empty( $event->payload ) ? json_decode( $event->payload, true ) : [];
		$payload = is_array( $payload ) ? $payload : [];

		$prev_id = isset( $payload['prev_thumbnail_id'] ) ? (int) $payload['prev_thumbnail_id'] : 0;

		if ( $prev_id && wp_attachment_is_image( $prev_id ) ) {
			set_post_thumbnail( $anime_id, $prev_id );
		} else {
			delete_post_thumbnail( $anime_id );
		}

		if ( ! empty( $payload['prev_cover_url'] ) ) {
			update_post_meta( $anime_id, 'anime_cover_image', esc_url_raw( (string) $payload['prev_cover_url'] ) );
		}
	}

	/**
	 * 人工新增一則消息。
	 *
	 * ★ 為什麼一定要有這個入口
	 *   偵測只看得到 AniList 欄位的異動。但真正的作品消息有一大半不在
	 *   任何 API 裡——「第一季全24話播出後宣布第二季製作決定」「監督確定」
	 *   「主視覺公開」這類要嘛只在官方推特、要嘛只在新聞稿。少了手動入口，
	 *   消息更新就只是一份「封面換了／日期改了」的機械紀錄。
	 *
	 *   直接以 published 寫入，不經審核——這是人寫的，沒有誤報問題。
	 *
	 * @param int    $anime_id   作品 post ID。
	 * @param string $summary    消息內容。
	 * @param string $event_date 消息日期 Y-m-d。
	 * @param string $event_type 事件類型，預設 visual 以外的通用類型由呼叫端指定。
	 * @return int 事件 ID；失敗回傳 -1；重複回傳 0。
	 */
	public static function add_manual( int $anime_id, string $summary, string $event_date, string $event_type ): int {
		global $wpdb;

		$summary = trim( $summary );

		if ( ! $anime_id || '' === $summary || ! isset( self::$TYPES[ $event_type ] ) ) {
			return -1;
		}

		if ( ! self::is_valid_date( $event_date ) ) {
			return -1;
		}

		// 人工消息用「日期＋內容」當指紋，避免同一則被重複貼上。
		$dedupe_key = md5( $anime_id . '|' . $event_type . '|manual|' . $event_date . '|' . $summary );

		$ok = $wpdb->insert(
			self::table(),
			[
				'anime_id'   => $anime_id,
				'event_type' => $event_type,
				'event_date' => $event_date,
				'status'     => 'published',
				'summary'    => mb_substr( $summary, 0, 255 ),
				'source'     => 'manual',
				'dedupe_key' => $dedupe_key,
				'created_at' => current_time( 'mysql' ),
			],
			[ '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
		);

		if ( false === $ok ) {
			$exists = (int) $wpdb->get_var( $wpdb->prepare(
				'SELECT id FROM ' . self::table() . ' WHERE dedupe_key = %s',
				$dedupe_key
			) );

			return $exists ? 0 : -1;
		}

		$event_id = (int) $wpdb->insert_id;

		self::notify_followers( $event_id );

		return $event_id;
	}

	/**
	 * Y-m-d 格式與真實性檢查（擋掉 2026-02-31 這種）。
	 */
	private static function is_valid_date( string $date ): bool {
		$d = DateTime::createFromFormat( 'Y-m-d', $date );

		return $d && $d->format( 'Y-m-d' ) === $date;
	}

	/**
	 * 退回事件（誤報）。
	 *
	 * 資料列保留——dedupe_key 還在，同一個變更下次才不會又被偵測成新事件。
	 *
	 * 但附件要刪掉：退回的理由通常就是「上游只是重新編碼，圖其實沒變」，
	 * 那張圖不會出現在任何地方，留著就是每次退回漏一張約 0.94 MB
	 * （含 WP 產生的尺寸變體）。
	 */
	public static function reject( int $event_id ): bool {
		global $wpdb;

		$event = self::get( $event_id );

		if ( ! $event ) {
			return false;
		}

		$ok = $wpdb->update(
			self::table(),
			[ 'status' => 'rejected' ],
			[ 'id' => $event_id ],
			[ '%s' ],
			[ '%d' ]
		);

		if ( false === $ok ) {
			return false;
		}

		// 順序不可調換：先把主圖還原，附件才不再被 delete_orphan_attachment() 視為使用中。
		self::restore_previous_cover( $event );
		self::delete_orphan_attachment( $event );

		return true;
	}

	/**
	 * 刪除事件專屬的附件。
	 *
	 * 三道防護，避免誤刪使用者的圖：
	 *   1. 只刪這筆事件自己記錄的 attachment_id
	 *   2. 是特色圖片就不刪（理論上不會發生，掃描從不設特色圖片，但擋著）
	 *   3. 被其他事件引用就不刪
	 */
	private static function delete_orphan_attachment( object $event ): void {
		global $wpdb;

		$attachment_id = (int) $event->attachment_id;

		if ( ! $attachment_id ) {
			return;
		}

		if ( (int) get_post_thumbnail_id( (int) $event->anime_id ) === $attachment_id ) {
			return;
		}

		$others = (int) $wpdb->get_var( $wpdb->prepare(
			'SELECT COUNT(*) FROM ' . self::table() . ' WHERE attachment_id = %d AND id != %d',
			$attachment_id,
			(int) $event->id
		) );

		if ( $others > 0 ) {
			return;
		}

		wp_delete_attachment( $attachment_id, true );

		$wpdb->update(
			self::table(),
			[ 'attachment_id' => null ],
			[ 'id' => (int) $event->id ],
			[ '%d' ],
			[ '%d' ]
		);
	}

	// =========================================================================
	// 通知
	// =========================================================================

	/**
	 * 把已發布的事件推給把這部作品加進清單的會員。
	 *
	 * 收件人來源是 anime_user_status（追番清單），不是 wxacg_follows
	 * ——後者是「會員追會員」，跟作品無關。
	 *
	 * 偏好檢查由 wxacg_create_notification() 內部的 wxacg_should_notify() 負責，
	 * 這裡不重複判斷。
	 *
	 * @return int 實際送出的通知數。
	 */
	private static function notify_followers( int $event_id ): int {
		global $wpdb;

		if ( ! function_exists( 'wxacg_create_notification' ) ) {
			return 0;
		}

		$event = self::get( $event_id );

		if ( ! $event || 'published' !== $event->status || ! empty( $event->notified_at ) ) {
			return 0;
		}

		// 防洗版：補審舊事件時不通知。
		$age_days = ( time() - strtotime( $event->event_date ) ) / DAY_IN_SECONDS;

		if ( $age_days > self::NOTIFY_MAX_AGE_DAYS ) {
			return 0;
		}

		/*
		 * 只通知「還在意這部作品」的人。
		 *
		 *   0 想看 / 1 追番中 / 4 暫停  → 通知
		 *   2 已看完 / 3 棄坑           → 不通知
		 *
		 * 已看完的人對這一篇不會再有新期待（續作是另一篇文章，不會漏掉），
		 * 棄坑更是明確表達過不想再收到。原本沒有這層過濾，等於棄坑了還被打擾。
		 */
		$user_ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT DISTINCT user_id FROM {$wpdb->prefix}anime_user_status
			  WHERE anime_id = %d AND status IN ( %d, %d, %d )",
			$event->anime_id,
			Anime_Sync_User_Status_Manager::STATUS_WANT,
			Anime_Sync_User_Status_Manager::STATUS_WATCHING,
			Anime_Sync_User_Status_Manager::STATUS_PAUSED
		) );

		$sent = 0;

		foreach ( $user_ids as $user_id ) {
			/*
			 * data 的欄位名由 wxacg_render_notification_item() 決定，
			 * 它只認得 title / excerpt / url / icon 四個鍵——
			 * 自己另外取名（例如 summary）不會報錯，但鈴鐺上就是空白一片。
			 */
			$result = wxacg_create_notification( [
				'user_id'     => (int) $user_id,
				'type'        => 'anime_update',
				'object_type' => 'anime',
				'object_id'   => (int) $event->anime_id,
				'data'        => [
					'title'      => sprintf( '《%s》有新消息', get_the_title( $event->anime_id ) ),
					'excerpt'    => $event->summary,
					'url'        => get_permalink( $event->anime_id ) . '#asd-sec-events',
					'icon'       => 'fa-bullhorn',
					'event_type' => $event->event_type,
				],
			] );

			if ( ! is_wp_error( $result ) ) {
				$sent++;
			}
		}

		// 標記已通知，避免重複發布時再送一次。
		$wpdb->update(
			self::table(),
			[ 'notified_at' => current_time( 'mysql' ) ],
			[ 'id' => $event_id ],
			[ '%s' ],
			[ '%d' ]
		);

		return $sent;
	}

	// =========================================================================
	// 讀取
	// =========================================================================

	/**
	 * 單筆事件。
	 *
	 * @return object|null
	 */
	public static function get( int $event_id ) {
		global $wpdb;

		return $wpdb->get_row( $wpdb->prepare(
			'SELECT * FROM ' . self::table() . ' WHERE id = %d',
			$event_id
		) );
	}

	/**
	 * 某部作品的事件（單頁「消息更新」區塊用）。
	 *
	 * @param int    $anime_id 作品 post ID。
	 * @param string $status   預設只取已發布。
	 * @return array<object> 依事件日期由新到舊。
	 */
	public static function get_for_anime( int $anime_id, string $status = 'published' ): array {
		global $wpdb;

		return (array) $wpdb->get_results( $wpdb->prepare(
			'SELECT * FROM ' . self::table() . '
			  WHERE anime_id = %d AND status = %s
			  ORDER BY event_date DESC, id DESC',
			$anime_id,
			$status
		) );
	}

	/**
	 * 全站最近事件（/recent-updated 頁用）。
	 *
	 * @param int    $limit  筆數上限。
	 * @param string $status 預設只取已發布。
	 * @return array<object>
	 */
	public static function get_recent( int $limit = 50, string $status = 'published' ): array {
		global $wpdb;

		return (array) $wpdb->get_results( $wpdb->prepare(
			'SELECT * FROM ' . self::table() . '
			  WHERE status = %s
			  ORDER BY event_date DESC, id DESC
			  LIMIT %d',
			$status,
			max( 1, min( 200, $limit ) )
		) );
	}

	/**
	 * 待審事件數（後台選單的紅點用）。
	 */
	public static function count_pending(): int {
		global $wpdb;

		return (int) $wpdb->get_var(
			'SELECT COUNT(*) FROM ' . self::table() . " WHERE status = 'pending'"
		);
	}
}
