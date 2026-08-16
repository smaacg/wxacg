<?php
/**
 * 未播出作品的 Bangumi 班底輪掃
 *
 * 問題背景：
 *   未播出作品的 staff / cast 會隨宣傳進度陸續補齊——先公布原作，之後才
 *   公布製作公司、監督、聲優。但這些欄位是匯入時寫一次的靜態資料，之後
 *   只有人工跑 `wp anime check-upcoming-drift --apply` 才會更新。冷門作品
 *   沒被想到就會一直停在建檔當下，而未播出階段正是搜尋量最高的時候。
 *
 * 為什麼是輪掃而不是事件觸發：
 *   Bangumi 沒有任何變更偵測機制。實測 /v0/subjects/{id} 沒有 updated_at；
 *   HTTP 層雖然有 Last-Modified，但它等於 Date 且 CF-Cache-Status 為 HIT，
 *   反映的是 Cloudflare 快取時間、不是內容修改時間，每小時就會變一次。
 *   條件請求能拿到 304（省頻寬），但請求數不變，而限流算的是請求數。
 *
 *   也不能拿 AniList 的變化當代理訊號：兩站各自獨立更新，實例是琉璃龍
 *   （AniList staff=1、Bangumi=2），用 A 推測 B 會漏掉「Bangumi 自己補了
 *   但 AniList 沒動」。AniList 側的偵測另外走 cron-manager 的
 *   detect_upstream_cast_growth()，兩者互補、不互相取代。
 *
 * 成本：
 *   每部 2 次 Bangumi 請求，rate limiter 設定為 1 req/s。每小時 8 部
 *   （約 16 請求、16 秒），181 部未播出作品約一天輪完一圈。
 *   批次刻意小於「每小時 × 24 = 一輪」所需，讓每輪之間自然拉開超過
 *   get_bgm_staff_public() 的 12 小時 transient 快取，避免整輪讀到快取
 *   等於沒檢查。
 *
 * 實際寫入由 Anime_Sync_Upcoming_Drift_Check::run(['apply'=>true]) 執行，
 * 護欄沿用該類別既有設計：只有上游筆數較多才寫、上游取不到就跳過判定、
 * 尊重 anime_locked_fields。本類別只負責排隊與節奏。
 *
 * 用法：
 *   wp anime scan-upcoming-bgm --dry-run   # 只列出本批會檢查誰
 *   wp anime scan-upcoming-bgm             # 跑一批
 *   wp anime scan-upcoming-bgm --batch=20  # 指定本批筆數
 *
 * @package Anime_Sync_Pro
 */

defined( 'ABSPATH' ) || exit;

class Anime_Sync_Upcoming_BGM_Scan {

	const HOOK_HOURLY = 'anime_sync_upcoming_bgm_scan';

	const QUEUE_OPTION      = 'anime_sync_upcoming_bgm_queue';
	const LAST_BUILD_OPTION = 'anime_sync_upcoming_bgm_last_build';
	const LAST_RUN_OPTION   = 'anime_sync_upcoming_bgm_last_run';

	const LOCK_KEY = 'anime_sync_lock_upcoming_bgm_scan';
	const LOCK_TTL = 900;

	/** 每批處理筆數；每部 2 次 Bangumi 請求、每次間隔 1 秒 */
	const BATCH_SIZE = 8;

	/**
	 * 佇列重建間隔。
	 *
	 * 設 20 小時而非 24，是為了容忍 cron 觸發的漂移（本站
	 * DISABLE_WP_CRON=true，靠外部觸發，實際間隔常略長於名目值），
	 * 同時仍大於 get_bgm_staff_public() 的 12 小時快取。
	 */
	const REBUILD_INTERVAL = 20 * HOUR_IN_SECONDS;

	/** 開播前多少天內仍資料稀疏就提示人工處理 */
	const NEAR_AIR_DAYS = 14;

	/** staff 或 cast 少於等於這個數字視為「稀疏」 */
	const SPARSE_THRESHOLD = 3;

	private Anime_Sync_Error_Logger $logger;

	public function __construct() {
		$this->logger = new Anime_Sync_Error_Logger();

		add_action( self::HOOK_HOURLY, [ $this, 'run_scheduled' ] );
	}

