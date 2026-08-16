<?php
/**
 * 未播出作品的上游漂移檢查
 *
 * 問題背景：
 *   每日同步只更新「會變動的數字」（狀態、評分、集數、播出日），
 *   staff / cast / 封面是匯入時寫一次的靜態欄位。但未播出作品恰恰是
 *   這些欄位會陸續補齊的階段——先公布原作，之後才公布製作公司、
 *   監督、聲優、正式主視覺。結果是資料停在建檔當下，而沒有任何提示。
 *
 * 為什麼用「比對筆數」而不是問上游「有沒有變」：
 *   AniList 兩個看似便宜的訊號都不能用——
 *     - characters/staff 的 pageInfo.total 固定回 500（上限值，非真實筆數）
 *     - updatedAt 每天都在跳（連 1998 年的舊作也是），是統計數字更新
 *   因此只能實際取回筆數比對。
 *
 * 來源優先序（與 class-api-handler.php 的匯入邏輯一致）：
 *   staff / cast — Bangumi 優先，Bangumi 沒資料才留 AniList
 *   封面         — AniList
 *
 * 用法：
 *   wp anime check-upcoming-drift              # 列出有落差的作品
 *   wp anime check-upcoming-drift --all        # 連沒落差的也列出
 *   wp anime check-upcoming-drift --limit=30   # 只檢查前 N 部
 *
 * 本指令唯讀，不會寫入任何資料。
 *
 * @package Anime_Sync_Pro
 */

defined( 'ABSPATH' ) || exit;

class Anime_Sync_Upcoming_Drift_Check {

	/** Bangumi 請求之間的間隔（秒），避免觸發對方限流 */
	private const BGM_DELAY = 1;

	private const UA = 'weixiaoacg-Project/1.0 (https://weixiaoacg.com)';

	/**
	 * 掃描未播出作品，回報上游與本地的筆數落差。
	 *
	 * @param array{limit?:int,ids?:int[]} $args
	 *        ids 指定只檢查這幾篇（供分批輪掃的 cron 使用，見
	 *        class-upcoming-bgm-scan.php）；未指定時掃全部未播出作品。
	 * @return array<int,array<string,mixed>> 每部作品一列
	 */
	public function run( array $args = [] ): array {
		$limit = isset( $args['limit'] ) ? max( 0, (int) $args['limit'] ) : 0;

		if ( ! empty( $args['ids'] ) ) {
			$ids = array_values( array_filter( array_map( 'intval', (array) $args['ids'] ) ) );
		} else {
			$ids = get_posts( [
				'post_type'      => 'anime',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'no_found_rows'  => true,
				'meta_query'     => [
					[
						'key'   => 'anime_status',
						'value' => 'NOT_YET_RELEASED',
					],
				],
			] );
		}

		if ( $limit > 0 ) {
			$ids = array_slice( $ids, 0, $limit );
		}

		if ( ! class_exists( 'Anime_Sync_API_Handler' ) ) {
			return [];
		}

		$api  = new Anime_Sync_API_Handler();
		$rows = [];

		foreach ( $ids as $id ) {
			$id     = (int) $id;
			$bgm_id = (int) get_post_meta( $id, 'anime_bangumi_id', true );

			$local_staff = $this->count_json( get_post_meta( $id, 'anime_staff_json', true ) );
			$local_cast  = $this->count_json( get_post_meta( $id, 'anime_cast_json', true ) );

			$row = [
				'post_id'     => $id,
				'title'       => get_the_title( $id ),
				'bgm_id'      => $bgm_id,
				'local_staff' => $local_staff,
				'local_cast'  => $local_cast,
				'bgm_staff'   => null,
				'bgm_cast'    => null,
				'drift'       => false,
				'note'        => '',
			];

			if ( $bgm_id <= 0 ) {
				$row['note'] = '無 Bangumi ID';
				$rows[]      = $row;
				continue;
			}

			/*
			 * 直接呼叫匯入端使用的取得方法，不自行計數。
			 *
			 * 前兩版都因為「檢查」與「實際抓取」算的東西不同而誤報：
			 *   1. staff — 匯入端只收 14 種主要職位，檢查卻數未過濾的原始清單
			 *      （180 部誤報 112 部）
			 *   2. cast  — 匯入端走 legacy API 的 crt 欄位，檢查卻數 v0 端點，
			 *      兩者筆數不同（火影忍者 108 vs 241）
			 *
			 * 改為呼叫同一組方法後，兩邊由結構保證一致，不需要再同步維護
			 * 白名單或端點選擇。這兩個方法內有 12 小時 transient 快取，
			 * 緊接著的 --apply 不會重複請求。
			 */
			$staff = $api->get_bgm_staff_public( $bgm_id );
			$cast  = $api->get_bgm_chars_public( $bgm_id );

			$row['bgm_staff'] = count( $staff );
			$row['bgm_cast']  = count( $cast );

			// 兩邊都取不到才視為請求失敗；單邊為空是正常的（上游尚未建）
			if ( empty( $staff ) && empty( $cast ) ) {
				$row['bgm_staff'] = null;
				$row['bgm_cast']  = null;
			}

			sleep( self::BGM_DELAY );

			// 取不到就跳過判定，避免把「對方暫時失敗」誤認為「本地資料過期」
			if ( $row['bgm_staff'] === null || $row['bgm_cast'] === null ) {
				$row['note'] = 'Bangumi 取得失敗，略過判定';
				$rows[]      = $row;
				continue;
			}

			if ( $row['bgm_staff'] === 0 && $row['bgm_cast'] === 0 ) {
				$row['note'] = 'Bangumi 尚無資料';
				$rows[]      = $row;
				continue;
			}

			// 只有「上游比本地多」才算漂移。上游比較少通常是條目整併或暫時異常，
			// 那種情況不該把現有資料當成過期。
			if ( $row['bgm_staff'] > $local_staff || $row['bgm_cast'] > $local_cast ) {
				$row['drift'] = true;
				$row['note']  = sprintf(
					'staff %d→%d, cast %d→%d',
					$local_staff,
					$row['bgm_staff'],
					$local_cast,
					$row['bgm_cast']
				);

				if ( ! empty( $args['apply'] ) ) {
					$row['applied'] = $this->apply_one( $id, $bgm_id, $local_staff, $local_cast );
				}
			}

			$rows[] = $row;
		}

		return $rows;
	}

