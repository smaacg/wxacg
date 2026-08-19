<?php
/**
 * 檔案名稱: includes/class-upstream-diff-scan.php
 * AniList 全站差異掃描 — 偵測上游資料異動並寫成事件
 *
 * ★ 問題背景
 *   作品的封面、播出日期、集數、狀態在匯入當下寫一次之後就不再更新。
 *   實例：迷宮飯第二季 2026-06-28 匯入，2026-07-05 官方公開超前導視覺圖
 *   並宣布 2027 年 10 月播出，AniList 與 Bangumi 都收錄了，站上停在匯入當天。
 *   而未播出階段正是搜尋量最高的時候。
 *
 * ★ 為什麼不用 AniList 的 updatedAt 當訊號
 *   實測站上 50 部隨機作品，50 部的 updatedAt 全部落在最近兩天內
 *   （48 部是同一天）。AniList 有批次作業每天把幾乎所有條目的時間戳推一次，
 *   拿它判斷「有沒有變」等於 100% 誤報。只能逐欄位比對。
 *
 * ★ 成本
 *   GraphQL 的 id_in 一次可查 50 部，實測單次 795ms、回應 14KB。
 *   全站 1522 部只要 31 個 request。限流實測 X-RateLimit-Limit: 30/分，
 *   因此每次排程只發 REQUESTS_PER_RUN 個，靠每小時輪替把全站掃完，
 *   單次執行約 10 秒——不做成一次跑完 62 秒，是因為 WP cron 逾時的事件
 *   會在執行前就被移除，等於永久遺失。
 *
 * ★ 第一次跑不產生事件
 *   站上從未儲存過上游封面 URL，第一次掃描沒有基準可比。若直接比對
 *   特色圖片檔名裡的 md5，會一次噴出幾百筆「封面變了」——那些是自匯入
 *   以來累積的舊帳，而且無從得知實際變更日期。沒有日期的事件比沒有事件
 *   更糟，也會讓待審清單第一天就失去可用性。
 *   因此首次掃描只建立基準（seed），事件從第二輪開始產生。
 *
 * @version 1.0.0
 * @package Anime_Sync_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Anime_Sync_Upstream_Diff_Scan {

	const HOOK_HOURLY   = 'anime_sync_upstream_diff_scan';
	const QUEUE_OPTION  = 'anime_sync_upstream_diff_queue';
	const SWEEP_OPTION  = 'anime_sync_upstream_diff_last_sweep';
	const SNAPSHOT_META = 'anime_upstream_snapshot';

	/** AniList 單次查詢的作品數上限（GraphQL perPage 上限即 50）。 */
	const IDS_PER_REQUEST = 50;

	/** 每次排程發出的 request 數。5 × 50 = 250 部／次，約 10 秒。 */
	const REQUESTS_PER_RUN = 5;

	/** request 之間的間隔（微秒）。限流 30/分，2 秒留有餘裕。 */
	const SLEEP_US = 2000000;

	/** 兩輪掃描的最小間隔（小時）。避免佇列空了就立刻重建。 */
	const SWEEP_INTERVAL_HOURS = 20;

	/**
	 * 單次執行最多下載幾張視覺圖。
	 *
	 * 限制的不只是磁碟（每張含 WP 尺寸變體約 0.94 MB），更是 CPU——
	 * 站台每張圖會產生 36 個尺寸，縮圖運算比下載本身重得多。
	 * 而外部觸發會把同一次到期的排程全部串在一個請求裡跑完，
	 * 這支拖太久會連累同一批的其他排程。
	 *
	 * 穩態下 277 部作品一天大概只有 1~5 張新視覺圖，這個上限平常不會碰到，
	 * 它是防上游批次重傳的保險絲。超過的留到下一輪（基準不會推進）。
	 */
	const MAX_VISUALS_PER_RUN = 8;

	const ENDPOINT = 'https://graphql.anilist.co';

	/** 本次執行已下載的視覺圖數。 */
	private int $visuals_this_run = 0;

	public function __construct() {
		add_action( self::HOOK_HOURLY, [ $this, 'run_scheduled' ] );
	}

	/**
	 * 事件類型 → 快照欄位名。
	 *
	 * 兩者刻意不同名：事件類型是對外的語意（visual／schedule），
	 * 快照欄位是內部儲存的鍵（cover／start_date）。
	 */
	private function snapshot_key( string $event_type ): string {
		$map = [
			'visual'   => 'cover',
			'schedule' => 'start_date',
			'episodes' => 'episodes',
			'status'   => 'status',
		];

		return $map[ $event_type ] ?? $event_type;
	}

	/**
	 * 註冊排程。
	 *
	 * ★ 釘死在每小時第 RUN_AT_MINUTE 分，不用 time() + 位移。
	 *   站台是 DISABLE_WP_CRON=true 靠外部觸發，同一次觸發會把所有到期的
	 *   排程依序跑完，撞在同一分鐘等於疊加執行時間。而 time() 位移的落點
	 *   取決於「外掛第一次初始化的時刻」，等同隨機，可能正好壓在別人身上。
	 *
	 *   既有的每小時排程佔用 :00 :01 :02 :09 :12 :16 :43 :52 :53，
	 *   :35 是空檔，且與 anime_sync_upcoming_bgm_scan（:43）保持距離。
	 *   每日的 anime_sync_new_release_scan（12:30）也吃 AniList 額度，
	 *   兩者相隔 5 分鐘以上，不會在同一次觸發裡爭搶限流。
	 */
	const RUN_AT_MINUTE = 35;

	public static function schedule(): void {
		if ( wp_next_scheduled( self::HOOK_HOURLY ) ) {
			return;
		}

		/*
		 * 下一個 :RUN_AT_MINUTE，純以 UTC 時間戳計算。
		 * 不混用 wp_date()（站台時區）與 mktime()（WP 已把 PHP 預設時區設為 UTC）
		 * ——兩者相減會算出偏移 8 小時的錯誤時間。cron 時間戳本來就是 UTC，
		 * 而「第幾分」在整點時區偏移下與當地時間一致。
		 */
		$now   = time();
		$first = $now - ( $now % HOUR_IN_SECONDS ) + self::RUN_AT_MINUTE * MINUTE_IN_SECONDS;

		if ( $first <= $now ) {
			$first += HOUR_IN_SECONDS;
		}

		wp_schedule_event( $first, 'hourly', self::HOOK_HOURLY );
	}

	public static function unschedule(): void {
		$ts = wp_next_scheduled( self::HOOK_HOURLY );

		if ( $ts ) {
			wp_unschedule_event( $ts, self::HOOK_HOURLY );
		}

		wp_clear_scheduled_hook( self::HOOK_HOURLY );
	}

	// =========================================================================
	// 排程進入點
	// =========================================================================

	public function run_scheduled(): void {
		$this->run( self::REQUESTS_PER_RUN );
	}

	/**
	 * 消耗佇列。
	 *
	 * @param int  $requests 本次最多發幾個 request。
	 * @param bool $dry_run  只比對不寫入，供 WP-CLI 驗證用。
	 * @return array 統計結果。
	 */
	public function run( int $requests, bool $dry_run = false ): array {
		$stats = [
			'checked' => 0,
			'seeded'  => 0,
			'events'  => 0,
			'skipped' => 0,
			'errors'  => [],
		];

		$this->visuals_this_run = 0;

		$queue = $this->get_queue();

		if ( empty( $queue ) ) {
			return $stats;
		}

		for ( $i = 0; $i < $requests; $i++ ) {
			$chunk = array_slice( $queue, 0, self::IDS_PER_REQUEST );

			if ( empty( $chunk ) ) {
				break;
			}

			$queue = array_slice( $queue, self::IDS_PER_REQUEST );

			if ( $i > 0 ) {
				usleep( self::SLEEP_US );
			}

			$media = $this->fetch_batch( wp_list_pluck( $chunk, 'anilist_id' ) );

			if ( is_wp_error( $media ) ) {
				$stats['errors'][] = $media->get_error_message();

				/*
				 * 取不到就把這批放回佇列前端，下次再試。
				 * 不直接丟棄——丟棄等於這批作品這一輪不會被檢查。
				 */
				$queue = array_merge( $chunk, $queue );
				break;
			}

			// AniList ID → post ID 的對照，回應順序不保證與請求相同。
			$by_al = [];

			foreach ( $chunk as $row ) {
				$by_al[ (int) $row['anilist_id'] ] = (int) $row['post_id'];
			}

			foreach ( $media as $m ) {
				$al_id = isset( $m['id'] ) ? (int) $m['id'] : 0;

				if ( ! $al_id || empty( $by_al[ $al_id ] ) ) {
					$stats['skipped']++;
					continue;
				}

				$result = $this->compare_and_record( $by_al[ $al_id ], $m, $dry_run );

				$stats['checked']++;
				$stats['seeded'] += $result['seeded'];
				$stats['events'] += $result['events'];
			}
		}

		if ( ! $dry_run ) {
			update_option( self::QUEUE_OPTION, $queue, false );

			// 佇列清空 = 完成一輪全站掃描。
			if ( empty( $queue ) ) {
				update_option( self::SWEEP_OPTION, time(), false );
			}
		}

		return $stats;
	}

	// =========================================================================
	// 佇列
	// =========================================================================

	/**
	 * 取得佇列；空的且距上輪夠久才重建。
	 *
	 * @return array<array{post_id:int,anilist_id:int}>
	 */
	private function get_queue(): array {
		$queue = get_option( self::QUEUE_OPTION, null );

		if ( is_array( $queue ) && ! empty( $queue ) ) {
			return $queue;
		}

		$last = (int) get_option( self::SWEEP_OPTION, 0 );

		if ( $last && ( time() - $last ) < self::SWEEP_INTERVAL_HOURS * HOUR_IN_SECONDS ) {
			return [];
		}

		$queue = $this->build_queue();

		update_option( self::QUEUE_OPTION, $queue, false );

		return $queue;
	}

	/**
	 * 建立佇列：只收未播出與放送中的作品。
	 *
	 * ★ 為什麼不掃全站
	 *   API 成本不是限制（全站也只要 31 個 request）。限制有兩個：
	 *
	 *   1. 審核時間。已完結作品的封面偶爾也會變，但那幾乎都是 AniList
	 *      自己換上更清晰的掃圖，不是消息。這種事件會塞滿待審清單，
	 *      而待審清單一旦充滿垃圾就不會再被打開，整套機制等於失效。
	 *
	 *   2. 磁碟。每筆視覺圖事件會下載一張圖，含 WP 自動產生的尺寸變體
	 *      約 0.94 MB。若上游批次重傳一批完結作品的封面，一個晚上就會
	 *      灌進幾百 MB 的垃圾圖。
	 *
	 *   站上 1522 部裡完結的佔 1245 部（82%），排除後每輪只需檢查 277 部。
	 *   而未播出階段正是資料最常變動、搜尋量也最高的時候。
	 *
	 * @return array<array{post_id:int,anilist_id:int}>
	 */
	private function build_queue(): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			"SELECT p.ID AS post_id, al.meta_value AS anilist_id
			   FROM {$wpdb->posts} p
			   JOIN {$wpdb->postmeta} al ON al.post_id = p.ID AND al.meta_key = 'anime_anilist_id'
			   JOIN {$wpdb->postmeta} st ON st.post_id = p.ID AND st.meta_key = 'anime_status'
			  WHERE p.post_type = 'anime'
			    AND p.post_status = 'publish'
			    AND al.meta_value REGEXP '^[0-9]+$'
			    AND st.meta_value IN ( 'NOT_YET_RELEASED', 'RELEASING' )
			  ORDER BY FIELD( st.meta_value, 'RELEASING', 'NOT_YET_RELEASED' ) DESC, p.ID ASC",
			ARRAY_A
		);

		$queue = [];

		foreach ( (array) $rows as $r ) {
			$queue[] = [
				'post_id'    => (int) $r['post_id'],
				'anilist_id' => (int) $r['anilist_id'],
			];
		}

		return $queue;
	}

	// =========================================================================
	// AniList 查詢
	// =========================================================================

	/**
	 * 一次取回多部作品的比對欄位。
	 *
	 * @param array<int> $ids AniList ID。
	 * @return array|WP_Error media 陣列。
	 */
	private function fetch_batch( array $ids ) {
		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );

		if ( empty( $ids ) ) {
			return [];
		}

		$query = 'query ( $ids: [Int] ) {
			Page( perPage: ' . self::IDS_PER_REQUEST . ' ) {
				media( id_in: $ids, type: ANIME ) {
					id
					status
					episodes
					startDate { year month day }
					coverImage { extraLarge large }
				}
			}
		}';

		$response = wp_remote_post( self::ENDPOINT, [
			'timeout' => 20,
			'headers' => [
				'Content-Type' => 'application/json',
				'Accept'       => 'application/json',
			],
			'body'    => wp_json_encode( [
				'query'     => $query,
				'variables' => [ 'ids' => $ids ],
			] ),
		] );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );

		// 429 = 撞到限流。回報錯誤讓呼叫端把這批放回佇列，不吞掉。
		if ( 429 === $code ) {
			return new WP_Error( 'anilist_rate_limited', 'AniList 限流（429），本批延後處理' );
		}

		if ( 200 !== $code ) {
			return new WP_Error( 'anilist_http_' . $code, 'AniList 回應 HTTP ' . $code );
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! empty( $body['errors'] ) ) {
			$msg = $body['errors'][0]['message'] ?? 'unknown';

			return new WP_Error( 'anilist_graphql_error', 'AniList GraphQL 錯誤：' . $msg );
		}

		return (array) ( $body['data']['Page']['media'] ?? [] );
	}

	// =========================================================================
	// 比對與寫入
	// =========================================================================

	/**
	 * 比對單部作品，差異寫成事件。
	 *
	 * @return array{seeded:int,events:int}
	 */
	private function compare_and_record( int $post_id, array $media, bool $dry_run ): array {
		$current = [
			'cover'      => (string) ( $media['coverImage']['extraLarge'] ?? $media['coverImage']['large'] ?? '' ),
			'start_date' => $this->format_start_date( $media['startDate'] ?? [] ),
			'episodes'   => isset( $media['episodes'] ) ? (int) $media['episodes'] : 0,
			'status'     => (string) ( $media['status'] ?? '' ),
		];

		$raw      = get_post_meta( $post_id, self::SNAPSHOT_META, true );
		$snapshot = is_string( $raw ) && '' !== $raw ? json_decode( $raw, true ) : null;

		// ── 首次掃描：只建立基準，不產生事件（見檔頭說明）。
		if ( ! is_array( $snapshot ) ) {
			if ( ! $dry_run ) {
				$this->save_snapshot( $post_id, $current );
			}

			return [ 'seeded' => 1, 'events' => 0 ];
		}

		$events = 0;

		/*
		 * 基準預設沿用舊值，逐欄位在「確實處理完」之後才換成新值。
		 * 不整包覆蓋是因為：視覺圖可能因為抓不到或撞到單次上限而沒處理，
		 * 那一欄若跟著更新，這個變更下一輪就再也偵測不到，等於永久遺失。
		 */
		$next = $snapshot;

		foreach ( $this->diff( $snapshot, $current ) as $type => $change ) {
			if ( $dry_run ) {
				$events++;
				continue;
			}

			$args = [
				'anime_id'    => $post_id,
				'event_type'  => $type,
				'fingerprint' => (string) $change['new'],
				'source'      => 'anilist',
				'payload'     => [
					'old' => $change['old'],
					'new' => $change['new'],
				],
			];

			// 視覺圖：把新圖抓下來存成附件，事件才有東西可看可比。
			if ( 'visual' === $type ) {
				/*
				 * 單次執行的下載上限。若上游批次重傳一批封面，
				 * 沒有這道閘門會在一次排程裡灌進幾百 MB。
				 * 超過就整筆跳過，基準不動，下一輪自然會重試。
				 */
				if ( $this->visuals_this_run >= self::MAX_VISUALS_PER_RUN ) {
					continue;
				}

				$attachment_id = $this->sideload_visual( $post_id, (string) $change['new'] );

				if ( ! $attachment_id ) {
					// 圖抓不到就不寫事件——留著下一輪重試，比寫一筆看不到圖的事件好。
					continue;
				}

				$this->visuals_this_run++;

				$args['attachment_id'] = $attachment_id;
			}

			$recorded = Anime_Sync_Anime_Events::record( $args );

			/*
			 * -1 是寫入失敗（不是重複）。基準維持舊值，下一輪重試，
			 * 否則這個變更會永久遺失而且無聲無息。
			 */
			if ( -1 === $recorded ) {
				continue;
			}

			if ( $recorded > 0 ) {
				$events++;
			}

			/*
			 * 這一欄確實記錄完了（新寫入或已存在）才推進基準。
			 * dedupe_key 已經擋掉重複寫入，這裡是為了避免重複下載視覺圖。
			 */
			$next[ $this->snapshot_key( $type ) ] = $change['new'];
		}

		// 沒有變更的欄位也要跟上——例如上游補了原本是空的集數。
		foreach ( $current as $key => $value ) {
			if ( ! array_key_exists( $key, $next ) ) {
				$next[ $key ] = $value;
			}
		}

		if ( ! $dry_run ) {
			$this->save_snapshot( $post_id, $next );
		}

		return [ 'seeded' => 0, 'events' => $events ];
	}

	/**
	 * 逐欄位比對。
	 *
	 * @return array<string,array{old:mixed,new:mixed}> 以事件類型為 key。
	 */
	private function diff( array $old, array $new ): array {
		$changes = [];

		// 封面：空值不算變更，避免上游暫時抓不到就誤判成換圖。
		if ( '' !== $new['cover'] && ( $old['cover'] ?? '' ) !== $new['cover'] ) {
			$changes['visual'] = [ 'old' => $old['cover'] ?? '', 'new' => $new['cover'] ];
		}

		if ( '' !== $new['start_date'] && ( $old['start_date'] ?? '' ) !== $new['start_date'] ) {
			$changes['schedule'] = [ 'old' => $old['start_date'] ?? '', 'new' => $new['start_date'] ];
		}

		if ( $new['episodes'] > 0 && (int) ( $old['episodes'] ?? 0 ) !== $new['episodes'] ) {
			$changes['episodes'] = [ 'old' => (int) ( $old['episodes'] ?? 0 ), 'new' => $new['episodes'] ];
		}

		if ( '' !== $new['status'] && ( $old['status'] ?? '' ) !== $new['status'] ) {
			$changes['status'] = [ 'old' => $old['status'] ?? '', 'new' => $new['status'] ];
		}

		return $changes;
	}

	/**
	 * AniList 的 startDate 轉成可比對的字串。
	 *
	 * 只有年份或只有年月都是常態（檔期未定），保留精度差異——
	 * 「2027」變成「2027-10」本身就是一則值得記錄的消息。
	 */
	private function format_start_date( array $d ): string {
		$y = isset( $d['year'] ) ? (int) $d['year'] : 0;

		if ( ! $y ) {
			return '';
		}

		$m = isset( $d['month'] ) ? (int) $d['month'] : 0;
		$day = isset( $d['day'] ) ? (int) $d['day'] : 0;

		if ( ! $m ) {
			return sprintf( '%04d', $y );
		}

		if ( ! $day ) {
			return sprintf( '%04d-%02d', $y, $m );
		}

		return sprintf( '%04d-%02d-%02d', $y, $m, $day );
	}

	/**
	 * 寫入基準快照。
	 *
	 * update_post_meta() 內部會 wp_unslash()，JSON 必須先 wp_slash() 再傳，
	 * 否則裡面的跳脫字元會被吃掉。
	 */
	private function save_snapshot( int $post_id, array $snapshot ): void {
		update_post_meta(
			$post_id,
			self::SNAPSHOT_META,
			wp_slash( wp_json_encode( $snapshot, JSON_UNESCAPED_UNICODE ) )
		);
	}

	/**
	 * 下載視覺圖並建立附件。
	 *
	 * 刻意不呼叫 set_post_thumbnail()——特色圖片維持使用者現有的選擇，
	 * 列表卡片與 OG 圖不受影響。新圖只進事件，是附加而非覆蓋。
	 *
	 * @return int 附件 ID；失敗回傳 0。
	 */
	private function sideload_visual( int $post_id, string $url ): int {
		if ( ! $url ) {
			return 0;
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$tmp = download_url( $url, 30 );

		if ( is_wp_error( $tmp ) ) {
			return 0;
		}

		$ext = pathinfo( wp_parse_url( $url, PHP_URL_PATH ), PATHINFO_EXTENSION );
		$ext = in_array( strtolower( (string) $ext ), [ 'jpg', 'jpeg', 'png', 'webp' ], true ) ? strtolower( $ext ) : 'jpg';

		$file = [
			'name'     => 'anime-visual-' . $post_id . '-' . md5( $url ) . '.' . $ext,
			'tmp_name' => $tmp,
		];

		$attachment_id = media_handle_sideload( $file, $post_id, null );

		if ( is_wp_error( $attachment_id ) ) {
			// download_url() 建立的暫存檔在失敗時不會自動清掉。
			@unlink( $tmp );

			return 0;
		}

		return (int) $attachment_id;
	}
}