	// =========================================================================
	// 排程
	// =========================================================================

	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::HOOK_HOURLY ) ) {
			wp_schedule_event( time() + 900, 'hourly', self::HOOK_HOURLY );
		}
	}

	public static function unschedule(): void {
		$ts = wp_next_scheduled( self::HOOK_HOURLY );
		if ( $ts ) {
			wp_unschedule_event( $ts, self::HOOK_HOURLY );
		}
		wp_clear_scheduled_hook( self::HOOK_HOURLY );
	}

	public function run_scheduled(): void {
		$this->run();
	}

	// =========================================================================
	// 主流程
	// =========================================================================

	/**
	 * @param array{dry_run?:bool,batch?:int} $args
	 * @return array<string,mixed>
	 */
	public function run( array $args = [] ): array {
		$dry_run = ! empty( $args['dry_run'] );
		$batch   = isset( $args['batch'] ) ? max( 1, (int) $args['batch'] ) : self::BATCH_SIZE;

		if ( ! $dry_run ) {
			if ( get_transient( self::LOCK_KEY ) ) {
				$this->logger->log( 'warning', '未播出班底輪掃：已有另一個程序在執行，本次跳過' );
				return [ 'success' => false, 'message' => '已鎖定，跳過' ];
			}
			set_transient( self::LOCK_KEY, 1, self::LOCK_TTL );
		}

		try {
			return $this->run_inner( $dry_run, $batch );
		} finally {
			if ( ! $dry_run ) {
				delete_transient( self::LOCK_KEY );
			}
		}
	}

	private function run_inner( bool $dry_run, int $batch_size ): array {
		if ( ! class_exists( 'Anime_Sync_Upcoming_Drift_Check' ) ) {
			$this->logger->log( 'error', '未播出班底輪掃：Anime_Sync_Upcoming_Drift_Check 尚未載入' );
			return [ 'success' => false, 'message' => 'Drift Check 未載入' ];
		}

		$queue = $this->get_queue( $dry_run );

		if ( $queue === null ) {
			// 上一輪已跑完，還沒到下次重建時間
			return [ 'success' => true, 'idle' => true, 'checked' => 0 ];
		}

		if ( empty( $queue ) ) {
			return [ 'success' => true, 'checked' => 0, 'message' => '目前沒有未播出作品' ];
		}

		$batch     = array_slice( $queue, 0, $batch_size );
		$remaining = array_slice( $queue, $batch_size );

		if ( $dry_run ) {
			return [
				'success'   => true,
				'dry_run'   => true,
				'checked'   => 0,
				'batch'     => $batch,
				'remaining' => count( $remaining ),
			];
		}

		// 先寫回剩餘佇列，避免本批中途逾時導致整個佇列卡住重跑同一批
		update_option( self::QUEUE_OPTION, $remaining, false );

		if ( class_exists( 'Anime_Sync_Performance' ) ) {
			Anime_Sync_Performance::set_time_limit( 300 );
		}

		$checker = new Anime_Sync_Upcoming_Drift_Check();
		$rows    = $checker->run( [ 'ids' => $batch, 'apply' => true ] );

		$applied = 0;
		$drifted = 0;

		foreach ( $rows as $row ) {
			if ( ! empty( $row['drift'] ) ) {
				$drifted++;
				if ( ! empty( $row['applied'] ) ) {
					$applied++;
					$this->logger->log( 'info', sprintf(
						'未播出班底輪掃〔%s〕：已補齊 %s（%s）',
						$row['title'] ?? ( '#' . $row['post_id'] ),
						implode( '/', (array) $row['applied'] ),
						$row['note'] ?? ''
					), [ 'post_id' => $row['post_id'] ] );
				}
			}

			$this->maybe_warn_sparse_near_air( $row );
		}

		$summary = [
			'success'   => true,
			'checked'   => count( $rows ),
			'drifted'   => $drifted,
			'applied'   => $applied,
			'remaining' => count( $remaining ),
		];

		update_option( self::LAST_RUN_OPTION, $summary + [ 'ran_at' => current_time( 'mysql' ) ], false );

		return $summary;
	}

	/**
	 * 快開播卻仍然沒資料的，記一筆提示。
	 *
	 * 自動補齊只能補「上游有的」。冷門作品常見的狀況是 Bangumi 自己也還
	 * 沒建條目——掃再多次都補不出東西，卻正是最需要人工介入的一批。
	 * 這裡不動任何資料，只負責讓它浮出來。
	 */
	private function maybe_warn_sparse_near_air( array $row ): void {
		$post_id = (int) ( $row['post_id'] ?? 0 );
		if ( $post_id <= 0 ) {
			return;
		}

		$start_raw = (string) get_post_meta( $post_id, 'anime_start_date', true );
		if ( ! ctype_digit( $start_raw ) || strlen( $start_raw ) !== 8 ) {
			return; // 檔期未定，無從判斷是否接近開播
		}

		$today    = (int) gmdate( 'Ymd' );
		$deadline = (int) gmdate( 'Ymd', strtotime( '+' . self::NEAR_AIR_DAYS . ' days' ) );
		$start    = (int) $start_raw;

		if ( $start < $today || $start > $deadline ) {
			return;
		}

		$staff = (int) ( $row['local_staff'] ?? 0 );
		$cast  = (int) ( $row['local_cast'] ?? 0 );

		if ( $staff > self::SPARSE_THRESHOLD && $cast > self::SPARSE_THRESHOLD ) {
			return;
		}

		$this->logger->log( 'warning', sprintf(
			'〔%s〕%s 開播，但 staff %d 筆／cast %d 筆，上游亦無更多資料，建議人工補充',
			$row['title'] ?? ( '#' . $post_id ),
			$start_raw,
			$staff,
			$cast
		), [
			'post_id'  => $post_id,
			'bgm_id'   => $row['bgm_id'] ?? 0,
			'edit_url' => get_edit_post_link( $post_id, 'raw' ),
		] );
	}

	// =========================================================================
	// 佇列
	// =========================================================================

	/**
	 * 取得待處理佇列。
	 *
	 * @return int[]|null null 表示上一輪已完成且尚未到重建時間
	 */
	private function get_queue( bool $dry_run ): ?array {
		$queue = get_option( self::QUEUE_OPTION, null );

		if ( is_array( $queue ) && ! empty( $queue ) ) {
			return $queue;
		}

		$last_build = (int) get_option( self::LAST_BUILD_OPTION, 0 );

		if ( $last_build > 0 && ( time() - $last_build ) < self::REBUILD_INTERVAL ) {
			return null;
		}

		$ids = $this->build_queue();

		if ( ! $dry_run ) {
			update_option( self::QUEUE_OPTION, $ids, false );
			update_option( self::LAST_BUILD_OPTION, time(), false );

			$this->logger->log( 'info', sprintf(
				'未播出班底輪掃：重建佇列，共 %d 部',
				count( $ids )
			) );
		}

		return $ids;
	}

	/** 已發布且未播出的作品；沒有 Bangumi ID 的先排除（無從比對） */
	private function build_queue(): array {
		$ids = get_posts( [
			'post_type'      => 'anime',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'orderby'        => 'ID',
			'order'          => 'ASC',
			'no_found_rows'  => true,
			'meta_query'     => [
				'relation' => 'AND',
				[
					'key'   => 'anime_status',
					'value' => 'NOT_YET_RELEASED',
				],
				[
					'key'     => 'anime_bangumi_id',
					'value'   => '0',
					'compare' => '>',
					'type'    => 'NUMERIC',
				],
			],
		] );

		return array_map( 'intval', $ids );
	}
}

