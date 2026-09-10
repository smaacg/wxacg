<?php
/**
 * MAL 動畫化決定掃描
 *
 * 問題背景：
 *   站上原有的「新登錄掃描」（class-new-release-scan.php）以 AniList 的
 *   sort: ID_DESC 加水位線抓新條目。2026-09 AniList 對第三方全面回 403 之後
 *   那條路整個停擺，新宣布動畫化的作品不再自動進站。
 *
 * 為什麼另開一個類別而不是給 New_Release_Scan 加後備：
 *   兩邊的偵測機制根本不同。AniList 靠「media id 遞增」這個性質做水位線；
 *   MAL API v2 沒有「列出最新建立的條目」這種端點，只能拿
 *   /v2/anime/ranking?ranking_type=upcoming 的清單跟上一次的比對。
 *   把兩套機制塞進同一個類別，只會讓每個方法都要先判斷「現在是哪一種」。
 *   站上既有的三支掃描器（新登錄／未播出班底輪掃／上游差異）本來就是
 *   各自獨立的類別，這裡沿用同一個結構。
 *
 * ★ 為什麼 upcoming 這個端點夠用
 *   實測 2026-09-11：回傳 564 部，其中 362 部沒有 start_season——也就是
 *   「動畫化決定、檔期未定」那一類，正是最需要提早建檔的。前段包含
 *   咒術迴戰死滅迴游後篇、轉生史萊姆第四季、EVA 新作系列、搖曳露營第四季。
 *   季度端點抓不到這些（沒有 start_season 就不屬於任何一季）。
 *
 * ★ 第一次執行只建立基準，不匯入
 *   與 class-upstream-diff-scan.php 同一個理由。實測站上沒有的存量是 373 部，
 *   一次倒進審核佇列只會讓那個清單失去可用性（站上本來就已經有 651 篇草稿）。
 *   存量要補的話走後台「動漫匯入 → MAL 匯入 → 動畫化決定」自己挑，
 *   那裡不設門檻、看得到完整清單。
 *
 * ★ 收藏數門檻的由來
 *   不是憑感覺定的。站上「已經匯入」的 191 部 upcoming 作品中，收藏數低於
 *   1000 的只有 7%（P10 = 1,317、中位數 = 11,611）。也就是說這條線本來就
 *   接近站上既有的選片行為，只是把它寫下來。可用 filter 調整。
 *
 * 用法：
 *   wp anime scan-mal-upcoming --dry-run   # 只列出會抓什麼，不寫入
 *   wp anime scan-mal-upcoming             # 實際匯入成草稿
 *   wp anime scan-mal-upcoming --limit=10  # 本次最多匯入 N 筆
 *   wp anime scan-mal-upcoming --reseed    # 重建基準（不匯入）
 *
 * @package Anime_Sync_Pro
 */

defined( 'ABSPATH' ) || exit;

class Anime_Sync_MAL_Upcoming_Scan {

	const HOOK_DAILY = 'anime_sync_mal_upcoming_scan';

	/** 已知的 MAL ID 清單（基準）。存 option，autoload = no。 */
	const SEEN_OPTION = 'anime_sync_mal_upcoming_seen';

	/** 上一輪執行摘要，供後台／CLI 查看 */
	const LAST_RUN_OPTION = 'anime_sync_mal_upcoming_last_run';

	const LOCK_KEY = 'anime_sync_lock_mal_upcoming_scan';
	const LOCK_TTL = 1800;

	const ENDPOINT = 'https://api.myanimelist.net/v2/anime/ranking';

	/** 一頁 500 是 MAL 的上限；實測全部只有 564 部，兩頁就抓完。 */
	const PER_PAGE  = 500;
	const MAX_PAGES = 4;

	/**
	 * 收藏人數門檻。低於此值的不自動匯入（理由見檔頭）。
	 * 要調整不必改程式：add_filter( 'anime_sync_mal_upcoming_min_members', fn() => 500 );
	 */
	const MIN_MEMBERS = 1000;

	/**
	 * 單次最多匯入幾部。
	 *
	 * 穩態下一天的新宣布通常是個位數，這個上限是防呆——萬一 MAL 一次
	 * 補進大量條目（或基準被誤清），不至於在一次排程裡跑掉幾百部。
	 * 超出的部分不會遺失：它們不會被記進基準，下一輪自然再撈到。
	 */
	const MAX_IMPORT_PER_RUN = 20;

