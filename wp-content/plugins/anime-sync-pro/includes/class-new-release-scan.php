<?php
/**
 * AniList 新登錄作品掃描
 *
 * 問題背景：
 *   既有的季度自動匯入（Anime_Sync_Cron_Manager::run_season_auto_import）
 *   以 season + seasonYear 查詢，只看得到「當季」。但剛宣布動畫化的作品
 *   AniList 常常 seasonYear 是 null——片商只先發製作消息，檔期未定。
 *   這類條目用季度查詢永遠查不到，要等它進入當季那一週才會被抓進來，
 *   通常已經晚了好幾個月。
 *
 * 做法：
 *   改以 sort: ID_DESC 由新到舊翻頁，翻到 id <= 水位線就停。AniList 的
 *   media id 是遞增發號，新登錄的條目 id 必定大於站上任何既有作品，
 *   因此「id > 水位線」就等於「站上沒有的新條目」，完全不依賴季度欄位。
 *
 * 為什麼在 API 端就過濾：
 *   不過濾的話，新登錄條目約六成是中國 ONA、另有一批 MUSIC（MV，不是動畫），
 *   抓回本地再篩等於白白消耗 AniList 額度與匯入時間。countryOfOrigin
 *   與 format_not_in 都是 AniList 原生支援的參數，直接讓上游篩。
 *
 * 匯入行為：
 *   逐筆呼叫 Anime_Sync_Import_Manager::import_single()，bangumi_id 傳 null
 *   ——Bangumi ID 由 Anime_Sync_API_Handler::get_core_anime_data() 內部的
 *   id_mapper 自動解析，此處不重複實作。新建文章一律 draft（見
 *   class-import-manager.php 的 post_status 判斷），等人工在審核佇列複核發布。
 *
 *   匯入後排一次 anime_sync_enrich_post（非同步，不拖慢本輪掃描）。
 *
 *   這一步不能省：import_single() 寫進去的 staff / cast 是 AniList 版本
 *   （parse_staff/parse_cast 標 source: anilist），要 enrich_anime_data()
 *   才會換成 Bangumi 版本。而既有 cron 沒有任何一個會做這個替換——每日更新
 *   管狀態／評分／MAL ID，15 分鐘那輪管主題曲與集數，唯一會排 enrich 的是
 *   class-cron-manager.php 的「MAL ID 從無到有」分支，條件太窄且只跑 publish。
 *   不排的話，這些草稿的 staff / cast 會永遠停在 AniList 版本。
 *
 *   剛宣布動畫化的作品 Bangumi 多半也還沒有 staff / cast，第一次 enrich
 *   大概率抓到空的——但 enrich 端的 keep_if_fewer() 遇到空陣列直接不覆蓋，
 *   不會洗掉既有資料，重複執行安全。
 *
 * 用法：
 *   wp anime scan-new-releases --dry-run     # 只列出會抓什麼，不寫入
 *   wp anime scan-new-releases               # 實際匯入成草稿
 *   wp anime scan-new-releases --limit=10    # 本次最多匯入 N 筆
 *
 * @package Anime_Sync_Pro
 */

defined( 'ABSPATH' ) || exit;

class Anime_Sync_New_Release_Scan {

	const HOOK_DAILY = 'anime_sync_new_release_scan';

	/** 水位線：上次掃描處理過的最大 AniList ID */
	const WATERMARK_OPTION = 'anime_sync_last_scanned_anilist_id';

	/** 上一輪執行摘要，供後台／CLI 查看 */
	const LAST_RUN_OPTION = 'anime_sync_last_new_release_scan';

	const LOCK_KEY = 'anime_sync_lock_new_release_scan';
	const LOCK_TTL = 1800;

	/**
	 * 翻頁上限。
	 *
	 * 水位線機制正常時，一天的新登錄量遠不到一頁。設上限是為了防呆：
	 * 萬一水位線被清成 0，不至於把 AniList 整個站往回撈。
	 */
	const MAX_PAGES = 5;
	const PER_PAGE  = 30;

	/**
	 * 熔斷門檻：連續失敗達此數量即中止本輪。
	 * 比照 Anime_Sync_Cron_Manager::DAILY_ABORT_AFTER_FAILURES，
	 * 用於上游整個不可用時避免逐筆空打。
	 */
	const ABORT_AFTER_FAILURES = 5;

