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

		self::notify_followers( $event_id );

		return true;
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

		$user_ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT DISTINCT user_id FROM {$wpdb->prefix}anime_user_status WHERE anime_id = %d",
			$event->anime_id
		) );

		$sent = 0;

		foreach ( $user_ids as $user_id ) {
			$result = wxacg_create_notification( [
				'user_id'     => (int) $user_id,
				'type'        => 'anime_update',
				'object_type' => 'anime',
				'object_id'   => (int) $event->anime_id,
				'data'        => [
					'title'      => get_the_title( $event->anime_id ),
					'summary'    => $event->summary,
					'event_type' => $event->event_type,
					'url'        => get_permalink( $event->anime_id ),
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