	/**
	 * 實際補寫單一作品的 staff / cast。
	 *
	 * 只增不減：僅在上游筆數較多時覆寫該欄位，另一欄若沒變多就完全不動。
	 * 理由見 class-api-handler.php 的同名護欄——續季條目常常只先建
	 * 「原作／動畫製作」兩筆，而手動修正過 Bangumi ID 的作品，本地留著的
	 * 舊班底資料多半仍然正確，覆蓋等於製造資訊損失。
	 *
	 * @return string[] 實際更新了哪些欄位
	 */
	private function apply_one( int $post_id, int $bgm_id, int $local_staff, int $local_cast ): array {
		if ( ! class_exists( 'Anime_Sync_API_Handler' ) ) {
			return [];
		}

		$locked = (array) get_post_meta( $post_id, 'anime_locked_fields', true );
		$api    = new Anime_Sync_API_Handler();
		$done   = [];

		/*
		 * 與 class-api-handler.php 的 enrich 護欄同一套判斷：
		 * 現有資料不是 Bangumi 來的就直接取代（staff / cast 以 Bangumi 為準），
		 * 兩邊都是 Bangumi 時才套用「只增不減」。
		 */
		$should_write = function ( string $meta_key, array $incoming ) use ( $post_id ): bool {
			if ( empty( $incoming ) ) {
				return false;
			}

			$current = json_decode( (string) get_post_meta( $post_id, $meta_key, true ), true );
			$current = is_array( $current ) ? $current : [];

			if ( empty( $current ) ) {
				return true;
			}

			foreach ( $current as $entry ) {
				$src = isset( $entry['source'] ) ? (string) $entry['source'] : '';
				if ( str_starts_with( $src, 'bangumi' ) ) {
					return count( $incoming ) >= count( $current );
				}
			}

			return true;
		};

		if ( ! in_array( 'anime_staff_json', $locked, true ) ) {
			$staff = $api->get_bgm_staff_public( $bgm_id );

			if ( $should_write( 'anime_staff_json', $staff ) ) {
				update_post_meta(
					$post_id,
					'anime_staff_json',
					wp_json_encode( $staff, JSON_UNESCAPED_UNICODE )
				);
				$done[] = 'staff';
			}
		}

		if ( ! in_array( 'anime_cast_json', $locked, true ) ) {
			$cast = $api->get_bgm_chars_public( $bgm_id );

			if ( $should_write( 'anime_cast_json', $cast ) ) {
				update_post_meta(
					$post_id,
					'anime_cast_json',
					wp_json_encode( $cast, JSON_UNESCAPED_UNICODE )
				);
				$done[] = 'cast';
			}
		}

		if ( ! empty( $done ) ) {
			update_post_meta( $post_id, 'anime_upcoming_filled_at', current_time( 'mysql' ) );
		}

		return $done;
	}

