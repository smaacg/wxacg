<?php
/**
 * YourAnimes 台灣串流資料回補
 *
 * 為什麼需要
 * ----------
 * 站上的台灣平台資料（巴哈、Hami、MyVideo、friDay、LINE TV、LiTV、CatchPlay、
 * Ofiii）只有 YourAnimes 一個來源；AniList 的 externalLinks 只涵蓋國際平台。
 * 但 class-youranimes-fetcher.php 的每日 cron 有兩道濾網：
 *
 *   1. 只處理已存有 anime_youranimes_url 的作品
 *   2. 只處理開播日落在「前 2 天 ~ 後 30 天」的作品
 *
 * 結果是舊番一旦錯過開播那個月，就永遠不會再被同步。實測正式站
 * 1,832 部已發布作品：有網址的 1,075 部（58.7%）、實際同步過的只有 905 部
 * （49.4%），而當下落在開播區間的只有 2 部——等於這支 cron 每天跑 2 筆。
 *
 * 對照 YourAnimes 自己的平台頁，我們各台灣平台的收錄數大約是他們的四成。
 *
 * 兩種模式
 * --------
 *   sync     有 anime_youranimes_url 但沒有 _anime_youranimes_last_synced_url
 *            的作品，直接呼叫既有的 sync_post()。不需要任何比對，跑就是了。
 *
 *   discover 連 anime_youranimes_url 都沒有的作品，用
 *            class-youranimes-season-index.php 既有的兩層完全相符比對
 *            （日文原名／羅馬字）找出網址。模糊比對已在 2026-09-02 被移除
 *            （錯誤率 20~40%，配錯會把別部作品的串流抓進來），這裡不重新引入。
 *
 * 用法：
 *   wp anime backfill-youranimes --mode=sync --dry-run
 *   wp anime backfill-youranimes --mode=sync --limit=50
 *   wp anime backfill-youranimes --mode=discover --dry-run     # 先看可配對率
 *   wp anime backfill-youranimes --mode=discover --limit=100
 *
 * 特性：
 *   - discover 的 dry-run 完全不寫入，只回報比對結果，可安心先跑
 *   - discover 只寫 anime_youranimes_url 這一個 meta，並主動取消 fetcher
 *     自動排程的單篇同步（否則幾百個工作會同時觸發，見該處註解）。
 *     實際抓取交給 --mode=sync 分批節流執行，兩步驟分開跑
 *   - sync 直接用 fetcher 的 sync_post()，行為與後台按鈕、每日 cron 完全一致
 *   - 遇到熔斷（連續 5 次失敗）立刻停止整批，不硬打對方伺服器
 *   - 可重複執行，中斷後再跑一次即可接續（判斷條件是資料狀態，不是進度指標）
 *
 * ⚠ 已知限制：sync_post() 寫入時是「只增不減」（見 fetcher write_to_acf()），
 *   因此本回補只會提高覆蓋率，不會清掉已下架平台造成的假陽性。那是另一個
 *   問題，需要先決定「自動同步可否移除人工勾選的平台」，不在本檔範圍。
 *
 * @package anime-sync-pro
 */

defined( 'ABSPATH' ) || exit;

class Anime_Sync_YourAnimes_Backfill {

	/** 每次 WP_Query 取幾筆 */
	private const PAGE_SIZE = 200;

	/**
	 * 兩次抓取之間的間隔（秒）。
	 * fetcher 內部本來就有 RATE_LIMIT_MIN/MAX（2~5 秒）的隨機延遲，
	 * 這裡不再疊加，只在 discover 模式自行抓季度表時使用。
	 */
	private const SEASON_INTERVAL_SEC = 2;

	/**
	 * @param array{mode?:string,dry_run?:bool,limit?:int} $args
	 * @return array<string,mixed>
	 */
	public function run( array $args = [] ): array {
		$mode    = ( $args['mode'] ?? 'sync' ) === 'discover' ? 'discover' : 'sync';
		$dry_run = ! empty( $args['dry_run'] );
		$limit   = isset( $args['limit'] ) ? max( 0, (int) $args['limit'] ) : 0;

		return $mode === 'discover'
			? $this->run_discover( $dry_run, $limit )
			: $this->run_sync( $dry_run, $limit );
	}

	// =====================================================================
	// mode=sync：有網址、沒同步過
	// =====================================================================