	private ?Anime_Sync_Import_Manager $import_manager;
	private Anime_Sync_Error_Logger    $logger;
	private ?Anime_Sync_Rate_Limiter   $rate_limiter;

	public function __construct( ?Anime_Sync_Import_Manager $import_manager = null ) {
		$this->import_manager = $import_manager;
		$this->logger         = new Anime_Sync_Error_Logger();
		$this->rate_limiter   = class_exists( 'Anime_Sync_Rate_Limiter' )
			? Anime_Sync_Rate_Limiter::get_instance()
			: null;

		add_action( self::HOOK_DAILY, [ $this, 'run_scheduled' ] );
	}

	// =========================================================================
	// 排程
	// =========================================================================

	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::HOOK_DAILY ) ) {
			$next = strtotime( 'tomorrow 04:30:00' );
			if ( $next === false ) {
				$next = time() + DAY_IN_SECONDS;
			}
			wp_schedule_event( $next, 'daily', self::HOOK_DAILY );
		}
	}

	public static function unschedule(): void {
		$ts = wp_next_scheduled( self::HOOK_DAILY );
		if ( $ts ) {
			wp_unschedule_event( $ts, self::HOOK_DAILY );
		}
		wp_clear_scheduled_hook( self::HOOK_DAILY );
	}

	/** cron 進入點；實際邏輯在 run() */
	public function run_scheduled(): void {
		$this->run();
	}

	// =========================================================================
	// 主流程
	// =========================================================================

	/**
	 * 掃描並匯入新登錄作品。
	 *
	 * @param array{dry_run?:bool,limit?:int} $args
	 * @return array<string,mixed> 本輪摘要
	 */
	public function run( array $args = [] ): array {
		$dry_run = ! empty( $args['dry_run'] );
		$limit   = isset( $args['limit'] ) ? max( 0, (int) $args['limit'] ) : 0;

		// dry-run 唯讀，不參與鎖，才不會卡住正常排程
		if ( ! $dry_run ) {
			if ( get_transient( self::LOCK_KEY ) ) {
				$this->logger->log( 'warning', '新登錄掃描：已有另一個程序在執行，本次跳過' );
				return [ 'success' => false, 'message' => '已鎖定，跳過', 'imported' => 0 ];
			}
			set_transient( self::LOCK_KEY, 1, self::LOCK_TTL );
		}

		try {
			return $this->run_inner( $dry_run, $limit );
		} finally {
			if ( ! $dry_run ) {
				delete_transient( self::LOCK_KEY );
			}
		}
	}

	private function run_inner( bool $dry_run, int $limit ): array {
		if ( class_exists( 'Anime_Sync_Performance' ) ) {
			Anime_Sync_Performance::set_time_limit( 600 );
			Anime_Sync_Performance::increase_memory_limit( '512M' );
		}

		$watermark = self::get_watermark();

		if ( $watermark <= 0 ) {
			$this->logger->log( 'error', '新登錄掃描：無法決定水位線，中止（站上找不到任何 anime_anilist_id）' );
			return [ 'success' => false, 'message' => '無法決定水位線', 'imported' => 0 ];
		}

		$media = $this->fetch_new_media( $watermark );

		if ( $media === null ) {
			$this->logger->log( 'error', '新登錄掃描：AniList 取得失敗，水位線不推進' );
			return [ 'success' => false, 'message' => 'AniList 取得失敗', 'imported' => 0 ];
		}

		if ( empty( $media ) ) {
			$this->logger->log( 'info', '新登錄掃描：沒有新條目', [ 'watermark' => $watermark ] );
			$summary = [
				'success'   => true,
				'watermark' => $watermark,
				'found'     => 0,
				'imported'  => 0,
				'skipped'   => 0,
				'failed'    => 0,
			];
			if ( ! $dry_run ) {
				update_option( self::LAST_RUN_OPTION, $summary + [ 'ran_at' => current_time( 'mysql' ) ], false );
			}
			return $summary;
		}

		// 由舊到新匯入，中途中止時水位線才能停在一個乾淨的位置
		usort( $media, static fn( $a, $b ) => (int) $a['id'] <=> (int) $b['id'] );

		if ( $limit > 0 && count( $media ) > $limit ) {
			$media = array_slice( $media, 0, $limit );
		}

		$max_seen  = $watermark;
		$imported  = 0;
		$skipped   = 0;
		$failed    = 0;
		$consec    = 0;
		$rows      = [];
		$aborted   = false;

		foreach ( $media as $item ) {
			$anilist_id = (int) ( $item['id'] ?? 0 );
			if ( $anilist_id <= 0 ) {
				continue;
			}

			$title = $item['title']['romaji']
				?: ( $item['title']['native'] ?? "ID {$anilist_id}" );

			if ( $dry_run ) {
				$rows[] = [
					'anilist_id' => $anilist_id,
					'title'      => $title,
					'format'     => $item['format'] ?? '',
					'status'     => $item['status'] ?? '',
					'season'     => trim( ( $item['season'] ?? '' ) . ' ' . ( $item['seasonYear'] ?? '' ) ),
				];
				continue;
			}

			if ( ! $this->import_manager ) {
				$this->logger->log( 'error', '新登錄掃描：Import Manager 未初始化，中止' );
				return [ 'success' => false, 'message' => 'Import Manager 未初始化', 'imported' => $imported ];
			}

			if ( $this->rate_limiter ) {
				$this->rate_limiter->wait_if_needed( 'anilist' );
			}

			$result = $this->import_manager->import_single( $anilist_id, null, 'new_release_scan' );

			if ( ! empty( $result['skipped'] ) ) {
				$skipped++;
				$consec = 0;
			} elseif ( ! empty( $result['success'] ) ) {
				$imported++;
				$consec = 0;
				$rows[] = [
					'anilist_id' => $anilist_id,
					'title'      => $result['title'] ?? $title,
					'post_id'    => $result['post_id'] ?? 0,
				];

				/*
				 * 排一次 enrich，把 staff / cast 由 AniList 換成 Bangumi 版本。
				 * 比照 class-cron-manager.php 的既有寫法：非同步單次事件，
				 * 不在本輪掃描裡同步打 Bangumi／Jikan／AnimeThemes／Wikipedia。
				 */
				$new_post_id = (int) ( $result['post_id'] ?? 0 );
				if ( $new_post_id > 0 && ! wp_next_scheduled( 'anime_sync_enrich_post', [ $new_post_id ] ) ) {
					wp_schedule_single_event( time() + 60, 'anime_sync_enrich_post', [ $new_post_id ] );
				}
			} else {
				$failed++;
				$consec++;
				$this->logger->log( 'warning', '新登錄掃描：單筆匯入失敗', [
					'anilist_id' => $anilist_id,
					'title'      => $title,
					'error'      => $result['message'] ?? '未知錯誤',
				] );

				// 連續失敗代表上游或本地整個出問題，繼續打下去只是浪費額度
				if ( $consec >= self::ABORT_AFTER_FAILURES ) {
					$aborted = true;
					$this->logger->log( 'error', '新登錄掃描：連續失敗達門檻，中止本輪', [
						'consecutive_failures' => $consec,
						'stopped_at'           => $anilist_id,
					] );
					break;
				}
			}

			// 已處理過的才推進水位線；中止時停在這裡，未處理的下輪再撈
			$max_seen = max( $max_seen, $anilist_id );
		}

		$summary = [
			'success'   => ! $aborted,
			'dry_run'   => $dry_run,
			'watermark' => $watermark,
			'found'     => count( $media ),
			'imported'  => $imported,
			'skipped'   => $skipped,
			'failed'    => $failed,
			'aborted'   => $aborted,
			'rows'      => $rows,
		];

		if ( $dry_run ) {
			return $summary;
		}

		if ( $max_seen > $watermark ) {
			update_option( self::WATERMARK_OPTION, $max_seen, false );
			$summary['new_watermark'] = $max_seen;
		}

		update_option( self::LAST_RUN_OPTION, [
			'ran_at'    => current_time( 'mysql' ),
			'watermark' => $watermark,
			'found'     => $summary['found'],
			'imported'  => $imported,
			'skipped'   => $skipped,
			'failed'    => $failed,
			'aborted'   => $aborted,
		], false );

		$this->logger->log( $aborted ? 'warning' : 'info', '新登錄掃描完成', [
			'watermark'     => $watermark,
			'new_watermark' => $summary['new_watermark'] ?? $watermark,
			'found'         => $summary['found'],
			'imported'      => $imported,
			'skipped'       => $skipped,
			'failed'        => $failed,
		] );

		return $summary;
	}

	// =========================================================================
	// 水位線
	// =========================================================================

	/**
	 * 取得水位線。
	 *
	 * option 不存在時（首次執行）改用站上實際最大的 anime_anilist_id，
	 * 避免從 0 起算把整個 AniList 往回撈。
	 */
	public static function get_watermark(): int {
		$stored = (int) get_option( self::WATERMARK_OPTION, 0 );
		if ( $stored > 0 ) {
			return $stored;
		}

		global $wpdb;

		$max = (int) $wpdb->get_var(
			"SELECT MAX(CAST(meta_value AS UNSIGNED))
			   FROM {$wpdb->postmeta}
			  WHERE meta_key = 'anime_anilist_id'
			    AND meta_value REGEXP '^[0-9]+$'"
		);

		if ( $max > 0 ) {
			update_option( self::WATERMARK_OPTION, $max, false );
		}

		return $max;
	}

	// =========================================================================
	// AniList
	// =========================================================================

	/**
	 * 取回 id 大於水位線的新條目。
	 *
	 * @return array<int,array<string,mixed>>|null 上游失敗回 null（與「沒有新條目」的空陣列區分）
	 */
	private function fetch_new_media( int $watermark ): ?array {
		$query = <<<'GQL'
		query ($page: Int, $perPage: Int) {
			Page(page: $page, perPage: $perPage) {
				pageInfo { hasNextPage }
				media(type: ANIME, countryOfOrigin: "JP", format_not_in: [MUSIC], isAdult: false, sort: ID_DESC) {
					id
					title { romaji native english }
					status
					format
					season
					seasonYear
				}
			}
		}
		GQL;

		$collected = [];
		$page      = 1;
		$any_ok    = false;

		do {
			if ( $this->rate_limiter ) {
				$this->rate_limiter->wait_if_needed( 'anilist' );
			}

			$body = $this->anilist_request( $query, [
				'page'    => $page,
				'perPage' => self::PER_PAGE,
			] );

			if ( $body === null ) {
				// 第一頁就失敗代表整個取不到；後續頁失敗則保留已收到的部分
				return $any_ok ? $collected : null;
			}

			$any_ok    = true;
			$page_data = $body['data']['Page'] ?? [];
			$media     = $page_data['media'] ?? [];

			if ( empty( $media ) ) {
				break;
			}

			$reached_watermark = false;

			foreach ( $media as $item ) {
				$id = (int) ( $item['id'] ?? 0 );

				// ID_DESC 排序，一旦掉到水位線以下，後面全是既有的舊條目
				if ( $id <= $watermark ) {
					$reached_watermark = true;
					break;
				}

				$collected[] = $item;
			}

			if ( $reached_watermark ) {
				break;
			}

			$has_next = ! empty( $page_data['pageInfo']['hasNextPage'] );
			$page++;

		} while ( $has_next && $page <= self::MAX_PAGES );

		return $collected;
	}

	/**
	 * @return array<string,mixed>|null 失敗回 null
	 */
	private function anilist_request( string $query, array $variables ): ?array {
		$response = wp_remote_post( 'https://graphql.anilist.co', [
			'timeout' => 30,
			'headers' => [
				'Content-Type' => 'application/json',
				'Accept'       => 'application/json',
				'User-Agent'   => class_exists( 'Anime_Sync_API_Handler' )
					? Anime_Sync_API_Handler::USER_AGENT
					: 'weixiaoacg-Project/1.0 (https://weixiaoacg.com)',
			],
			'body' => wp_json_encode( [
				'query'     => $query,
				'variables' => $variables,
			] ),
		] );

		if ( is_wp_error( $response ) ) {
			$this->logger->log( 'error', '新登錄掃描：AniList 請求失敗', [
				'error' => $response->get_error_message(),
			] );
			return null;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code !== 200 || ! is_array( $body ) ) {
			$this->logger->log( 'error', '新登錄掃描：AniList 回應異常', [
				'http_code' => $code,
			] );
			return null;
		}

		if ( ! empty( $body['errors'] ) ) {
			$this->logger->log( 'error', '新登錄掃描：AniList 回傳錯誤', [
				'errors' => wp_json_encode( $body['errors'] ),
			] );
			return null;
		}

		return $body;
	}
}