	/** JSON 陣列筆數；格式不對一律當 0 */
	private function count_json( $raw ): int {
		$decoded = json_decode( (string) $raw, true );

		return is_array( $decoded ) ? count( $decoded ) : 0;
	}
}

/**
 * 註冊 WP-CLI 指令
 */
if ( defined( 'WP_CLI' ) && WP_CLI ) {

	WP_CLI::add_command( 'anime check-upcoming-drift', function ( $args, $assoc_args ) {
		$show_all = isset( $assoc_args['all'] );
		$apply    = isset( $assoc_args['apply'] );
		$limit    = isset( $assoc_args['limit'] ) ? (int) $assoc_args['limit'] : 0;

		WP_CLI::log( $apply
			? '=== 未播出作品：補齊上游資料（會寫入）==='
			: '=== 未播出作品：上游資料落差檢查（唯讀）===' );
		if ( $limit > 0 ) {
			WP_CLI::log( '本次上限：--limit=' . $limit );
		}
		WP_CLI::log( '每部需向 Bangumi 取兩次，過程較慢，請耐心等候。' );
		WP_CLI::log( '' );

		$checker = new Anime_Sync_Upcoming_Drift_Check();
		$rows    = $checker->run( [ 'limit' => $limit, 'apply' => $apply ] );

		$drift = array_values( array_filter( $rows, static fn( $r ) => $r['drift'] ) );
		$notes = array_values( array_filter(
			$rows,
			static fn( $r ) => ! $r['drift'] && $r['note'] !== ''
		) );

		if ( ! empty( $drift ) ) {
			WP_CLI::log( '── 有落差，建議重新同步 ──' );
			foreach ( $drift as $r ) {
				WP_CLI::log( sprintf(
					'  #%-6d %-30s %s',
					$r['post_id'],
					mb_strimwidth( (string) $r['title'], 0, 30, '…' ),
					$r['note']
				) );
				WP_CLI::log( sprintf(
					'          https://bgm.tv/subject/%d%s',
					$r['bgm_id'],
					isset( $r['applied'] )
						? ( empty( $r['applied'] )
							? '   → 未更新（上游未較多或欄位已鎖定）'
							: '   → 已更新 ' . implode( '/', $r['applied'] ) )
						: ''
				) );
			}
			WP_CLI::log( '' );
		}

		if ( $show_all && ! empty( $notes ) ) {
			WP_CLI::log( '── 無法判定或上游尚無資料 ──' );
			foreach ( $notes as $r ) {
				WP_CLI::log( sprintf(
					'  #%-6d %-30s %s',
					$r['post_id'],
					mb_strimwidth( (string) $r['title'], 0, 30, '…' ),
					$r['note']
				) );
			}
			WP_CLI::log( '' );
		}

		WP_CLI::log( '─────────────────────────────' );
		WP_CLI::log( '檢查作品數  : ' . count( $rows ) );
		WP_CLI::log( '有落差      : ' . count( $drift ) );
		WP_CLI::log( '其他狀況    : ' . count( $notes ) . '（加 --all 可列出）' );

		if ( $apply ) {
			$applied = array_filter( $drift, static fn( $r ) => ! empty( $r['applied'] ) );
			WP_CLI::log( '實際更新    : ' . count( $applied ) );
			WP_CLI::success( '補齊完成' );
		} else {
			WP_CLI::success( '檢查完成（未寫入任何資料）。加 --apply 可實際補齊。' );
		}
	} );
}