	/** 這些不是作品，是宣傳素材 */
	const EXCLUDE_TYPES = [ 'music', 'pv', 'cm' ];

	private ?Anime_Sync_Import_Manager $import_manager;
	private Anime_Sync_Error_Logger    $logger;

	public function __construct( ?Anime_Sync_Import_Manager $import_manager = null ) {
		$this->import_manager = $import_manager;
		$this->logger         = new Anime_Sync_Error_Logger();

		add_action( self::HOOK_DAILY, [ $this, 'run_scheduled' ] );
	}

	// =========================================================================
	// 排程
	// =========================================================================

	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::HOOK_DAILY ) ) {
			// 05:00：錯開 AniList 新登錄掃描的 04:30，兩支不要擠在同一次 cron 觸發
			$next = strtotime( 'tomorrow 05:00:00' );
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

	public function run_scheduled(): void {
		$this->run();
	}

	// =========================================================================
	// 主流程
	// =========================================================================

	/**
	 * @param array{dry_run?:bool,limit?:int,reseed?:bool} $args
	 * @return array<string,mixed>
	 */
	public function run( array $args = [] ): array {
		$dry_run = ! empty( $args['dry_run'] );

		// dry-run 唯讀，不參與鎖，才不會卡住正常排程
		if ( ! $dry_run ) {
			if ( get_transient( self::LOCK_KEY ) ) {
				$this->logger->log( 'warning', 'MAL 動畫化決定掃描：已有另一個程序在執行，本次跳過' );
				return [ 'success' => false, 'message' => '已鎖定，跳過', 'imported' => 0 ];
			}
			set_transient( self::LOCK_KEY, 1, self::LOCK_TTL );
		}

		try {
			return $this->run_inner( $dry_run, (int) ( $args['limit'] ?? 0 ), ! empty( $args['reseed'] ) );
		} finally {
			if ( ! $dry_run ) {
				delete_transient( self::LOCK_KEY );
			}
		}
	}

	private function run_inner( bool $dry_run, int $limit, bool $reseed ): array {
		if ( class_exists( 'Anime_Sync_Performance' ) ) {
			Anime_Sync_Performance::set_time_limit( 600 );
			Anime_Sync_Performance::increase_memory_limit( '512M' );
		}

		$upcoming = $this->fetch_upcoming();

		if ( $upcoming === null ) {
			$this->logger->log( 'error', 'MAL 動畫化決定掃描：清單取得失敗，基準不推進' );
			return [ 'success' => false, 'message' => 'MAL 取得失敗', 'imported' => 0 ];
		}

		if ( empty( $upcoming ) ) {
			$this->logger->log( 'warning', 'MAL 動畫化決定掃描：清單是空的' );
			return [ 'success' => false, 'message' => '清單為空', 'imported' => 0 ];
		}

		$seen       = self::get_seen();
		$first_run  = empty( $seen );
		$all_ids    = array_keys( $upcoming );

		/*
		 * 首次執行（或明確要求重建）只建立基準。
		 * 存量不是「新宣布」，是舊帳——理由見檔頭。
		 */
		if ( $first_run || $reseed ) {
			if ( ! $dry_run ) {
				self::save_seen( $all_ids );
				update_option( self::LAST_RUN_OPTION, [
					'ran_at'   => current_time( 'mysql' ),
					'seeded'   => count( $all_ids ),
					'imported' => 0,
				], false );
			}

			$this->logger->log( 'info', sprintf(
				'MAL 動畫化決定掃描：%s，記錄 %d 個 ID 為基準，本次不匯入（存量請走後台手動匯入）',
				$reseed ? '重建基準' : '首次執行',
				count( $all_ids )
			) );

			return [
				'success'   => true,
				'seeded'    => count( $all_ids ),
				'imported'  => 0,
				'first_run' => true,
			];
		}

		// 這一輪新冒出來的 ID＝真正的「新宣布動畫化」
		$new_ids = array_values( array_diff( $all_ids, $seen ) );

		if ( empty( $new_ids ) ) {
			if ( ! $dry_run ) {
				// 基準仍要更新：MAL 會把已開播的移出 upcoming，不同步會讓清單越積越舊
				self::save_seen( $all_ids );
			}
			return [ 'success' => true, 'found' => 0, 'imported' => 0 ];
		}

		$min_members = (int) apply_filters( 'anime_sync_mal_upcoming_min_members', self::MIN_MEMBERS );

		$candidates = [];
		$below      = 0;

		foreach ( $new_ids as $id ) {
			$node    = $upcoming[ $id ];
			$members = (int) ( $node['num_list_users'] ?? 0 );

			if ( $members < $min_members ) {
				$below++;
				continue;
			}

			$candidates[] = [
				'mal_id'  => $id,
				'title'   => (string) ( $node['title'] ?? '' ),
				'members' => $members,
				'season'  => isset( $node['start_season'] )
					? trim( ( $node['start_season']['season'] ?? '' ) . ' ' . ( $node['start_season']['year'] ?? '' ) )
					: '',
			];
		}

		// 熱門的先進站：中途中止時留下來的是比較重要的那批
		usort( $candidates, static fn( $a, $b ) => $b['members'] <=> $a['members'] );

		$cap = self::MAX_IMPORT_PER_RUN;
		if ( $limit > 0 ) {
			$cap = min( $cap, $limit );
		}

		$deferred = [];
		if ( count( $candidates ) > $cap ) {
			$deferred   = array_slice( $candidates, $cap );
			$candidates = array_slice( $candidates, 0, $cap );
		}

		if ( $dry_run ) {
			return [
				'success'      => true,
				'dry_run'      => true,
				'found'        => count( $new_ids ),
				'below_min'    => $below,
				'min_members'  => $min_members,
				'rows'         => $candidates,
				'deferred'     => count( $deferred ),
				'imported'     => 0,
			];
		}

		if ( ! $this->import_manager ) {
			$this->logger->log( 'error', 'MAL 動畫化決定掃描：Import Manager 未初始化，中止' );
			return [ 'success' => false, 'message' => 'Import Manager 未初始化', 'imported' => 0 ];
		}

		$imported = 0;
		$skipped  = 0;
		$failed   = 0;
		$rows     = [];

		foreach ( $candidates as $c ) {
			$result = $this->import_manager->import_single_from_mal(
				$c['mal_id'],
				null,
				'mal_upcoming'
			);

			if ( ! empty( $result['skipped'] ) ) {
				$skipped++;
			} elseif ( ! empty( $result['success'] ) ) {
				$imported++;
				$rows[] = [
					'mal_id'  => $c['mal_id'],
					'title'   => $result['title'] ?? $c['title'],
					'post_id' => (int) ( $result['post_id'] ?? 0 ),
				];

				/*
				 * 排一次 enrich，把 staff / cast 從 Bangumi 補進來。
				 * 與 class-new-release-scan.php 同樣的做法：非同步單次事件，
				 * 不在掃描裡同步打 Bangumi／AnimeThemes／Wikipedia。
				 */
				$pid = (int) ( $result['post_id'] ?? 0 );
				if ( $pid > 0 && ! wp_next_scheduled( 'anime_sync_enrich_post', [ $pid ] ) ) {
					wp_schedule_single_event( time() + 60, 'anime_sync_enrich_post', [ $pid ] );
				}
			} else {
				$failed++;
				$this->logger->log( 'warning', 'MAL 動畫化決定掃描：單筆匯入失敗', [
					'mal_id' => $c['mal_id'],
					'title'  => $c['title'],
					'error'  => $result['message'] ?? '未知錯誤',
				] );
			}
		}

		/*
		 * 基準的推進方式：把「這一輪確實處理過的」記進去。
		 *
		 * 低於門檻的也要記——它們是被政策排除，不是漏掉；不記的話每天都會
		 * 重新被撈出來比對一次。真的想要那些的話走後台手動匯入。
		 *
		 * 因為單輪上限而延後的（$deferred）刻意「不」記，下一輪才會再撈到。
		 */
		$processed = array_diff( $all_ids, wp_list_pluck( $deferred, 'mal_id' ) );
		self::save_seen( array_values( $processed ) );

		$summary = [
			'success'     => true,
			'found'       => count( $new_ids ),
			'below_min'   => $below,
			'min_members' => $min_members,
			'imported'    => $imported,
			'skipped'     => $skipped,
			'failed'      => $failed,
			'deferred'    => count( $deferred ),
			'rows'        => $rows,
		];

		update_option( self::LAST_RUN_OPTION, [
			'ran_at'    => current_time( 'mysql' ),
			'found'     => $summary['found'],
			'imported'  => $imported,
			'skipped'   => $skipped,
			'failed'    => $failed,
			'below_min' => $below,
			'deferred'  => count( $deferred ),
		], false );

		$this->logger->log( 'info', sprintf(
			'MAL 動畫化決定掃描完成：新條目 %d（低於門檻 %d 略過）／匯入 %d／跳過 %d／失敗 %d%s',
			$summary['found'],
			$below,
			$imported,
			$skipped,
			$failed,
			$deferred ? sprintf('／因單輪上限延後 %d', count( $deferred ) ) : ''
		) );

		return $summary;
	}

	// =========================================================================
	// 基準
	// =========================================================================

	/** @return int[] */
	public static function get_seen(): array {
		$v = get_option( self::SEEN_OPTION, [] );

		return is_array( $v ) ? array_map( 'intval', $v ) : [];
	}

	private static function save_seen( array $ids ): void {
		$ids = array_values( array_unique( array_map( 'intval', $ids ) ) );
		sort( $ids );

		// autoload = no：這個陣列有五、六百筆，不該每個前台請求都載入
		if ( get_option( self::SEEN_OPTION, null ) === null ) {
			add_option( self::SEEN_OPTION, $ids, '', 'no' );
		} else {
			update_option( self::SEEN_OPTION, $ids, false );
		}
	}

	// =========================================================================
	// MAL
	// =========================================================================

	/**
	 * 取回 upcoming 清單。
	 *
	 * @return array<int,array<string,mixed>>|null 以 MAL ID 為鍵；取得失敗回 null
	 *                                            （與「清單是空的」區分開，
	 *                                              失敗時基準不能推進）。
	 */
	private function fetch_upcoming(): ?array {
		$client_id = defined( 'MAL_CLIENT_ID' ) ? MAL_CLIENT_ID : '';
		if ( $client_id === '' ) {
			$this->logger->log( 'error', 'MAL 動畫化決定掃描：wp-config.php 未設定 MAL_CLIENT_ID' );
			return null;
		}

		$rate = class_exists( 'Anime_Sync_Rate_Limiter' ) ? Anime_Sync_Rate_Limiter::get_instance() : null;

		$collected = [];
		$offset    = 0;
		$any_ok    = false;

		for ( $page = 1; $page <= self::MAX_PAGES; $page++ ) {

			if ( $rate ) {
				$rate->wait_if_needed( 'mal' );
			}

			$url = add_query_arg( [
				'ranking_type' => 'upcoming',
				'limit'        => self::PER_PAGE,
				'offset'       => $offset,
				'fields'       => 'id,title,media_type,status,start_season,start_date,num_list_users',
			], self::ENDPOINT );

			$response = wp_remote_get( $url, [
				'timeout' => 30,
				'headers' => [
					'User-Agent'      => class_exists( 'Anime_Sync_API_Handler' )
						? Anime_Sync_API_Handler::USER_AGENT
						: 'weixiaoacg-Project/1.0 (https://weixiaoacg.com)',
					'X-MAL-CLIENT-ID' => $client_id,
				],
			] );

			if ( is_wp_error( $response ) ) {
				$this->logger->log( 'error', 'MAL upcoming 請求失敗：' . $response->get_error_message() );
				return $any_ok ? $collected : null;
			}

			$code = (int) wp_remote_retrieve_response_code( $response );
			if ( $code !== 200 ) {
				$this->logger->log( 'error', "MAL upcoming 回應 HTTP {$code}" );
				return $any_ok ? $collected : null;
			}

			$body = json_decode( wp_remote_retrieve_body( $response ), true );
			$rows = $body['data'] ?? [];

			if ( ! is_array( $rows ) || empty( $rows ) ) {
				break;
			}

			$any_ok = true;

			foreach ( $rows as $row ) {
				$node = $row['node'] ?? [];
				$id   = (int) ( $node['id'] ?? 0 );
				if ( $id <= 0 ) continue;

				if ( in_array( strtolower( (string) ( $node['media_type'] ?? '' ) ), self::EXCLUDE_TYPES, true ) ) {
					continue;
				}

				$collected[ $id ] = $node;
			}

			if ( empty( $body['paging']['next'] ) ) {
				break;
			}

			$offset += self::PER_PAGE;
		}

		return $collected;
	}
}