/**
 * 註冊 WP-CLI 指令
 */
if ( defined( 'WP_CLI' ) && WP_CLI ) {

	WP_CLI::add_command( 'anime scan-new-releases', function ( $args, $assoc_args ) {
		$dry_run = isset( $assoc_args['dry-run'] );
		$limit   = isset( $assoc_args['limit'] ) ? (int) $assoc_args['limit'] : 0;

		$watermark = Anime_Sync_New_Release_Scan::get_watermark();

		WP_CLI::log( $dry_run
			? '=== AniList 新登錄掃描（唯讀，不寫入）==='
			: '=== AniList 新登錄掃描（會匯入成草稿）===' );
		WP_CLI::log( '目前水位線：' . $watermark );
		WP_CLI::log( '過濾條件　：日本作品、排除 MUSIC、排除成人' );
		if ( $limit > 0 ) {
			WP_CLI::log( '本次上限　：--limit=' . $limit );
		}
		WP_CLI::log( '' );

		/*
		 * CLI 下 import_manager 由 plugins_loaded 建立的那組實例負責，
		 * 這裡重新組一份相同依賴——與 anime-sync-pro.php 的組法一致。
		 */
		$import_manager = null;

		if ( ! $dry_run ) {
			$rate_limiter = class_exists( 'Anime_Sync_Rate_Limiter' )
				? Anime_Sync_Rate_Limiter::get_instance()
				: null;
			$id_mapper = class_exists( 'Anime_Sync_ID_Mapper' )
				? new Anime_Sync_ID_Mapper( $rate_limiter )
				: null;
			$converter = class_exists( 'Anime_Sync_CN_Converter' )
				? new Anime_Sync_CN_Converter()
				: null;
			$api_handler = class_exists( 'Anime_Sync_API_Handler' )
				? new Anime_Sync_API_Handler( $rate_limiter, $id_mapper )
				: null;

			if ( ! $api_handler || ! $converter || ! class_exists( 'Anime_Sync_Import_Manager' ) ) {
				WP_CLI::error( '無法建立 Import Manager，請檢查外掛相依。' );
			}

			$import_manager = new Anime_Sync_Import_Manager( $api_handler, $converter );
		}

		$scanner = new Anime_Sync_New_Release_Scan( $import_manager );
		$summary = $scanner->run( [ 'dry_run' => $dry_run, 'limit' => $limit ] );

		if ( empty( $summary['success'] ) && empty( $summary['found'] ) ) {
			WP_CLI::error( $summary['message'] ?? '掃描失敗' );
		}

		if ( empty( $summary['found'] ) ) {
			WP_CLI::success( '沒有新條目，水位線維持 ' . $watermark );
			return;
		}

		if ( ! empty( $summary['rows'] ) ) {
			WP_CLI::log( $dry_run ? '── 會被匯入的條目 ──' : '── 本次新增的草稿 ──' );
			foreach ( $summary['rows'] as $r ) {
				WP_CLI::log( sprintf(
					'  %-8d %-42s %s',
					$r['anilist_id'],
					mb_strimwidth( (string) $r['title'], 0, 42, '…' ),
					$dry_run
						? trim( ( $r['format'] ?? '' ) . ' ' . ( $r['status'] ?? '' ) . ' ' . ( $r['season'] ?? '' ) )
						: ( '→ post #' . ( $r['post_id'] ?? 0 ) )
				) );
			}
			WP_CLI::log( '' );
		}

		WP_CLI::log( '─────────────────────────────' );
		WP_CLI::log( '掃到新條目  : ' . $summary['found'] );

		if ( $dry_run ) {
			WP_CLI::success( '唯讀掃描完成，未寫入任何資料。拿掉 --dry-run 即實際匯入。' );
			return;
		}

		WP_CLI::log( '匯入成草稿  : ' . $summary['imported'] );
		WP_CLI::log( '跳過        : ' . $summary['skipped'] );
		WP_CLI::log( '失敗        : ' . $summary['failed'] );
		WP_CLI::log( '新水位線    : ' . ( $summary['new_watermark'] ?? $watermark ) );

		if ( ! empty( $summary['aborted'] ) ) {
			WP_CLI::warning( '因連續失敗達門檻而中止，未處理的條目下輪會再撈。' );
			return;
		}

		WP_CLI::success( '掃描完成。新草稿請至審核佇列複核後發布。' );
	} );
}
