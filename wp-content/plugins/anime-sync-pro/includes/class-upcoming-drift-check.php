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
	 * 主要職位白名單。
	 *
	 * 必須與 class-api-handler.php 的 get_bgm_staff() 完全一致——匯入端只收
	 * 這些職位，Bangumi 原始清單則包含原画／動画／制作進行等上百筆細項。
	 * 不套用同一份過濾就會拿「過濾後的本地」比「未過濾的上游」，
	 * 幾乎每部都會被誤判成有落差（初版就踩過：180 部報出 112 部）。
	 */
	private const MAIN_ROLES = [
		'导演',
		'原作',
		'系列构成',
		'脚本',
		'人物原案',
		'角色设计',
		'人物设定',
		'音乐',
		'音響監督',
		'音响监督',
		'主题歌演出',
		'主题歌作词',
		'主题歌作曲',
		'动画制作',
	];

	/**
	 * 掃描未播出作品，回報上游與本地的筆數落差。
	 *
	 * @param array{limit?:int} $args
	 * @return array<int,array<string,mixed>> 每部作品一列
	 */
	public function run( array $args = [] ): array {
		$limit = isset( $args['limit'] ) ? max( 0, (int) $args['limit'] ) : 0;

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

		if ( $limit > 0 ) {
			$ids = array_slice( $ids, 0, $limit );
		}

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

			$row['bgm_staff'] = $this->bgm_count( $bgm_id, 'persons' );
			sleep( self::BGM_DELAY );
			$row['bgm_cast'] = $this->bgm_count( $bgm_id, 'characters' );
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
			}

			$rows[] = $row;
		}

		return $rows;
	}

	/**
	 * 取得 Bangumi 子資源的筆數。
	 *
	 * @param string $kind persons | characters
	 * @return int|null null 代表請求失敗（與「筆數為 0」意義不同）
	 */
	private function bgm_count( int $bgm_id, string $kind ): ?int {
		$res = wp_remote_get(
			'https://api.bgm.tv/v0/subjects/' . $bgm_id . '/' . $kind,
			[
				'timeout' => 15,
				'headers' => [
					'User-Agent' => self::UA,
					'Accept'     => 'application/json',
				],
			]
		);

		if ( is_wp_error( $res ) ) {
			return null;
		}

		$code = (int) wp_remote_retrieve_response_code( $res );

		// 404 代表該條目沒有這類資料，是明確的「0」而非失敗
		if ( 404 === $code ) {
			return 0;
		}

		if ( 200 !== $code ) {
			return null;
		}

		$data = json_decode( wp_remote_retrieve_body( $res ), true );

		if ( ! is_array( $data ) ) {
			return null;
		}

		// 製作人員套用與匯入端相同的主要職位過濾；角色沒有這層過濾，全數計入。
		if ( 'persons' === $kind ) {
			$data = array_filter(
				$data,
				static fn( $p ) => in_array( $p['relation'] ?? '', self::MAIN_ROLES, true )
			);
		}

		return count( $data );
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
		$limit    = isset( $assoc_args['limit'] ) ? (int) $assoc_args['limit'] : 0;

		WP_CLI::log( '=== 未播出作品：上游資料落差檢查（唯讀）===' );
		if ( $limit > 0 ) {
			WP_CLI::log( '本次上限：--limit=' . $limit );
		}
		WP_CLI::log( '每部需向 Bangumi 取兩次，過程較慢，請耐心等候。' );
		WP_CLI::log( '' );

		$checker = new Anime_Sync_Upcoming_Drift_Check();
		$rows    = $checker->run( [ 'limit' => $limit ] );

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
				WP_CLI::log( sprintf( '          https://bgm.tv/subject/%d', $r['bgm_id'] ) );
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
		WP_CLI::success( '檢查完成（未寫入任何資料）' );
	} );
}