/**
 * WP-CLI
 */
if ( defined( 'WP_CLI' ) && WP_CLI ) {

	WP_CLI::add_command( 'anime scan-mal-upcoming', function ( $args, $assoc_args ) {
		$dry_run = isset( $assoc_args['dry-run'] );
		$reseed  = isset( $assoc_args['reseed'] );
		$limit   = isset( $assoc_args['limit'] ) ? (int) $assoc_args['limit'] : 0;

		$seen = Anime_Sync_MAL_Upcoming_Scan::get_seen();

		WP_CLI::log( '=== MAL 動畫化決定掃描 ===' );
		WP_CLI::log( '目前基準　: ' . ( $seen ? count( $seen ) . ' 個 ID' : '（尚未建立，本次會建基準且不匯入）' ) );
		WP_CLI::log( '模式　　　: ' . ( $reseed ? '重建基準' : ( $dry_run ? '唯讀' : '實際匯入' ) ) );
		WP_CLI::log( '' );

		$import_manager = null;

		if ( ! $dry_run && ! $reseed ) {
			$rate_limiter = class_exists( 'Anime_Sync_Rate_Limiter' ) ? Anime_Sync_Rate_Limiter::get_instance() : null;
			$id_mapper    = class_exists( 'Anime_Sync_ID_Mapper' ) ? new Anime_Sync_ID_Mapper( $rate_limiter ) : null;
			$converter    = class_exists( 'Anime_Sync_CN_Converter' ) ? new Anime_Sync_CN_Converter() : null;
			$api_handler  = class_exists( 'Anime_Sync_API_Handler' ) ? new Anime_Sync_API_Handler( $rate_limiter, $id_mapper ) : null;

			if ( ! $api_handler || ! $converter || ! class_exists( 'Anime_Sync_Import_Manager' ) ) {
				WP_CLI::error( '無法建立 Import Manager，請檢查外掛相依。' );
			}

			$import_manager = new Anime_Sync_Import_Manager( $api_handler, $converter );
		}

		$scanner = new Anime_Sync_MAL_Upcoming_Scan( $import_manager );
		$summary = $scanner->run( [ 'dry_run' => $dry_run, 'limit' => $limit, 'reseed' => $reseed ] );

		if ( empty( $summary['success'] ) ) {
			WP_CLI::error( $summary['message'] ?? '掃描失敗' );
		}

		if ( ! empty( $summary['first_run'] ) ) {
			WP_CLI::success( sprintf(
				'已建立基準（%d 個 ID），本次未匯入。存量請走後台「動漫匯入 → MAL 匯入 → 動畫化決定」自行挑選。',
				$summary['seeded']
			) );
			return;
		}

		WP_CLI::log( '新條目　　: ' . ( $summary['found'] ?? 0 ) );

		if ( ! empty( $summary['below_min'] ) ) {
			WP_CLI::log( sprintf( '低於門檻　: %d（收藏數 < %d）', $summary['below_min'], $summary['min_members'] ) );
		}

		if ( ! empty( $summary['rows'] ) ) {
			WP_CLI::log( '' );
			WP_CLI::log( $dry_run ? '── 會被匯入的條目 ──' : '── 本次新增的草稿 ──' );
			foreach ( $summary['rows'] as $r ) {
				WP_CLI::log( sprintf(
					'  %-8d %-44s %s',
					$r['mal_id'],
					mb_strimwidth( (string) $r['title'], 0, 44, '…' ),
					$dry_run
						? ( '收藏 ' . ( $r['members'] ?? 0 ) . ( ! empty( $r['season'] ) ? ' / ' . $r['season'] : ' / 檔期未定' ) )
						: ( '→ post #' . ( $r['post_id'] ?? 0 ) )
				) );
			}
			WP_CLI::log( '' );
		}

		if ( $dry_run ) {
			WP_CLI::success( '唯讀掃描完成，未寫入任何資料。' );
			return;
		}

		WP_CLI::log( '匯入成草稿: ' . ( $summary['imported'] ?? 0 ) );
		WP_CLI::log( '跳過　　　: ' . ( $summary['skipped'] ?? 0 ) );
		WP_CLI::log( '失敗　　　: ' . ( $summary['failed'] ?? 0 ) );

		if ( ! empty( $summary['deferred'] ) ) {
			WP_CLI::warning( sprintf( '因單輪上限延後 %d 部，下一輪會再處理。', $summary['deferred'] ) );
		}

		WP_CLI::success( '掃描完成。新草稿請至審核佇列複核後發布。' );
	} );
}
