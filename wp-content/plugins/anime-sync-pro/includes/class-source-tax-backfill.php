<?php
/**
 * 原作類型分類法回填
 *
 * anime_source_tax 是後來才加的分類法（見 anime-sync-pro.php）。
 * 同步流程從此會在匯入時一併指派詞彙，但既有作品只有 anime_source meta、
 * 沒有對應詞彙，前台的「原作類型」因此點不了。本檔負責一次性補寫。
 *
 * 用法：
 *   wp anime backfill-source-tax --dry-run          # 先看影響範圍，不寫入
 *   wp anime backfill-source-tax --limit=500        # 分批跑，避免一次跑太久
 *   wp anime backfill-source-tax                    # 全部跑
 *   wp anime backfill-source-tax --force            # 連已有詞彙的也重設
 *
 * 特性：
 *   - 可重複執行：預設略過已經有詞彙的作品，中斷後直接再跑一次即可接續
 *   - 只寫 anime_source_tax，不動 anime_source meta 與其他任何欄位
 *   - 對照表沒有的代碼一律略過並列入報告，不會生出髒詞彙
 *
 * @package anime-sync-pro
 */

defined( 'ABSPATH' ) || exit;

class Anime_Sync_Source_Tax_Backfill {

	/** 每次 WP_Query 取幾筆（與 --limit 無關，只是分頁大小） */
	private const PAGE_SIZE = 200;

	/**
	 * 執行回填。
	 *
	 * @param array{dry_run?:bool,force?:bool,limit?:int} $args
	 * @return array<string,mixed> 統計結果
	 */
	public function run( array $args = [] ): array {
		$dry_run = ! empty( $args['dry_run'] );
		$force   = ! empty( $args['force'] );
		$limit   = isset( $args['limit'] ) ? max( 0, (int) $args['limit'] ) : 0;

		$stats = [
			'scanned'   => 0,
			'updated'   => 0,
			'skipped'   => 0,  // 已有詞彙
			'no_source' => 0,  // meta 為空
			'unmapped'  => [], // 對照表沒有的代碼 => 筆數
		];

		if ( ! function_exists( 'anime_sync_get_source_tax_map' ) ) {
			return $stats;
		}

		$map       = anime_sync_get_source_tax_map();
		$term_ids  = [];   // slug => term_id，避免同一 slug 重複查詢
		$paged     = 1;
		$hit_limit = false;

		/*
		 * 延後詞彙計數：每次 wp_set_post_terms() 都即時重算 count 很慢，
		 * 而且本迴圈為了控制記憶體會定期清物件快取，兩者相加會讓 count 算錯
		 * （首次上線時就發生過：實際 1412 筆卻只記到 968，得事後手動 recount）。
		 *
		 * wp_defer_term_counting() 把待算的 term 收在 PHP 全域變數裡，不受
		 * 物件快取清除影響；迴圈結束後關閉時，WordPress 會一次補算完畢。
		 */
		if ( ! $dry_run ) {
			wp_defer_term_counting( true );
		}

		while ( ! $hit_limit ) {
			$query = new WP_Query( [
				'post_type'              => 'anime',
				'post_status'            => 'any',
				'posts_per_page'         => self::PAGE_SIZE,
				'paged'                  => $paged,
				'fields'                 => 'ids',
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				'suppress_filters'       => true,
			] );

			if ( empty( $query->posts ) ) {
				break;
			}

			foreach ( $query->posts as $post_id ) {
				$post_id = (int) $post_id;
				$stats['scanned']++;

				$source_raw = trim( (string) get_post_meta( $post_id, 'anime_source', true ) );

				if ( '' === $source_raw ) {
					$stats['no_source']++;
					continue;
				}

				// 已有詞彙且非 --force → 略過（讓指令可以安全重跑）
				if ( ! $force ) {
					$existing = get_the_terms( $post_id, 'anime_source_tax' );

					if ( is_array( $existing ) && ! empty( $existing ) ) {
						$stats['skipped']++;
						continue;
					}
				}

				// 與匯入、SEO 描述共用同一套判斷（原作國別可補足韓漫／國漫）。
				$source_key = function_exists( 'anime_sync_resolve_source_key' )
					? anime_sync_resolve_source_key(
						$source_raw,
						(string) get_post_meta( $post_id, 'anime_source_country', true )
					)
					: strtoupper( $source_raw );

				if ( ! isset( $map[ $source_key ] ) ) {
					if ( ! isset( $stats['unmapped'][ $source_key ] ) ) {
						$stats['unmapped'][ $source_key ] = 0;
					}
					$stats['unmapped'][ $source_key ]++;
					continue;
				}

				$name = $map[ $source_key ]['name'];
				$slug = $map[ $source_key ]['slug'];

				if ( $dry_run ) {
					$stats['updated']++;
					continue;
				}

				if ( ! isset( $term_ids[ $slug ] ) ) {
					$term_ids[ $slug ] = $this->resolve_term_id( $name, $slug );
				}

				$term_id = $term_ids[ $slug ];

				if ( $term_id > 0 ) {
					wp_set_post_terms( $post_id, [ $term_id ], 'anime_source_tax' );
					$stats['updated']++;
				}

				if ( $limit > 0 && $stats['updated'] >= $limit ) {
					$hit_limit = true;
					break;
				}
			}

			/*
			 * 記憶體控制：長跑時 WP 的物件快取會持續膨脹。
			 *
			 * 不用 wp_cache_flush()：站上裝了物件快取 drop-in，在 WP-CLI 下
			 * 每次呼叫都會印出一行「全部快取已成功清除」，把指令輸出洗掉；
			 * 而且它會連同其他無關快取一起清，代價過高。
			 * 這裡只清掉本迴圈真正會累積的 post／meta／term 三組快取。
			 */
			$this->flush_loop_caches();
			$paged++;
		}

		if ( ! $dry_run ) {
			// 關閉延後計數 → WordPress 於此處一次補算所有受影響的 term count
			wp_defer_term_counting( false );
		}

		return $stats;
	}