	private function run_sync( bool $dry_run, int $limit ): array {
		$stats = [
			'mode'          => 'sync',
			'candidates'    => 0,
			'processed'     => 0,
			'gained'        => 0,   // 實際多出平台的作品數
			'unchanged'     => 0,   // 同步成功但平台沒增加
			'still_empty'   => 0,   // 同步後仍然一個平台都沒有
			'platforms_add' => 0,   // 總共新增幾個平台標記
			'failed'        => 0,
			'circuit'       => false,
			'samples'       => [],
			'failures'      => [],
		];

		if ( ! class_exists( 'Anime_Sync_YourAnimes_Fetcher' ) ) {
			$stats['failures'][] = '找不到 Anime_Sync_YourAnimes_Fetcher';
			return $stats;
		}

		$ids = $this->candidates_for_sync();
		$stats['candidates'] = count( $ids );

		if ( $dry_run ) {
			foreach ( array_slice( $ids, 0, 15 ) as $id ) {
				$stats['samples'][] = sprintf(
					'#%d %s → %s',
					$id,
					mb_substr( (string) get_the_title( $id ), 0, 30 ),
					(string) get_post_meta( $id, 'anime_youranimes_url', true )
				);
			}
			return $stats;
		}

		$fetcher = new Anime_Sync_YourAnimes_Fetcher();

		foreach ( $ids as $id ) {
			if ( $limit > 0 && $stats['processed'] >= $limit ) {
				break;
			}

			/* 同步前先記數，才能算出「這次實際多補到幾個平台」 */
			$before = get_post_meta( $id, 'anime_tw_streaming', true );
			$before = is_array( $before ) ? count( $before ) : 0;

			$result = $fetcher->sync_post( $id, true );
			$stats['processed']++;

			if ( is_wp_error( $result ) ) {
				$code = $result->get_error_code();

				// 熔斷代表對方已經連續失敗，繼續打只會更糟，整批停下
				if ( $code === 'circuit_open' ) {
					$stats['circuit'] = true;
					break;
				}

				$stats['failed']++;
				if ( count( $stats['failures'] ) < 20 ) {
					$stats['failures'][] = sprintf(
						'#%d %s：%s',
						$id,
						mb_substr( (string) get_the_title( $id ), 0, 24 ),
						$result->get_error_message()
					);
				}
				continue;
			}

			/*
			 * sync_post() 不會自己寫同步標記——三個既有呼叫端（後台 AJAX
			 * 按鈕、meta 變更自動同步、每日 cron）都是各自在成功後補寫。
			 * 這裡不寫的話，候選查詢永遠撈到同一批，指令變成無法接續。
			 */
			$url = (string) get_post_meta( $id, 'anime_youranimes_url', true );
			if ( $url !== '' ) {
				update_post_meta( $id, '_anime_youranimes_last_synced_url', $url );
			}

			/*
			 * sync_post() 成功但沒解析到任何平台也是有效結果（該作品在
			 * YourAnimes 上就是沒有台灣串流），要跟失敗分開記，
			 * 否則看到「失敗 300」會誤以為是抓取壞掉。
			 */
			$after = get_post_meta( $id, 'anime_tw_streaming', true );
			$after = is_array( $after ) ? count( $after ) : 0;
			$delta = $after - $before;

			if ( $delta > 0 ) {
				$stats['gained']++;
				$stats['platforms_add'] += $delta;
				if ( count( $stats['samples'] ) < 15 ) {
					$stats['samples'][] = sprintf(
						'#%d %s：%d → %d 個平台（+%d）',
						$id,
						mb_substr( (string) get_the_title( $id ), 0, 24 ),
						$before,
						$after,
						$delta
					);
				}
			} elseif ( $after === 0 ) {
				$stats['still_empty']++;
			} else {
				$stats['unchanged']++;
			}
		}

		return $stats;
	}

	/**
	 * 有 anime_youranimes_url、但沒有 _anime_youranimes_last_synced_url 的作品。
	 *
	 * 用 SQL 而不是 WP_Query 的 NOT EXISTS meta_query：後者在 postmeta
	 * 幾十萬列時會產生很慢的 LEFT JOIN 加 NULL 判斷，這裡直接寫清楚。
	 */
	private function candidates_for_sync(): array {
		global $wpdb;

		return array_map( 'intval', (array) $wpdb->get_col(
			"SELECT p.ID
			   FROM {$wpdb->posts} p
			   JOIN {$wpdb->postmeta} u
			     ON u.post_id = p.ID
			    AND u.meta_key = 'anime_youranimes_url'
			    AND u.meta_value LIKE '%youranimes.tw%'
			   LEFT JOIN {$wpdb->postmeta} s
			     ON s.post_id = p.ID
			    AND s.meta_key = '_anime_youranimes_last_synced_url'
			    AND s.meta_value <> ''
			  WHERE p.post_type = 'anime'
			    AND p.post_status = 'publish'
			    AND s.post_id IS NULL
			  ORDER BY p.ID"
		) );
	}

