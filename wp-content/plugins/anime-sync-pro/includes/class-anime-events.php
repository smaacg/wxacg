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
	 * @return int 新事件的 ID；重複事件回傳 0；參數錯誤或寫入失敗回傳 0。
	 */
	public static function record( array $args ): int {
		global $wpdb;

		$anime_id    = isset( $args['anime_id'] ) ? absint( $args['anime_id'] ) : 0;
		$event_type  = isset( $args['event_type'] ) ? sanitize_key( $args['event_type'] ) : '';
		$fingerprint = isset( $args['fingerprint'] ) ? (string) $args['fingerprint'] : '';

		if ( ! $anime_id || ! $event_type || '' === $fingerprint ) {
			return 0;
		}

		// 未登錄的類型視為錯誤——寧可不寫，也不要在前台冒出無名標籤。
		if ( ! isset( self::$TYPES[ $event_type ] ) ) {
			return 0;
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
			// 重複（UNIQUE 衝突）是預期內的正常結果，不當成錯誤記錄。
			return 0;
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * 發布事件：寫入 summary、轉為 published，並通知追番會員。
	 *
	 * @param int    $event_id 事件 ID。
	 * @param string $summary  顯示在前台的中文說明。
	 * @return bool 是否成功。
	 */
	public static function publish( int $event_id, string $summary ): bool {
		global $wpdb;

		$event = self::get( $event_id );

		if ( ! $event ) {
			return false;
		}

		$ok = $wpdb->update(
			self::table(),
			[
				'status'  => 'published',
				'summary' => mb_substr( $summary, 0, 255 ),
			],
			[ 'id' => $event_id ],
			[ '%s', '%s' ],
			[ '%d' ]
		);

		if ( false === $ok ) {
			return false;
		}

		self::notify_followers( $event_id );

		return true;
	}

	/**
	 * 退回事件（誤報）。保留列以免同一個變更下次又被偵測成新事件。
	 */
	public static function reject( int $event_id ): bool {
		global $wpdb;

		return false !== $wpdb->update(
			self::table(),
			[ 'status' => 'rejected' ],
			[ 'id' => $event_id ],
			[ '%s' ],
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