	/**
	 * 清掉本迴圈累積的快取，取代整站 wp_cache_flush()。
	 *
	 * 只針對真正會膨脹的群組；wp_cache_flush_group() 需要物件快取支援，
	 * 不支援時退回重設內建 runtime cache，兩者都不會產生 CLI 輸出。
	 */
	private function flush_loop_caches(): void {
		$groups = [ 'posts', 'post_meta', 'terms', 'term_relationships' ];

		if ( function_exists( 'wp_cache_supports' ) && wp_cache_supports( 'flush_group' ) ) {
			foreach ( $groups as $group ) {
				wp_cache_flush_group( $group );
			}

			return;
		}

		// 沒有 flush_group 支援時，直接重設 WP 內建物件快取的 runtime 陣列。
		global $wp_object_cache;

		if ( is_object( $wp_object_cache ) && isset( $wp_object_cache->cache ) && is_array( $wp_object_cache->cache ) ) {
			foreach ( $groups as $group ) {
				unset( $wp_object_cache->cache[ $group ] );
			}
		}
	}

	/**
	 * 依 slug 取得詞彙 ID，沒有就建立。
	 *
	 * @return int 失敗回 0
	 */
	private function resolve_term_id( string $name, string $slug ): int {
		$term = get_term_by( 'slug', $slug, 'anime_source_tax' );

		if ( $term instanceof WP_Term ) {
			return (int) $term->term_id;
		}

		$result = wp_insert_term( $name, 'anime_source_tax', [ 'slug' => $slug ] );

		if ( ! is_wp_error( $result ) ) {
			return (int) $result['term_id'];
		}

		if ( 'term_exists' === $result->get_error_code() ) {
			return (int) ( $result->get_error_data() ?: 0 );
		}

		return 0;
	}
}

/**
 * 註冊 WP-CLI 指令
 */
if ( defined( 'WP_CLI' ) && WP_CLI ) {

	WP_CLI::add_command( 'anime backfill-source-tax', function ( $args, $assoc_args ) {
		$dry_run = isset( $assoc_args['dry-run'] );
		$force   = isset( $assoc_args['force'] );
		$limit   = isset( $assoc_args['limit'] ) ? (int) $assoc_args['limit'] : 0;

		$backfill = new Anime_Sync_Source_Tax_Backfill();

		WP_CLI::log( $dry_run ? '=== DRY RUN（不寫入）===' : '=== 開始回填原作類型分類 ===' );

		if ( $force ) {
			WP_CLI::log( '模式：--force（連已有詞彙的作品也重設）' );
		}
		if ( $limit > 0 ) {
			WP_CLI::log( '本批上限：--limit=' . $limit );
		}

		$stats = $backfill->run( [
			'dry_run' => $dry_run,
			'force'   => $force,
			'limit'   => $limit,
		] );

		WP_CLI::log( '─────────────────────────────' );
		WP_CLI::log( '掃描作品數        : ' . $stats['scanned'] );
		WP_CLI::log( ( $dry_run ? '預計寫入' : '已寫入' ) . '          : ' . $stats['updated'] );
		WP_CLI::log( '略過（已有詞彙）  : ' . $stats['skipped'] );
		WP_CLI::log( '略過（無原作類型）: ' . $stats['no_source'] );

		if ( ! empty( $stats['unmapped'] ) ) {
			WP_CLI::warning( '以下代碼不在對照表中，未處理：' );
			foreach ( $stats['unmapped'] as $code => $count ) {
				WP_CLI::log( '  - ' . $code . '（' . $count . ' 筆）' );
			}
			WP_CLI::log( '若這些代碼確實需要，請補進 anime_sync_get_source_tax_map()。' );
		}

		WP_CLI::success( $dry_run ? 'Dry run 完成（未寫入）' : '回填完成' );
	} );
}