	// =====================================================================
	// mode=discover：連網址都沒有
	// =====================================================================

	private function run_discover( bool $dry_run, int $limit ): array {
		$stats = [
			'mode'         => 'discover',
			'candidates'   => 0,
			'scanned'      => 0,
			'matched'      => 0,
			'no_match'     => 0,
			'no_season'    => 0,
			'written'      => 0,
			'unscheduled'  => 0,
			'by_method'    => [],
			'samples'      => [],
			'unmatched'    => [],
		];

		if ( ! class_exists( 'Anime_Sync_YourAnimes_Season_Index' ) ) {
			$stats['unmatched'][] = '找不到 Anime_Sync_YourAnimes_Season_Index';
			return $stats;
		}

		$ids = $this->candidates_for_discover();
		$stats['candidates'] = count( $ids );

		foreach ( $ids as $id ) {
			if ( $limit > 0 && $stats['scanned'] >= $limit ) {
				break;
			}
			$stats['scanned']++;

			$season = strtoupper( trim( (string) get_post_meta( $id, 'anime_season', true ) ) );
			$year   = (int) get_post_meta( $id, 'anime_season_year', true );

			/*
			 * 沒有季度就查不了——季度表是以「年 + 季」為單位抓的。
			 * 這類作品（多半是劇場版與很舊的作品）本檔處理不了，單獨計數，
			 * 免得混進 no_match 讓可配對率看起來比實際差。
			 */
			if ( $season === '' || $year < 1960 || $year > 2100 ) {
				$stats['no_season']++;
				continue;
			}

			$hit = Anime_Sync_YourAnimes_Season_Index::resolve( [
				'anime_season'        => $season,
				'anime_season_year'   => $year,
				'anime_title_native'  => (string) get_post_meta( $id, 'anime_title_native', true ),
				'anime_title_romaji'  => (string) get_post_meta( $id, 'anime_title_romaji', true ),
				'anime_title_english' => (string) get_post_meta( $id, 'anime_title_english', true ),
			] );

			if ( ! $hit || empty( $hit['url'] ) ) {
				$stats['no_match']++;
				if ( count( $stats['unmatched'] ) < 20 ) {
					$stats['unmatched'][] = sprintf(
						'#%d %s（%d %s）',
						$id,
						mb_substr( (string) get_the_title( $id ), 0, 26 ),
						$year,
						$season
					);
				}
				continue;
			}

			$stats['matched']++;
			$method = (string) ( $hit['match_method'] ?? '?' );
			$stats['by_method'][ $method ] = ( $stats['by_method'][ $method ] ?? 0 ) + 1;

			if ( count( $stats['samples'] ) < 15 ) {
				$stats['samples'][] = sprintf(
					'#%d %s → %s（%s）',
					$id,
					mb_substr( (string) get_the_title( $id ), 0, 24 ),
					$hit['url'],
					$method
				);
			}

			if ( ! $dry_run ) {
				update_post_meta( $id, 'anime_youranimes_url', $hit['url'] );
				$stats['written']++;

				/*
				 * ⚠ 一定要取消自動排程。
				 *
				 * fetcher 的 maybe_auto_sync_on_meta_change() 會在 meta 寫入時
				 * wp_schedule_single_event( time() + 10 )。一次回補幾百筆的話，
				 * 幾百個同步工作會全部排在 10 秒後——對 youranimes.tw 是突發連打，
				 * 對主機是一次性負載尖峰（這台共享主機先前就因為回補 cron 連打 API
				 * 讓 load average 衝到 22.7、Cloudflare 回 522）。
				 *
				 * 本模式只負責「找出網址」，實際抓取交給 --mode=sync 分批節流執行。
				 */
				$ts = wp_next_scheduled( Anime_Sync_YourAnimes_Fetcher::AUTO_SYNC_HOOK, [ $id ] );
				if ( $ts ) {
					wp_unschedule_event( $ts, Anime_Sync_YourAnimes_Fetcher::AUTO_SYNC_HOOK, [ $id ] );
					$stats['unscheduled']++;
				}
			}
		}

		return $stats;
	}