/**
 * 註冊 WP-CLI 指令
 */
if ( defined( 'WP_CLI' ) && WP_CLI ) {

	WP_CLI::add_command( 'anime scan-upcoming-bgm', function ( $args, $assoc_args ) {
		$dry_run = isset( $assoc_args['dry-run'] );
		$batch   = isset( $assoc_args['batch'] ) ? (int) $assoc_args['batch'] : 0;

		$scanner = new Anime_Sync_Upcoming_BGM_Scan();

		$run_args = [ 'dry_run' => $dry_run ];
		if ( $batch > 0 ) {
			$run_args['batch'] = $batch;
		}

		WP_CLI::log( $dry_run
			? '=== 未播出班底輪掃（唯讀，只列出本批對象）==='
			: '=== 未播出班底輪掃（會補齊 staff／cast）===' );

		$summary = $scanner->run( $run_args );

		if ( empty( $summary['success'] ) ) {
			WP_CLI::error( $summary['message'] ?? '執行失敗' );
		}

		if ( ! empty( $summary['idle'] ) ) {
			WP_CLI::success( '本輪已跑完，尚未到下次重建時間（間隔 20 小時）。' );
			return;
		}

		if ( $dry_run ) {
			WP_CLI::log( '本批對象：' );
			foreach ( (array) ( $summary['batch'] ?? [] ) as $id ) {
				WP_CLI::log( sprintf( '  #%-6d %s', $id, get_the_title( $id ) ) );
			}
			WP_CLI::log( '佇列剩餘：' . ( $summary['remaining'] ?? 0 ) );
			WP_CLI::success( '唯讀，未寫入任何資料。' );
			return;
		}

		WP_CLI::log( '─────────────────────────────' );
		WP_CLI::log( '本批檢查    : ' . ( $summary['checked'] ?? 0 ) );
		WP_CLI::log( '有落差      : ' . ( $summary['drifted'] ?? 0 ) );
		WP_CLI::log( '實際補齊    : ' . ( $summary['applied'] ?? 0 ) );
		WP_CLI::log( '佇列剩餘    : ' . ( $summary['remaining'] ?? 0 ) );
		WP_CLI::success( '本批完成。' );
	} );
}
