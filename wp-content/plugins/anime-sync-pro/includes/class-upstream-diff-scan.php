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
	/** 最後一輪執行結果，供管理頁顯示「掃描是不是活的」。 */
	const REPORT_OPTION = 'anime_sync_upstream_diff_report';
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

	/**
	 * dHash 漢明距離小於等於這個值，視為同一張圖（上游只是重新編碼）。
	 *
	 * 64 bit 指紋。實務上重新編碼的差異通常是 0~2，換成另一張圖普遍在
	 * 20 以上。設 5 留了寬裕的安全邊際：寧可把「輕微修圖」也判成同一張
	 * 而不發消息，也不要發出一則「公開新視覺圖」但圖看起來一模一樣。
	 */
	const DHASH_SAME_THRESHOLD = 5;

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
			'trailer'  => 'trailer',
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
		$stats = $this->run( self::REQUESTS_PER_RUN );

		$this->save_run_report( $stats );
	}

	/**
	 * 把這一輪的執行結果存起來，供管理頁顯示。
	 *
	 * ★ 為什麼需要這個
	 *   run() 一直都有回傳 checked／seeded／events／skipped／errors，但排程
	 *   進入點原本把回傳值整包丟掉。結果是：AniList 連續失敗、撞到限流、
	 *   佇列空轉，畫面上通通看不出來——管理頁只有一片空白，分不清「沒有
	 *   新消息」和「掃描壞了」。
	 *
	 *   2026-08-20 那次 227 則假消息，正是因為沒有任何地方看得出掃描做了
	 *   什麼，錯了一整天才被發現。這裡把結果落地，管理頁才有東西可讀。
	 *
	 * ★ 只存最後一輪
	 *   目的是回答「現在還活著嗎、上次做了什麼」，不是做歷史報表。
	 *   要追歷史請看 Error_Logger，錯誤會另外寫進去。
	 *
	 * @param array $stats run() 的回傳值。
	 */
	private function save_run_report( array $stats ): void {
		$queue = get_option( self::QUEUE_OPTION, null );

		$report = [
			'time'      => time(),
			'checked'   => (int) ( $stats['checked'] ?? 0 ),
			'seeded'    => (int) ( $stats['seeded'] ?? 0 ),
			'events'    => (int) ( $stats['events'] ?? 0 ),
			'skipped'   => (int) ( $stats['skipped'] ?? 0 ),
			'errors'    => array_values( array_slice( (array) ( $stats['errors'] ?? [] ), 0, 5 ) ),
			'remaining' => is_array( $queue ) ? count( $queue ) : 0,
		];

		update_option( self::REPORT_OPTION, $report, false );

		// 有錯誤才寫 log，正常執行不製造噪音
		if ( $report['errors'] && class_exists( 'Anime_Sync_Error_Logger' ) ) {
			Anime_Sync_Error_Logger::warning(
				sprintf(
					'上游差異掃描出現 %d 個錯誤（檢查 %d 部、剩餘 %d 部）',
					count( $report['errors'] ),
					$report['checked'],
					$report['remaining']
				),
				[ 'errors' => $report['errors'] ]
			);
		}
	}

	/**
	 * 讀取最後一輪的執行結果。管理頁用。
	 *
	 * @return array|null 沒跑過就回 null。
	 */
	public static function get_run_report(): ?array {
		$report = get_option( self::REPORT_OPTION, null );

		return is_array( $report ) ? $report : null;
	}

	/**
	 * 掃描器的整體狀態，一次給齊。
	 *
	 * ★ 由掃描器自己回報，而不是讓管理頁各自查
	 *   「監看範圍」的判斷條件（連載中／未播出、要有數字型 AniList ID）
	 *   只存在 build_queue() 一處。若管理頁自己再寫一份 SQL，兩邊遲早會
	 *   漂移——畫面顯示 275 部、實際掃的卻是別的數字，那比沒有狀態列更糟。
	 *
	 * @return array{
	 *   last_sweep:int, next_cron:int, queue_remaining:int,
	 *   monitored:int, by_status:array<string,int>, sweep_interval_hours:int,
	 *   next_rebuild:int, report:array|null
	 * }
	 */
	public static function get_status(): array {
		global $wpdb;

		$by_status = [];
		$rows      = $wpdb->get_results(
			"SELECT st.meta_value AS st, COUNT(*) AS c
			   FROM {$wpdb->posts} p
			   JOIN {$wpdb->postmeta} al ON al.post_id = p.ID AND al.meta_key = 'anime_anilist_id'
			   JOIN {$wpdb->postmeta} st ON st.post_id = p.ID AND st.meta_key = 'anime_status'
			  WHERE p.post_type = 'anime'
			    AND p.post_status = 'publish'
			    AND al.meta_value REGEXP '^[0-9]+$'
			    AND st.meta_value IN ( 'NOT_YET_RELEASED', 'RELEASING' )
			  GROUP BY st.meta_value",
			ARRAY_A
		);

		$monitored = 0;
		foreach ( (array) $rows as $r ) {
			$by_status[ $r['st'] ] = (int) $r['c'];
			$monitored            += (int) $r['c'];
		}

		$queue      = get_option( self::QUEUE_OPTION, null );
		$last_sweep = (int) get_option( self::SWEEP_OPTION, 0 );

		return [
			'last_sweep'           => $last_sweep,
			'next_cron'            => (int) wp_next_scheduled( self::HOOK_HOURLY ),
			'queue_remaining'      => is_array( $queue ) ? count( $queue ) : 0,
			'monitored'            => $monitored,
			'by_status'            => $by_status,
			'sweep_interval_hours' => self::SWEEP_INTERVAL_HOURS,
			'next_rebuild'         => $last_sweep ? $last_sweep + self::SWEEP_INTERVAL_HOURS * HOUR_IN_SECONDS : 0,
			'report'               => self::get_run_report(),
		];
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
					bannerImage
					trailer { id site }
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
			'banner'     => (string) ( $media['bannerImage'] ?? '' ),
			'trailer'    => $this->format_trailer( $media['trailer'] ?? [] ),
		];

		$raw      = get_post_meta( $post_id, self::SNAPSHOT_META, true );
		$snapshot = is_string( $raw ) && '' !== $raw ? json_decode( $raw, true ) : null;

		// ── 首次掃描：只建立基準，不產生事件（見檔頭說明）。
		if ( ! is_array( $snapshot ) ) {
			if ( ! $dry_run ) {
				$this->maybe_update_banner( $post_id, $current['banner'] );
				$this->align_status_on_seed( $post_id, $current['status'] );
				$this->save_snapshot( $post_id, $current );
			}

			return [ 'seeded' => 1, 'events' => 0 ];
		}

		/*
		 * ── 舊快照缺欄位：視為「該欄位首次建立基準」，靜默補上、不產生事件。
		 *
		 * ★ 這道防護是實際事故的產物，不是預防性程式碼：
		 *   2026-08-19 種基準線時，查詢還沒有納入 trailer 欄位，因此快照裡
		 *   沒有這個鍵。隔天第一次真正比對時，diff() 的 `$old['trailer'] ?? ''`
		 *   把「缺欄位」當成「上游本來沒有 PV」，於是 227 部作品早就存在的
		 *   宣傳影片全被判定成「剛公開」，一次產生 227 則假消息並發出通知。
		 *
		 *   缺欄位代表「這個欄位是後來才加入監看的」，不代表「上游從無到有」。
		 *   兩者語意完全不同，卻在比對時被混為一談。這裡先把缺的鍵補成現值，
		 *   diff() 自然就不會判定為變更；下一輪起才是真正的比對。
		 *
		 *   有了這道防護，日後再新增任何監看欄位都不會重演同一場事故。
		 */
		foreach ( $current as $key => $value ) {
			if ( ! array_key_exists( $key, $snapshot ) ) {
				$snapshot[ $key ] = $value;
			}
		}

		/*
		 * 橫幅圖靜默更新，不產生事件。
		 *
		 * 它是頁首的背景裝飾，不是消息——上游換了背景圖不值得通知會員，
		 * 也不該佔用待審清單。而且它不影響搜尋縮圖、列表卡片與 OG 圖，
		 * 覆寫的風險遠低於封面。
		 */
		if ( ! $dry_run ) {
			$this->maybe_update_banner( $post_id, $current['banner'] );
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

			/*
			 * 宣傳影片：追加到既有清單，不覆寫。
			 * anime_trailer_url 本來就是分隔字串（模板以 ,，、;； 與換行切分），
			 * 舊 PV 留著才有「第 1 彈／第 2 彈」的完整歷程可看。
			 */
			if ( 'trailer' === $type ) {
				$this->append_trailer( $post_id, (string) $change['new'] );
			}

			/*
			 * ★ 純數值類型也要回寫站上欄位（2026-08-21 修正）。
			 *
			 *   原本只有 visual／trailer／banner 會回寫——因為那兩者有明確的
			 *   「把檔案抓下來存起來」動作，順手就寫了；schedule／status／
			 *   episodes 是純數值，被漏掉，變成「偵測到變更、發了消息、推進了
			 *   快照，但站上的資料沒有跟著更新」。
			 *
			 *   實例：《冰劍的魔術師將要統一世界 第二季》消息更新寫著「播出日期
			 *   確定為 2026 年 10 月 9 日」，資料欄位卻仍顯示 2026-10-01。全站
			 *   量測有 13 部日期不符、49 部集數錯誤或空白。
			 *
			 *   讀者看到的是自相矛盾的兩塊資訊，比沒有消息更糟。
			 */
			if ( ! $dry_run ) {
				$this->write_back_field( $post_id, $type, $change['new'] );
			}

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

				/*
				 * 內容比對：上游只是重新編碼時，圖看起來一模一樣，
				 * 發一則「公開新視覺圖」會是假消息。
				 * 判定為同一張就把剛下載的檔案刪掉、不產生事件，
				 * 但基準照樣推進（下一輪不需要再抓一次同樣的東西）。
				 */
				$same = $this->is_same_image(
					(string) get_attached_file( $attachment_id ),
					(string) get_attached_file( (int) get_post_thumbnail_id( $post_id ) )
				);

				if ( true === $same ) {
					wp_delete_attachment( $attachment_id, true );

					$next[ $this->snapshot_key( $type ) ] = $change['new'];

					continue;
				}

				/*
				 * $same === null 代表判斷不了（讀不到檔、格式不支援）。
				 * 這種情況保守處理：事件照建，但不自動發布，退回人工審核。
				 */
				$args['needs_review'] = ( null === $same );

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

				/*
				 * 文案能從新舊值機械推導出來的類型直接發布，不進待審清單。
				 * 只有 visual 需要人並排看圖判斷是不是誤報。
				 * 推導不出文案時（回傳空字串）就退回人工審核，
				 * 不發布一則空白說明的消息。
				 */
				// 圖片內容比對不出結果時退回人工審核（見上方 needs_review）。
				$needs_review = ! empty( $args['needs_review'] );

				if ( ! $needs_review && Anime_Sync_Anime_Events::is_auto_publish( $type ) ) {
					$auto = Anime_Sync_Anime_Events::auto_summary( $type, $change['old'], $change['new'] );

					if ( '' !== $auto ) {
						Anime_Sync_Anime_Events::publish( $recorded, $auto );
					}
				}
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

		/*
		 * 宣傳影片。AniList 的 trailer 是單一欄位，換了就代表官方換上新影片
		 * ——不同的 YouTube ID 必定是不同支片，不像封面會因為重新編碼而變網址。
		 */
		if ( '' !== $new['trailer'] && ( $old['trailer'] ?? '' ) !== $new['trailer'] ) {
			$changes['trailer'] = [ 'old' => $old['trailer'] ?? '', 'new' => $new['trailer'] ];
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
	 * 兩張圖在視覺上是不是同一張。
	 *
	 * ★ 這是「視覺圖能不能自動發布」的關鍵
	 *   AniList 有時只是重新編碼、換個檔名，圖的內容完全沒變。單看網址
	 *   分辨不出來，這正是原本必須人工並排看圖的原因。改為比對「圖片內容」
	 *   之後，機器就能自己判斷，人工審核可以取消。
	 *
	 * ★ 作法：dHash（difference hash）
	 *   縮成 9×8 灰階，逐列比較相鄰像素的亮度大小，得到 64 bit 指紋。
	 *   只看「相對明暗關係」，所以對重新編碼、壓縮品質、輕微縮放都不敏感，
	 *   但換成另一張圖時關係會大量改變。
	 *
	 *   比 md5 之類的檔案雜湊好在：重新編碼會讓檔案雜湊完全不同，
	 *   dHash 卻幾乎不變——這正是我們要的區分能力。
	 *
	 * @param string $path_a 本機檔案路徑。
	 * @param string $path_b 本機檔案路徑。
	 * @return bool|null true=同一張、false=不同張；無法判斷時回傳 null。
	 */
	private function is_same_image( string $path_a, string $path_b ): ?bool {
		$a = $this->dhash( $path_a );
		$b = $this->dhash( $path_b );

		if ( null === $a || null === $b ) {
			return null;
		}

		$distance = 0;

		for ( $i = 0; $i < 64; $i++ ) {
			if ( $a[ $i ] !== $b[ $i ] ) {
				$distance++;
			}
		}

		return $distance <= self::DHASH_SAME_THRESHOLD;
	}

	/**
	 * 計算 dHash，回傳 64 個字元的 0/1 字串。
	 *
	 * @return string|null 讀不到或不是圖片時回傳 null（呼叫端據此走保守路徑）。
	 */
	private function dhash( string $path ): ?string {
		if ( ! $path || ! file_exists( $path ) || ! function_exists( 'imagecreatefromstring' ) ) {
			return null;
		}

		$data = file_get_contents( $path );

		if ( false === $data ) {
			return null;
		}

		$src = @imagecreatefromstring( $data );

		unset( $data );

		if ( ! $src ) {
			return null;
		}

		$small = imagecreatetruecolor( 9, 8 );

		if ( ! $small ) {
			imagedestroy( $src );

			return null;
		}

		imagecopyresampled( $small, $src, 0, 0, 0, 0, 9, 8, imagesx( $src ), imagesy( $src ) );
		imagedestroy( $src );

		$hash = '';

		for ( $y = 0; $y < 8; $y++ ) {
			$prev = null;

			for ( $x = 0; $x < 9; $x++ ) {
				$rgb  = imagecolorat( $small, $x, $y );
				$gray = ( ( $rgb >> 16 & 0xFF ) * 299 + ( $rgb >> 8 & 0xFF ) * 587 + ( $rgb & 0xFF ) * 114 ) / 1000;

				if ( null !== $prev ) {
					$hash .= $gray > $prev ? '1' : '0';
				}

				$prev = $gray;
			}
		}

		imagedestroy( $small );

		return 64 === strlen( $hash ) ? $hash : null;
	}

	/**
	 * AniList 的 trailer 轉成可比對的網址。
	 *
	 * 只收 youtube——站上的 anime_trailer_url 是 YouTube 網址格式，
	 * dailymotion 等其他站台混進去模板解析不了。
	 */
	private function format_trailer( array $t ): string {
		$id   = isset( $t['id'] ) ? (string) $t['id'] : '';
		$site = isset( $t['site'] ) ? strtolower( (string) $t['site'] ) : '';

		if ( '' === $id || 'youtube' !== $site ) {
			return '';
		}

		return 'https://www.youtube.com/watch?v=' . $id;
	}

	/**
	 * 把 schedule／status／episodes 的新值回寫到站上的 meta 欄位。
	 *
	 * ★ 為什麼要有這一步
	 *   掃描器原本只寫事件、不動資料，導致「消息說 10 月 9 日開播、資料欄位
	 *   還是 10 月 1 日」這種自相矛盾。visual／trailer／banner 因為有下載檔案
	 *   的動作而順手寫了，純數值的三種被漏掉。
	 *
	 * ★ 日期的精度規則
	 *   快照用可變精度（2026 / 2026-10 / 2026-10-09），meta 用 8 碼純數字。
	 *   轉換交給 wxacg_fuzzy_date_to_meta()：年月日俱全才寫，精度不足就不動。
	 *   否則「上游只知道 2026 年 10 月」會被硬補成 20261001，變成假的精確日期
	 *   ——全站已經有 41 部處於這種狀態，不能再製造更多。
	 *
	 * ★ 尊重 anime_locked_fields
	 *   比照 append_trailer() 與 maybe_update_banner()：使用者手動鎖定的欄位
	 *   不覆寫。
	 *
	 * @param int    $post_id 文章 ID。
	 * @param string $type    事件類型。
	 * @param mixed  $new     快照格式的新值。
	 */
	private function write_back_field( int $post_id, string $type, $new ): void {
		$map = [
			'schedule' => 'anime_start_date',
			'status'   => 'anime_status',
			'episodes' => 'anime_episodes',
		];

		if ( ! isset( $map[ $type ] ) ) {
			return;
		}

		$meta_key = $map[ $type ];
		$locked   = get_post_meta( $post_id, 'anime_locked_fields', true );

		if ( is_array( $locked ) && in_array( $meta_key, $locked, true ) ) {
			return;
		}

		if ( 'schedule' === $type ) {
			$value = wxacg_fuzzy_date_to_meta( (string) $new );

			// 精度不足（只有年或年月）就不動，避免補出假的日
			if ( '' === $value ) {
				return;
			}
		} elseif ( 'episodes' === $type ) {
			$value = (int) $new;

			if ( $value <= 0 ) {
				return;
			}

			$value = (string) $value;
		} else {
			$value = trim( (string) $new );

			if ( '' === $value ) {
				return;
			}
		}

		if ( (string) get_post_meta( $post_id, $meta_key, true ) === $value ) {
			return;
		}

		update_post_meta( $post_id, $meta_key, $value );
	}

	/**
	 * 把新的宣傳影片追加進 anime_trailer_url。
	 *
	 * 不覆寫：舊 PV 留著才看得到「第 1 彈 → 第 2 彈」的歷程，
	 * 與視覺圖採同一個原則（只增不減）。
	 */
	private function append_trailer( int $post_id, string $url ): void {
		if ( '' === $url ) {
			return;
		}

		$locked = get_post_meta( $post_id, 'anime_locked_fields', true );

		if ( is_array( $locked ) && in_array( 'anime_trailer_url', $locked, true ) ) {
			return;
		}

		$current = (string) get_post_meta( $post_id, 'anime_trailer_url', true );

		// 已經在清單裡就不重複追加（人工先貼過的情況）。
		if ( '' !== $current && false !== strpos( $current, $url ) ) {
			return;
		}

		update_post_meta(
			$post_id,
			'anime_trailer_url',
			'' === trim( $current ) ? $url : $current . "\n" . $url
		);
	}

	/**
	 * 建立基準時，把站上的播出狀態對齊上游。
	 *
	 * ★ 為什麼需要這一步
	 *   首次掃描刻意「只建立基準、不產生事件」，避免自匯入以來的舊帳
	 *   一次淹掉待審清單。但如果 seed 當下站上值本來就跟上游不同，
	 *   那個差異會被寫進快照後永遠消失——之後每輪比對都是
	 *   「上游 == 快照」，掃描認為一切正常，站上卻一直是錯的。
	 *
	 *   實例：#582 魔女之谷的夜晚、#504 航海王 女英雄們的故事，
	 *   上游 FINISHED、站上 NOT_YET_RELEASED。而每日同步的佇列只收
	 *   RELEASING 的作品，這兩部因為狀態是 NOT_YET_RELEASED 而永遠
	 *   排不進去——狀態錯了導致修不到，修不到所以狀態一直錯。
	 *
	 *   狀態不像視覺圖或消息，它沒有「發生日期」可言，對齊是資料修正
	 *   不是新聞，所以只寫入、不產生事件。
	 *
	 * 其他欄位（封面、日期、集數）不在此對齊：它們的站上值可能來自
	 * Bangumi 或人工編輯，上游優先的假設不成立。狀態的唯一來源是 AniList。
	 */
	private function align_status_on_seed( int $post_id, string $upstream_status ): void {
		if ( '' === $upstream_status ) {
			return;
		}

		$locked = get_post_meta( $post_id, 'anime_locked_fields', true );

		if ( is_array( $locked ) && in_array( 'anime_status', $locked, true ) ) {
			return;
		}

		$current = (string) get_post_meta( $post_id, 'anime_status', true );

		if ( $current === $upstream_status ) {
			return;
		}

		update_post_meta( $post_id, 'anime_status', $upstream_status );

		if ( class_exists( 'Anime_Sync_Error_Logger' ) ) {
			Anime_Sync_Error_Logger::info(
				sprintf( '差異掃描建立基準：播出狀態對齊 %s → %s', $current ?: '（空）', $upstream_status ),
				[ 'post_id' => $post_id ]
			);
		}
	}

	/**
	 * 更新橫幅圖（靜默，不產生事件）。
	 *
	 * 空值不覆寫——上游暫時抓不到就清掉現有橫幅是淨損失。
	 * 尊重 ACF 的欄位鎖定，比照封面的處理。
	 */
	private function maybe_update_banner( int $post_id, string $banner_url ): void {
		if ( '' === $banner_url ) {
			return;
		}

		if ( (string) get_post_meta( $post_id, 'anime_banner_image', true ) === $banner_url ) {
			return;
		}

		$locked = get_post_meta( $post_id, 'anime_locked_fields', true );

		if ( is_array( $locked ) && in_array( 'anime_banner_image', $locked, true ) ) {
			return;
		}

		update_post_meta( $post_id, 'anime_banner_image', esc_url_raw( $banner_url ) );
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