	/** 已發布、但沒有 anime_youranimes_url 的作品 */
	private function candidates_for_discover(): array {
		global $wpdb;

		return array_map( 'intval', (array) $wpdb->get_col(
			"SELECT p.ID
			   FROM {$wpdb->posts} p
			   LEFT JOIN {$wpdb->postmeta} u
			     ON u.post_id = p.ID
			    AND u.meta_key = 'anime_youranimes_url'
			    AND u.meta_value LIKE '%youranimes.tw%'
			  WHERE p.post_type = 'anime'
			    AND p.post_status = 'publish'
			    AND u.post_id IS NULL
			  ORDER BY p.ID"
		) );
	}
}

// =========================================================================
// WP-CLI
// =========================================================================

if ( defined( 'WP_CLI' ) && WP_CLI ) {

	WP_CLI::add_command( 'anime backfill-youranimes', function ( $args, $assoc_args ) {

		$mode    = isset( $assoc_args['mode'] ) ? (string) $assoc_args['mode'] : 'sync';
		$dry_run = isset( $assoc_args['dry-run'] );
		$limit   = isset( $assoc_args['limit'] ) ? (int) $assoc_args['limit'] : 0;

		if ( ! in_array( $mode, [ 'sync', 'discover' ], true ) ) {
			WP_CLI::error( '--mode 只接受 sync 或 discover' );
		}

		WP_CLI::log( $dry_run ? "=== DRY RUN（不寫入）mode={$mode} ===" : "=== 開始回補 mode={$mode} ===" );
		if ( $limit > 0 ) {
			WP_CLI::log( '本批上限：--limit=' . $limit );
		}

		$runner = new Anime_Sync_YourAnimes_Backfill();
		$stats  = $runner->run( [
			'mode'    => $mode,
			'dry_run' => $dry_run,
			'limit'   => $limit,
		] );

		WP_CLI::log( '─────────────────────────────' );

		if ( $mode === 'sync' ) {
			WP_CLI::log( '待同步作品    : ' . $stats['candidates'] );
			WP_CLI::log( '本次處理      : ' . $stats['processed'] );
			WP_CLI::log( '有補到新平台  : ' . $stats['gained'] . ' 部，共新增 ' . $stats['platforms_add'] . ' 個平台標記' );
			WP_CLI::log( '平台數沒變    : ' . $stats['unchanged'] );
			WP_CLI::log( '同步後仍為 0  : ' . $stats['still_empty'] );
			WP_CLI::log( '失敗          : ' . $stats['failed'] );
			if ( ! empty( $stats['circuit'] ) ) {
				WP_CLI::warning( '因熔斷提前結束——YourAnimes 連續失敗，稍後再跑' );
			}
		} else {
			WP_CLI::log( '無網址的作品  : ' . $stats['candidates'] );
			WP_CLI::log( '本次掃描      : ' . $stats['scanned'] );
			WP_CLI::log( '配對成功      : ' . $stats['matched'] );
			WP_CLI::log( '配對失敗      : ' . $stats['no_match'] );
			WP_CLI::log( '沒有季度資料  : ' . $stats['no_season'] );
			WP_CLI::log( '實際寫入      : ' . $stats['written'] );
			WP_CLI::log( '取消自動排程  : ' . $stats['unscheduled'] . '（避免同步工作全部擠在同一刻觸發）' );

			$checked = $stats['matched'] + $stats['no_match'];
			if ( $checked > 0 ) {
				WP_CLI::log( sprintf( '可配對率      : %.1f%%（不含沒有季度資料的）', $stats['matched'] / $checked * 100 ) );
			}
			foreach ( (array) $stats['by_method'] as $m => $n ) {
				WP_CLI::log( '  比對方式 ' . $m . ' : ' . $n );
			}
		}

		if ( ! empty( $stats['samples'] ) ) {
			WP_CLI::log( '─── 樣本 ───' );
			foreach ( $stats['samples'] as $s ) {
				WP_CLI::log( '  ' . $s );
			}
		}

		$problems = $stats['failures'] ?? ( $stats['unmatched'] ?? [] );
		if ( ! empty( $problems ) ) {
			WP_CLI::log( '─── ' . ( $mode === 'sync' ? '失敗明細' : '配對不到（樣本）' ) . ' ───' );
			foreach ( $problems as $p ) {
				WP_CLI::log( '  ' . $p );
			}
		}

		WP_CLI::success( $dry_run ? 'Dry run 完成（未寫入）' : '回補完成' );
	} );
}
